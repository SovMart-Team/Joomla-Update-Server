<?php

declare(strict_types=1);

namespace Joomla\Plugin\System\SovmartUpdateServer\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

final class UpdateServerManagerService
{
    public const MAX_ROOT_BYTES = 1024 * 1024;

    private const PLUGIN_ELEMENT = 'sovmartupdateserver';

    public function __construct(private readonly DatabaseInterface $database)
    {
    }

    public function captureBackup(Registry $params): void
    {
        if ((int) $params->get('_backup_complete', 0) === 1) {
            return;
        }

        $coreExtensionId = $this->getCoreExtensionId();
        $updateSite      = $this->getCoreUpdateSite($coreExtensionId);
        $componentParams = $this->getComponentParams();
        $root            = $this->getStoredRoot((int) $updateSite->update_site_id);

        $params->set('_backup_complete', 1);
        $params->set('_backup_update_site_id', (int) $updateSite->update_site_id);
        $params->set('_backup_update_site_location', (string) $updateSite->location);
        $params->set('_backup_update_site_type', (string) $updateSite->type);
        $params->set('_backup_update_site_enabled', (int) $updateSite->enabled);
        $params->set('_backup_tuf_root', base64_encode($root ?? ''));
        $params->set('_backup_component_params', base64_encode((string) $componentParams));
    }

    public function apply(
        string $repositoryUrl,
        string $trustedRoot,
        ?string $previousManagedLocation = null
    ): string
    {
        $location        = self::normalizeRepositoryUrl($repositoryUrl);
        $coreExtensionId = $this->getCoreExtensionId();
        $updateSite      = $this->getCoreUpdateSite($coreExtensionId);

        self::validateTrustedRoot($trustedRoot);

        $updateSiteId          = (int) $updateSite->update_site_id;
        $currentRoot           = $this->getStoredRoot($updateSiteId);
        $locationChanged       = (string) $updateSite->location !== $location
            || (string) $updateSite->type !== 'tuf';
        $rootBootstrapRequired = self::requiresTrustedRootBootstrap(
            (string) $updateSite->location,
            (string) $updateSite->type,
            $currentRoot,
            $previousManagedLocation
        );

        if ($locationChanged || $rootBootstrapRequired || (int) $updateSite->enabled !== 1) {
            $this->database->transactionStart();

            try {
                $site                       = new \stdClass();
                $site->update_site_id       = $updateSiteId;
                $site->location             = $location;
                $site->type                 = 'tuf';
                $site->enabled              = 1;
                $site->last_check_timestamp = 0;
                $this->database->updateObject('#__update_sites', $site, 'update_site_id');

                if ($rootBootstrapRequired) {
                    $this->storeTrustedRoot($updateSiteId, $trustedRoot);
                } elseif ($locationChanged) {
                    $this->clearTrustedMetadata($updateSiteId);
                }

                $this->clearCoreUpdateCache($coreExtensionId, $updateSiteId);
                $this->setComponentChannel($location);
                $this->database->transactionCommit();
            } catch (\Throwable $exception) {
                $this->database->transactionRollback();
                throw $exception;
            }
        } else {
            $this->setComponentChannel($location);
        }

        return $location;
    }

    public static function requiresTrustedRootBootstrap(
        string $currentLocation,
        string $currentType,
        ?string $currentRoot,
        ?string $previousManagedLocation
    ): bool
    {
        if ($currentRoot === null || $currentRoot === '' || $currentType !== 'tuf') {
            return true;
        }

        if ($previousManagedLocation === null || trim($previousManagedLocation) === '') {
            return true;
        }

        try {
            return self::normalizeRepositoryUrl($previousManagedLocation)
                !== self::normalizeRepositoryUrl($currentLocation);
        } catch (\InvalidArgumentException) {
            return true;
        }
    }

