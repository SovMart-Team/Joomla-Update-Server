<?php

declare(strict_types=1);

define('_JEXEC', 1);

require_once dirname(__DIR__) . '/plugin/src/Service/UpdateServerManager.php';
require_once dirname(__DIR__) . '/plugin/src/Configuration/UpdateServerConfiguration.php';

use Joomla\Plugin\System\SovmartUpdateServer\Configuration\UpdateServerConfiguration;
use Joomla\Plugin\System\SovmartUpdateServer\Service\UpdateServerManager;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

assertSameValue(
    'https://updates.example.test/path/joomla-core-tuf/',
    UpdateServerManager::normalizeRepositoryUrl(
        'https://updates.example.test/path/joomla-core-tuf/root.json'
    ),
    'root.json URL must be normalized to a repository directory'
);
assertSameValue(
    'http://joomla-tuf-consumer.test:8080/repository/',
    UpdateServerManager::normalizeRepositoryUrl(
        'http://joomla-tuf-consumer.test:8080/repository'
    ),
    'local test URLs may use HTTP'
);
assertSameValue(
    'http://sovmart-origin/repository/',
    UpdateServerManager::normalizeRepositoryUrl('http://sovmart-origin/repository'),
    'single-label container hosts may use HTTP'
);
assertThrows(
    static fn () => UpdateServerManager::normalizeRepositoryUrl('http://updates.example.com/repository/'),
    'public HTTP URL must be rejected'
);
assertThrows(
    static fn () => UpdateServerManager::normalizeRepositoryUrl('https://user:pass@example.com/repository/'),
    'URL credentials must be rejected'
);

$keyId = str_repeat('a', 64);
$root  = json_encode([
    'signatures' => [['keyid' => $keyId, 'sig' => str_repeat('b', 128)]],
    'signed' => [
        '_type'   => 'root',
        'version' => 1,
        'expires' => '2099-01-01T00:00:00Z',
        'keys'    => [
            $keyId => [
                'keytype' => 'ed25519',
                'scheme'  => 'ed25519',
                'keyval'  => ['public' => str_repeat('c', 64)],
            ],
        ],
        'roles' => array_fill_keys(
            ['root', 'targets', 'snapshot', 'timestamp'],
            ['keyids' => [$keyId], 'threshold' => 1]
        ),
    ],
], JSON_THROW_ON_ERROR);

UpdateServerManager::validateTrustedRoot($root);
assertThrows(
    static fn () => UpdateServerManager::validateTrustedRoot('{"signed":{"_type":"targets"}}'),
    'non-root metadata must be rejected'
);

$bundledRoot = UpdateServerConfiguration::getTrustedRoot();
UpdateServerManager::validateTrustedRoot($bundledRoot);
assertSameValue(
    'fa81f71ccf692e8147edba26570af9d4fe55312972fb5c8f96fe20a817432d50',
    hash('sha256', $bundledRoot),
    'bundled trusted root fingerprint must stay pinned'
);
assertSameValue(
    'https://sovmart.ru/updates/joomla-core-tuf/',
    UpdateServerManager::normalizeRepositoryUrl(UpdateServerConfiguration::REPOSITORY_URL),
    'bundled repository URL must be valid'
);

echo "Update server manager validation passed.\n";
