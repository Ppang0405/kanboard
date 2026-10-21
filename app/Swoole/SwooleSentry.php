<?php

namespace Kanboard\Swoole;

use Sentry\Event;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;

/**
 * Optional error reporting to a Sentry-protocol server (Sentry, Rustrak).
 *
 * Off unless SENTRY_DSN is set. Reports uncaught request failures, JSON-RPC
 * internal errors, and PHP errors/warnings (no notices or deprecations).
 * No PII: no cookies, bodies, IP addresses or usernames; the user is sent as
 * the numeric Kanboard id only. The default RequestIntegration is not used
 * because it reads superglobals, which are empty under Swoole; the request
 * context is attached per request from the engine request instead.
 *
 * Performance tracing is off unless SENTRY_TRACES_SAMPLE_RATE > 0: one
 * transaction per UI or JSON-RPC request (health checks and static files are
 * never traced), named after the controller/action or API method, with the
 * HTTP status and the request's database query count and time.
 */
class SwooleSentry
{
    const ERROR_TYPES = E_ERROR | E_WARNING | E_PARSE | E_CORE_ERROR | E_CORE_WARNING
        | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR;

    // Query parameters that can carry secrets (CSRF tokens, cronjob/feed tokens).
    const REDACTED_QUERY_KEYS = array('token', 'csrf_token', 'password');

    // JSON-RPC protocol and authorization outcomes: client mistakes, not server errors.
    const API_CLIENT_EXCEPTIONS = array(
        \JsonRPC\Exception\InvalidJsonFormatException::class,
        \JsonRPC\Exception\InvalidJsonRpcFormatException::class,
        \JsonRPC\Exception\AuthenticationFailureException::class,
        \JsonRPC\Exception\AccessDeniedException::class,
        \JsonRPC\Exception\ResponseException::class,
        \BadFunctionCallException::class,
        \InvalidArgumentException::class,
    );

    private static $enabled = false;
    private static $tracing = false;
    private static $transaction = null;
    private static $status = 200;

    /**
     * Initialize the SDK in the worker process (WorkerStart). Second calls are no-ops.
     */
    public static function initializeOnce(): void
    {
        $dsn = getenv('SENTRY_DSN');

        if (self::$enabled || ! $dsn) {
            return;
        }

        $tracesSampleRate = (float) getenv('SENTRY_TRACES_SAMPLE_RATE');

        \Sentry\init(array(
            'dsn' => $dsn,
            'environment' => getenv('SENTRY_ENVIRONMENT') ?: 'production',
            'release' => getenv('SENTRY_RELEASE') ?: null,
            'send_default_pii' => false,
            'max_request_body_size' => 'never',
            'error_types' => self::ERROR_TYPES,
            'traces_sample_rate' => $tracesSampleRate > 0 ? $tracesSampleRate : null,
            'default_integrations' => false,
            'integrations' => array(
                new ErrorListenerIntegration(),
                new FatalErrorListenerIntegration(),
                new FrameContextifierIntegration(),
                new EnvironmentIntegration(),
            ),
            // Sends are synchronous on the single worker: keep a dead collector from stalling requests.
            'http_connect_timeout' => 1,
            'http_timeout' => 2,
        ));

        self::$enabled = true;
        self::$tracing = $tracesSampleRate > 0;
        fwrite(STDERR, 'Sentry error reporting enabled'.(self::$tracing ? ', tracing at '.$tracesSampleRate : '').PHP_EOL);
    }

    /**
     * Open a per-request scope carrying the request context.
     *
     * @param \Swoole\Http\Request $engineRequest
     */
    public static function beginRequest($engineRequest): void
    {
        if (! self::$enabled) {
            return;
        }

        $request = self::describeRequest($engineRequest);

        SentrySdk::getCurrentHub()->pushScope();
        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($request) {
            $scope->addEventProcessor(function (Event $event) use ($request) {
                $event->setRequest($request);
                return $event;
            });
        });

