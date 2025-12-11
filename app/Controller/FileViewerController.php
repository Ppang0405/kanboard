<?php

namespace Kanboard\Controller;

use Kanboard\Core\ObjectStorage\ObjectStorageException;

/**
 * File Viewer Controller
 *
 * @package  Kanbaord\Controller
 * @author   Frederic Guillot
 */
class FileViewerController extends BaseController
{
    /**
     * Get file content from object storage
     *
     * @access protected
     * @param  array $file
     * @return string
     */
    protected function getFileContent(array $file)
    {
        $content = '';

        try {
            if ($file['is_image'] == 0) {
                $content = $this->objectStorage->get($file['path']);
            }
        } catch (ObjectStorageException $e) {
            $this->logger->error($e->getMessage());
        }

        return $content;
    }

    /**
     * Output file with cache
     *
     * Serves files to the browser with caching support.
     * Handles Safari's Range requests by returning full content with 200 status.
     * Includes Accept-Ranges: none header to prevent future range requests.
     *
     * @access protected
     * @param array $file File metadata array containing path, etag, etc.
     * @param string $mimetype MIME type for the Content-Type header
     * @return void
     */
    protected function renderFileWithCache(array $file, $mimetype)
    {
        if ($this->request->getHeader('If-None-Match') === '"'.$file['etag'].'"') {
            $this->response->status(304);
        } else {
            try {
                // Get file content first to determine Content-Length
                $content = $this->objectStorage->get($file['path']);
                
                $this->response->withContentType($mimetype);
                $this->response->withCache(5 * 86400, $file['etag']);
                // Tell browsers we don't support Range requests (prevents Safari errors)
                $this->response->withHeader('Accept-Ranges', 'none');
                // Content-Length helps Safari handle the response properly
                $this->response->withHeader('Content-Length', strlen($content));
                $this->response->send();
                echo $content;
            } catch (ObjectStorageException $e) {
                $this->logger->error($e->getMessage());
                $this->response->status(404);
            }
        }
    }

    /**
     * Show file content in a popover
     *
     * @access public
     */
    public function show()
    {
        $file = $this->getFile();
        $type = $this->helper->file->getPreviewType($file['name']);
        $params = ['file_id' => $file['id']];

        if (array_key_exists('etag', $file)) {
            $params['etag'] = $file['etag'];
        }

        $project_id = $this->request->getIntegerParam('project_id');
        if ($project_id !== 0) {
            $params['project_id'] = $project_id;
        }

        if ($file['model'] === 'taskFileModel') {
            $params['task_id'] = $file['task_id'];
        }

        $this->response->html($this->template->render('file_viewer/show', array(
            'file' => $file,
            'params' => $params,
            'type' => $type,
            'content' => $this->getFileContent($file),
        )));
    }

    /**
     * Display image
     *
     * @access public
     */
    public function image()
    {
        $file = $this->getFile();
        $this->renderFileWithCache($file, $this->helper->file->getImageMimeType($file['name']));
    }

    /**
     * Display file in browser
     *
     * @access public
     */
    public function browser()
    {
        $file = $this->getFile();
        $this->renderFileWithCache($file, $this->helper->file->getBrowserViewType($file['name']));
    }

    /**
     * Display image thumbnail
     *
     * Serves a thumbnail version of an image file.
     * Falls back to generating thumbnail on-the-fly for legacy images.
     * Handles Safari's Range requests by returning full content with 200 status.
     *
     * @access public
     * @return void
     */
    public function thumbnail()
    {
        $file = $this->getFile();
        $model = $file['model'];
        $filename = $this->$model->getThumbnailPath($file['path']);

        if ($this->request->getHeader('If-None-Match') === '"'.$file['etag'].'"') {
            $this->response->status(304);
        } else {
            try {
                // Try to get thumbnail content
                $content = $this->objectStorage->get($filename);
            } catch (ObjectStorageException $e) {
                $this->logger->error($e->getMessage());

                // Try to generate thumbnail on the fly for images uploaded before Kanboard < 1.0.19
                try {
                    $data = $this->objectStorage->get($file['path']);
                    $this->$model->generateThumbnailFromData($file['path'], $data);
                    $content = $this->objectStorage->get($this->$model->getThumbnailPath($file['path']));
                } catch (ObjectStorageException $e2) {
                    $this->logger->error($e2->getMessage());
                    $this->response->status(404);
                    return;
                }
            }

            $this->response->withCache(5 * 86400, $file['etag']);
            $this->response->withContentType('image/png');
            // Tell browsers we don't support Range requests (prevents Safari errors)
            $this->response->withHeader('Accept-Ranges', 'none');
            // Content-Length helps Safari handle the response properly
            $this->response->withHeader('Content-Length', strlen($content));
            $this->response->send();
            echo $content;
        }
    }

    /**
     * File download
     *
     * Forces the browser to download the file as an attachment.
     * Handles Safari's Range requests by returning full content with 200 status.
     * Includes Accept-Ranges: none header to prevent future range requests.
     *
     * @access public
     * @return void
     */
    public function download()
    {
        try {
            $file = $this->getFile();
            // Get file content first to determine Content-Length
            $content = $this->objectStorage->get($file['path']);
            
            $this->response->withFileDownload($file['name']);
            // Tell browsers we don't support Range requests (prevents Safari errors)
            $this->response->withHeader('Accept-Ranges', 'none');
            // Content-Length helps Safari handle the response properly
            $this->response->withHeader('Content-Length', strlen($content));
            $this->response->send();
            echo $content;
        } catch (ObjectStorageException $e) {
            $this->logger->error($e->getMessage());
            $this->response->status(404);
        }
    }
}
