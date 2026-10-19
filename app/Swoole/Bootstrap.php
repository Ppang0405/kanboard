<?php

namespace Kanboard\Swoole;

class Bootstrap
{
    private static $initialized = false;

    public static function initializeOnce(): void
    {
        if (self::$initialized) {
            return;
        }

        self::defineDatabaseSettingsFromEnvironment();
        self::loadLocalConfiguration();

        require_once __DIR__.'/../constants.php';
        require_once __DIR__.'/../check_setup.php';

        self::$initialized = true;
    }

    private static function defineDatabaseSettingsFromEnvironment(): void
    {
        $parser = new \PicoDb\UrlParser();

        if (! $parser->isEnvironmentVariableDefined()) {
            return;
        }

        $settings = $parser->getSettings();

        defined('DB_DRIVER') or define('DB_DRIVER', $settings['driver']);
        defined('DB_USERNAME') or define('DB_USERNAME', $settings['username']);
        defined('DB_PASSWORD') or define('DB_PASSWORD', $settings['password']);
        defined('DB_HOSTNAME') or define('DB_HOSTNAME', $settings['hostname']);
        defined('DB_PORT') or define('DB_PORT', $settings['port']);
        defined('DB_NAME') or define('DB_NAME', $settings['database']);
    }

    private static function loadLocalConfiguration(): void
    {
        $localConfig = implode(DIRECTORY_SEPARATOR, array(__DIR__, '..', '..', 'data', 'config.php'));

        if (file_exists($localConfig)) {
            require_once $localConfig;
        }

        $rootConfig = implode(DIRECTORY_SEPARATOR, array(__DIR__, '..', '..', 'config.php'));

        if (file_exists($rootConfig)) {
            require_once $rootConfig;
        }
    }
}
