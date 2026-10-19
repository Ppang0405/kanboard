<?php

namespace KanboardTests\units\Swoole;

/**
 * System-boundary double for the Swoole engine request, which only exists
 * inside the Linux runtime image. Mirrors the engine shape (server, header,
 * get, post, cookie, files properties plus rawContent()).
 */
class SwooleEngineDouble
{
    public $server = array();
    public $header = array();
    public $get = array();
    public $post = array();
    public $cookie = array();
    public $files = array();
    private $rawBody = '';

    public function setRawBody($body)
    {
        $this->rawBody = $body;
    }

    public function rawContent()
    {
        return $this->rawBody;
    }
}
