<?php

namespace Kanboard\Swoole;

class SwooleSession
{
    const COOKIE_NAME = 'KB_SID';
    const ID_PATTERN = '/^[a-zA-Z0-9,-]{1,128}$/';

    /**
     * Bind this request's session cookie before dispatch.
     *
     * Closes a leaked active session, clears worker-global session data and
     * adopts only a well-formed incoming id; anything else starts fresh so
     * strict mode plus login regeneration defeat fixation.
     *
     * @param array $cookies  This request's cookies
     */
    public static function bind(array $cookies)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = array();
        header_remove();

        $id = isset($cookies[self::COOKIE_NAME]) ? $cookies[self::COOKIE_NAME] : '';
        session_id(is_string($id) && preg_match(self::ID_PATTERN, $id) ? $id : '');
    }

    /**
     * Finalize the session before the response is sent.
     *
     * Writes and closes an active session, then reports the cookies the
     * transport must emit: the current id (new, continued or regenerated),
     * a deletion when the session was destroyed, and any remember-me
     * cookies written during the request. The SAPI layer never emits them.
     *
     * Failed session persistence must never publish a session cookie: the
     * caller has to answer 500 instead. session_write_close() reports
     * success even when the save handler fails, so the write warning is
     * captured and converted into a false return.
     *
     * @param  array $incomingCookies  This request's cookies
     * @param  array $pendingCookies   Remember-me outbox entries
     * @return array|false  Name/value/expires/path/domain/secure/httponly/samesite, or false
     */
    public static function finalize(array $incomingCookies, array $pendingCookies)
    {
        $destroyed = session_status() !== PHP_SESSION_ACTIVE;
        $id = $destroyed ? '' : session_id();
        $writeFailed = false;

        if (! $destroyed) {
            set_error_handler(function () use (&$writeFailed) {
                $writeFailed = true;
                return true;
            }, E_WARNING);

            try {
                session_write_close();
            } finally {
                restore_error_handler();
            }
        }

        if ($writeFailed) {
            session_id('');
            header_remove();
            return false;
        }

        $params = session_get_cookie_params();
        $cookies = array();

        if ($id !== '') {
            $cookies[] = array(
                'name' => self::COOKIE_NAME,
                'value' => $id,
                'expires' => (int) $params['lifetime'],
                'path' => $params['path'] !== '' ? $params['path'] : '/',
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => ini_get('session.cookie_samesite') ?: '',
            );
        } elseif (isset($incomingCookies[self::COOKIE_NAME]) && $incomingCookies[self::COOKIE_NAME] !== '') {
            $cookies[] = array(
                'name' => self::COOKIE_NAME,
                'value' => '',
                'expires' => time() - 42000,
                'path' => $params['path'] !== '' ? $params['path'] : '/',
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => ini_get('session.cookie_samesite') ?: '',
            );
        }

        foreach ($pendingCookies as $pending) {
            $cookies[] = $pending;
        }

        session_id('');
        header_remove();

        return $cookies;
    }
}
