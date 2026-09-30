<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\SovmartUpdateServer\Extension;

defined('_JEXEC') or die;

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\SovmartUpdateServer\Configuration\UpdateServerConfiguration;
use Joomla\Plugin\System\SovmartUpdateServer\Service\UpdateServerManager;

final class SovmartUpdateServer extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public function __construct(
        $subject,
        array $config,
        private readonly UpdateServerManager $manager
    ) {
        parent::__construct($subject, $config);
    }

    public static function getSubscribedEvents(): array
    {
        return [ApplicationEvents::BEFORE_EXECUTE => 'configureJoomlaUpdateServer'];
    }

    public function configureJoomlaUpdateServer(ApplicationEvent $event): void
    {
        $application = $this->getApplication();

        if (!$application->isClient('administrator') && !$application->isClient('cli')) {
            return;
        }

        try {
            $location = $this->manager->apply(
                UpdateServerConfiguration::REPOSITORY_URL,
                UpdateServerConfiguration::getTrustedRoot()
            );

            if ((string) $this->params->get('_managed_location', '') !== $location) {
                $this->params->set('_managed_location', $location);
                $this->manager->savePluginParams($this->params);
            }
        } catch (\Throwable $exception) {
            Log::add(
                'Unable to configure the SovMart TUF update server: ' . $exception->getMessage(),
                Log::ERROR,
                'plg_system_sovmartupdateserver'
            );

            if ($application->isClient('administrator')) {
                $application->enqueueMessage(
                    Text::sprintf(
                        'PLG_SYSTEM_SOVMARTUPDATESERVER_CONFIG_ERROR',
                        $exception->getMessage()
                    ),
                    'error'
                );
            }
        }
    }
}
