<?php

namespace Kanboard\Swoole;

use Kanboard\Core\Http\Response;

class SwooleResponseEmitter
{
    /**
     * Forward a captured Kanboard response through the Swoole transport.
     *
     * The engine argument is intentionally not type-hinted because the
     * Swoole extension only exists inside the Linux runtime image. A file
     * path streams the validated download directly; otherwise the recorded
     * body is sent. Callers defer this until session write-close and
     * request cleanup have succeeded.
     *
     * @param object        $engine    Swoole\Http\Response
     * @param Response      $captured  Capture-enabled Kanboard response
     * @param string|null   $filePath  Validated download path or null
     * @param array         $cookies   Name/value/expires/path/domain/secure/httponly/samesite
     */
    public static function emit($engine, Response $captured, $filePath = null, array $cookies = array())
    {
        $status = self::resolveStatusCode($captured);
        SwooleSentry::recordStatus($status);
        $engine->status($status);

        foreach ($captured->getHeaders() as $name => $value) {
            $engine->header($name, $value);
        }

        foreach ($cookies as $cookie) {
            $engine->cookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expires'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly'],
                $cookie['samesite']
            );
        }

        if ($filePath !== null) {
            $engine->sendfile($filePath);
        } else {
            $engine->end($captured->getBody());
        }
    }

    /**
     * Resolve the status code with PHP SAPI parity: sending a Location
     * header defaults an unset (200) status to 302 Found. The header
     * name match is case-insensitive, like the SAPI itself.
     *
     * @param  Response $captured
     * @return integer
     */
    private static function resolveStatusCode(Response $captured)
    {
        if ($captured->getStatusCode() !== 200) {
            return $captured->getStatusCode();
        }

        foreach ($captured->getHeaders() as $name => $value) {
            if (strtolower((string) $name) === 'location') {
                return 302;
            }
        }

        return 200;
    }
}
