<?php

namespace Kanboard\Controller;

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\ObjectStorage\ObjectStorageException;
use Kanboard\Core\Security\Token;

/**
 * File Link Controller
 *
 * Signed links that open an HTML or image attachment in a new tab without
 * a session. The link is shareable and expires after LIFETIME seconds.
 *
 * @package  Kanboard\Controller
 */
class FileLinkController extends BaseController
{
    /**
     * Link lifetime in seconds
     */
    const LIFETIME = 3600;

    /**
     * Settings option that holds the signing key. Regenerating it revokes
     * every link handed out so far.
     */
    const KEY_OPTION = 'file_link_key';

    /**
     * Sign a fresh link and redirect to it
     *
     * Access to the file is checked by the project authorization middleware
     * and getFile(), so only users who can already see the file get a link.
     *
     * @access public
     */
    public function create()
    {
        $file = $this->getFile();

        if (! $this->isShareable($file)) {
            throw AccessForbiddenException::getInstance()->withoutLayout();
        }

        $this->response->redirect($this->helper->url->to('FileLinkController', 'open', $this->getSignedParams($file, time() + self::LIFETIME)));
    }

    /**
     * Stream the file of a signed link (public)
     *
     * HTML runs in an opaque origin (sandbox without allow-same-origin):
     * its scripts work but cannot reach Kanboard cookies, storage or API.
     *
     * @access public
     */
    public function open()
    {
        $expires = $this->request->getIntegerParam('expires');
        $remaining = $expires - time();

        if ($remaining <= 0 || $remaining > self::LIFETIME) {
            throw AccessForbiddenException::getInstance()->withoutLayout();
        }

        $file = $this->getFile();
        $expected = $this->getSignedParams($file, $expires);

        if (! hash_equals($expected['sig'], $this->request->getStringParam('sig')) || ! $this->isShareable($file)) {
            throw AccessForbiddenException::getInstance()->withoutLayout();
        }

        if ($this->helper->file->getPreviewType($file['name']) === 'html') {
            $this->response->withContentType('text/html');
            $this->response->withHeader('Content-Security-Policy', 'sandbox allow-scripts allow-forms allow-popups allow-modals allow-downloads');
        } else {
            $this->response->withContentType($this->helper->file->getImageMimeType($file['name']));
            $this->response->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        }

        $this->response->withHeader('X-Content-Type-Options', 'nosniff');
        $this->response->withHeader('Referrer-Policy', 'no-referrer');
        $this->response->withHeader('Cache-Control', 'private, max-age='.$remaining);
        $this->response->withHeader('Pragma', 'cache');
        $this->response->withHeader('Expires', gmdate('D, d M Y H:i:s', $expires).' GMT');
        $this->response->send();

        try {
            $this->objectStorage->output($file['path']);
        } catch (ObjectStorageException $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Only HTML documents and raster images can be opened through a link
     *
     * @access protected
     * @param  array $file
     * @return boolean
     */
    protected function isShareable(array $file)
    {
        return $file['is_image'] == 1 || $this->helper->file->getPreviewType($file['name']) === 'html';
    }

    /**
     * URL parameters of a signed link, including its signature
     *
     * @access protected
     * @param  array   $file
     * @param  integer $expires
     * @return array
     */
    protected function getSignedParams(array $file, $expires)
    {
        $params = array('file_id' => (int) $file['id']);

        if ($file['model'] === 'taskFileModel') {
            $params['task_id'] = (int) $file['task_id'];
        } else {
            $params['project_id'] = (int) $file['project_id'];
        }

        $params['expires'] = (int) $expires;
        $params['sig'] = hash_hmac('sha256', implode('|', array($file['model'], $file['id'], $file['model'] === 'taskFileModel' ? $file['task_id'] : $file['project_id'], $expires)), $this->getKey());

        return $params;
    }

    /**
     * Signing key, created on first use
     *
     * @access protected
     * @return string
     */
    protected function getKey()
    {
        $key = $this->configModel->get(self::KEY_OPTION);

        if ($key === '') {
            $key = Token::getToken(64);
            $this->configModel->save(array(self::KEY_OPTION => $key));
            $this->memoryCache->flush();
        }

        return $key;
    }
}
