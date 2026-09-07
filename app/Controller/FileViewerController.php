<?php

namespace Kanboard\Controller;

use Kanboard\Core\Controller\AccessForbiddenException;
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
     * @param array $file
     * @param $mimetype
     */
    protected function renderFileWithCache(array $file, $mimetype)
    {
        if ($this->request->getHeader('If-None-Match') === '"'.$file['etag'].'"') {
            $this->response->status(304);
        } else {
            try {
                $this->response->withContentType($mimetype);
                $this->response->withCache(5 * 86400, $file['etag']);
                $this->response->send();
                $this->objectStorage->output($file['path']);
            } catch (ObjectStorageException $e) {
                $this->logger->error($e->getMessage());
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
     * Serve an HTML attachment for the sandboxed preview iframe
     *
     * The `Content-Security-Policy: sandbox` header keeps the document
     * script-free even when this URL is opened outside the iframe.
     *
     * @access public
     */
    public function html()
    {
        $file = $this->getFile();

        if ($this->helper->file->getPreviewType($file['name']) !== 'html') {
            throw AccessForbiddenException::getInstance()->withoutLayout();
        }

        $this->response->withHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->response->withHeader('Content-Security-Policy', "sandbox; frame-ancestors 'self'");
        $this->response->withHeader('X-Content-Type-Options', 'nosniff');
        $this->renderFileWithCache($file, 'text/html');
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
     * @access public
     */
    public function thumbnail()
    {
        $file = $this->getFile();
        $model = $file['model'];
        $filename = $this->$model->getThumbnailPath($file['path']);

        $this->response->withCache(5 * 86400, $file['etag']);
        $this->response->withContentType('image/png');

        if ($this->request->getHeader('If-None-Match') === '"'.$file['etag'].'"') {
            $this->response->status(304);
        } else {

            $this->response->send();

            try {

                $this->objectStorage->output($filename);
            } catch (ObjectStorageException $e) {
                $this->logger->error($e->getMessage());

                // Try to generate thumbnail on the fly for images uploaded before Kanboard < 1.0.19
                $data = $this->objectStorage->get($file['path']);
                $this->$model->generateThumbnailFromData($file['path'], $data);
                $this->objectStorage->output($this->$model->getThumbnailPath($file['path']));
            }
        }
    }

    /**
     * File download
     *
     * @access public
     */
    public function download()
    {
        try {
            $file = $this->getFile();
            $this->response->withFileDownload($file['name']);
            $this->response->send();
            $this->objectStorage->output($file['path']);
        } catch (ObjectStorageException $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
