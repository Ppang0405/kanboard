<?php

namespace Kanboard\Swoole;

class SwooleRequestMapper
{
    /**
     * Map a Swoole engine request to explicit PHP-style request bags.
     *
     * Every bag is filled from this request only: empty stays empty so the
     * isolated Kanboard Request never falls back to worker superglobals.
     * The engine argument is intentionally not type-hinted because the
     * Swoole extension only exists inside the Linux runtime image.
     *
     * @param  object $engine  Swoole\Http\Request
     * @return array  Keys: server, get, post, files, cookies, body
     */
    public static function map($engine)
    {
        $engineServer = isset($engine->server) && is_array($engine->server) ? $engine->server : array();
        $engineHeaders = isset($engine->header) && is_array($engine->header) ? $engine->header : array();

        $server = array(
            'REQUEST_METHOD' => strtoupper(isset($engineServer['request_method']) ? $engineServer['request_method'] : 'GET'),
            'REQUEST_URI' => isset($engineServer['request_uri']) ? $engineServer['request_uri'] : '/',
            'QUERY_STRING' => isset($engineServer['query_string']) ? $engineServer['query_string'] : '',
            'SERVER_PROTOCOL' => isset($engineServer['server_protocol']) ? $engineServer['server_protocol'] : 'HTTP/1.1',
            'REMOTE_ADDR' => isset($engineServer['remote_addr']) ? $engineServer['remote_addr'] : '',
            'REQUEST_TIME_FLOAT' => isset($engineServer['request_time_float']) ? $engineServer['request_time_float'] : microtime(true),
            'PHP_SELF' => '/index.php',
            'SCRIPT_NAME' => '/index.php',
            'CONTENT_TYPE' => '',
            'CONTENT_LENGTH' => '',
            'REQUEST_SCHEME' => 'http',
        );

        if (! empty($engineServer['https']) || ! empty($engineServer['ssl'])) {
            $server['HTTPS'] = 'on';
            $server['REQUEST_SCHEME'] = 'https';
        }

        $host = self::findHeader($engineHeaders, 'host');

        if ($host !== null) {
            $parsed = self::parseHostHeader($host, $server['REQUEST_SCHEME'] === 'https' ? '443' : '80');

            if ($parsed !== null) {
                $server['SERVER_NAME'] = $parsed[0];
                $server['SERVER_PORT'] = $parsed[1];
            }
        }

        foreach ($engineHeaders as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $server[$key] = $value;
            } else {
                $server['HTTP_'.$key] = $value;
            }
        }

        self::mapBasicAuthorization($engineHeaders, $server);

        return array(
            'server' => $server,
            'get' => isset($engine->get) && is_array($engine->get) ? $engine->get : array(),
            'post' => isset($engine->post) && is_array($engine->post) ? $engine->post : array(),
            'files' => self::normalizeFiles(isset($engine->files) && is_array($engine->files) ? $engine->files : array()),
            'cookies' => isset($engine->cookie) && is_array($engine->cookie) ? $engine->cookie : array(),
            'body' => (string) $engine->rawContent(),
        );
    }

    /**
     * Find a header value without regard to the sender's casing.
     *
     * Swoole preserves the casing sent by the client, so lookups by a
     * fixed lowercase key would miss e.g. `Authorization` or `HOST`.
     *
     * @param  array  $headers
     * @param  string $name  Lowercase header name
     * @return mixed|null  Raw header value or null when absent
     */
    private static function findHeader(array $headers, $name)
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Split a Host header into server name and port.
     *
     * Handles `example.com`, `example.com:8080` and bracketed IPv6
     * literals (`[::1]`, `[::1]:8080`); a bare unbracketed IPv6 literal
     * is never split on its inner colons. Returns null for values that
     * are unusable (empty, or containing control/CRLF/whitespace bytes)
     * so the caller falls back to the scheme-based defaults.
     *
     * @param  mixed  $host
     * @param  string $defaultPort
     * @return array|null  Array($name, $port) or null
     */
    private static function parseHostHeader($host, $defaultPort)
    {
        if (! is_string($host) || $host === '') {
            return null;
        }

        if (preg_match('/[\x00-\x20\x7F]/', $host)) {
            return null;
        }

        if ($host[0] === '[') {
            $end = strpos($host, ']');

            if ($end === false) {
                return null;
            }

            $name = substr($host, 1, $end - 1);
            $rest = substr($host, $end + 1);

            if ($rest === '') {
                $port = $defaultPort;
            } elseif (preg_match('/^:(\d{1,5})$/', $rest, $matches) && (int) $matches[1] >= 1 && (int) $matches[1] <= 65535) {
                $port = $matches[1];
            } else {
                return null;
            }

            return $name === '' ? null : array($name, $port);
        }

        if (substr_count($host, ':') > 1) {
            return array($host, $defaultPort);
        }

        $parts = explode(':', $host, 2);
        $name = $parts[0];

        if ($name === '') {
            return null;
        }

        $port = $defaultPort;

        if (isset($parts[1]) && ctype_digit($parts[1]) && (int) $parts[1] >= 1 && (int) $parts[1] <= 65535) {
            $port = $parts[1];
        }

        return array($name, $port);
    }

    private static function mapBasicAuthorization(array $headers, array &$server)
    {
        $authorization = self::findHeader($headers, 'authorization');

        if (! is_string($authorization) || stripos($authorization, 'Basic ') !== 0) {
            return;
        }

        $decoded = base64_decode(substr($authorization, 6), true);

        if ($decoded === false || strpos($decoded, ':') === false) {
            return;
        }

        list($username, $password) = explode(':', $decoded, 2);
        $server['PHP_AUTH_USER'] = $username;
        $server['PHP_AUTH_PW'] = $password;
    }

    private static function normalizeFiles(array $files)
    {
        $normalized = array();

        foreach ($files as $field => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (array_key_exists('name', $entry) && ! is_array($entry['name'])) {
                $normalized[$field] = self::normalizeSingleFile($entry);
            } elseif (array_key_exists(0, $entry) && is_array($entry[0])) {
                $normalized[$field] = self::normalizeMultipleFiles($entry);
            }
        }

        return $normalized;
    }

    private static function normalizeSingleFile(array $entry)
    {
        return array(
            'name' => isset($entry['name']) ? $entry['name'] : '',
            'type' => isset($entry['type']) ? $entry['type'] : '',
            'tmp_name' => isset($entry['tmp_name']) ? $entry['tmp_name'] : '',
            'error' => isset($entry['error']) ? $entry['error'] : UPLOAD_ERR_NO_FILE,
            'size' => isset($entry['size']) ? $entry['size'] : 0,
        );
    }

    private static function normalizeMultipleFiles(array $entries)
    {
        $normalized = array(
            'name' => array(),
            'type' => array(),
            'tmp_name' => array(),
            'error' => array(),
            'size' => array(),
        );

        foreach ($entries as $entry) {
            $file = self::normalizeSingleFile(is_array($entry) ? $entry : array());

            foreach ($normalized as $attribute => $values) {
                $normalized[$attribute][] = $file[$attribute];
            }
        }

        return $normalized;
    }
}
