<?php

namespace Kanboard\Swoole;

use Kanboard\Core\Translator;
use Pimple\Container;

class SwooleLifecycle
{
    /**
     * Restore the worker baseline after every request, failures included.
     *
     * Rolls back a leaked open transaction, closes session state, clears
     * buffers and superglobal session data, unloads translator locales and
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
        } catch (\Exception $failure) {
            fwrite(STDERR, 'Swoole lifecycle: transaction rollback skipped: '.$failure->getMessage().PHP_EOL);
        }
    }
}