    public function restore(Registry $params): bool
    {
        if ((int) $params->get('_backup_complete', 0) !== 1) {
            return false;
        }

        $updateSiteId   = (int) $params->get('_backup_update_site_id', 0);
        $managedLocation = (string) $params->get('_managed_location', '');

        if ($updateSiteId < 1 || $managedLocation === '') {
            return false;
        }

        $query = $this->database->getQuery(true)
            ->select([$this->database->quoteName('location'), $this->database->quoteName('type')])
            ->from($this->database->quoteName('#__update_sites'))
            ->where($this->database->quoteName('update_site_id') . ' = :id')
            ->bind(':id', $updateSiteId, ParameterType::INTEGER);
        $current = $this->database->setQuery($query)->loadObject();

        if ($current === null || (string) $current->location !== $managedLocation || (string) $current->type !== 'tuf') {
            return false;
        }

        $this->database->transactionStart();

        try {
            $site                       = new \stdClass();
            $site->update_site_id       = $updateSiteId;
            $site->location             = (string) $params->get('_backup_update_site_location', '');
            $site->type                 = (string) $params->get('_backup_update_site_type', 'collection');
            $site->enabled              = (int) $params->get('_backup_update_site_enabled', 1);
            $site->last_check_timestamp = 0;
            $this->database->updateObject('#__update_sites', $site, 'update_site_id');

            $root = base64_decode((string) $params->get('_backup_tuf_root', ''), true);
            $this->restoreTrustedRoot($updateSiteId, $root === false ? null : $root);

            $componentParams = base64_decode(
                (string) $params->get('_backup_component_params', ''),
                true
            );

            if ($componentParams !== false) {
                $this->writeComponentParams($componentParams);
            }

            $this->clearCoreUpdateCache($this->getCoreExtensionId(), $updateSiteId);
            $this->database->transactionCommit();
        } catch (\Throwable $exception) {
            $this->database->transactionRollback();
            throw $exception;
        }

        return true;
    }

    public function savePluginParams(Registry $params): void
    {
        $paramsString = (string) $params;
        $query = $this->database->getQuery(true)
            ->update($this->database->quoteName('#__extensions'))
            ->set($this->database->quoteName('params') . ' = :params')
            ->where($this->database->quoteName('type') . ' = ' . $this->database->quote('plugin'))
            ->where($this->database->quoteName('folder') . ' = ' . $this->database->quote('system'))
            ->where($this->database->quoteName('element') . ' = ' . $this->database->quote(self::PLUGIN_ELEMENT))
            ->bind(':params', $paramsString);
        $this->database->setQuery($query)->execute();
    }

