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
use Joomla\Plugin\System\SovmartUpdateServer\Service\UpdateServerConfigurationService;
use Joomla\Plugin\System\SovmartUpdateServer\Service\UpdateServerManagerService;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->share(
            UpdateServerConfigurationService::class,
            static fn (): UpdateServerConfigurationService => new UpdateServerConfigurationService()
        );

        $container->share(
            UpdateServerManagerService::class,
            static fn (Container $container): UpdateServerManagerService => new UpdateServerManagerService(
                $container->get(DatabaseInterface::class)
            )
        );

        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                $plugin = new SovmartUpdateServer(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'sovmartupdateserver'),
                    $container->get(UpdateServerManagerService::class),
                    $container->get(UpdateServerConfigurationService::class)
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
