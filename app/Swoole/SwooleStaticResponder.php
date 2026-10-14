<?php

namespace Kanboard\Swoole;

class SwooleStaticResponder
{
    const MAX_STATIC_BYTES = 5242880;

    private static $contentTypes = array(
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'map' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'txt' => 'text/plain; charset=utf-8',
    );

    /**
     * Return true for explicitly allowlisted public files only
     *
     * @param  string $path  Already sanitized absolute path
     * @return bool
     */
    public static function isAllowlisted($path)
    {
        if ($path === '/favicon.ico' || $path === '/robots.txt') {
            return true;
        }

        if (strpos($path, '/assets/') !== 0) {
            return false;
        }

        $relative = substr($path, strlen('/assets/'));

        if ($relative === '' || strpos($relative, '//') !== false) {
            return false;
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment[0] === '.') {
                return false;
            }
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        return $extension !== '' && isset(self::$contentTypes[$extension]);
    }

    /**
     * Stream an allowlisted file directly, denying everything else
     *
     * @param object $engineResponse  Swoole\Http\Response
     * @param string $path            Sanitized absolute path
     */
    public static function respond($engineResponse, $path)
    {
        $root = realpath(ROOT_DIR);
        $filename = $root === false ? false : realpath(ROOT_DIR.$path);

        if ($filename === false || ! is_file($filename)) {
            self::deny($engineResponse);
            return;
        }

        if (strpos($filename, $root.DIRECTORY_SEPARATOR) !== 0 || filesize($filename) > self::MAX_STATIC_BYTES) {
            self::deny($engineResponse);
            return;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! isset(self::$contentTypes[$extension])) {
            self::deny($engineResponse);
            return;
        }

        $engineResponse->header('Content-Type', self::$contentTypes[$extension]);
        $engineResponse->header('Content-Length', (string) filesize($filename));
        $engineResponse->header('Cache-Control', 'public, max-age=86400');
        $engineResponse->status(200);
        $engineResponse->sendfile($filename);
    }

    /**
     * Answer 404 without disclosing anything about the requested path
     *
     * @param object $engineResponse  Swoole\Http\Response
     */
    public static function deny404($engineResponse)
    {
        self::deny($engineResponse);
    }

    private static function deny($engineResponse)
    {
        $engineResponse->header('Content-Type', 'application/json');
        $engineResponse->status(404);
        $engineResponse->end(json_encode(array('status' => 404, 'message' => 'Not Found')));
    }
}
