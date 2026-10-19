<?php

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/Swoole/Bootstrap.php';
require_once __DIR__.'/Swoole/ContainerFactory.php';

Kanboard\Swoole\Bootstrap::initializeOnce();

$container = Kanboard\Swoole\ContainerFactory::create();
