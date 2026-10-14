<?php

namespace Kanboard\Swoole;

use Kanboard\ServiceProvider\ActionProvider;
use Kanboard\ServiceProvider\ApiProvider;
use Kanboard\ServiceProvider\AuthenticationProvider;
use Kanboard\ServiceProvider\AvatarProvider;
use Kanboard\ServiceProvider\CacheProvider;
use Kanboard\ServiceProvider\ClassProvider;
use Kanboard\ServiceProvider\CommandProvider;
use Kanboard\ServiceProvider\DatabaseProvider;
use Kanboard\ServiceProvider\EventDispatcherProvider;
use Kanboard\ServiceProvider\ExternalLinkProvider;
use Kanboard\ServiceProvider\ExternalTaskProvider;
use Kanboard\ServiceProvider\FilterProvider;
use Kanboard\ServiceProvider\FormatterProvider;
use Kanboard\ServiceProvider\GroupProvider;
use Kanboard\ServiceProvider\HelperProvider;
use Kanboard\ServiceProvider\JobProvider;
use Kanboard\ServiceProvider\LoggingProvider;
use Kanboard\ServiceProvider\MailProvider;
use Kanboard\ServiceProvider\NotificationProvider;
use Kanboard\ServiceProvider\ObjectStorageProvider;
use Kanboard\ServiceProvider\PluginProvider;
use Kanboard\ServiceProvider\QueueProvider;
use Kanboard\ServiceProvider\RouteProvider;
use Kanboard\ServiceProvider\SessionProvider;
use Kanboard\ServiceProvider\UserProvider;
use Pimple\Container;

class ContainerFactory
{
    public static function create(): Container
    {
        $container = new Container;
        $container->register(new MailProvider());
        $container->register(new HelperProvider());
        $container->register(new SessionProvider());
        $container->register(new LoggingProvider());
        $container->register(new CacheProvider());
        $container->register(new DatabaseProvider());
        $container->register(new AuthenticationProvider());
        $container->register(new NotificationProvider());
        $container->register(new ClassProvider());
        $container->register(new EventDispatcherProvider());
        $container->register(new GroupProvider());
        $container->register(new UserProvider());
        $container->register(new RouteProvider());
        $container->register(new ActionProvider());
        $container->register(new ExternalLinkProvider());
        $container->register(new ExternalTaskProvider());
        $container->register(new AvatarProvider());
        $container->register(new FilterProvider());
        $container->register(new FormatterProvider());
        $container->register(new JobProvider());
        $container->register(new QueueProvider());
        $container->register(new ApiProvider());
        $container->register(new CommandProvider());
        $container->register(new ObjectStorageProvider());
        $container->register(new PluginProvider());

        return $container;
    }
}