        if (self::$tracing) {
            // Runs before the entrypoint's try block: tracing must never fail a request.
            try {
                self::startTransaction($engineRequest);
            } catch (\Throwable $failure) {
                self::$transaction = null;
                fwrite(STDERR, 'Sentry transaction start failed: '.$failure->getMessage().PHP_EOL);
            }
        }
    }

    /**
     * Time this request's database work (only while a transaction is open).
     *
     * @param \Pimple\Container $container
     */
    public static function instrument($container): void
    {
        if (self::$transaction === null) {
            return;
        }

        try {
            $container['db']->getStatementHandler()->withStopWatch();
        } catch (\Throwable $failure) {
            fwrite(STDERR, 'Sentry DB instrumentation failed: '.$failure->getMessage().PHP_EOL);
        }
    }

    /**
     * Remember the HTTP status sent for this request (closes the transaction with it).
     *
     * @param int $status
     */
    public static function recordStatus($status): void
    {
        self::$status = (int) $status;
    }

    /**
     * Report a request failure, tagged with the logged-in user id when known.
     *
     * @param \Throwable $failure
     * @param int        $userId  0 when anonymous or unknown
     */
    public static function captureException(\Throwable $failure, $userId = 0): void
    {
        if (! self::$enabled) {
            return;
        }

        try {
            \Sentry\withScope(function (Scope $scope) use ($failure, $userId) {
                if ($userId > 0) {
                    $scope->setUser(array('id' => (string) $userId));
                }

                \Sentry\captureException($failure);
            });
        } catch (\Throwable $reportFailure) {
            fwrite(STDERR, 'Sentry report failed: '.$reportFailure->getMessage().PHP_EOL);
        }
    }

    /**
     * Report a JSON-RPC exception unless it is a protocol or authorization outcome.
     *
     * @param \Throwable $exception
     */
    public static function captureApiException(\Throwable $exception): void
    {
        foreach (self::API_CLIENT_EXCEPTIONS as $class) {
            if ($exception instanceof $class) {
                return;
            }
        }

        self::captureException($exception);
    }

    /**
     * Logged-in user id of this request, 0 when anonymous or unavailable.
     *
     * @param \Pimple\Container|null $container
     * @return int
     */
    public static function currentUserId($container)
    {
        if (! self::$enabled || $container === null) {
            return 0;
        }

        try {
            return (int) $container['userSession']->getId();
        } catch (\Throwable $failure) {
            return 0;
        }
    }

    /**
     * Finish the transaction, then close the per-request scope (server finally block).
     *
     * @param \Pimple\Container|null $container
     */
    public static function endRequest($container = null): void
    {
        if (! self::$enabled) {
            return;
        }

        if (self::$transaction !== null) {
            try {
                self::$transaction->setData(self::databaseData($container));
                self::$transaction->setHttpStatus(self::$status);
                self::$transaction->finish();
            } catch (\Throwable $failure) {
                fwrite(STDERR, 'Sentry transaction failed: '.$failure->getMessage().PHP_EOL);
            }

            self::$transaction = null;
        }

        SentrySdk::getCurrentHub()->popScope();
    }

    private static function startTransaction($engineRequest): void
    {
        $server = isset($engineRequest->server) ? $engineRequest->server : array();
        $method = isset($server['request_method']) ? strtoupper($server['request_method']) : 'GET';
        $route = SwooleRouter::classify($method, isset($server['request_uri']) ? $server['request_uri'] : '/')['route'];

        if ($route !== 'ui' && $route !== 'api') {
            return;
        }

        $context = TransactionContext::make()
            ->setOp('http.server')
            ->setName(self::transactionName($engineRequest, $method, $route))
            ->setSource(TransactionSource::route());

        $transaction = \Sentry\startTransaction($context);

        if ($transaction->getSampled()) {
            SentrySdk::getCurrentHub()->setSpan($transaction);
            self::$transaction = $transaction;
            self::$status = 200;
        }
    }

    /**
     * "GET TaskViewController::show" or "jsonrpc getTask"; unexpected values
     * collapse to "?" so garbage input cannot create unbounded names.
     */
    private static function transactionName($engineRequest, $method, $route)
    {
        if ($route === 'api') {
            $body = method_exists($engineRequest, 'rawContent') ? (string) $engineRequest->rawContent() : '';
            $payload = strlen($body) <= 65536 ? json_decode($body, true) : null;

            if (is_array($payload) && isset($payload[0])) {
                return 'jsonrpc batch';
            }

            $procedure = is_array($payload) && isset($payload['method']) ? $payload['method'] : '';
            return 'jsonrpc '.(is_string($procedure) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $procedure) ? $procedure : '?');
        }

        $query = isset($engineRequest->get) && is_array($engineRequest->get) ? $engineRequest->get : array();

        if (! isset($query['controller'])) {
            return $method.' /';
        }

        $name = function ($value) {
            return is_string($value) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) ? $value : '?';
        };

        return $method.' '.$name($query['controller']).'::'.$name(isset($query['action']) ? $query['action'] : 'show');
    }

    private static function databaseData($container): array
    {
        if ($container === null) {
            return array();
        }

        try {
            $handler = $container['db']->getStatementHandler();
            $data = array('db.query_count' => $handler->getNbQueries());

            // The stopwatch logs a running total after each query; the last one is this request's DB time.
            foreach (array_reverse($container['db']->getLogMessages()) as $message) {
                if (strpos($message, 'total_execution_time=') === 0) {
                    $data['db.time_ms'] = round(1000 * (float) substr($message, 21), 1);
                    break;
                }
            }

            return $data;
        } catch (\Throwable $failure) {
            return array();
        }
    }

    private static function describeRequest($engineRequest): array
    {
        $server = isset($engineRequest->server) ? $engineRequest->server : array();
        $headers = isset($engineRequest->header) ? $engineRequest->header : array();
        $host = isset($headers['host']) ? $headers['host'] : 'localhost';
        $scheme = isset($headers['x-forwarded-proto']) && $headers['x-forwarded-proto'] === 'https' ? 'https' : 'http';
        $path = isset($server['request_uri']) ? $server['request_uri'] : '/';

        parse_str(isset($server['query_string']) ? $server['query_string'] : '', $query);

        foreach (self::REDACTED_QUERY_KEYS as $key) {
            if (isset($query[$key])) {
                $query[$key] = '[Filtered]';
            }
        }

        $request = array(
            'url' => $scheme.'://'.$host.$path,
            'method' => isset($server['request_method']) ? $server['request_method'] : 'GET',
            'query_string' => http_build_query($query),
        );

        if (isset($headers['user-agent'])) {
            $request['headers'] = array('User-Agent' => $headers['user-agent']);
        }

        return $request;
    }
}
