<?php
namespace Icicle\Http\Server;

use Exception;
use Icicle\Awaitable\Exception\TimeoutException;
use Icicle\Coroutine\Coroutine;
use Icicle\Http\Driver\Driver;
use Icicle\Http\Driver\Http1Driver;
use Icicle\Http\Exception\ClosedError;
use Icicle\Http\Exception\InvalidResultError;
use Icicle\Http\Exception\InvalidValueException;
use Icicle\Http\Exception\MessageException;
use Icicle\Http\Exception\ParseException;
use Icicle\Http\Message\BasicResponse;
use Icicle\Http\Message\Request;
use Icicle\Http\Message\Response;
use Icicle\Log as LogNS;
use Icicle\Log\Log;
use Icicle\Socket\Server\DefaultServerFactory;
use Icicle\Socket\Server\ServerFactory;
use Icicle\Socket\Server\Server as SocketServer;
use Icicle\Socket\Socket;
use Icicle\Stream\MemorySink;

class Server
{
    const DEFAULT_ADDRESS = '*';
    const DEFAULT_CRYPTO_METHOD = STREAM_CRYPTO_METHOD_TLS_SERVER;
    const DEFAULT_TIMEOUT = 15;

    /**
     * @var \Icicle\Http\Server\RequestHandler
     */
    private $handler;

    /**
     * @var \Icicle\Http\Driver\Driver
     */
    private $driver;

    /**
     * @var \Icicle\Socket\Server\ServerFactory
     */
    private $factory;

    /**
     * @var \Icicle\Socket\Server\Server[]
     */
    private $servers = [];

    /**
     * @var \Icicle\Log\Log
     */
    private $log;

    /**
     * @var bool
     */
    private $open = true;

    /**
     * @var \Closure
     */
    private $onError;

    /**
     * @param \Icicle\Http\Server\RequestHandler $handler
     * @param \Icicle\Log\Log $log
     * @param \Icicle\Socket\Server\ServerFactory|null $factory
     * @param \Icicle\Http\Driver\Driver|null $driver
     * @param
     */
    public function __construct(
        RequestHandler $handler,
        Log $log = null,
        Driver $driver = null,
        ServerFactory $factory = null
    ) {
        $this->handler = $handler;
        $this->log = $log ?: LogNS\log();
        $this->driver = $driver ?: new Http1Driver();
        $this->factory = $factory ?: new DefaultServerFactory();

        $this->onError = function (Exception $exception) {
            $this->close();
            throw $exception;
        };
    }

    /**
     * @return bool
     */
    public function isOpen()
    {
        return $this->open;
    }

    /**
     * Closes all listening servers.
     */
    public function close()
    {
        $this->open = false;

        foreach ($this->servers as $server) {
            $server->close();
        }
    }

    /**
     * @param int $port
     * @param string $address
     * @param mixed[] $options
     *
     * @throws \Icicle\Http\Exception\ClosedError If the server has been closed.
     * @throws \Icicle\Socket\Exception\FailureException If creating the server fails.
     *
     * @see \Icicle\Socket\Server\ServerFactory::create() Options are similar to this method with the
     *     addition of the crypto_method option.
     */
    public function listen($port, $address = self::DEFAULT_ADDRESS, array $options = [])
    {
        switch ($address) {
            case '*':
                $this->start($port, '0.0.0.0', $options);
                $this->start($port, '[::]', $options);
                return;

            case 'localhost':
                $this->start($port, '127.0.0.1', $options);
                $this->start($port, '[::1]', $options);
                return;

            default:
                $this->start($port, $address, $options);
        }
    }

