<?php

namespace KanboardTests\integration\swoole;

/** Shared real-HTTP client: jars, CSRF extractors, admin API caller. */
class SwooleHttpClient
{
    private $baseUrl;
    private $jars = array();

    public function __construct($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    public function isContainerReachable()
    {
        try {
            return $this->request('GET', '/health')['status'] === 200;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function request($method, $path, array $headers = array(), $body = null)
    {
        $handle = curl_init($this->baseUrl.$path);
        curl_setopt_array($handle, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15));

        if ($headers !== array()) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        }

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        try {
            $raw = curl_exec($handle);

            if ($raw === false) {
                throw new \RuntimeException('Swoole HTTP request failed: '.curl_error($handle));
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);

            list($headers, $setCookies) = $this->parseHeaderBlock(substr($raw, 0, $headerSize));

            return array(
                'status' => $status,
                'headers' => $headers,
                'setCookies' => $setCookies,
                'body' => substr($raw, $headerSize),
            );
        } finally {
            if (PHP_VERSION_ID < 80500) {
                curl_close($handle); // deprecated no-op since PHP 8.5
            }
        }
    }

    public function jarRequest($jar, $method, $path, array $headers = array(), $body = null, array $extraCookies = array())
    {
        if (! isset($this->jars[$jar])) {
            $this->jars[$jar] = array();
        }

        $cookies = array_merge($this->jars[$jar], $extraCookies);
        $pairs = array();

        foreach ($cookies as $name => $value) {
            $pairs[] = $name.'='.$value;
        }

        if ($pairs !== array()) {
            $headers[] = 'Cookie: '.implode('; ', $pairs);
        }

        $exchange = $this->request($method, $path, $headers, $body);

        foreach ($exchange['setCookies'] as $setCookie) {
            $parts = explode(';', $setCookie);
            list($name, $value) = array_pad(explode('=', trim($parts[0]), 2), 2, '');

            if ($value === '' || strtolower($value) === 'deleted' || self::partsExpired($parts)) {
                unset($this->jars[$jar][$name]);
            } else {
                $this->jars[$jar][$name] = $value;
            }
        }

        return $exchange;
    }

    private static function partsExpired(array $parts)
    {
        foreach ($parts as $part) {
            $part = trim($part);

            if (stripos($part, 'max-age=0') === 0) {
                return true;
            }

            if (stripos($part, 'expires=') === 0) {
                $timestamp = strtotime(substr($part, strlen('expires=')));
                return $timestamp !== false && $timestamp <= time();
            }
        }

        return false;
    }

    public function jarValue($jar, $name)
    {
        return isset($this->jars[$jar][$name]) ? $this->jars[$jar][$name] : null;
    }

    public function extractCsrfToken($html)
    {
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches)) {
            return $matches[1];
        }

        if (preg_match('/csrf_token=([a-zA-Z0-9]+)/', $html, $matches)) {
            return $matches[1];
        }

        if (preg_match('/csrf["\']?\s*[:=]\s*["\']?([a-f0-9]{32,})/i', $html, $matches)) {
            return $matches[1];
        }

        return '';
    }

    public function loginPost($jar, $username, $password, $rememberMe)
    {
        $page = $this->jarRequest($jar, 'GET', '/?controller=AuthController&action=login');
        $fields = 'username='.urlencode($username).'&password='.urlencode($password)
            .'&csrf_token='.urlencode($this->extractCsrfToken($page['body']));

        if ($rememberMe) {
            $fields .= '&remember_me=1';
        }

        return $this->jarRequest($jar, 'POST', '/?controller=AuthController&action=check',
            array('Content-Type: application/x-www-form-urlencoded'), $fields);
    }

    public function apiCall($method, array $params = array(), $id = 1)
    {
        $exchange = $this->request(
            'POST',
            '/jsonrpc.php',
            array('Content-Type: application/json', 'Authorization: Basic '.base64_encode('admin:admin')),
            json_encode(array('jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params))
        );

        return json_decode($exchange['body'], true);
    }

    private function parseHeaderBlock($block)
    {
        $headers = array();
        $cookies = array();

        foreach (explode("\r\n", $block) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookies[] = trim(substr($line, strlen('Set-Cookie:')));
            } elseif (strpos($line, ':') !== false) {
                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return array($headers, $cookies);
    }
}
