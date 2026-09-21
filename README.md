# SCSI Enclosure Monitor Extended for Unraid

Monitors SES-capable SCSI enclosures (JBODs, disk shelves) and raises an Unraid notification when something in them
changes: a fan or power supply fails, a temperature climbs, a drive bay reports a fault. It is a fork of
[desertwitch/sesmon-unRAID](https://github.com/desertwitch/sesmon-unRAID), the plugin for
[sesmon](https://github.com/desertwitch/sesmon), the daemon that does the monitoring. All credit for the daemon and
the original plugin goes to desertwitch.

What this fork adds: you choose the enclosures from the ones found on your server (no typing SAS addresses into
YAML), settings are dropdowns and checkboxes that generate the configuration, and the Enclosure Devices page shows
what each enclosure reports in plain words. The configuration files stay editable. Details are [below](#the-pages).

## Install

**Plugin URL:** `https://raw.githubusercontent.com/joshmans/unraid-sesmon-extended/main/sesmon-ext.plg`
(Plugins tab, Install Plugin).

- **Needs:** an enclosure that supports SES, attached through a SAS HBA. The plugin installs sesmon 0.1.1 and
  `sg3_utils` (for `sg_ses`) itself.
- **Tested on:** Unraid 7.4.0-beta.2, with a NetApp DS424IOM12A shelf. The plugin declares Unraid 7.0 as its minimum,
  as the original does, but other versions have not been verified.
- **It cannot be installed together with the original `dwsesmon` plugin** (both use the same `sesmon` package and
  service). The installer stops and tells you to remove that one first.

## Getting started

1. Open **Settings → SCSI Enclosure Monitor → Enclosures**. The enclosures found on the server are listed.
2. Tick **Monitor** on the ones you want. Optionally open **Settings** on a row for the polling and back-off
   options, and pick how alerts are sent (Unraid notifications is the default).
3. Click **Send test notification** on a row to check that alerts reach you, then **Save and restart service**.
4. On the **Service Settings** tab set **Start Service** to **Yes** so it also starts after a reboot. The service
   is off until you do (a new install has nothing to monitor yet, so there is nothing to start).
5. Optionally set **Frontpage Dashboards** to **Yes** (Service Settings tab) to get a tile per enclosure on the
   Unraid dashboard. It is off by default.

## The pages

### Enclosures

The page is built from the configuration files on disk, and saving writes them back.

- **Enclosure selection:** the enclosures are found the way the daemon finds them (`/sys/class/scsi_generic`), with
  vendor, model, device and SAS address. A new entry uses the SAS address; an enclosure whose address is shared by
  several device nodes (dual-pathed) is offered by device path instead, with a note that paths are less stable.
- **Settings as choices:** poll interval, attempts, timeouts and back-off are dropdowns with presets (the daemon's
  defaults are marked) and a "Custom..." fallback; the rest are checkboxes. Alerts per enclosure: Unraid's
  notification system, log only, or a script of your own from `/etc/sesmon-ext`.
- **Problems are shown where they are:** a configured address that no longer resolves is flagged next to that
  device (also on the Service Settings tab) with a one-click choice among the enclosures that were found. It also
  catches a SAS address written in capitals, which sesmon can never find because it compares in lower case.
- **Test notification:** sends a clearly marked test message through a device's alert path, the way sesmon calls it
  (for the default, through Unraid's notification system, so email, push and other agents you set up there are
  included). It asks first, and only ever runs the built-in script or a script of the configuration folder.
- **Save / Save and restart service:** the page shows whether the service is running and can start, stop and
  restart it.
- **Advanced: edit the files directly:** the configuration files (`config.yaml`, `notify.sh`, any script of your own)
  are editable. A file is checked with `sesmon check` before anything is written, and the last save wins: the
  selections above are always reloaded from the saved file. Settings the selections do not cover are kept when the
  page saves; comments you add by hand are not (saving raw text keeps everything). A file that uses YAML the page
  cannot represent (anchors, flow lists, ...) switches the selections off and leaves raw editing.
- **Restore previous version:** puts back what a file was before its last save (checked like any save; the version
  it replaces becomes the new backup, so restoring twice undoes it). The backup is `config.yaml.bak` on the flash.

### Enclosure Devices

What each enclosure reports, in two views (switch at the top, remembered per browser):

- **Friendly** (default): elements grouped by kind with names ("Drive bay 10", "Cooling fan 3"), status in plain
  words with a tooltip, one Reading column with units (temperatures in °C or °F, your choice), a Notes column that
  only shows the flags that are set, and none of the per-type "overall" entries that enclosures usually do not
  report. Alerts (changes sesmon detected) read the same way. A **Warning** is SES's "non-critical": the enclosure
  says something needs attention but not what, and the tooltip says so.
- **Raw:** the values exactly as sesmon reports them, in Celsius.

### Service Settings

Versions of the installed packages, **Start Service**, **Start Failure Notification** (an Unraid notification if the
service cannot start), **Restart Service**, **Frontpage Dashboards**, and **Clear Alerts** / **Clear Devices** (empty
what the Enclosure Devices page and the dashboard show until the next poll). **Reset Config** deletes your
configuration and restores the installed default (no devices): use it only when you mean that. If the saved
configuration cannot start, this tab says why.

### Dashboard tile

With Frontpage Dashboards on, one tile per enclosure with the counts of Alerts, Critical, Warning, Failed and OK
components, and when it was last updated.

## Where things live

| What | Where |
|---|---|
| Your configuration (kept across reboots and uninstalls) | `/boot/config/plugins/sesmon-ext/config/` |
| The copy the service reads | `/etc/sesmon-ext/config.yaml` |
| Previous version of a file | `/boot/config/plugins/sesmon-ext/config/<file>.bak` |
| Plugin settings (Start Service, ...) | `/boot/config/plugins/sesmon-ext/sesmon-ext.cfg` |
| What each enclosure reported (JSON) | `/var/lib/sesmon-ext/<enclosure>/` |
| Log of the daemon | `/var/log/sesmon-ext.log` and the syslog (`sesmon:`) |
| Service script | `/etc/rc.d/rc.sesmon-ext` (`start`, `stop`, `restart`, `status`) |

## Troubleshooting

- **The service will not start.** The Service Settings and Enclosures tabs say why when they can; the syslog has the
  daemon's own message. The usual causes are no enclosure selected (`no devices configured`) and an enclosure that is
  gone or powered off (`SAS address [...] is not resolvable`): pick the right one on the Enclosures page.
- **"SAS address [...] came up for multiple devices" in the log.** Harmless. Drives that show several LUNs on one
  address (dual-actuator drives, for example) trigger it; they are not enclosures and sesmon ignores them.
- **An enclosure with a shared address.** sesmon cannot look such an address up, so the page monitors it by device
  path (`/dev/sgN`), which can change between boots.
- **No notification arrives.** Use Send test notification. If the test reports success and nothing arrives, the fault
  is in Unraid's notification setup (Settings, Notification Settings), not in the plugin.

## Coming from the original plugin

Uninstall `dwsesmon` first. Do not copy its `config.yaml` over unchanged: it refers to `/var/lib/sesmon` and
`/etc/sesmon/notify.sh`, which are `/var/lib/sesmon-ext` and `/etc/sesmon-ext/notify.sh` here. Choose your
enclosures again on the Enclosures page instead. (Your old file stays in `/boot/config/plugins/dwsesmon/config/`.)

## Differences from the original

- Everything on the system is named `sesmon-ext` (plugin folder, service, config and log paths), so it never clashes
  with the original.
- The default configuration has no example devices. The original ships three, one of them enabled with a made-up SAS
  address, so a fresh install with the service switched on fails with
  `SAS address [0x500a098012345678] is not resolvable (not found)`.
- The file editor is limited to the plugin's configuration folder; the original's could read and write any file.
- Upgrades remove pages and scripts that a newer version no longer ships instead of leaving them behind.
- Packages are verified with SHA-256 instead of MD5.

## Development

```
tests/run.sh
```

runs the PHP tests (only `php-cli` is needed) and, if `node` is installed, the JavaScript ones. They cover the YAML
reader and writer, enclosure discovery against real sysfs data captured from a server with a NetApp JBOD (plus
dual-path and address-less enclosures), the configuration model, the save, restore and notification logic (with a
stand-in `sesmon`), the shape of a fresh install, and the Friendly view's translation of SES elements against a real
snapshot.

`tests/harness/serve.sh` serves the real Enclosures and Enclosure Devices pages against that data on
http://127.0.0.1:8088 for looking at them in a browser (`CONFIG=fresh` shows what a fresh install gets, `/devices`
the Enclosure Devices page, `?theme=black` the dark theme).

```
./build.sh 2026.09.20
```

builds `dist/sesmon-ext-<version>.txz` and stamps `sesmon-ext.plg` with the version, the SHA-256 of that package and
`CHANGELOG.md`; `NOSTAMP=1 ./build.sh <version>` only builds, for installing on a test server by hand. A release is
the stamped plg on `main` plus the exact built package uploaded to a GitHub release whose tag equals the version.
The vendored `sesmon` and `sg3_utils` packages in `packages/` come from the original repository and are unchanged.

## Credits and license

The plugin code is licensed under the GPL 2 (`LICENSE`), as the original. sesmon is by desertwitch (MIT). The
original plugin, this one's starting point, is by desertwitch as well.
