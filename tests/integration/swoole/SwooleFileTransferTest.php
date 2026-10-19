<?php

namespace KanboardTests\integration\swoole;

use PHPUnit\Framework\TestCase;

/**
 * Real-HTTP file-transfer checks: genuine multipart upload through the
 * trusted transfer path, then byte-exact authorized download. Fixtures
 * are uniquely named per file so reruns stay independent.
 */
class SwooleFileTransferTest extends TestCase
{
    private $http;

    protected function setUp(): void
    {
        $this->http = new SwooleHttpClient(getenv('SWOOLE_BASE_URL') ?: 'http://127.0.0.1:18080');

        if (! $this->http->isContainerReachable()) {
            $this->markTestSkipped('Swoole container not reachable');
        }
    }

    public function testAuthorizedMultipartUploadStreamsBackOverSession()
    {
        $jar = 'upload';
        $this->http->loginPost($jar, 'admin', 'admin', false);

        $projectId = $this->http->apiCall('createProject', array('name' => 'Swoole Xfer Project'))['result'];
        $taskId = $this->http->apiCall('createTask', array('title' => 'Xfer Task', 'project_id' => $projectId))['result'];

        $form = $this->http->jarRequest($jar, 'GET', '/?controller=TaskFileController&action=create&task_id='.$taskId);
        $this->assertSame(200, $form['status']);
        $csrf = $this->http->extractCsrfToken($form['body']);
        $this->assertNotEmpty($csrf);

        $fixture = tempnam(sys_get_temp_dir(), 'swoole-upload-');
        file_put_contents($fixture, 'multipart-bytes-456');

        try {
            $upload = $this->http->jarRequest(
                $jar,
                'POST',
                '/?controller=TaskFileController&action=save&task_id='.$taskId,
                array(),
                array(
                    'csrf_token' => $csrf,
                    'files[0]' => new \CURLFile($fixture, 'text/plain', 'upload.txt'),
                )
            );
        } finally {
            unlink($fixture);
        }

        $this->assertSame(302, $upload['status']);

        $files = $this->http->apiCall('getAllTaskFiles', array($taskId))['result'];
        $this->assertNotEmpty($files);

        $fileId = $files[0]['id'];
        $download = $this->http->jarRequest($jar, 'GET', '/?controller=FileViewerController&action=download&task_id='.$taskId.'&file_id='.$fileId);
        $this->assertSame(200, $download['status']);
        $this->assertSame('multipart-bytes-456', $download['body']);
    }

    public function testBoardTaskMutationAndAuthorizedDownloadOverSession()
    {
        $jar = 'mutation';
        $this->http->loginPost($jar, 'admin', 'admin', false);

        $projectForm = $this->http->jarRequest($jar, 'GET', '/?controller=ProjectCreationController&action=create');
        $this->assertSame(200, $projectForm['status']);
        $projectSave = $this->http->jarRequest(
            $jar,
            'POST',
            '/?controller=ProjectCreationController&action=save',
            array('Content-Type: application/x-www-form-urlencoded'),
            'name='.urlencode('Swoole Board Project').'&identifier=&task_limit=&csrf_token='.urlencode($this->http->extractCsrfToken($projectForm['body']))
        );
        $this->assertSame(302, $projectSave['status']);

        $projectId = $this->http->apiCall('getProjectByName', array('Swoole Board Project'))['result']['id'];
        $taskId = $this->http->apiCall('createTask', array('title' => 'Swoole Board Task', 'project_id' => $projectId))['result'];
        $fileId = $this->http->apiCall('createTaskFile', array($projectId, $taskId, 'note.txt', base64_encode('download-bytes-123')))['result'];

        $board = $this->http->jarRequest($jar, 'GET', '/?controller=BoardViewController&action=show&project_id='.$projectId);
        $this->assertSame(200, $board['status']);
        $this->assertStringContainsString('Swoole Board Task', $board['body']);

        $download = $this->http->jarRequest($jar, 'GET', '/?controller=FileViewerController&action=download&task_id='.$taskId.'&file_id='.$fileId);
        $this->assertSame(200, $download['status']);
        $this->assertSame('download-bytes-123', $download['body']);
    }
}
