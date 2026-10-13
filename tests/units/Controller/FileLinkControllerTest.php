<?php

namespace KanboardTests\units\Controller;

use Kanboard\Controller\FileLinkController;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Http\Response;
use Kanboard\Core\ObjectStorage\FileStorage;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ProjectFileModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\TaskCreationModel;
use Kanboard\Model\TaskFileModel;
use Kanboard\Model\UserModel;
use KanboardTests\units\Base;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class FileLinkControllerTest extends Base
{
    public function testCreateRedirectsToAnOpenableTaskFileLink()
    {
        $this->createFixtures();
        $params = $this->createLink(array('task_id' => 1, 'file_id' => 1));

        $this->assertSame('FileLinkController', $params['controller']);
        $this->assertSame('open', $params['action']);
        $this->assertSame('1', $params['task_id']);
        $this->assertArrayNotHasKey('project_id', $params);
        $this->assertGreaterThan(time() + FileLinkController::LIFETIME - 10, (int) $params['expires']);

        $this->expectOutput('tasks/1/atlas.html');
        $this->openLink($params);

        $headers = $this->getHeaders();
        $this->assertSame('text/html', $headers['Content-Type']);
        $this->assertStringContainsString('allow-scripts', $headers['Content-Security-Policy']);
        $this->assertStringNotContainsString('allow-same-origin', $headers['Content-Security-Policy']);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
    }

    public function testCreateRedirectsToAnOpenableProjectImageLink()
    {
        $this->createFixtures();
        $params = $this->createLink(array('project_id' => 2, 'file_id' => 1));

        $this->assertSame('2', $params['project_id']);

        $this->expectOutput('projects/2/photo.png');
        $this->openLink($params);

        $headers = $this->getHeaders();
        $this->assertSame('image/png', $headers['Content-Type']);
    }

    public function testCreateRefusesOtherFileTypes()
    {
        $this->createFixtures();
        $this->buildRequest(array('task_id' => 1, 'file_id' => 2));

        $this->expectException('Kanboard\Core\Controller\AccessForbiddenException');
        (new FileLinkController($this->container))->create();
    }

    public function testOpenRefusesTamperedLinks()
    {
        $this->createFixtures();
        $params = $this->createLink(array('task_id' => 1, 'file_id' => 1));

        $tampered = array(
            array('sig' => str_repeat('0', 64)),
            array('sig' => ''),
            array('expires' => (string) ((int) $params['expires'] - 1)),
            array('file_id' => '2'),
        );

        foreach ($tampered as $change) {
            $this->expectNoOutput();

            try {
                $this->openLink(array_merge($params, $change));
                $this->fail('Tampered link was accepted: '.json_encode($change));
            } catch (\Kanboard\Core\Controller\AccessForbiddenException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testOpenRefusesLinksSignedForAnotherTask()
    {
        $this->createFixtures();
        $params = $this->createLink(array('task_id' => 1, 'file_id' => 1));
        $params['task_id'] = '2';

        $this->expectNoOutput();
        $this->expectException('Kanboard\Core\Controller\PageNotFoundException');
        $this->openLink($params);
    }

    public function testOpenRefusesExpiredLinks()
    {
        $this->createFixtures();
        $this->expectNoOutput();

        $controller = new SignedFileLinkController($this->container);
        $params = $controller->sign(array('task_id' => 1, 'file_id' => 1), time() - 1);

        $this->expectException('Kanboard\Core\Controller\AccessForbiddenException');
        $this->openLink($params);
    }

    public function testOpenRefusesLinksLivingLongerThanTheLifetime()
    {
        $this->createFixtures();
        $this->expectNoOutput();

        $controller = new SignedFileLinkController($this->container);
        $params = $controller->sign(array('task_id' => 1, 'file_id' => 1), time() + FileLinkController::LIFETIME + 60);

        $this->expectException('Kanboard\Core\Controller\AccessForbiddenException');
        $this->openLink($params);
    }

    public function testRegeneratingTheKeyRevokesLinks()
    {
        $this->createFixtures();
        $params = $this->createLink(array('task_id' => 1, 'file_id' => 1));

        $this->container['configModel']->regenerateToken(FileLinkController::KEY_OPTION);
        $this->container['memoryCache']->flush();

        $this->expectNoOutput();
        $this->expectException('Kanboard\Core\Controller\AccessForbiddenException');
        $this->openLink($params);
    }

    private function getHeaders()
    {
        return \Closure::bind(function () {
            return $this->httpHeaders;
        }, $this->container['response'], Response::class)();
    }

    private function createLink(array $params)
    {
        $this->buildRequest($params);
        $location = null;

        $this->container['response']
            ->expects($this->once())
            ->method('redirect')
            ->willReturnCallback(function ($url) use (&$location) {
                $location = $url;
            });

        (new FileLinkController($this->container))->create();

        $this->assertStringContainsString('controller=FileLinkController', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function openLink(array $params)
    {
        unset($params['controller'], $params['action']);
        $_SESSION = array();

        $this->buildRequest($params, $this->container['objectStorage']);
        (new FileLinkController($this->container))->open();
    }

    private function expectOutput($path)
    {
        $this->container['objectStorage'] = $this->mockStorage();
        $this->container['objectStorage']->expects($this->once())->method('output')->with($path);
    }

    private function expectNoOutput()
    {
        $this->container['objectStorage'] = $this->mockStorage();
        $this->container['objectStorage']->expects($this->never())->method('output');
    }

    private function mockStorage()
    {
        return $this
            ->getMockBuilder(FileStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('output'))
            ->getMock();
    }

    private function buildRequest(array $params, $storage = null)
    {
        $this->container['request'] = new Request($this->container, array('REQUEST_METHOD' => 'GET'), $params);

        $this->container['response'] = $this
            ->getMockBuilder(Response::class)
            ->setConstructorArgs(array($this->container))
            ->onlyMethods(array('send', 'redirect'))
            ->getMock();

        $this->container['objectStorage'] = $storage ?: $this->mockStorage();
    }

    private function createFixtures()
    {
        $projectModel = new ProjectModel($this->container);
        $taskCreationModel = new TaskCreationModel($this->container);
        $taskFileModel = new TaskFileModel($this->container);
        $projectFileModel = new ProjectFileModel($this->container);
        $userModel = new UserModel($this->container);

        $this->assertEquals(2, $userModel->create(array('username' => 'bob')));

        $this->assertEquals(1, $projectModel->create(array('name' => 'Project A')));
        $this->assertEquals(2, $projectModel->create(array('name' => 'Project B')));

        $this->assertEquals(1, $taskCreationModel->create(array('project_id' => 2, 'title' => 'Task B')));
        $this->assertEquals(2, $taskCreationModel->create(array('project_id' => 2, 'title' => 'Task C')));
        $this->assertEquals(1, $taskFileModel->create(1, 'atlas.html', 'tasks/1/atlas.html', 42));
        $this->assertEquals(2, $taskFileModel->create(1, 'notes.txt', 'tasks/1/notes.txt', 42));
        $this->assertEquals(1, $projectFileModel->create(2, 'photo.png', 'projects/2/photo.png', 42));

        $_SESSION['user'] = array(
            'id' => 2,
            'role' => Role::APP_USER,
            'username' => 'bob',
        );
    }
}

class SignedFileLinkController extends FileLinkController
{
    public function sign(array $params, $expires)
    {
        $this->container['request'] = new Request($this->container, array('REQUEST_METHOD' => 'GET'), $params);
        return $this->getSignedParams($this->getFile(), $expires);
    }
}
