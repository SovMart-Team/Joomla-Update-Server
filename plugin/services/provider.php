<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\SovmartUpdateServer\Extension\SovmartUpdateServer;
use Joomla\Plugin\System\SovmartUpdateServer\Service\UpdateServerManager;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                $manager = new UpdateServerManager($container->get(DatabaseInterface::class));
                $plugin = new SovmartUpdateServer(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'sovmartupdateserver'),
                    $manager
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
