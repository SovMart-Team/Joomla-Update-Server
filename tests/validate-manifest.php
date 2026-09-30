<?php

declare(strict_types=1);

$root     = dirname(__DIR__);
$manifest = $root . '/plugin/sovmartupdateserver.xml';
$xml      = simplexml_load_file($manifest);

if ($xml === false) {
    throw new RuntimeException('Plugin manifest is not valid XML.');
}

if ((string) $xml['type'] !== 'plugin' || (string) $xml['group'] !== 'system') {
    throw new RuntimeException('Manifest must describe a system plugin.');
}

$required = [
    'plugin/services/provider.php',
    'plugin/script.php',
    'plugin/src/Extension/SovmartUpdateServer.php',
    'plugin/src/Configuration/UpdateServerConfiguration.php',
    'plugin/src/Service/UpdateServerManager.php',
    'plugin/resources/root.json',
];

foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('Required file is missing: ' . $file);
    }
}

echo "Manifest validation passed.\n";
