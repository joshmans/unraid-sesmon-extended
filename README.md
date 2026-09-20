**SCSI Enclosure Monitor Extended for UNRAID**

A fork of [desertwitch/sesmon-unRAID](https://github.com/desertwitch/sesmon-unRAID), the Unraid plugin for
[sesmon](https://github.com/desertwitch/sesmon) (a monitoring and alerting daemon for SES-capable SCSI enclosures).
All credit for the original plugin and the daemon goes to desertwitch.

**Installation:** https://raw.githubusercontent.com/joshmans/unraid-sesmon-extended/main/sesmon-ext.plg

**This plugin cannot be installed together with the original `dwsesmon` plugin.** Remove that one first: both
use the same `sesmon` package and service. Your old enclosure configuration is kept in
`/boot/config/plugins/dwsesmon/config/config.yaml` if you want to copy it over.

## Differences from the original

- Everything on the system is named `sesmon-ext` (plugin folder, service `rc.sesmon-ext`, `/etc/sesmon-ext`,
  `/var/lib/sesmon-ext`, `/var/log/sesmon-ext.log`) so it never clashes with the original.
- The example enclosure in the default configuration is disabled. The original ships it enabled with a made-up
  SAS address, so a fresh install with the service switched on fails with
  `SAS address [0x500a098012345678] is not resolvable (not found)`.
- Packages are verified with SHA-256 instead of MD5.

## Planned

A settings page that discovers the enclosures on your server and generates the YAML configuration from your
choices (enclosure selection, poll intervals, backoff, notifications), with the raw configuration files still
editable in an "Advanced" section. Not implemented yet.

## Building

```
./build.sh 2026.09.20
```

builds `dist/sesmon-ext-<version>.txz` and stamps `sesmon-ext.plg` with the version, the SHA-256 of that
package and `CHANGELOG.md`. Upload the exact built package to a GitHub release whose tag equals the version.
The vendored `sesmon` and `sg3_utils` packages in `packages/` come from the original repository and are
unchanged.

Licensed under the GPL 2, as the original (see `LICENSE`).
