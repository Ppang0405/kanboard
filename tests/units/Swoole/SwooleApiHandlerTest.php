<?php

namespace KanboardTests\units\Swoole;

use Kanboard\Swoole\ContainerFactory;
use Kanboard\Swoole\SwooleApiHandler;
use PHPUnit\Framework\TestCase;

class SwooleApiHandlerTest extends TestCase
{
    public function testEmptyBodyReturnsJsonRpcErrorWithoutGlobals()
    {
        list($container, $status, $headers, $payload) = $this->handleJson('', array());

        $this->assertSame(200, $status);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertArrayNotHasKey('result', $payload);
        $this->assertArrayHasKey('error', $payload);
    }

    public function testMalformedBodyKeepsLegacyParseErrorStatus()
    {
        list(, $status, , $payload) = $this->handleJson('{not-json', array());

        $this->assertSame(200, $status);
        $this->assertSame(-32700, $payload['error']['code']);
    }

    public function testMissingCredentialsReturnHttpUnauthorized()
    {
        list(, $status, $headers, $payload) = $this->handleJson(
            '{"jsonrpc":"2.0","id":10,"method":"getVersion","params":[]}',
            array()
        );

        $this->assertSame(401, $status);
        $this->assertSame('Basic realm="JsonRPC"', $headers['WWW-Authenticate']);
        $this->assertSame(401, $payload['error']['code']);
    }

    public function testBadCredentialsReturnHttpUnauthorized()
    {
        list(, $status, $headers, $payload) = $this->handleJson(
            '{"jsonrpc":"2.0","id":3,"method":"getVersion","params":[]}',
            array('PHP_AUTH_USER' => 'nobody', 'PHP_AUTH_PW' => 'wrong')
        );

        $this->assertSame(401, $status);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('Basic realm="JsonRPC"', $headers['WWW-Authenticate']);
        $this->assertSame(401, $payload['error']['code']);
    }

    public function testAppTokenOnUserProcedureReturnsHttpForbidden()
    {
        $_SESSION = array();
        $container = ContainerFactory::create();
        list(, $status, $headers, $payload) = $this->handleJson(
            '{"jsonrpc":"2.0","id":4,"method":"getMe","params":[]}',
            array('PHP_AUTH_USER' => 'jsonrpc', 'PHP_AUTH_PW' => $this->apiToken($container)),
            $container
        );

        $this->assertSame(403, $status);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame(403, $payload['error']['code']);
    }

    public function testAppTokenSuccessStaysHttpOk()
    {
        $_SESSION = array();
        $container = ContainerFactory::create();
        list(, $status, , $payload) = $this->handleJson(
            '{"jsonrpc":"2.0","id":5,"method":"getVersion","params":[]}',
            array('PHP_AUTH_USER' => 'jsonrpc', 'PHP_AUTH_PW' => $this->apiToken($container)),
            $container
        );

        $this->assertSame(200, $status);
        $this->assertArrayHasKey('result', $payload);
    }

    public function testFreshContainersIsolateRequestsFromEachOther()
    {
        // Production uses one fresh container per request, so per-request
        // injection can never leak across requests even though the api
        // service itself is shared within a container.
        list(, $firstStatus) = $this->handleJson(
            '{"jsonrpc":"2.0","id":8,"method":"getVersion","params":[]}',
            array('PHP_AUTH_USER' => 'nobody', 'PHP_AUTH_PW' => 'wrong')
        );
        $this->assertSame(401, $firstStatus);

        $_SESSION = array();
        $secondContainer = ContainerFactory::create();
        list(, $secondStatus, , $secondPayload) = $this->handleJson(
            '{"jsonrpc":"2.0","id":9,"method":"getVersion","params":[]}',
            array('PHP_AUTH_USER' => 'jsonrpc', 'PHP_AUTH_PW' => $this->apiToken($secondContainer)),
            $secondContainer
        );

        $this->assertSame(200, $secondStatus);
        $this->assertArrayHasKey('result', $secondPayload);
    }

    public function testApiServiceIsSharedWithinOneContainer()
    {
        $_SESSION = array();
        $container = ContainerFactory::create();

        $this->assertSame($container['api'], $container['api']);
    }

    public function testValidBatchStaysHttpOk()
    {
        $_SESSION = array();
        $container = ContainerFactory::create();
        list(, $status, , $payload) = $this->handleJson(
            '[{"jsonrpc":"2.0","id":6,"method":"getVersion","params":[]},'
            .'{"jsonrpc":"2.0","id":7,"method":"getVersion","params":[]}]',
            array('PHP_AUTH_USER' => 'jsonrpc', 'PHP_AUTH_PW' => $this->apiToken($container)),
            $container
        );

        $this->assertSame(200, $status);
        $this->assertCount(2, $payload);
    }

    public function testBatchWithAuthFailureReturnsErrorWithoutResults()
    {
        list(, $status, , $payload) = $this->handleJson(
            '[{"jsonrpc":"2.0","id":11,"method":"getVersion","params":[]}]',
            array('PHP_AUTH_USER' => 'nobody', 'PHP_AUTH_PW' => 'wrong')
        );

        $this->assertSame(401, $status);
        $this->assertArrayNotHasKey('result', $payload);
        $this->assertArrayHasKey('error', $payload);
    }

    public function testSendHeadersSuppressionEmitsNothing()
    {
        $builder = new \Kanboard\Swoole\SwooleApiResponseBuilder();

        $this->expectOutputString('');
        $this->assertSame($builder, $builder->sendHeaders());
        $this->assertSame(array('Content-Type' => 'application/json'), $builder->getHeaders());
        $this->assertNull($builder->getStatus());
    }

    private function handleJson($body, array $auth, $container = null)
    {
        $_SESSION = array();

        if ($container === null) {
            $container = ContainerFactory::create();
        }

        $server = array('REMOTE_ADDR' => '127.0.0.1') + $auth;
        list($status, $headers, $rawBody) = SwooleApiHandler::handle($container, $body, $server);

        return array($container, $status, $headers, json_decode($rawBody, true));
    }

    private function apiToken($container)
    {
        $token = $container['configModel']->get('api_token');
        $this->assertNotEmpty($token);

        return $token;
    }
}
