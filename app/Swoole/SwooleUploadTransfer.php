<?php

namespace Kanboard\Swoole;

use Kanboard\Core\ObjectStorage\FileStorage;
use Kanboard\Core\ObjectStorage\ObjectStorageException;

class SwooleUploadTransfer
{
    private $tmpDir;
    private $allowedPaths = array();

    /**
     * @param string|null $privateTmpDir  Canonical private tmp dir; defaults
     *                                    to the system temp directory
     */
    public function __construct($privateTmpDir = null)
    {
        $realDir = realpath($privateTmpDir ?: sys_get_temp_dir());

        if ($realDir === false || ! is_dir($realDir)) {
            throw new ObjectStorageException('Invalid upload temp folder');
        }

        $this->tmpDir = $realDir;
    }

    /**
     * Register the tmp files of this request after success, error, size and
     * location checks. Only registered paths may be moved into storage.
     *
     * @param  array $normalizedFiles  $_FILES-shaped entries
     * @return $this
     */
    public function register(array $normalizedFiles)
    {
        foreach ($normalizedFiles as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (array_key_exists('tmp_name', $entry) && ! is_array($entry['tmp_name'])) {
                $this->registerSingleFile($entry);
            } elseif (array_key_exists('tmp_name', $entry) && is_array($entry['tmp_name'])) {
                $this->registerMultipleFiles($entry);
            }
        }

        return $this;
    }

    /**
     * Return true when the exact canonical path was registered by this request
     *
     * @param  string $path
     * @return bool
     */
    public function isRegistered($path)
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        $realPath = realpath($path);

        return $realPath !== false && isset($this->allowedPaths[$realPath]);
    }

    /**
     * Move a registered tmp file into object storage. The destination keeps
     * the storage's own traversal and base-directory guards.
     *
     * @param  string      $source   Exact registered tmp path
     * @param  string      $key      Storage destination key
     * @param  FileStorage $storage
     * @return bool
     */
    public function moveRegisteredFile($source, $key, FileStorage $storage)
    {
        if (! $this->isRegistered($source)) {
            throw new ObjectStorageException('Untrusted upload source: '.$source);
        }

        return $storage->moveFile(realpath($source), $key);
    }

    private function registerSingleFile(array $entry)
    {
        if (! isset($entry['error']) || $entry['error'] !== UPLOAD_ERR_OK) {
            return;
        }

        if (empty($entry['tmp_name']) || ! isset($entry['size'])) {
            return;
        }

        $realPath = realpath($entry['tmp_name']);

        if ($realPath === false || ! is_file($realPath)) {
            return;
        }

        if (strpos($realPath, $this->tmpDir.DIRECTORY_SEPARATOR) !== 0) {
            return;
        }

        if (filesize($realPath) !== (int) $entry['size']) {
            return;
        }

        $this->allowedPaths[$realPath] = true;
    }

    private function registerMultipleFiles(array $entry)
    {
        $count = count($entry['tmp_name']);

        for ($index = 0; $index < $count; $index++) {
            $this->registerSingleFile(array(
                'tmp_name' => isset($entry['tmp_name'][$index]) ? $entry['tmp_name'][$index] : '',
                'error' => isset($entry['error'][$index]) ? $entry['error'][$index] : UPLOAD_ERR_NO_FILE,
                'size' => isset($entry['size'][$index]) ? $entry['size'][$index] : 0,
            ));
        }
    }
}
