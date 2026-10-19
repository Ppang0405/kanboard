<?php

namespace KanboardTests\units\Swoole;

use Kanboard\Swoole\SwooleRequestMapper;
use PHPUnit\Framework\TestCase;

class SwooleRequestMapperTest extends TestCase
{
    public function testMapsServerVariablesHeadersAndBody()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array(
            'request_method' => 'post',
            'request_uri' => '/dashboard?x=1',
            'query_string' => 'x=1',
            'server_protocol' => 'HTTP/1.1',
            'remote_addr' => '10.0.0.7',
            'request_time_float' => 1234567.125,
        );
        $engine->header = array(
            'content-type' => 'application/x-www-form-urlencoded',
            'content-length' => '13',
            'x-requested-with' => 'XMLHttpRequest',
            'cookie' => 'KB_SID=abc123',
        );
        $engine->get = array('x' => '1');
        $engine->post = array('field' => 'value');
        $engine->cookie = array('KB_SID' => 'abc123');
        $engine->setRawBody('field=value');

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('POST', $mapped['server']['REQUEST_METHOD']);
        $this->assertSame('/dashboard?x=1', $mapped['server']['REQUEST_URI']);
        $this->assertSame('x=1', $mapped['server']['QUERY_STRING']);
        $this->assertSame('HTTP/1.1', $mapped['server']['SERVER_PROTOCOL']);
        $this->assertSame('10.0.0.7', $mapped['server']['REMOTE_ADDR']);
        $this->assertSame(1234567.125, $mapped['server']['REQUEST_TIME_FLOAT']);
        $this->assertSame('/index.php', $mapped['server']['PHP_SELF']);
        $this->assertSame('application/x-www-form-urlencoded', $mapped['server']['CONTENT_TYPE']);
        $this->assertSame('13', $mapped['server']['CONTENT_LENGTH']);
        $this->assertSame('XMLHttpRequest', $mapped['server']['HTTP_X_REQUESTED_WITH']);
        $this->assertSame('KB_SID=abc123', $mapped['server']['HTTP_COOKIE']);
        $this->assertSame(array('x' => '1'), $mapped['get']);
        $this->assertSame(array('field' => 'value'), $mapped['post']);
        $this->assertSame(array('KB_SID' => 'abc123'), $mapped['cookies']);
        $this->assertSame('field=value', $mapped['body']);
    }

    public function testMapsHttpsServerNamePortAndScheme()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array(
            'request_method' => 'GET',
            'request_uri' => '/',
            'https' => 1,
        );
        $engine->header = array('host' => 'kanboard.lan:8443');

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('on', $mapped['server']['HTTPS']);
        $this->assertSame('kanboard.lan', $mapped['server']['SERVER_NAME']);
        $this->assertSame('8443', $mapped['server']['SERVER_PORT']);
        $this->assertSame('https', $mapped['server']['REQUEST_SCHEME']);
    }

    public function testPlainHttpDefaultsWithoutEngineHints()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'GET', 'request_uri' => '/');

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertArrayNotHasKey('HTTPS', $mapped['server']);
        $this->assertSame('http', $mapped['server']['REQUEST_SCHEME']);
    }

    public function testDecodesBasicAuthorizationHeader()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'POST', 'request_uri' => '/jsonrpc.php');
        $engine->header = array('authorization' => 'Basic '.base64_encode('admin:secret:with:colons'));

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('admin', $mapped['server']['PHP_AUTH_USER']);
        $this->assertSame('secret:with:colons', $mapped['server']['PHP_AUTH_PW']);
    }

    public function testMalformedAuthorizationHeaderYieldsNoCredentials()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'GET', 'request_uri' => '/');
        $engine->header = array('authorization' => 'Basic !!!not-base64!!!');

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertArrayNotHasKey('PHP_AUTH_USER', $mapped['server']);
        $this->assertArrayNotHasKey('PHP_AUTH_PW', $mapped['server']);
    }

    public function testAuthorizationHeaderLookupIsCaseInsensitive()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'POST', 'request_uri' => '/jsonrpc.php');
        $engine->header = array('Authorization' => 'Basic '.base64_encode('api-user:api-pass'));

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('api-user', $mapped['server']['PHP_AUTH_USER']);
        $this->assertSame('api-pass', $mapped['server']['PHP_AUTH_PW']);
    }

    public function testHostHeaderLookupIsCaseInsensitive()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'GET', 'request_uri' => '/');
        $engine->header = array('HOST' => 'kanboard.lan:9000');

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('kanboard.lan', $mapped['server']['SERVER_NAME']);
        $this->assertSame('9000', $mapped['server']['SERVER_PORT']);
    }

    public function testParsesBracketedIpv6HostWithPort()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'GET', 'request_uri' => '/');
        $engine->header = array('host' => '[::1]:8080');

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('::1', $mapped['server']['SERVER_NAME']);
        $this->assertSame('8080', $mapped['server']['SERVER_PORT']);
    }

    public function testParsesBracketedIpv6HostWithDefaultPort()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'GET', 'request_uri' => '/');
        $engine->header = array('host' => '[2001:db8::1]');

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('2001:db8::1', $mapped['server']['SERVER_NAME']);
        $this->assertSame('80', $mapped['server']['SERVER_PORT']);
    }

    public function testInvalidHostHeaderFallsBackToDefaults()
    {
        foreach (array("evil.example.com\r\nX-Injected: 1", 'evil.example.com bad', '', "[::1", '[]:8080') as $host) {
            $engine = new SwooleEngineDouble();
            $engine->server = array('request_method' => 'GET', 'request_uri' => '/');
            $engine->header = array('host' => $host);

            $mapped = SwooleRequestMapper::map($engine);

            $this->assertArrayNotHasKey('SERVER_NAME', $mapped['server'], 'Host must be rejected: '.$host);
            $this->assertArrayNotHasKey('SERVER_PORT', $mapped['server'], 'Host must be rejected: '.$host);
        }
    }

    public function testNonStringRawBodyIsCastToString()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'POST', 'request_uri' => '/');
        $engine->setRawBody(null);

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('', $mapped['body']);

        $engine->setRawBody(123);

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('123', $mapped['body']);
    }

    public function testEmptyBagsStayEmptyForIsolatedRequests()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'GET', 'request_uri' => '/');

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame(array(), $mapped['get']);
        $this->assertSame(array(), $mapped['post']);
        $this->assertSame(array(), $mapped['files']);
        $this->assertSame(array(), $mapped['cookies']);
        $this->assertSame('', $mapped['body']);
    }

    public function testNormalizesSingleAndMultipleFiles()
    {
        $engine = new SwooleEngineDouble();
        $engine->server = array('request_method' => 'POST', 'request_uri' => '/');
        $engine->files = array(
            'avatar' => array(
                'name' => 'me.png',
                'type' => 'image/png',
                'tmp_name' => '/tmp/swoole.upfile.AAA',
                'error' => UPLOAD_ERR_OK,
                'size' => 42,
            ),
            'docs' => array(
                array(
                    'name' => 'a.txt',
                    'type' => 'text/plain',
                    'tmp_name' => '/tmp/swoole.upfile.BBB',
                    'error' => UPLOAD_ERR_OK,
                    'size' => 7,
                ),
                array(
                    'name' => 'b.txt',
                    'type' => 'text/plain',
                    'tmp_name' => '',
                    'error' => UPLOAD_ERR_NO_FILE,
                    'size' => 0,
                ),
            ),
        );

        $mapped = SwooleRequestMapper::map($engine);

        $this->assertSame('me.png', $mapped['files']['avatar']['name']);
        $this->assertSame('/tmp/swoole.upfile.AAA', $mapped['files']['avatar']['tmp_name']);
        $this->assertSame(array('a.txt', 'b.txt'), $mapped['files']['docs']['name']);
        $this->assertSame(array('/tmp/swoole.upfile.BBB', ''), $mapped['files']['docs']['tmp_name']);
        $this->assertSame(array(UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE), $mapped['files']['docs']['error']);
    }
}
