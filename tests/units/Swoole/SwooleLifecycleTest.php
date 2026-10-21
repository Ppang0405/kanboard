<?php

namespace KanboardTests\units\Swoole;

use Kanboard\Core\Translator;
use Kanboard\Swoole\ContainerFactory;
use Kanboard\Swoole\SwooleLifecycle;
use PHPUnit\Framework\TestCase;

class SwooleLifecycleTest extends TestCase
{
    public function testResetRestoresWorkerBaseline()
    {
        $serverBackup = $_SERVER;
        $container = ContainerFactory::create();
        $baselineBufferLevel = ob_get_level();

        date_default_timezone_set('America/New_York');
        Translator::load('fr_FR');
        $_SESSION['user'] = array('id' => 9);
        ob_start();
        echo 'stray controller output';
        $container['db']->startTransaction();

        $this->assertSame('Tableau de bord', t('Dashboard'));

        try {
            SwooleLifecycle::reset($container, $baselineBufferLevel);

            $this->assertSame('UTC', date_default_timezone_get());
            $this->assertSame(array(), $_SESSION);
            $this->assertSame($baselineBufferLevel, ob_get_level());
            $this->assertFalse($container['db']->getConnection()->inTransaction());
            $this->assertSame('Dashboard', t('Dashboard'));
            $this->assertSame('', session_id());
        } finally {
            $_SERVER = $serverBackup;
        }
    }

    public function testResetWithoutContainerStillCleansProcessState()
    {
        $serverBackup = $_SERVER;
        $baselineBufferLevel = ob_get_level();
        date_default_timezone_set('America/New_York');
        $_SESSION['leak'] = true;

        try {
            SwooleLifecycle::reset(null, $baselineBufferLevel);

            $this->assertSame('UTC', date_default_timezone_get());
            $this->assertSame(array(), $_SESSION);
        } finally {
            $_SERVER = $serverBackup;
        }

        date_default_timezone_set('UTC');
    }

    public function testResetClearsSuperglobalsAndRestoresHttpStatus()
    {
        $serverBackup = $_SERVER;
        $baselineBufferLevel = ob_get_level();
        $container = ContainerFactory::create();

        $_GET['leak'] = 'get';
        $_POST['leak'] = 'post';
        $_FILES['leak'] = array('tmp_name' => '/tmp/leak');
        $_COOKIE['leak'] = 'cookie';
        $_REQUEST['leak'] = 'request';
        $_SERVER['LEAK_MARKER'] = 'present';
        http_response_code(418);

        try {
            SwooleLifecycle::reset($container, $baselineBufferLevel);

            $this->assertSame(array(), $_GET);
            $this->assertSame(array(), $_POST);
            $this->assertSame(array(), $_FILES);
            $this->assertSame(array(), $_COOKIE);
            $this->assertSame(array(), $_REQUEST);
            $this->assertSame(SwooleLifecycle::BASELINE_SERVER, $_SERVER);
            $this->assertSame(200, http_response_code());
        } finally {
            $_GET = array();
            $_POST = array();
            $_FILES = array();
            $_COOKIE = array();
            $_REQUEST = array();
            $_SERVER = $serverBackup;
            http_response_code(200);
        }
    }
}
