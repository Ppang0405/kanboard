<?php

namespace KanboardTests\units\Swoole;

use Kanboard\Core\Http\Request;
use Kanboard\Core\ObjectStorage\FileStorage;
use Kanboard\Core\ObjectStorage\ObjectStorageException;
use Kanboard\Swoole\ContainerFactory;
use PHPUnit\Framework\TestCase;

class SwooleHttpTransportTest extends TestCase
{
    private $previousSession = array();

    protected function setUp(): void
    {
        $this->previousSession = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : array();
        $_SESSION = array();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previousSession;
    }

    public function testCapturedResponseEmitsNothingAndExposesStatusHeadersBody()
    {
        $container = ContainerFactory::create();
        $response = $container['response'];
        $response->capture();
        $response->withStatusCode(302);
        $response->withHeader('Location', '/login');
        $response->withBody('redirecting');

        $this->expectOutputString('');
        $response->send();

        $this->assertTrue($response->isResponseAlreadySent());
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
        $this->assertSame('redirecting', $response->getBody());
    }

    public function testIsolatedRequestIgnoresSuperglobals()
    {
        $container = ContainerFactory::create();
        $request = new Request(
            $container,
            array('REQUEST_METHOD' => 'GET'),
            array(),
            array(),
            array(),
            array(),
            true
        );

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('', $request->getCookie('KB_SID'));
        $this->assertSame('', $request->getServerVariable('argv'));
    }

    public function testRequestBodyOverrideAvoidsPhpInput()
    {
        $container = ContainerFactory::create();
        $request = new Request($container, array(), array(), array(), array(), array(), true);
        $request->setBody('{"greeting":"hello"}');

        $this->assertSame('{"greeting":"hello"}', $request->getBody());
        $this->assertSame(array('greeting' => 'hello'), $request->getJson(false));
    }

    public function testCapturedCsvResponseExposesBodyBytes()
    {
        $container = ContainerFactory::create();
        $response = $container['response'];
        $response->capture();

        $this->expectOutputString('');
        $response->csv(array(array('Task', 'Project'), array('Write docs', 'Acme')));

        $this->assertTrue($response->isResponseAlreadySent());
        $this->assertSame("Task,Project\n\"Write docs\",Acme\n", $response->getBody());
    }

    public function testFileStorageExposesValidatedPath()
    {
        $baseDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'swoole_path_'.uniqid();
        mkdir($baseDir);

        try {
            $storage = new FileStorage($baseDir);

            $blob = 'download-bytes';
            $storage->put('report.txt', $blob);

            $this->assertSame(realpath($baseDir).DIRECTORY_SEPARATOR.'report.txt', $storage->getPath('report.txt'));

            try {
                $storage->getPath('../outside.txt');
                $this->fail('Traversal key must be rejected');
            } catch (ObjectStorageException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        } finally {
            if (is_file($baseDir.DIRECTORY_SEPARATOR.'report.txt')) {
                unlink($baseDir.DIRECTORY_SEPARATOR.'report.txt');
            }

            if (is_dir($baseDir)) {
                rmdir($baseDir);
            }
        }
    }

    public function testApiProviderHonorsInjectedEmptyBodyWithoutGlobalsFallback()
    {
        $_SESSION = array();
        $container = ContainerFactory::create();
        $container['apiRequestBody'] = '';
        $container['apiServerVars'] = array('REMOTE_ADDR' => '127.0.0.1');

        $payload = json_decode($container['api']->execute(), true);

        $this->assertArrayNotHasKey('result', $payload);
        $this->assertArrayHasKey('error', $payload);
    }

    public function testApiProviderRejectsInjectedBadCredentials()
    {
        $_SESSION = array();
        $container = ContainerFactory::create();
        $container['apiRequestBody'] = '{"jsonrpc":"2.0","id":7,"method":"getVersion","params":[]}';
        $container['apiServerVars'] = array(
            'REMOTE_ADDR' => '127.0.0.1',
            'PHP_AUTH_USER' => 'nobody',
            'PHP_AUTH_PW' => 'wrong',
        );

        $payload = json_decode($container['api']->execute(), true);

        $this->assertArrayNotHasKey('result', $payload);
        $this->assertSame(401, $payload['error']['code']);
        $this->assertSame('Unauthorized', $payload['error']['message']);
    }
}
