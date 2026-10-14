<?php

namespace KanboardTests\units\Swoole;

/**
 * System-boundary double for the Swoole engine response, which only exists
 * inside the Linux runtime image. Records status/header/end/sendfile calls
 * for assertions.
 */
class SwooleResponseDouble
{
    public $statusCode;
    public $headers = array();
    public $endedBody;
    public $sentFile;
    public $cookies = array();

    public function cookie($name, $value, $expires, $path, $domain, $secure, $httponly, $samesite)
    {
        $this->cookies[] = compact('name', 'value', 'expires', 'path', 'domain', 'secure', 'httponly', 'samesite');
    }

    public function status($code)
    {
        $this->statusCode = $code;
    }

    public function header($name, $value)
    {
        $this->headers[$name] = $value;
    }

    public function end($body)
    {
        $this->endedBody = $body;
    }

    public function sendfile($path)
    {
        $this->sentFile = $path;
    }
}
