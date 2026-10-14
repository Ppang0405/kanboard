<?php

namespace Kanboard\Swoole;

use Kanboard\Core\ObjectStorage\FileStorage;
use LogicException;

/**
 * Request-scoped file storage for the Swoole worker.
 *
 * Uploads move only tmp files registered by this request's transfer object;
 * the native move_uploaded_file() path stays untouched for SAPI runtimes.
 * Downloads record their validated path instead of streaming so the
 * transport can send the file directly without buffering it.
 */
class SwooleFileStorage extends FileStorage
{
    private $transfer;
    private $recordedDownloadPath;

    public function __construct($baseDir, SwooleUploadTransfer $transfer)
    {
        self::ensureDirectory($baseDir);
        parent::__construct($baseDir);
        $this->transfer = $transfer;
    }

    /**
     * Move a tmp file registered by this request into object storage
     *
     * @param  string $srcFilename  Exact registered tmp path
     * @param  string $key          Storage destination key
     * @return bool
     */
    public function moveUploadedFile($srcFilename, $key)
    {
        return $this->transfer->moveRegisteredFile($srcFilename, $key, $this);
    }

    /**
     * Record the validated download path instead of streaming it
     *
     * @param  string $key  Storage object key
     */
    public function output($key)
    {
        $this->recordedDownloadPath = $this->getPath($key);
    }

    /**
     * Get the path recorded by the latest output() call, if any
     *
     * @return string|null
     */
    public function getRecordedDownloadPath()
    {
        return $this->recordedDownloadPath;
    }

    private static function ensureDirectory($baseDir)
    {
        if (file_exists($baseDir)) {
            if (! is_writable($baseDir)) {
                throw new LogicException('The folder to store uploaded files is not writeable: '.$baseDir);
            }

            return;
        }

        if (! @mkdir($baseDir, 0755, true)) {
            throw new LogicException('Unable to create folder to store uploaded files: '.$baseDir);
        }
    }
}
