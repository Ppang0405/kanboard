<?php

namespace Kanboard\Core\Http;

use Kanboard\Core\Base;

/**
 * Remember Me Cookie
 *
 * @package  http
 * @author   Frederic Guillot
 */
class RememberMeCookie extends Base
{
    /**
     * Cookie name
     *
     * @var string
     */
    const COOKIE_NAME = 'KB_RM';

    /**
     * Cookies written during this request, oldest first.
     *
     * Long-running workers forward these through their own transport
     * because the SAPI setcookie() call below never reaches the client.
     *
     * @var array
     */
    private $pendingCookies = array();

    /**
     * Encode the cookie
     *
     * @access public
     * @param  string   $token        Session token
     * @param  string   $sequence     Sequence token
     * @return string
     */
    public function encode($token, $sequence)
    {
        return implode('|', array($token, $sequence));
    }

    /**
     * Decode the value of a cookie
     *
     * @access public
     * @param  string   $value    Raw cookie data
     * @return array
     */
    public function decode($value)
    {
        list($token, $sequence) = explode('|', $value);

        return array(
            'token' => $token,
            'sequence' => $sequence,
        );
    }

    /**
     * Return true if the current user has a RememberMe cookie
     *
     * @access public
     * @return bool
     */
    public function hasCookie()
    {
        return $this->request->getCookie(self::COOKIE_NAME) !== '';
    }

    /**
     * Write and encode the cookie
     *
     * @access public
     * @param  string   $token        Session token
     * @param  string   $sequence     Sequence token
     * @param  string   $expiration   Cookie expiration
     * @return boolean
     */
    public function write($token, $sequence, $expiration)
    {
        $this->pendingCookies[] = array(
            'name' => self::COOKIE_NAME,
            'value' => $this->encode($token, $sequence),
            'expires' => $expiration,
            'path' => $this->helper->url->dir(),
            'domain' => '',
            'secure' => $this->request->isHTTPS(),
            'httponly' => true,
            'samesite' => '',
        );

        return setcookie(
            self::COOKIE_NAME,
            $this->encode($token, $sequence),
            $expiration,
            $this->helper->url->dir(),
            '',
            $this->request->isHTTPS(),
            true
        );
    }

    /**
     * Get the cookies written during this request, oldest first
     *
     * @access public
     * @return array
     */
    public function getPendingCookies()
    {
        return $this->pendingCookies;
    }

    /**
     * Read and decode the cookie
     *
     * @access public
     * @return mixed
     */
    public function read()
    {
        $cookie = $this->request->getCookie(self::COOKIE_NAME);

        if (empty($cookie)) {
            return false;
        }

        return $this->decode($cookie);
    }

    /**
     * Remove the cookie
     *
     * @access public
     * @return boolean
     */
    public function remove()
    {
        $this->pendingCookies[] = array(
            'name' => self::COOKIE_NAME,
            'value' => '',
            'expires' => time() - 3600,
            'path' => $this->helper->url->dir(),
            'domain' => '',
            'secure' => $this->request->isHTTPS(),
            'httponly' => true,
            'samesite' => '',
        );

        return setcookie(
            self::COOKIE_NAME,
            '',
            time() - 3600,
            $this->helper->url->dir(),
            '',
            $this->request->isHTTPS(),
            true
        );
    }
}
