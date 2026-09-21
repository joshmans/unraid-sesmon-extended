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

## The Enclosures page

The page is built from the configuration files on disk, and saving writes them back:

- **Enclosure selection:** the SES enclosures of this server are found the way the daemon finds them
  (`/sys/class/scsi_generic`), with vendor, model, device and SAS address. Tick the ones to monitor. A new
  entry uses the SAS address; an enclosure whose address is shared by several device nodes (dual-pathed) is
  offered by device path instead, with a note that paths are less stable.
- **Settings as choices:** poll interval, attempts, timeouts and back-off are dropdowns with sensible presets and a
  "Custom..." fallback; the rest are checkboxes. Notifications per enclosure: Unraid's notification system, log
  only, or a script of your own from `/etc/sesmon-ext`.
- **Problems are shown where they are:** a configured address that no longer resolves is flagged next to that
  device (also on the Service Settings tab), with a one-click choice among the enclosures that were found. It also
  catches a SAS address written in capitals, which sesmon can never find because it compares in lower case.
- **The files stay yours:** under "Advanced" the configuration files are editable as before. Files are checked with
  `sesmon check` before anything is written, the previous version is kept as `config.yaml.bak`, and whichever
  save came last wins: the selections are always reloaded from the saved file. Settings the page does not know
  are kept when it saves; comments you add by hand are not (raw saves keep everything). A file that uses YAML the
  page cannot represent (anchors, flow lists, ...) switches the selections off and leaves raw editing.
- **Apply:** Save, or Save and restart service; the page shows whether the service is running and can start,
  stop and restart it.

## The Enclosure Devices page

Shows what each enclosure reports, in two views (switch at the top, remembered per browser):

- **Friendly** (default): elements grouped by kind with names ("Drive bay 10", "Cooling fan 3"), status in plain words
  with a tooltip, one Reading column with units (temperatures in °C or °F, your choice), a Notes column that only shows the flags that are set, and no
  clutter from the per-type "overall" entries that enclosures usually do not report. Alerts read the same way.
  A "Warning" is SES's "non-critical": the enclosure says something needs attention but not what, and the tooltip
  says so.
- **Raw**: the values exactly as sesmon reports them (the original columns).

## Tests

```
tests/run.sh
```

runs the PHP tests (only `php-cli` is needed) and, if `node` is installed, the JavaScript ones: the YAML reader and writer, enclosure discovery against real sysfs
data captured from a server with a NetApp JBOD (plus dual-path and address-less enclosures), the configuration
model and the save logic with a stand-in `sesmon`; the friendly view's translation of SES elements against a real snapshot. `tests/harness/serve.sh` serves the real Configuration and Enclosure Devices pages
against that data on http://127.0.0.1:8088 for looking at it in a browser.

## Building

```
./build.sh 2026.09.20
```

builds `dist/sesmon-ext-<version>.txz` and stamps `sesmon-ext.plg` with the version, the SHA-256 of that
package and `CHANGELOG.md`. Upload the exact built package to a GitHub release whose tag equals the version.
The vendored `sesmon` and `sg3_utils` packages in `packages/` come from the original repository and are
unchanged.

Licensed under the GPL 2, as the original (see `LICENSE`).
