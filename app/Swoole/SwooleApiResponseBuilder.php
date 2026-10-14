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
}
