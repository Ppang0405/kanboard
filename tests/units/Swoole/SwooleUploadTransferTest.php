<?php

namespace KanboardTests\units\Swoole;

use Kanboard\Core\ObjectStorage\FileStorage;
use Kanboard\Core\ObjectStorage\ObjectStorageException;
use Kanboard\Swoole\SwooleFileStorage;
use Kanboard\Swoole\SwooleUploadTransfer;
use PHPUnit\Framework\TestCase;

class SwooleUploadTransferTest extends TestCase
{
    private $tmpDir;
    private $baseDir;

    protected function setUp(): void
    {
        $this->tmpDir = realpath(sys_get_temp_dir());
        $this->baseDir = $this->tmpDir.DIRECTORY_SEPARATOR.'swoole_store_'.uniqid();
        mkdir($this->baseDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->baseDir.DIRECTORY_SEPARATOR.'*') ?: array() as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->baseDir)) {
            rmdir($this->baseDir);
        }
    }

    private function createTmpUpload($name, $content)
    {
        $path = $this->tmpDir.DIRECTORY_SEPARATOR.$name.'_'.uniqid();
        file_put_contents($path, $content);
        return $path;
    }

    public function testRegisteredFileMovesIntoStorage()
    {
        $source = $this->createTmpUpload('avatar', 'image-bytes');
        $transfer = new SwooleUploadTransfer($this->tmpDir);
        $transfer->register(array(
            'avatar' => array(
                'name' => 'me.png',
                'type' => 'image/png',
                'tmp_name' => $source,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($source),
            ),
        ));

        $storage = new FileStorage($this->baseDir);
        $this->assertTrue($transfer->isRegistered($source));
        $this->assertTrue($transfer->moveRegisteredFile($source, 'me.png', $storage));
        $this->assertSame('image-bytes', file_get_contents($this->baseDir.DIRECTORY_SEPARATOR.'me.png'));
        $this->assertFileDoesNotExist($source);
    }

    public function testUnregisteredFileIsRejected()
    {
        $source = $this->createTmpUpload('evil', 'x');
        $transfer = new SwooleUploadTransfer($this->tmpDir);
        $transfer->register(array());
        $storage = new FileStorage($this->baseDir);

        $this->assertFalse($transfer->isRegistered($source));

        try {
            $transfer->moveRegisteredFile($source, 'evil.txt', $storage);
            $this->fail('Unregistered source must be rejected');
        } catch (ObjectStorageException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertFileExists($source);
        unlink($source);
    }

    public function testTraversalDestinationIsRejected()
    {
        $source = $this->createTmpUpload('traversal', 'x');
        $transfer = new SwooleUploadTransfer($this->tmpDir);
        $transfer->register(array(
            'doc' => array(
                'name' => 'x.txt',
                'type' => 'text/plain',
                'tmp_name' => $source,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($source),
            ),
        ));
        $storage = new FileStorage($this->baseDir);

        try {
            $transfer->moveRegisteredFile($source, '../outside.txt', $storage);
            $this->fail('Traversal destination must be rejected');
        } catch (ObjectStorageException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        unlink($source);
    }

    public function testZeroByteUploadRegistersAndMoves()
    {
        $source = $this->createTmpUpload('empty', '');
        $transfer = new SwooleUploadTransfer($this->tmpDir);
        $transfer->register(array(
            'doc' => array(
                'name' => 'empty.txt',
                'type' => 'text/plain',
                'tmp_name' => $source,
                'error' => UPLOAD_ERR_OK,
                'size' => 0,
            ),
        ));
        $storage = new FileStorage($this->baseDir);

        $this->assertTrue($transfer->isRegistered($source));
        $this->assertTrue($transfer->moveRegisteredFile($source, 'empty.txt', $storage));
        $this->assertSame('', file_get_contents($this->baseDir.DIRECTORY_SEPARATOR.'empty.txt'));
    }

    public function testFailedUploadErrorIsNotRegistered()
    {
        $transfer = new SwooleUploadTransfer($this->tmpDir);
        $transfer->register(array(
            'doc' => array(
                'name' => 'missing.txt',
                'type' => 'text/plain',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE,
                'size' => 0,
            ),
        ));

        $this->assertFalse($transfer->isRegistered(''));
    }

    public function testStorageDecoratorMovesRegisteredUploadsWithoutSapi()
    {
        $source = $this->createTmpUpload('photo', 'photo-bytes');
        $transfer = new SwooleUploadTransfer($this->tmpDir);
        $storage = new SwooleFileStorage($this->baseDir, $transfer);
        $transfer->register(array(
            'photo' => array(
                'name' => 'photo.png',
                'type' => 'image/png',
                'tmp_name' => $source,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($source),
            ),
        ));

        $this->assertTrue($storage->moveUploadedFile($source, 'photo.png'));
        $this->assertSame('photo-bytes', file_get_contents($this->baseDir.DIRECTORY_SEPARATOR.'photo.png'));
    }

    public function testStorageDecoratorRecordsDownloadsInsteadOfStreaming()
    {
        $transfer = new SwooleUploadTransfer($this->tmpDir);
        $storage = new SwooleFileStorage($this->baseDir, $transfer);
        $blob = 'stream-me';
        $storage->put('doc.txt', $blob);

        $this->expectOutputString('');
        $storage->output('doc.txt');

        $this->assertSame(
            realpath($this->baseDir).DIRECTORY_SEPARATOR.'doc.txt',
            $storage->getRecordedDownloadPath()
        );
    }
}
