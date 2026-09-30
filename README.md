# SovMart Joomla TUF Update Server

Zero-configuration system plugin for Joomla 5.1+ which switches the native
Joomla core updater to the SovMart SaaS TUF repository.

The plugin does not replace `com_joomlaupdate`. It configures Joomla's own update
site, installs the trusted TUF root into `#__tuf_metadata`, clears stale core
update records, and leaves discovery, download, checksum verification and
installation to Joomla.

## Build

```bash
make package
```

The installable archive is written to `build/plg_system_sovmartupdateserver.zip`.

## Install

1. Install the ZIP in Joomla.
2. Open **Components → Joomla Update** and select **Check for Updates**.

The repository URL and offline-signed trusted `root.json` are bundled with the
plugin. Installation enables the plugin, stores the trust anchor and switches
Joomla's native core update channel immediately. There are no user settings.

The current temporary repository URL is defined once in
`plugin/src/Configuration/UpdateServerConfiguration.php`. Replace that constant
with the final public endpoint before production release. Replace
`plugin/resources/root.json` only with an explicitly approved offline-signed
root; never fetch the initial root implicitly during installation.

On uninstall, the plugin restores the update channel and trusted root that were
active before its first installation, provided the core update site is still
managed by this plugin.
