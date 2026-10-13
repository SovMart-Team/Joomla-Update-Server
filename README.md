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

The canonical UUID repository URL
(`https://dev.sovmart.ru/updates/49/28/4928b7d5-3788-415c-8c9a-13f2a69cfdf5/joomla-core-tuf/`)
and offline-signed trusted `root.json` are bundled with the plugin. Installation
enables the plugin, stores the trust anchor and switches Joomla's native core
update channel immediately. There are no user settings.

Replace `plugin/resources/root.json` only with an explicitly approved
offline-signed root; never fetch the initial root implicitly during
installation. After bootstrap, Joomla persists newer root metadata obtained
through TUF's signed root rotation. The plugin preserves that persisted root on
normal requests and when its managed repository URL changes, so it cannot roll
Joomla back to the bundled root version.

## Repository contract

The configured base URL must expose the complete TUF repository, including
`root.json`, every required `N.root.json`, `timestamp.json`, and the snapshot and
targets files referenced by that metadata. Signed target download URLs must
return the declared ZIP with HTTP 200, matching length and hash, and an
attachment filename ending in `.zip`.

On uninstall, the plugin restores the update channel and trusted root that were
active before its first installation, provided the core update site is still
managed by this plugin.
