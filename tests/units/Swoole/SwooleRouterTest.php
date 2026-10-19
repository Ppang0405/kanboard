<?php

namespace KanboardTests\units\Swoole;

use Kanboard\Swoole\ContainerFactory;
use Kanboard\Swoole\SwooleRouter;
use Kanboard\Swoole\SwooleStaticResponder;
use PHPUnit\Framework\TestCase;

class TransactionObservingResponseDouble extends SwooleResponseDouble
{
    private $db;
    public $transactionOpenAtEnd;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function end($body)
    {
        $this->transactionOpenAtEnd = $this->db->getConnection()->inTransaction();
        parent::end($body);
    }
}

class ThrowingErrorConnectionDouble
{
    public function query($statement)
    {
        throw new \Error('boom');
    }
}

class ThrowingErrorDbDouble
{
    public function getConnection()
    {
        return new ThrowingErrorConnectionDouble();
    }
}

class SwooleRouterTest extends TestCase
{
    public function testClassifiesPublicRoutes()
    {
        $this->assertSame('health', SwooleRouter::classify('GET', '/health')['route']);
        $this->assertSame('api', SwooleRouter::classify('POST', '/jsonrpc.php')['route']);
        $this->assertSame('ui', SwooleRouter::classify('GET', '/')['route']);
        $this->assertSame('ui', SwooleRouter::classify('GET', '/?controller=AuthController&action=login')['route']);
        $this->assertSame('ui', SwooleRouter::classify('GET', '/dashboard/1')['route']);
    }

    public function testClassifiesAllowlistedStatics()
    {
        $classified = SwooleRouter::classify('GET', '/assets/js/app.min.js');
        $this->assertSame('static', $classified['route']);
        $this->assertSame('/assets/js/app.min.js', $classified['path']);

        $this->assertSame('static', SwooleRouter::classify('GET', '/assets/css/auto.min.css')['route']);
        $this->assertSame('static', SwooleRouter::classify('GET', '/assets/js/app.min.js?v=1')['route']);
        $this->assertSame('static', SwooleRouter::classify('GET', '/favicon.ico')['route']);
        $this->assertSame('static', SwooleRouter::classify('GET', '/robots.txt')['route']);
    }

    public function testDeniesPrivateSourcesAndTrickPaths()
    {
        $denied = array(
            '/app/common.php',
            '/libs/jsonrpc/src/JsonRPC/Server.php',
            '/vendor/autoload.php',
            '/data/db.sqlite',
            '/config.php',
            '/plugins/Slackbot/Plugin.php',
            '/index.php',
            '/jsonrpc.php.bak',
            '/admin.php',
            '/assets/../app/common.php',
            '/assets/%2e%2e/app/common.php',
            '/..%2fapp/common.php',
            '/assets/js/evil.php',
        );

        foreach ($denied as $uri) {
            $this->assertSame('deny', SwooleRouter::classify('GET', $uri)['route'], 'URI must be denied: '.$uri);
        }
    }

    public function testDeniesBackslashesNullBytesAndDotfiles()
    {
        $this->assertSame('deny', SwooleRouter::classify('GET', '/assets\\js\\app.min.js')['route']);
        $this->assertSame('deny', SwooleRouter::classify('GET', "/assets/js/app.min.js\0.png")['route']);
        $this->assertSame('deny', SwooleRouter::classify('GET', '/.gitignore')['route']);
    }

    public function testEmptyUriDispatchesToUi()
    {
        $this->assertSame('ui', SwooleRouter::classify('GET', '')['route']);
    }

    public function testDeniedPrefixesMatchWholeSegmentsCaseInsensitively()
    {
        foreach (array('/data', '/data/db.sqlite', '/DATA/export.csv', '/cli', '/CLI/run', '/app/x.php', '/App/common.php') as $uri) {
            $this->assertSame('deny', SwooleRouter::classify('GET', $uri)['route'], 'URI must be denied: '.$uri);
        }

        foreach (array('/dataset', '/dataset/1', '/client', '/clients/new', '/application', '/configurator') as $uri) {
            $this->assertNotSame('deny', SwooleRouter::classify('GET', $uri)['route'], 'URI must not be denied: '.$uri);
        }
    }

