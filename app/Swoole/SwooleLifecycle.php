<?php

namespace Kanboard\Swoole;

use Kanboard\Core\Translator;
use Pimple\Container;

class SwooleLifecycle
{
    /**
     * $_SERVER after a reset: request data is gone, but the per-request
     * container still builds the console commands, and Symfony's
     * DumpCompletionCommand::configure() reads PHP_SELF (an "Undefined array
     * key" warning on every request otherwise).
     */
    const BASELINE_SERVER = array('PHP_SELF' => 'server.php');

    /**
     * Restore the worker baseline after every request, failures included.
     *
     * Rolls back a leaked open transaction, closes session state, clears
     * buffers and superglobal request data, unloads translator locales and
     * restores the UTC baseline. Never throws: cleanup must stay bounded.
     *
     * @param Container|null $container    This request's container, if built
     * @param integer        $bufferFloor  Buffers at or below this level are
     *                                      left alone; the worker baseline
     *                                      is zero
     */
    public static function reset($container = null, $bufferFloor = 0)
    {
        self::rollbackTransaction($container);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_id('');
        $_SESSION = array();
        $_GET = array();
        $_POST = array();
        $_FILES = array();
        $_COOKIE = array();
        $_REQUEST = array();
        $_SERVER = self::BASELINE_SERVER;

        $strayOutput = '';

        while (ob_get_level() > $bufferFloor) {
            $strayOutput .= (string) ob_get_clean();
        }

        if ($strayOutput !== '') {
            fwrite(STDERR, 'Swoole lifecycle: discarded '.strlen($strayOutput).' stray output bytes'.PHP_EOL);
        }

        Translator::unload();
        date_default_timezone_set('UTC');
        header_remove();
        http_response_code(200);
    }

    /**
     * Roll back an open database transaction, if any.
     *
     * Request handlers call this before publishing their response so a
     * leaked transaction can never stay open past the response. Never
     * throws: cleanup must stay bounded.
     *
     * @param Container|null $container  This request's container, if built
     */
    public static function rollbackTransaction($container)
    {
        if (! $container instanceof Container) {
            return;
        }

        try {
            $container['db']->cancelTransaction();
        } catch (\Throwable $failure) {
            fwrite(STDERR, 'Swoole lifecycle: transaction rollback skipped: '.$failure->getMessage().PHP_EOL);
        }
    }
}
