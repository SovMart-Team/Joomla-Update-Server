<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\SovmartUpdateServer\Configuration;

defined('_JEXEC') or die;

final class UpdateServerConfiguration
{
    /**
     * Temporary public endpoint. Replace this constant when the final SovMart
     * Joomla Core TUF repository URL is available.
     */
    public const REPOSITORY_URL = 'https://sovmart.ru/updates/joomla-core-tuf/';

    public static function getTrustedRoot(): string
    {
        $filename = dirname(__DIR__, 2) . '/resources/root.json';
        $root     = @file_get_contents($filename);

        if (!is_string($root) || $root === '') {
            throw new \RuntimeException('The bundled SovMart TUF root.json is unavailable.');
        }

        return $root;
    }
}
