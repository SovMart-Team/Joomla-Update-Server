<?php

declare(strict_types=1);

define('_JEXEC', 1);

require_once dirname(__DIR__) . '/plugin/src/Service/UpdateServerManagerService.php';
require_once dirname(__DIR__) . '/plugin/src/Service/UpdateServerConfigurationService.php';

use Joomla\Plugin\System\SovmartUpdateServer\Service\UpdateServerConfigurationService;
use Joomla\Plugin\System\SovmartUpdateServer\Service\UpdateServerManagerService;

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
    UpdateServerManagerService::normalizeRepositoryUrl(
        'https://updates.example.test/path/joomla-core-tuf/root.json'
    ),
    'root.json URL must be normalized to a repository directory'
);
assertSameValue(
    'http://joomla-tuf-consumer.test:8080/repository/',
    UpdateServerManagerService::normalizeRepositoryUrl(
        'http://joomla-tuf-consumer.test:8080/repository'
    ),
    'local test URLs may use HTTP'
);
assertSameValue(
    'http://sovmart-origin/repository/',
    UpdateServerManagerService::normalizeRepositoryUrl('http://sovmart-origin/repository'),
    'single-label container hosts may use HTTP'
);
assertThrows(
    static fn () => UpdateServerManagerService::normalizeRepositoryUrl('http://updates.example.com/repository/'),
    'public HTTP URL must be rejected'
);
assertThrows(
    static fn () => UpdateServerManagerService::normalizeRepositoryUrl('https://user:pass@example.com/repository/'),
    'URL credentials must be rejected'
);

assertSameValue(
    false,
    UpdateServerManagerService::requiresTrustedRootBootstrap(
        'https://old.example.test/repository/',
        'tuf',
        '{"signed":{"version":2}}',
        'https://old.example.test/repository/'
    ),
    'an existing root managed by the plugin must survive a repository URL migration'
);
assertSameValue(
    true,
    UpdateServerManagerService::requiresTrustedRootBootstrap(
        'https://updates.joomla.org/core/',
        'tuf',
        '{"signed":{"version":1}}',
        null
    ),
    'a fresh installation must bootstrap the bundled SovMart root'
);
assertSameValue(
    true,
    UpdateServerManagerService::requiresTrustedRootBootstrap(
        'https://tuf.sovmart.ru/',
        'tuf',
        null,
        'https://tuf.sovmart.ru/'
    ),
    'a missing persisted root must be restored from the plugin bundle'
);
assertSameValue(
    true,
    UpdateServerManagerService::requiresTrustedRootBootstrap(
        'https://example.test/tampered/',
        'tuf',
        '{"signed":{"version":2}}',
        'https://tuf.sovmart.ru/'
    ),
    'a channel changed outside the plugin must be bootstrapped again'
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

UpdateServerManagerService::validateTrustedRoot($root);
assertThrows(
    static fn () => UpdateServerManagerService::validateTrustedRoot('{"signed":{"_type":"targets"}}'),
    'non-root metadata must be rejected'
);

$configuration = new UpdateServerConfigurationService();
$bundledRoot    = $configuration->getTrustedRoot();
UpdateServerManagerService::validateTrustedRoot($bundledRoot);
assertSameValue(
    'fa81f71ccf692e8147edba26570af9d4fe55312972fb5c8f96fe20a817432d50',
    hash('sha256', $bundledRoot),
    'bundled trusted root fingerprint must stay pinned'
);
assertSameValue(
    'https://dev.sovmart.ru/updates/49/28/'
        . '4928b7d5-3788-415c-8c9a-13f2a69cfdf5/joomla-core-tuf/',
    UpdateServerManagerService::normalizeRepositoryUrl($configuration->getRepositoryUrl()),
    'bundled repository URL must be valid'
);

echo "Update server manager validation passed.\n";
