<?php

namespace Kanboard\Swoole;

use Pimple\Container;

class SwooleApiHandler
{
    /**
     * Execute one JSON-RPC request with explicitly injected body, server
     * variables and response builder. Must run before the first
     * $container['api'] access so the lazy provider picks up this
     * request's values. Mirrors the legacy jsonrpc.php contract, including
     * the builder's HTTP statuses: 401 with WWW-Authenticate on
     * authentication failure, 403 on access denial, 200 otherwise.
     *
     * @param  Container $container
     * @param  string    $body        Raw request body, possibly empty
     * @param  array     $serverVars  Explicit server variables
     * @return array     Status code, headers, body
     */
    public static function handle(Container $container, $body, array $serverVars)
    {
        $builder = new SwooleApiResponseBuilder();
        $container['apiRequestBody'] = $body;
        $container['apiServerVars'] = $serverVars;
        $container['apiResponseBuilder'] = $builder;

        $payload = $container['api']->execute();

        return array(
            self::resolveStatusCode($builder),
            array('Content-Type' => 'application/json') + $builder->getHeaders(),
            $payload,
        );
    }

    private static function resolveStatusCode(SwooleApiResponseBuilder $builder)
    {
        // Exact matches only: the builder sets these two statuses itself,
        // everything else (including null) keeps the legacy default.
        if ($builder->getStatus() === 'HTTP/1.0 401 Unauthorized') {
            return 401;
        }

        if ($builder->getStatus() === 'HTTP/1.0 403 Forbidden') {
            return 403;
        }

        return 200;
    }
}
