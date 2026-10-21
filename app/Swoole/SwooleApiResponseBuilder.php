<?php

namespace Kanboard\Swoole;

use JsonRPC\Response\ResponseBuilder;

class SwooleApiResponseBuilder extends ResponseBuilder
{
    /**
     * Suppress native header emission.
     *
     * The transport forwards the captured status and headers through the
     * engine after execute() returns; the SAPI layer must stay silent.
     *
     * @access public
     * @return $this
     */
    public function sendHeaders()
    {
        return $this;
    }

    /**
     * Report server-side failures before they become JSON-RPC errors.
     *
     * JsonRPC\Server turns every Exception into an error response, so
     * internal failures (e.g. database errors) never reach the entrypoint.
     *
     * @access public
     * @param  \Exception $exception
     * @return $this
     */
    public function withException(\Exception $exception)
    {
        SwooleSentry::captureApiException($exception);
        return parent::withException($exception);
    }
}