    public static function normalizeRepositoryUrl(string $repositoryUrl): string
    {
        $repositoryUrl = trim($repositoryUrl);
        $parts         = parse_url($repositoryUrl);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('The SovMart TUF repository URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host   = strtolower((string) $parts['host']);
        $local  = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.test')
            || !str_contains($host, '.');

        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new \InvalidArgumentException('The TUF repository must use HTTPS outside a local test environment.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('The TUF repository URL must not contain credentials, a query, or a fragment.');
        }

        $path = (string) ($parts['path'] ?? '/');

        if (str_ends_with($path, '/root.json')) {
            $path = substr($path, 0, -strlen('root.json'));
        }

        $port      = isset($parts['port']) ? ':' . $parts['port'] : '';
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;

        return $scheme . '://' . $authority . $port . '/' . ltrim(rtrim($path, '/') . '/', '/');
    }

    public static function validateTrustedRoot(string $trustedRoot): void
    {
        if ($trustedRoot === '' || strlen($trustedRoot) > self::MAX_ROOT_BYTES) {
            throw new \InvalidArgumentException('The trusted TUF root is empty or too large.');
        }

        try {
            $metadata = json_decode($trustedRoot, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The trusted TUF root is not valid JSON.', 0, $exception);
        }

        if (!is_array($metadata) || !is_array($metadata['signed'] ?? null)) {
            throw new \InvalidArgumentException('The trusted TUF root has no signed object.');
        }

        $signed = $metadata['signed'];

        if (($signed['_type'] ?? null) !== 'root' || (int) ($signed['version'] ?? 0) < 1) {
            throw new \InvalidArgumentException('The trusted metadata is not a versioned TUF root.');
        }

        if (!is_array($signed['keys'] ?? null) || $signed['keys'] === []) {
            throw new \InvalidArgumentException('The trusted TUF root has no public keys.');
        }

        try {
            $expires = new \DateTimeImmutable((string) ($signed['expires'] ?? ''));
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('The trusted TUF root has an invalid expiry date.', 0, $exception);
        }

        if ($expires <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new \InvalidArgumentException('The trusted TUF root has expired.');
        }

        foreach (['root', 'targets', 'snapshot', 'timestamp'] as $role) {
            $definition = $signed['roles'][$role] ?? null;

            if (
                !is_array($definition)
                || !is_array($definition['keyids'] ?? null)
                || $definition['keyids'] === []
                || (int) ($definition['threshold'] ?? 0) < 1
            ) {
                throw new \InvalidArgumentException('The trusted TUF root has an invalid ' . $role . ' role.');
            }

            foreach ($definition['keyids'] as $keyId) {
                if (!is_string($keyId) || !isset($signed['keys'][$keyId])) {
                    throw new \InvalidArgumentException('The trusted TUF root refers to an unknown public key.');
                }
            }
        }

        if (!is_array($metadata['signatures'] ?? null) || $metadata['signatures'] === []) {
            throw new \InvalidArgumentException('The trusted TUF root has no signatures.');
        }
    }

    private function getCoreExtensionId(): int
    {
        $query = $this->database->getQuery(true)
            ->select($this->database->quoteName('extension_id'))
            ->from($this->database->quoteName('#__extensions'))
            ->where($this->database->quoteName('type') . ' = ' . $this->database->quote('file'))
            ->where($this->database->quoteName('element') . ' = ' . $this->database->quote('joomla'));
        $id = (int) $this->database->setQuery($query)->loadResult();

        if ($id < 1) {
            throw new \RuntimeException('The Joomla core extension record was not found.');
        }

        return $id;
    }

    private function getCoreUpdateSite(int $coreExtensionId): object
    {
        $query = $this->database->getQuery(true)
            ->select('us.*')
            ->from($this->database->quoteName('#__update_sites_extensions', 'map'))
            ->join(
                'INNER',
                $this->database->quoteName('#__update_sites', 'us')
                    . ' ON ' . $this->database->quoteName('us.update_site_id')
                    . ' = ' . $this->database->quoteName('map.update_site_id')
            )
            ->where($this->database->quoteName('map.extension_id') . ' = :extensionId')
            ->bind(':extensionId', $coreExtensionId, ParameterType::INTEGER);
        $site = $this->database->setQuery($query)->loadObject();

        if ($site === null) {
            throw new \RuntimeException('The Joomla core update site was not found.');
        }

        return $site;
    }

    private function getStoredRoot(int $updateSiteId): ?string
    {
        $query = $this->database->getQuery(true)
            ->select($this->database->quoteName('root'))
            ->from($this->database->quoteName('#__tuf_metadata'))
            ->where($this->database->quoteName('update_site_id') . ' = :id')
            ->bind(':id', $updateSiteId, ParameterType::INTEGER);
        $root = $this->database->setQuery($query)->loadResult();

        return $root === null ? null : (string) $root;
    }

    private function storeTrustedRoot(int $updateSiteId, string $trustedRoot): void
    {
        $query = $this->database->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->database->quoteName('#__tuf_metadata'))
            ->where($this->database->quoteName('update_site_id') . ' = :id')
            ->bind(':id', $updateSiteId, ParameterType::INTEGER);
        $exists = (int) $this->database->setQuery($query)->loadResult() > 0;

        if (!$exists) {
            $row                 = new \stdClass();
            $row->update_site_id = $updateSiteId;
            $row->root           = $trustedRoot;
            $this->database->insertObject('#__tuf_metadata', $row);

            return;
        }

        $query = $this->database->getQuery(true)
            ->update($this->database->quoteName('#__tuf_metadata'))
            ->set($this->database->quoteName('root') . ' = :root')
            ->set($this->database->quoteName('targets') . ' = NULL')
            ->set($this->database->quoteName('snapshot') . ' = NULL')
            ->set($this->database->quoteName('timestamp') . ' = NULL')
            ->set($this->database->quoteName('mirrors') . ' = NULL')
            ->where($this->database->quoteName('update_site_id') . ' = :id')
            ->bind(':root', $trustedRoot, ParameterType::LARGE_OBJECT)
            ->bind(':id', $updateSiteId, ParameterType::INTEGER);
        $this->database->setQuery($query)->execute();
    }

    private function clearTrustedMetadata(int $updateSiteId): void
    {
        $query = $this->database->getQuery(true)
            ->update($this->database->quoteName('#__tuf_metadata'))
            ->set($this->database->quoteName('targets') . ' = NULL')
            ->set($this->database->quoteName('snapshot') . ' = NULL')
            ->set($this->database->quoteName('timestamp') . ' = NULL')
            ->set($this->database->quoteName('mirrors') . ' = NULL')
            ->where($this->database->quoteName('update_site_id') . ' = :id')
            ->bind(':id', $updateSiteId, ParameterType::INTEGER);
        $this->database->setQuery($query)->execute();
    }

    private function restoreTrustedRoot(int $updateSiteId, ?string $trustedRoot): void
    {
        if ($trustedRoot === null || $trustedRoot === '') {
            $query = $this->database->getQuery(true)
                ->delete($this->database->quoteName('#__tuf_metadata'))
                ->where($this->database->quoteName('update_site_id') . ' = :id')
                ->bind(':id', $updateSiteId, ParameterType::INTEGER);
            $this->database->setQuery($query)->execute();

            return;
        }

        $this->storeTrustedRoot($updateSiteId, $trustedRoot);
    }

    private function getComponentParams(): Registry
    {
        $query = $this->database->getQuery(true)
            ->select($this->database->quoteName('params'))
            ->from($this->database->quoteName('#__extensions'))
            ->where($this->database->quoteName('type') . ' = ' . $this->database->quote('component'))
            ->where($this->database->quoteName('element') . ' = ' . $this->database->quote('com_joomlaupdate'));

        return new Registry((string) $this->database->setQuery($query)->loadResult());
    }

    private function setComponentChannel(string $location): void
    {
        $params = $this->getComponentParams();

        if ($params->get('updatesource') === 'custom' && $params->get('customurl') === $location) {
            return;
        }

        $params->set('updatesource', 'custom');
        $params->set('customurl', $location);
        $this->writeComponentParams((string) $params);
    }

    private function writeComponentParams(string $params): void
    {
        $query = $this->database->getQuery(true)
            ->update($this->database->quoteName('#__extensions'))
            ->set($this->database->quoteName('params') . ' = :params')
            ->where($this->database->quoteName('type') . ' = ' . $this->database->quote('component'))
            ->where($this->database->quoteName('element') . ' = ' . $this->database->quote('com_joomlaupdate'))
            ->bind(':params', $params);
        $this->database->setQuery($query)->execute();
    }

    private function clearCoreUpdateCache(int $coreExtensionId, int $updateSiteId): void
    {
        $query = $this->database->getQuery(true)
            ->delete($this->database->quoteName('#__updates'))
            ->where(
                '(' . $this->database->quoteName('extension_id') . ' = :extensionId'
                . ' OR ' . $this->database->quoteName('update_site_id') . ' = :siteId)'
            )
            ->bind(':extensionId', $coreExtensionId, ParameterType::INTEGER)
            ->bind(':siteId', $updateSiteId, ParameterType::INTEGER);
        $this->database->setQuery($query)->execute();
    }
}
