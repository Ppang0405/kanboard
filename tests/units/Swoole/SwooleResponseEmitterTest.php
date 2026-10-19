<?php

namespace KanboardTests\units\Swoole;

use Kanboard\Swoole\ContainerFactory;
use Kanboard\Swoole\SwooleResponseEmitter;
use PHPUnit\Framework\TestCase;

class SwooleResponseEmitterTest extends TestCase
{
    public function testEmitsCapturedStatusHeadersAndBody()
    {
        $container = ContainerFactory::create();
        $response = $container['response'];
        $response->capture();
        $response->withStatusCode(302);
        $response->withHeader('Location', '/login');
        $response->withBody('bye');
        $response->send();

        $engine = new SwooleResponseDouble();
        SwooleResponseEmitter::emit($engine, $response);

        $this->assertSame(302, $engine->statusCode);
        $this->assertSame('/login', $engine->headers['Location']);
        $this->assertSame('bye', $engine->endedBody);
        $this->assertNull($engine->sentFile);
    }

    public function testLocationHeaderDefaultsUnsetStatusToFound()
    {
        $container = ContainerFactory::create();
        $response = $container['response'];
        $response->capture();
        $response->redirect('/?controller=AuthController&action=login');

        $engine = new SwooleResponseDouble();
        SwooleResponseEmitter::emit($engine, $response);

        $this->assertSame(302, $engine->statusCode);
        $this->assertSame('/?controller=AuthController&action=login', $engine->headers['Location']);
    }

    public function testLowercaseLocationHeaderDefaultsUnsetStatusToFound()
    {
        $container = ContainerFactory::create();
        $response = $container['response'];
        $response->capture();
        $response->withHeader('location', '/dashboard');
        $response->send();

        $engine = new SwooleResponseDouble();
        SwooleResponseEmitter::emit($engine, $response);

        $this->assertSame(302, $engine->statusCode);
    }

    public function testExplicitStatusIsKeptWhenLocationPresent()
    {
        $container = ContainerFactory::create();
        $response = $container['response'];
        $response->capture();
        $response->withStatusCode(201);
        $response->withHeader('Location', '/tasks/1');
        $response->send();

        $engine = new SwooleResponseDouble();
        SwooleResponseEmitter::emit($engine, $response);

        $this->assertSame(201, $engine->statusCode);
    }

    public function testEmitsSessionAndRememberMeCookies()
    {
        $container = ContainerFactory::create();
        $response = $container['response'];
        $response->capture();
        $response->send();

        $engine = new SwooleResponseDouble();
        SwooleResponseEmitter::emit($engine, $response, null, array(
            array('name' => 'KB_SID', 'value' => 'id123', 'expires' => 0, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'),
            array('name' => 'KB_RM', 'value' => '', 'expires' => 1, 'path' => '/', 'domain' => '', 'secure' => false, 'httponly' => true, 'samesite' => ''),
        ));

        $this->assertSame(200, $engine->statusCode);
        $this->assertSame('id123', $engine->cookies[0]['value']);
        $this->assertSame('Lax', $engine->cookies[0]['samesite']);
        $this->assertTrue($engine->cookies[0]['httponly']);
        $this->assertSame('', $engine->cookies[1]['value']);
    }

    public function testStreamsValidatedFilePathInsteadOfBuffering()
    {
        $container = ContainerFactory::create();
        $response = $container['response'];
        $response->capture();
        $response->withHeader('Content-Type', 'application/octet-stream');
        $response->send();

        $engine = new SwooleResponseDouble();
        SwooleResponseEmitter::emit($engine, $response, '/data/files/9/report.txt');

        $this->assertSame(200, $engine->statusCode);
        $this->assertSame('application/octet-stream', $engine->headers['Content-Type']);
        $this->assertSame('/data/files/9/report.txt', $engine->sentFile);
        $this->assertNull($engine->endedBody);
    }
}
