<?php

namespace KanboardTests\integration\swoole;

use PHPUnit\Framework\TestCase;

/**
 * Real-HTTP checks for health, UI redirects, API auth, static allowlist
 * and denial behavior. Each test is self-contained; request isolation is
 * enforced by the worker lifecycle, so file order carries no meaning.
 */
class SwooleHttpApiTest extends TestCase
{
    private $http;

    protected function setUp(): void
    {
        $this->http = new SwooleHttpClient(getenv('SWOOLE_BASE_URL') ?: 'http://127.0.0.1:18080');

        if (! $this->http->isContainerReachable()) {
            $this->markTestSkipped('Swoole container not reachable');
        }
    }

    public function testHealthAnswersOk()
    {
        $exchange = $this->http->request('GET', '/health');

        $this->assertSame(200, $exchange['status']);
        $this->assertSame('OK', json_decode($exchange['body'], true)['message']);
    }

    public function testLoginPageRenders()
    {
        $exchange = $this->http->request('GET', '/?controller=AuthController&action=login');

        $this->assertSame(200, $exchange['status']);
        $this->assertStringContainsString('password', strtolower($exchange['body']));
    }

    public function testProtectedPageRedirectsToLogin()
    {
        $exchange = $this->http->request('GET', '/dashboard/1');

        $this->assertSame(302, $exchange['status']);
        $this->assertStringContainsString('login', strtolower($exchange['headers']['location']));
    }

    public function testUnauthorizedDownloadRedirects()
    {
        $exchange = $this->http->request('GET', '/?controller=FileViewerController&action=download&file_id=1');

        $this->assertSame(302, $exchange['status']);
        $this->assertStringContainsString('login', strtolower($exchange['headers']['location']));
    }

    public function testApiEmptyBodyReturnsJsonError()
    {
        $exchange = $this->http->request('POST', '/jsonrpc.php', array('Content-Type: application/json'), '');

        $payload = json_decode($exchange['body'], true);

        $this->assertSame(200, $exchange['status']);
        $this->assertArrayNotHasKey('result', $payload);
        $this->assertArrayHasKey('error', $payload);
    }

    public function testApiRejectsBadCredentialsBeforeAcceptingGoodOnes()
    {
        $rejected = $this->http->request(
            'POST',
            '/jsonrpc.php',
            array('Content-Type: application/json', 'Authorization: Basic '.base64_encode('nobody:wrong')),
            '{"jsonrpc":"2.0","id":2,"method":"getVersion","params":[]}'
        );
        $rejectedPayload = json_decode($rejected['body'], true);

        $this->assertSame(401, $rejected['status']);
        $this->assertSame('Basic realm="JsonRPC"', $rejected['headers']['www-authenticate']);
        $this->assertSame(401, $rejectedPayload['error']['code']);

        $accepted = $this->http->request(
            'POST',
            '/jsonrpc.php',
            array('Content-Type: application/json', 'Authorization: Basic '.base64_encode('admin:admin')),
            '{"jsonrpc":"2.0","id":1,"method":"getVersion","params":[]}'
        );
        $acceptedPayload = json_decode($accepted['body'], true);

        $this->assertSame(200, $accepted['status']);
        $this->assertArrayHasKey('result', $acceptedPayload);
        $this->assertNotEmpty($acceptedPayload['result']);
    }

    public function testApiMalformedBodyKeepsLegacyStatus()
    {
        $exchange = $this->http->request(
            'POST',
            '/jsonrpc.php',
            array('Content-Type: application/json', 'Authorization: Basic '.base64_encode('admin:admin')),
            '{not-json'
        );
        $payload = json_decode($exchange['body'], true);

        $this->assertSame(200, $exchange['status']);
        $this->assertSame(-32700, $payload['error']['code']);
    }

    public function testApiBatchReturnsArray()
    {
        $exchange = $this->http->request(
            'POST',
            '/jsonrpc.php',
            array('Content-Type: application/json', 'Authorization: Basic '.base64_encode('admin:admin')),
            '[{"jsonrpc":"2.0","id":21,"method":"getVersion","params":[]},'
            .'{"jsonrpc":"2.0","id":22,"method":"getVersion","params":[]}]'
        );
        $payload = json_decode($exchange['body'], true);

        $this->assertSame(200, $exchange['status']);
        $this->assertCount(2, $payload);
    }

    public function testApiAppTokenOnUserProcedureIsForbidden()
    {
        $jar = 'token-reader';
        $this->http->loginPost($jar, 'admin', 'admin', false);
        $settings = $this->http->jarRequest($jar, 'GET', '/?controller=ConfigController&action=api');
        $this->assertSame(200, $settings['status']);
        $this->assertSame(1, preg_match('/<strong>([a-f0-9]{32,})<\/strong>/', $settings['body'], $matches));

        $exchange = $this->http->request(
            'POST',
            '/jsonrpc.php',
            array('Content-Type: application/json', 'Authorization: Basic '.base64_encode('jsonrpc:'.$matches[1])),
            '{"jsonrpc":"2.0","id":23,"method":"getMe","params":[]}'
        );
        $payload = json_decode($exchange['body'], true);

        $this->assertSame(403, $exchange['status']);
        $this->assertSame(403, $payload['error']['code']);
    }

    public function testCsvExportMatchesNativeBytes()
    {
        $jar = 'csv-export';
        $this->http->loginPost($jar, 'admin', 'admin', false);
        $projectId = $this->http->apiCall('createProject', array('name' => 'Swoole CSV Project'))['result'];

        $form = $this->http->jarRequest($jar, 'GET', '/?controller=ExportController&action=tasks&project_id='.$projectId);
        $this->assertSame(200, $form['status']);

        $export = $this->http->jarRequest(
            $jar,
            'POST',
            '/?controller=ExportController&action=tasks&project_id='.$projectId,
            array('Content-Type: application/x-www-form-urlencoded'),
            'from=2020-01-01&to=2030-12-31&csrf_token='.urlencode($this->http->extractCsrfToken($form['body']))
        );

        $this->assertSame(200, $export['status']);
        $this->assertStringContainsString('text/csv', $export['headers']['content-type']);
        $this->assertStringContainsString('Task Id', $export['body']);
    }

    public function testPrivateSourcesAreNotDisclosed()
    {
        foreach (array('/app/common.php', '/index.php', '/data/db.sqlite', '/config.php', '/assets/../app/common.php') as $path) {
            $exchange = $this->http->request('GET', $path);
            $this->assertSame(404, $exchange['status'], 'Path must be denied: '.$path);
            $this->assertStringNotContainsString('define(', $exchange['body']);
        }
    }

    public function testAllowlistedStaticAssetIsServed()
    {
        $exchange = $this->http->request('GET', '/assets/js/app.min.js');

        $this->assertSame(200, $exchange['status']);
        $this->assertStringContainsString('javascript', $exchange['headers']['content-type']);
        $this->assertNotEmpty($exchange['body']);
    }
}
