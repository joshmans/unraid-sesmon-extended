# SCSI Enclosure Monitor Extended for Unraid

Monitors SES-capable SCSI enclosures (JBODs, disk shelves) and raises an Unraid notification when something in them
changes: a fan or power supply fails, a temperature climbs, a drive bay reports a fault. It is a fork of
[desertwitch/sesmon-unRAID](https://github.com/desertwitch/sesmon-unRAID), the plugin for
[sesmon](https://github.com/desertwitch/sesmon), the daemon that does the monitoring. All credit for the daemon and
the original plugin goes to desertwitch.

What this fork adds: you choose the enclosures from the ones found on your server (no typing SAS addresses into
YAML), settings are dropdowns and checkboxes that generate the configuration, the Enclosure Devices page shows what each
enclosure reports in plain words, and bays are named by the drive in them ("Drive bay 10 is disk22 and disk23"), also in
the alerts. The configuration files stay editable. Details are [below](#the-pages).

## Install

**Plugin URL:** `https://raw.githubusercontent.com/joshmans/unraid-sesmon-extended/main/sesmon-ext.plg`
(Plugins tab, Install Plugin).

- **Needs:** an enclosure that supports SES, attached through a SAS HBA. The plugin installs sesmon 0.1.1 and
  `sg3_utils` (for `sg_ses`) itself.
- **Tested on:** Unraid 7.2 and newer, with a NetApp DS424IOM12A shelf and a Broadcom 430-16i (SAS3416) HBA. The plugin
  declares Unraid 7.0 as its minimum, as the original does, but versions before 7.2 and other shelves and HBAs have not
  been verified: please report what you see.
- **It cannot be installed together with the original `dwsesmon` plugin** (both use the same `sesmon` package and
  service). The installer stops and tells you to remove that one first.

## Getting started

1. Open **Settings → SCSI Enclosure Monitor Extended → Enclosures**. The enclosures found on the server are listed.
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
- **Alerts name the drives:** the shipped `notify.sh` puts a line in front of sesmon's message that names the disks in the
  affected bays, for example `Affected drives - drive bay 10: disk22 + disk23 (SEAGATE ST14000NM0001, one drive presenting
  2 disks)`. For a drive that has just dropped off the bus the last mapping seen is used, marked `[last known]`; it is kept in
  memory (`/var/lib/sesmon-ext/.cache`) and refreshed every five minutes while the service runs. An installed `notify.sh`
  that you never edited is replaced by the current one when the plugin is installed or upgraded; one you edited is left as
  it is (the added lines are marked in the shipped file, so you can copy them).
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
  only shows the flags that are set, and none of the elements an enclosure reports no status for (the per-type
  "overall" entries, the unused slots of a virtual enclosure). Alerts (changes sesmon detected) read the same way. A **Warning** is SES's "non-critical": the enclosure
  says something needs attention but not what, and the tooltip says so.
- **Raw:** the values exactly as sesmon reports them, in Celsius.

**Which drive is in which bay.** In the Friendly view a Drive column names each bay by its drive: the Unraid disk
(linked to its page), model, size and temperature. An enclosure reports the SAS address of the drive in every bay;
the plugin matches that against the drives the kernel sees (`/sys/class/scsi_generic`) and the disks Unraid knows
(`/var/local/emhttp/disks.ini`). Temperatures come from that state file, so no disk is woken up: a spun down disk reads
"standby", and drives Unraid does not manage (for example an SSD on an HBA port) show no temperature.

A dual-actuator drive (Seagate ST14000NM0001 and similar) shows up as two disks in one bay. The bay then shows the
physical drive once, with each disk under it, and if the shelf reports that bay as a Warning the tooltip says that some
shelves flag this and that the enclosure does not say why. (A shelf that flags such a drive permanently also means a
real change in that bay is only visible as a change: sesmon alerts on changes.)

**The HBA's virtual enclosure** (Broadcom "VirtualSES") is the HBA reporting its own ports, not a chassis. It has no
fans, power supplies or temperatures. It is shown as "Port N" with the drive on it (or "No drive"), ports cabled to
another enclosure say so, and the HBA's model and firmware version are shown. Monitoring it alerts you when a drive drops
off an HBA port. To see it, tick it on the Enclosures page like any other enclosure.

If a shelf does not report the address of the drive in each bay (SES "additional element status", which most SES-2
shelves do), its bays are simply listed without a drive.

**Physical layout.** SES carries no geometry (which bay is where on the front of the shelf), so this page lists bays but
does not draw them. For a tray map use [Disk Location Next](https://github.com/joshmans/unraid-disklocation-next); the page
links to it (to its own page if it is installed, and the note can be dismissed).

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
| Which drive was in which bay (last known) | `/var/lib/sesmon-ext/.cache/bays.json` (memory, gone after a reboot) |
| Service script | `/etc/rc.d/rc.sesmon-ext` (`start`, `stop`, `restart`, `status`) |

## Troubleshooting

- **The service will not start.** The Service Settings and Enclosures tabs say why when they can; the syslog has the
  daemon's own message. The usual causes are no enclosure selected (`no devices configured`) and an enclosure that is
  gone or powered off (`SAS address [...] is not resolvable`): pick the right one on the Enclosures page.
- **"SAS address [...] came up for multiple devices" in the log.** Harmless. Drives that show several LUNs on one
  address (dual-actuator drives, for example) trigger it; they are not enclosures and sesmon ignores them.
- **An enclosure with a shared address.** sesmon cannot look such an address up, so the page monitors it by device
  path (`/dev/sgN`), which can change between boots.
- **A bay shows no drive, or "last known".** The drive's SAS address is not (or no longer) among the devices the kernel
  sees: the drive is missing, failed or dropped off the bus. While the bay still reports a device the plugin names the
  drive it last saw there and marks it "last known" (kept in memory: after a reboot there is nothing to remember until the
  service has been running for a few minutes).
- **A drive shows no temperature.** Temperatures come from Unraid's state file, which has one only for disks Unraid manages
  and only while they are spun up: a spun down disk reads "standby" (reading it would wake it), and a drive that is not in the
  array or a pool (an SSD on an HBA port, for example) has none.
- **Many "No drive" ports on the HBA's enclosure.** Normal: it lists all of the HBA's ports, and only the ones with a drive
  on them show one. Ports cabled to another enclosure say so.
- **The Warning bays.** A shelf that reports a bay as a Warning without saying why gives nothing more to show. When the bay
  holds a drive that presents two disks, the tooltip says that some shelves flag this.
- **No notification arrives.** Use Send test notification. If the test reports success and nothing arrives, the fault
  is in Unraid's notification setup (Settings, Notification Settings), not in the plugin.

## Coming from the original plugin

Uninstall `dwsesmon` first. Do not copy its `config.yaml` over unchanged: it refers to `/var/lib/sesmon` and
`/etc/sesmon/notify.sh`, which are `/var/lib/sesmon-ext` and `/etc/sesmon-ext/notify.sh` here. Choose your
enclosures again on the Enclosures page instead. (Your old file stays in `/boot/config/plugins/dwsesmon/config/`.)

## Differences from the original

- Everything on the system is named `sesmon-ext` (plugin folder, service, config and log paths), so it never clashes
  with the original.
- **Not in the original:** the Enclosures page (choose enclosures and settings instead of writing YAML), Send test
  notification, Restore previous version, the Friendly view with its Fahrenheit option, bays named by their drives, the
  virtual enclosure of an HBA shown as ports with the HBA's firmware, and alerts that name the disks in the affected bays.
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
stand-in `sesmon`), the shape of a fresh install, which drive is in which bay (the SES data of a NetApp shelf and of an
HBA's virtual enclosure matched against real sysfs and Unraid state), the whole alert path through `notify.sh` with a
stub for Unraid's notify command, the installer's rule for replacing `notify.sh`, and the Friendly view's translation
of SES elements. The fixtures in `tests/fixtures/catan` are captures from a real server with serial numbers removed;
`tests/fixtures/make-bays-fixture.php` regenerates `bays.json` after a change to the bay logic.

`tests/harness/serve.sh` serves the real Enclosures and Enclosure Devices pages against that data on
http://127.0.0.1:8088 for looking at them in a browser (`CONFIG=fresh` shows what a fresh install gets, `/devices`
the Enclosure Devices page, `?theme=black` the dark theme).

```
./build.sh 2026.09.21
```

builds `dist/sesmon-ext-<version>.txz` and stamps `sesmon-ext.plg` with the version, the SHA-256 of that package and
`CHANGELOG.md`; `NOSTAMP=1 ./build.sh <version>` only builds, for installing on a test server by hand. A release is
the stamped plg on `main` plus the exact built package uploaded to a GitHub release whose tag equals the version.
The vendored `sesmon` and `sg3_utils` packages in `packages/` come from the original repository and are unchanged.

## Credits and license

The plugin code is licensed under the GPL 2 (`LICENSE`), as the original. sesmon is by desertwitch (MIT). The
original plugin, this one's starting point, is by desertwitch as well.
