<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\SovmartUpdateServer\Service;

defined('_JEXEC') or die;

final class UpdateServerConfigurationService
{
    public const REPOSITORY_URL = 'https://dev.sovmart.ru/updates/49/28/'
        . '4928b7d5-3788-415c-8c9a-13f2a69cfdf5/joomla-core-tuf/';

    public function getRepositoryUrl(): string
    {
        return self::REPOSITORY_URL;
    }

    public function getTrustedRoot(): string
    {
        $filename = dirname(__DIR__, 2) . '/resources/root.json';
        $root     = @file_get_contents($filename);

        if (!is_string($root) || $root === '') {
            throw new \RuntimeException('The bundled SovMart TUF root.json is unavailable.');
        }

        return $root;
    }
}
