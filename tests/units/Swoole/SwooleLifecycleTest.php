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
        $container = ContainerFactory::create();
        $baselineBufferLevel = ob_get_level();

        date_default_timezone_set('America/New_York');
        Translator::load('fr_FR');
        $_SESSION['user'] = array('id' => 9);
        ob_start();
        echo 'stray controller output';
        $container['db']->startTransaction();

        $this->assertSame('Tableau de bord', t('Dashboard'));

        SwooleLifecycle::reset($container, $baselineBufferLevel);

        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame(array(), $_SESSION);
        $this->assertSame($baselineBufferLevel, ob_get_level());
        $this->assertFalse($container['db']->getConnection()->inTransaction());
        $this->assertSame('Dashboard', t('Dashboard'));
        $this->assertSame('', session_id());
    }

    public function testResetWithoutContainerStillCleansProcessState()
    {
        $baselineBufferLevel = ob_get_level();
        date_default_timezone_set('America/New_York');
        $_SESSION['leak'] = true;

        SwooleLifecycle::reset(null, $baselineBufferLevel);

        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame(array(), $_SESSION);

        date_default_timezone_set('UTC');
    }
}
