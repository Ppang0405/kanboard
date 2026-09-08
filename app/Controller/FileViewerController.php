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
     * Maximum file size (in bytes) loaded into memory for text-based previews.
     * Larger files fall back to download-only instead of exhausting memory.
     */
    const PREVIEW_MAX_SIZE = 1048576;

    /**
     * Get file content from object storage
     *
     * Text-based previews (markdown, text) are capped at PREVIEW_MAX_SIZE.
     * Returns null when the file is too large to preview safely.
     *
     * @access protected
     * @param  array $file
     * @param  string|null $type  Preview type from FileHelper::getPreviewType()
     * @return string|null
     */
    protected function getFileContent(array $file, $type = null)
    {
        $content = '';

        if ($type === 'text' || $type === 'markdown') {
            if ($file['size'] > self::PREVIEW_MAX_SIZE) {
                return null;
            }
        }

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
            'content' => $this->getFileContent($file, $type),
            'too_large' => ($type === 'text' || $type === 'markdown') && $file['size'] > self::PREVIEW_MAX_SIZE,
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
     * Stream an audio or video attachment for the in-modal media player
     *
     * Serves the file with its proper mime-type so the browser can play it
     * inline. Only files mapped by getAudioMimeType()/getVideoMimeType()
     * are served here (403 otherwise).
     *
     * @access public
     */
    public function media()
    {
        $file = $this->getFile();
        $type = $this->helper->file->getPreviewType($file['name']);

        if ($type === 'audio') {
            $mimetype = $this->helper->file->getAudioMimeType($file['name']);
        } elseif ($type === 'video') {
            $mimetype = $this->helper->file->getVideoMimeType($file['name']);
        } else {
            $mimetype = null;
        }

        if ($mimetype === null) {
            throw AccessForbiddenException::getInstance()->withoutLayout();
        }

        $this->renderFileWithCache($file, $mimetype);
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
     * Falls back to the audio/video mime-types for stale or bookmarked
     * links to files that have since moved to the in-modal media player.
     *
     * The framing override (global X-Frame-Options: DENY replaced with
     * SAMEORIGIN) applies only to PDF/audio/video, which are embedded in
     * the preview modal. SVG keeps the global headers: replacing its
     * Content-Security-Policy would drop the default-src rule and allow
     * inline scripts in directly-opened SVG files.
     *
     * @access public
     */
    public function browser()
    {
        $file = $this->getFile();
        $mimetype = $this->helper->file->getBrowserViewType($file['name']);
        $framed = $mimetype === 'application/pdf';

        if ($mimetype === null) {
            $mimetype = $this->helper->file->getAudioMimeType($file['name']);
            $framed = $mimetype !== null;
        }

        if ($mimetype === null) {
            $mimetype = $this->helper->file->getVideoMimeType($file['name']);
            $framed = $mimetype !== null;
        }

        if ($mimetype === null) {
            throw AccessForbiddenException::getInstance()->withoutLayout();
        }

        if ($framed) {
            $this->response->withHeader('X-Frame-Options', 'SAMEORIGIN');
            $this->response->withHeader('Content-Security-Policy', "frame-ancestors 'self'");
        }

        $this->renderFileWithCache($file, $mimetype);
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
