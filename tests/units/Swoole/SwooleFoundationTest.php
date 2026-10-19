<?php

namespace KanboardTests\units\Swoole;

use Kanboard\Swoole\Bootstrap;
use Kanboard\Swoole\ContainerFactory;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

class SwooleFoundationTest extends TestCase
{
    public function testBootstrapInitializeOnceIsIdempotent()
    {
        Bootstrap::initializeOnce();
        Bootstrap::initializeOnce();

        $this->assertTrue(defined('DATA_DIR'));
        $this->assertTrue(defined('DB_DRIVER'));
    }

    public function testContainerFactoryCreatesFreshContainers()
    {
        $first = ContainerFactory::create();
        $second = ContainerFactory::create();

        $this->assertInstanceOf(Container::class, $first);
        $this->assertInstanceOf(Container::class, $second);
        $this->assertNotSame($first, $second);
        $this->assertTrue(isset($first['db']));
        $this->assertTrue(isset($first['router']));
        $this->assertTrue(isset($second['db']));
        $this->assertTrue(isset($second['router']));
    }
}