    /**
     * @param int $port
     * @param string $address
     * @param array $options
     *
     * @throws \Icicle\Http\Exception\ClosedError If the server has been closed.
     * @throws \Icicle\Socket\Exception\FailureException If creating the server fails.
     */
    private function start($port, $address, array $options)
    {
        if (!$this->open) {
            throw new ClosedError('The server has been closed.');
        }

        $cryptoMethod = isset($options['crypto_method'])
            ? (int) $options['crypto_method']
            : (isset($options['pem']) ? self::DEFAULT_CRYPTO_METHOD : 0);
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : self::DEFAULT_TIMEOUT;
        $allowPersistent = isset($options['allow_persistent']) ? (bool) $options['allow_persistent'] : true;

        try {
            $server = $this->factory->create($address, $port, $options);
        } catch (Exception $exception) {
            $this->close();
            throw $exception;
        }

        $this->servers[] = $server;

        $coroutine = new Coroutine($this->accept($server, $cryptoMethod, $timeout, $allowPersistent));
        $coroutine->done(null, $this->onError);
    }

    /**
     * @coroutine
     *
     * @param \Icicle\Socket\Server\Server $server
     * @param int $cryptoMethod
     * @param float $timeout
     * @param bool $allowPersistent
     *
     * @return \Generator
     */
    private function accept(SocketServer $server, $cryptoMethod, $timeout, $allowPersistent)
    {
        yield $this->log->log(
            Log::INFO,
            'HTTP server listening on %s:%d',
            $server->getAddress(),
            $server->getPort()
        );

        while ($server->isOpen()) {
            try {
                $coroutine = new Coroutine(
                    $this->process((yield $server->accept()), $cryptoMethod, $timeout, $allowPersistent)
                );
                $coroutine->done(null, $this->onError);
            } catch (Exception $exception) {
                if ($this->isOpen()) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param \Icicle\Socket\Socket $socket
     * @param int $cryptoMethod
     * @param float|int $timeout
     * @param bool $allowPersistent
     *
     * @return \Generator
     *
     * @resolve null
     */
    private function process(Socket $socket, $cryptoMethod, $timeout, $allowPersistent)
    {
        $count = 0;

        assert(yield $this->log->log(
            Log::DEBUG,
            'Accepted client from %s:%d on %s:%d',
            $socket->getRemoteAddress(),
            $socket->getRemotePort(),
            $socket->getLocalAddress(),
            $socket->getLocalPort()
        ));

        try {
            if (0 !== $cryptoMethod) {
                yield $socket->enableCrypto($cryptoMethod, $timeout);
            }

            do {
                $request = null;

                try {
                    /** @var \Icicle\Http\Message\Request $request */
                    $request = (yield $this->driver->readRequest($socket, $timeout));
                    ++$count;

                    /** @var \Icicle\Http\Message\Response $response */
                    $response = (yield $this->createResponse($request, $socket));

                    assert(yield $this->log->log(
                        Log::DEBUG,
                        'Responded to request from %s:%d for %s with %d %s',
                        $socket->getRemoteAddress(),
                        $socket->getRemotePort(),
                        $request->getUri(),
                        $response->getStatusCode(),
                        $response->getReasonPhrase()
                    ));
                } catch (TimeoutException $exception) { // Request timeout.
                    if (0 < $count) {
                        assert(yield $this->log->log(
                            Log::DEBUG,
                            'Keep-alive timeout from %s:%d on %s:%d',
                            $socket->getRemoteAddress(),
                            $socket->getRemotePort(),
                            $socket->getLocalAddress(),
                            $socket->getLocalPort()
                        ));
                        return; // Keep-alive timeout expired.
                    }
                    $response = (yield $this->createErrorResponse(Response::REQUEST_TIMEOUT, $socket));
                } catch (MessageException $exception) { // Bad request.
                    $response = (yield $this->createErrorResponse($exception->getCode(), $socket));
                } catch (InvalidValueException $exception) { // Invalid value in message header.
                    $response = (yield $this->createErrorResponse(Response::BAD_REQUEST, $socket));
                } catch (ParseException $exception) { // Parse error in request.
                    $response = (yield $this->createErrorResponse(Response::BAD_REQUEST, $socket));
                }

                $response = (yield $this->driver->buildResponse(
                    $response,
                    $request,
                    $timeout,
                    $allowPersistent
                ));

                try {
                    yield $this->driver->writeResponse($socket, $response, $request, $timeout);
                } finally {
                    $response->getBody()->close();
                }

            } while (strtolower($response->getHeader('Connection')) === 'keep-alive');
        } catch (Exception $exception) {
            yield $this->log->log(
                Log::NOTICE,
                "Error when handling request from %s:%d: %s",
                $socket->getRemoteAddress(),
                $socket->getRemotePort(),
                $exception->getMessage()
            );
        } finally {
            $socket->close();
        }

        assert(yield $this->log->log(
            Log::DEBUG,
            'Disconnected client from %s:%d on %s:%d',
            $socket->getRemoteAddress(),
            $socket->getRemotePort(),
            $socket->getLocalAddress(),
            $socket->getLocalPort()
        ));
    }

    /**
     * @coroutine
     *
     * @param \Icicle\Http\Message\Request $request
     * @param \Icicle\Socket\Socket $socket
     *
     * @return \Generator
     *
     * @resolve \Icicle\Http\Message|Response
     */
    private function createResponse(Request $request, Socket $socket)
    {
        try {
            assert(yield $this->log->log(
                Log::DEBUG,
                'Received request from %s:%d for %s',
                $socket->getRemoteAddress(),
                $socket->getRemotePort(),
                $request->getUri()
            ));

            $response = (yield $this->handler->onRequest($request, $socket));

            if (!$response instanceof Response) {
                throw new InvalidResultError(sprintf(
                    'A %s object was not returned from %::onRequest().',
                    Response::class,
                    get_class($this->handler)
                ), $response);
            }
        } catch (Exception $exception) {
            yield $this->log->log(
                Log::ERROR,
                "Uncaught exception when creating response to a request from %s:%d on %s:%d in file %s on line %d: %s",
                $socket->getRemoteAddress(),
                $socket->getRemotePort(),
                $socket->getLocalAddress(),
                $socket->getLocalPort(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getMessage()
            );
            $response = (yield $this->createDefaultErrorResponse(500));
        }

        yield $response;
    }

    /**
     * @coroutine
     *
     * @param int $code
     * @param \Icicle\Socket\Socket $socket
     *
     * @return \Generator
     *
     * @resolve \Icicle\Http\Message|Response
     */
    private function createErrorResponse($code, Socket $socket)
    {
        try {
            yield $this->log->log(
                Log::NOTICE,
                'Error reading request from %s:%d (Status Code: %d)',
                $socket->getRemoteAddress(),
                $socket->getRemotePort(),
                $code
            );

            $response = (yield $this->handler->onError($code, $socket));

            if (!$response instanceof Response) {
                throw new InvalidResultError(sprintf(
                    'A %s object was not returned from %::onError().',
                    Response::class,
                    get_class($this->handler)
                ), $response);
            }
        } catch (Exception $exception) {
            yield $this->log->log(
                Log::ERROR,
                "Uncaught exception when creating response to an error from %s:%d on %s:%d in file %s on line %d: %s",
                $socket->getRemoteAddress(),
                $socket->getRemotePort(),
                $socket->getLocalAddress(),
                $socket->getLocalPort(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getMessage()
            );
            $response = (yield $this->createDefaultErrorResponse(500));
        }

        yield $response;
    }

    /**
     * @coroutine
     *
     * @param int $code
     *
     * @return \Generator
     *
     * @resolve \Icicle\Http\Message\Response
     */
    protected function createDefaultErrorResponse($code)
    {
        $sink = new MemorySink(sprintf('%d Error', $code));

        $headers = [
            'Connection' => 'close',
            'Content-Type' => 'text/plain',
            'Content-Length' => $sink->getLength(),
        ];

        yield new BasicResponse($code, $headers, $sink);
    }
}