    public function testCaseMismatchedAssetPathFallsThroughToUiWithoutDisclosure()
    {
        // Strict lowercase allowlist: unknown casing never maps to a file,
        // the UI flow answers instead of disclosing anything on disk.
        $this->assertSame('ui', SwooleRouter::classify('GET', '/ASSETS/js/app.min.js')['route']);
    }

    public function testStaticResponderStreamsAllowlistedFile()
    {
        $engine = new SwooleResponseDouble();
        SwooleStaticResponder::respond($engine, '/assets/js/app.min.js');

        $this->assertSame(200, $engine->statusCode);
        $this->assertStringContainsString('javascript', $engine->headers['Content-Type']);
        $this->assertStringEndsWith('assets/js/app.min.js', $engine->sentFile);
        $this->assertNull($engine->endedBody);
    }

    public function testStaticResponderServesRobotsTxt()
    {
        $engine = new SwooleResponseDouble();
        SwooleStaticResponder::respond($engine, '/robots.txt');

        $this->assertSame(200, $engine->statusCode);
        $this->assertStringContainsString('text/plain', $engine->headers['Content-Type']);
        $this->assertStringEndsWith('robots.txt', $engine->sentFile);
    }

    public function testStaticResponderDeniesMissingFile()
    {
        $engine = new SwooleResponseDouble();
        SwooleStaticResponder::respond($engine, '/assets/js/does-not-exist.min.js');

        $this->assertSame(404, $engine->statusCode);
        $this->assertNull($engine->sentFile);
    }

    public function testStaticHeadRespondsHeadersOnlyWithoutBody()
    {
        $engine = new SwooleResponseDouble();
        SwooleStaticResponder::respond($engine, '/assets/js/app.min.js', true);

        $this->assertSame(200, $engine->statusCode);
        $this->assertStringContainsString('javascript', $engine->headers['Content-Type']);
        $this->assertSame('public, max-age=86400', $engine->headers['Cache-Control']);
        $this->assertSame((string) filesize(ROOT_DIR.'/assets/js/app.min.js'), $engine->headers['Content-Length']);
        $this->assertSame('', $engine->endedBody);
        $this->assertNull($engine->sentFile);
    }

    public function testStaticGetStillStreamsFile()
    {
        $engine = new SwooleResponseDouble();
        SwooleStaticResponder::respond($engine, '/assets/js/app.min.js', false);

        $this->assertSame(200, $engine->statusCode);
        $this->assertStringEndsWith('assets/js/app.min.js', $engine->sentFile);
        $this->assertNull($engine->endedBody);
    }

    public function testRouterPassesHeadMethodThroughToStaticResponder()
    {
        $engineRequest = new SwooleEngineDouble();
        $engineRequest->server = array('request_method' => 'HEAD', 'request_uri' => '/assets/js/app.min.js');
        $engineResponse = new SwooleResponseDouble();
        SwooleRouter::handle($engineRequest, $engineResponse, ContainerFactory::create());

        $this->assertSame(200, $engineResponse->statusCode);
        $this->assertArrayHasKey('Content-Length', $engineResponse->headers);
        $this->assertSame('', $engineResponse->endedBody);
        $this->assertNull($engineResponse->sentFile);
    }

    public function testApiRollsBackOpenTransactionBeforeEndingResponse()
    {
        $_SESSION = array();
        $container = ContainerFactory::create();
        $container['db']->startTransaction();

        $engineRequest = new SwooleEngineDouble();
        $engineRequest->server = array('request_method' => 'POST', 'request_uri' => '/jsonrpc.php');
        $engineRequest->setRawBody('');

        $engineResponse = new TransactionObservingResponseDouble($container['db']);
        SwooleRouter::handle($engineRequest, $engineResponse, $container);

        $this->assertSame(200, $engineResponse->statusCode);
        $this->assertNotNull($engineResponse->endedBody);
        $this->assertFalse($engineResponse->transactionOpenAtEnd);
        $this->assertFalse($container['db']->getConnection()->inTransaction());
    }

    public function testHealthThrowableReturns500InternalError()
    {
        $container = ContainerFactory::create();
        $container['db'] = new ThrowingErrorDbDouble();

        $engineRequest = new SwooleEngineDouble();
        $engineRequest->server = array('request_method' => 'GET', 'request_uri' => '/health');
        $engineResponse = new SwooleResponseDouble();
        SwooleRouter::handle($engineRequest, $engineResponse, $container);

        $this->assertSame(500, $engineResponse->statusCode);
        $this->assertSame('application/json', $engineResponse->headers['Content-Type']);
        $payload = json_decode($engineResponse->endedBody, true);
        $this->assertSame(500, $payload['status']);
        $this->assertSame('Internal Error', $payload['message']);
    }

