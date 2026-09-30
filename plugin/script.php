<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\System\SovmartUpdateServer\Configuration\UpdateServerConfiguration;
use Joomla\Plugin\System\SovmartUpdateServer\Service\UpdateServerManager;
use Joomla\Registry\Registry;

if (!class_exists(UpdateServerManager::class)) {
    require_once __DIR__ . '/src/Service/UpdateServerManager.php';
}

if (!class_exists(UpdateServerConfiguration::class)) {
    require_once __DIR__ . '/src/Configuration/UpdateServerConfiguration.php';
}

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class ($container->get(AdministratorApplication::class)) implements InstallerScriptInterface {
                private DatabaseInterface $database;

                public function __construct(private readonly AdministratorApplication $application)
                {
                    $this->database = Factory::getContainer()->get(DatabaseInterface::class);
                }

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    try {
                        $params = $this->loadPluginParams();
                        $this->manager()->restore($params);
                    } catch (\Throwable $exception) {
                        $this->application->enqueueMessage(
                            Text::sprintf(
                                'PLG_SYSTEM_SOVMARTUPDATESERVER_RESTORE_ERROR',
                                $exception->getMessage()
                            ),
                            'warning'
                        );
                    }

                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if (!(new Version())->isCompatible('5.1')) {
                        $this->application->enqueueMessage(
                            Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', '5.1'),
                            'error'
                        );

                        return false;
                    }

                    if (version_compare(PHP_VERSION, '8.1', '<')) {
                        $this->application->enqueueMessage(
                            Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', '8.1'),
                            'error'
                        );

                        return false;
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    $plugin = $this->loadPluginRecord();
                    $params = new Registry((string) ($plugin->params ?? ''));
                    $manager = $this->manager();

                    try {
                        $manager->captureBackup($params);
                    } catch (\Throwable $exception) {
                        $this->application->enqueueMessage(
                            Text::sprintf(
                                'PLG_SYSTEM_SOVMARTUPDATESERVER_BACKUP_ERROR',
                                $exception->getMessage()
                            ),
                            'error'
                        );

                        return false;
                    }

                    $record               = new \stdClass();
                    $record->extension_id = (int) $plugin->extension_id;
                    $record->enabled      = 1;
                    $record->params       = (string) $params;
                    $this->database->updateObject('#__extensions', $record, 'extension_id');

                    try {
                        $location = $manager->apply(
                            UpdateServerConfiguration::REPOSITORY_URL,
                            UpdateServerConfiguration::getTrustedRoot()
                        );
                        $params->set('_managed_location', $location);
                        $manager->savePluginParams($params);
                    } catch (\Throwable $exception) {
                        $this->application->enqueueMessage(
                            Text::sprintf(
                                'PLG_SYSTEM_SOVMARTUPDATESERVER_CONFIG_ERROR',
                                $exception->getMessage()
                            ),
                            'error'
                        );

                        return false;
                    }

                    return true;
                }

                private function manager(): UpdateServerManager
                {
                    return new UpdateServerManager($this->database);
                }

                private function loadPluginParams(): Registry
                {
                    return new Registry((string) ($this->loadPluginRecord()->params ?? ''));
                }

                private function loadPluginRecord(): object
                {
                    $query = $this->database->getQuery(true)
                        ->select(['extension_id', 'params'])
                        ->from($this->database->quoteName('#__extensions'))
                        ->where($this->database->quoteName('type') . ' = ' . $this->database->quote('plugin'))
                        ->where($this->database->quoteName('folder') . ' = ' . $this->database->quote('system'))
                        ->where($this->database->quoteName('element') . ' = ' . $this->database->quote('sovmartupdateserver'));
                    $plugin = $this->database->setQuery($query)->loadObject();

                    if ($plugin === null) {
                        throw new \RuntimeException('The SovMart update server plugin record was not found.');
                    }

                    return $plugin;
                }
            }
        );
    }
};
