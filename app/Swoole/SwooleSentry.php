<?php

namespace Kanboard\Swoole;

use Sentry\Event;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\SentrySdk;
use Sentry\State\Scope;

/**
 * Optional error reporting to a Sentry-protocol server (Sentry, Rustrak).
 *
 * Off unless SENTRY_DSN is set. Reports uncaught request failures, JSON-RPC
 * internal errors, and PHP errors/warnings (no notices or deprecations).
 * No PII: no cookies, bodies, IP addresses or usernames; the user is sent as
 * the numeric Kanboard id only. The default RequestIntegration is not used
 * because it reads superglobals, which are empty under Swoole; the request
 * context is attached per request from the engine request instead.
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

    /**
     * Initialize the SDK in the worker process (WorkerStart). Second calls are no-ops.
     */
    public static function initializeOnce(): void
    {
        $dsn = getenv('SENTRY_DSN');

        if (self::$enabled || ! $dsn) {
            return;
        }

        \Sentry\init(array(
            'dsn' => $dsn,
            'environment' => getenv('SENTRY_ENVIRONMENT') ?: 'production',
            'release' => getenv('SENTRY_RELEASE') ?: null,
            'send_default_pii' => false,
            'max_request_body_size' => 'never',
            'error_types' => self::ERROR_TYPES,
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
        fwrite(STDERR, 'Sentry error reporting enabled'.PHP_EOL);
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
     * Close the per-request scope (server finally block).
     */
    public static function endRequest(): void
    {
        if (self::$enabled) {
            SentrySdk::getCurrentHub()->popScope();
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