    public function testStaticHeadDeniesMissingFileWithoutBody()
    {
        $get = new SwooleResponseDouble();
        SwooleStaticResponder::respond($get, '/assets/js/does-not-exist.min.js', false);

        $head = new SwooleResponseDouble();
        SwooleStaticResponder::respond($head, '/assets/js/does-not-exist.min.js', true);

        $this->assertSame(404, $get->statusCode);
        $this->assertNotSame('', $get->endedBody);
        $this->assertSame(404, $head->statusCode);
        $this->assertSame('', $head->endedBody);
        $this->assertSame('application/json', $head->headers['Content-Type']);
        $this->assertSame($get->headers['Content-Length'], $head->headers['Content-Length']);
        $this->assertSame((string) strlen($get->endedBody), $head->headers['Content-Length']);
        $this->assertNull($head->sentFile);
    }

    public function testStaticHeadDeniesOversizeFileWithoutBody()
    {
        $probe = ROOT_DIR.'/assets/swoole-oversize-probe.txt';
        $handle = fopen($probe, 'w');
        $this->assertNotFalse($handle);

        try {
            $this->assertNotFalse(fseek($handle, SwooleStaticResponder::MAX_STATIC_BYTES));
            $this->assertNotFalse(fwrite($handle, 'x'));
            fclose($handle);
            $handle = false;

            $get = new SwooleResponseDouble();
            SwooleStaticResponder::respond($get, '/assets/swoole-oversize-probe.txt', false);

            $head = new SwooleResponseDouble();
            SwooleStaticResponder::respond($head, '/assets/swoole-oversize-probe.txt', true);

            $this->assertSame(404, $get->statusCode);
            $this->assertSame(404, $head->statusCode);
            $this->assertSame('', $head->endedBody);
            $this->assertSame('application/json', $head->headers['Content-Type']);
            $this->assertSame($get->headers['Content-Length'], $head->headers['Content-Length']);
            $this->assertSame((string) strlen($get->endedBody), $head->headers['Content-Length']);
            $this->assertNull($head->sentFile);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($probe)) {
                unlink($probe);
            }
        }
    }

    public function testStaticHeadDeniesDisallowedExtensionWithoutBody()
    {
        $get = new SwooleResponseDouble();
        SwooleStaticResponder::respond($get, '/app/common.php', false);

        $head = new SwooleResponseDouble();
        SwooleStaticResponder::respond($head, '/app/common.php', true);

        $this->assertSame(404, $get->statusCode);
        $this->assertSame(404, $head->statusCode);
        $this->assertSame('', $head->endedBody);
        $this->assertSame('application/json', $head->headers['Content-Type']);
        $this->assertSame($get->headers['Content-Length'], $head->headers['Content-Length']);
        $this->assertSame((string) strlen($get->endedBody), $head->headers['Content-Length']);
        $this->assertNull($head->sentFile);
    }

    public function testRouterHeadDeniedPathReturns404WithoutBody()
    {
        $getRequest = new SwooleEngineDouble();
        $getRequest->server = array('request_method' => 'GET', 'request_uri' => '/app/common.php');
        $getResponse = new SwooleResponseDouble();
        SwooleRouter::handle($getRequest, $getResponse, ContainerFactory::create());

        $headRequest = new SwooleEngineDouble();
        $headRequest->server = array('request_method' => 'HEAD', 'request_uri' => '/app/common.php');
        $headResponse = new SwooleResponseDouble();
        SwooleRouter::handle($headRequest, $headResponse, ContainerFactory::create());

        $this->assertSame(404, $getResponse->statusCode);
        $this->assertSame(404, $headResponse->statusCode);
        $this->assertSame('', $headResponse->endedBody);
        $this->assertSame('application/json', $headResponse->headers['Content-Type']);
        $this->assertSame($getResponse->headers['Content-Length'], $headResponse->headers['Content-Length']);
        $this->assertSame((string) strlen($getResponse->endedBody), $headResponse->headers['Content-Length']);
    }
}
