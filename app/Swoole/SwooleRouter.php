<?php

namespace Kanboard\Swoole;

use Kanboard\Core\Controller\Runner;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\Session\SessionManager;
use Pimple\Container;

class SwooleRouter
{

    /**
     * Classify a request path without touching the filesystem.
     *
     * Anything that is not an explicitly allowlisted entry is either UI
     * dispatch or denied: no private source, config, data, plugin or
     * dotfile path is ever served, and only /jsonrpc.php answers the API.
     *
     * @param  string $method
     * @param  string $uri
     * @return array  Route name plus the static path when relevant
     */
    public static function classify($method, $uri)
    {
        $path = self::extractPath($uri);

        if ($path === null) {
            return array('route' => 'deny');
        }

        if ($path === '/health') {
            return array('route' => 'health');
        }

        if ($path === '/jsonrpc.php') {
            return array('route' => 'api');
        }

        if ($path === '/') {
            return array('route' => 'ui');
        }

        if (SwooleStaticResponder::isAllowlisted($path)) {
            return array('route' => 'static', 'path' => $path);
        }

        if (self::isDeniedPath($path)) {
            return array('route' => 'deny');
        }

        return array('route' => 'ui');
    }

    /**
     * Handle one engine request with a fresh per-request container
     *
     * @param object    $engineRequest   Swoole\Http\Request
     * @param object    $engineResponse  Swoole\Http\Response
     * @param Container $container       Fresh container for this request
     */
    public static function handle($engineRequest, $engineResponse, Container $container)
    {
        $server = isset($engineRequest->server) && is_array($engineRequest->server) ? $engineRequest->server : array();
        $method = isset($server['request_method']) ? strtoupper($server['request_method']) : 'GET';
        $uri = isset($server['request_uri']) ? $server['request_uri'] : '/';
        $classified = self::classify($method, $uri);

        switch ($classified['route']) {
            case 'health':
                self::handleHealth($engineResponse, $container);
                break;
            case 'api':
                self::handleApi($engineRequest, $engineResponse, $container);
                break;
            case 'static':
                self::handleStatic($engineResponse, $classified['path'], $method);
                break;
            case 'ui':
                self::handleUi($engineRequest, $engineResponse, $container);
                break;
            default:
                SwooleStaticResponder::deny404($engineResponse, strtoupper($method) === 'HEAD');
                break;
        }
    }

    private static function handleHealth($engineResponse, Container $container)
    {
        try {
            $container['db']->getConnection()->query('SELECT 1');
            $engineResponse->header('Content-Type', 'application/json');
            $engineResponse->status(200);
            $engineResponse->end(json_encode(array('status' => 200, 'message' => 'OK')));
        } catch (\Throwable $failure) {
            $engineResponse->header('Content-Type', 'application/json');
            $engineResponse->status(500);
            $engineResponse->end(json_encode(array('status' => 500, 'message' => 'Internal Error')));
        }
    }

    private static function handleApi($engineRequest, $engineResponse, Container $container)
    {
        $mapped = SwooleRequestMapper::map($engineRequest);
        list($status, $headers, $body) = SwooleApiHandler::handle($container, $mapped['body'], $mapped['server']);

        SwooleLifecycle::rollbackTransaction($container);
        $engineResponse->status($status);

        foreach ($headers as $name => $value) {
            $engineResponse->header($name, $value);
        }

        $engineResponse->end($body);
    }

    private static function handleStatic($engineResponse, $path, $method = 'GET')
    {
        SwooleStaticResponder::respond($engineResponse, $path, strtoupper($method) === 'HEAD');
    }

    private static function handleUi($engineRequest, $engineResponse, Container $container)
    {
        $mapped = SwooleRequestMapper::map($engineRequest);
        $transfer = new SwooleUploadTransfer();
        $transfer->register($mapped['files']);
        SwooleSession::bind($mapped['cookies']);
        $container['dispatcher']->addListener(SessionManager::EVENT_DESTROY, function () {
            SwooleSession::markDestroyed();
        });

        $request = new Request(
            $container,
            $mapped['server'],
            $mapped['get'],
            $mapped['post'],
            $mapped['files'],
            $mapped['cookies'],
            true
        );
        $request->setBody($mapped['body']);

        $response = new Response($container);
        $response->capture();

        $container['request'] = $request;
        $container['response'] = $response;
        $container['objectStorage'] = new SwooleFileStorage(FILES_DIR, $transfer);

        $container['router']->dispatch();
        $runner = new Runner($container);
        $runner->execute();

        $cookies = SwooleSession::finalize($mapped['cookies'], $container['rememberMeCookie']->getPendingCookies());

        if ($cookies === false) {
            // Session persistence failed: the entrypoint answers 500 without
            // publishing any session cookie or the captured response.
            throw new \RuntimeException('Session persistence failed');
        }

        $storage = $container['objectStorage'];
        $download = $storage instanceof SwooleFileStorage ? $storage->getRecordedDownloadPath() : null;
        SwooleResponseEmitter::emit($engineResponse, $response, $download, $cookies);
    }

    private static function extractPath($uri)
    {
        if (! is_string($uri) || $uri === '') {
            return '/';
        }

        $path = strtok($uri, '?');

        if ($path === false || $path === '' || $path[0] !== '/') {
            return null;
        }

        if (strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            return null;
        }

        if (stripos($path, '%00') !== false || stripos($path, '%5c') !== false || stripos($path, '%2e') !== false) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || ($segment !== '' && $segment[0] === '.')) {
                return null;
            }
        }

        return $path;
    }

    private static function isDeniedPath($path)
    {
        if (stripos($path, '.php') !== false) {
            return true;
        }

        $lowerPath = strtolower($path);

        foreach (array('/app', '/libs', '/vendor', '/tests', '/data', '/config', '/plugins', '/cli', '/docker', '/custom-hooks') as $prefix) {
            if ($lowerPath === $prefix || strpos($lowerPath, $prefix.'/') === 0) {
                return true;
            }
        }

        return false;
    }
}
