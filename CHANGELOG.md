# SCSI Enclosure Monitor Extended

## 2026.09.25a

- The Plugins page now shows a server icon for this plugin. No other change.

## 2026.09.25

- The plugin no longer ships the original sesmon project's mascot picture. The logo on the plugin's page is gone, and its Settings tile now uses a plain server icon instead of an image. Nothing else changes.

## 2026.09.21a

- The plugin calls itself **SCSI Enclosure Monitor Extended** on the Settings page (menu entry, page header, update check) and on the Dashboard tile's settings link, so it is not mistaken for the original plugin.
- The Dashboard tile of the HBA's virtual enclosure is named after the HBA ("SCSI Enclosure: 430-16i SAS HBA (SAS3416)") instead of "BROADCOM VirtualSES". A description you typed yourself is kept. The Enclosures page proposes the same name when you tick the HBA.

## 2026.09.21

- **Bays are named by their drives.** The Enclosure Devices page has a Drive column: "Drive bay 10" is disk22 and disk23, with model, size and temperature, each disk linked to its Unraid page. An enclosure reports the SAS address of the drive in every bay; the plugin matches it against the drives the kernel sees and the disks Unraid knows. Temperatures come from Unraid's own state file, so no disk is woken up (a spun down disk reads "standby"), and they follow the °C / °F toggle.
- **One drive, two disks.** A dual-actuator drive (for example Seagate ST14000NM0001) appears as two disks in one bay: the bay shows the physical drive once with each disk under it. When such a bay is reported as a Warning, the tooltip says that some shelves flag this, and that the enclosure does not say why.
- **The HBA's virtual enclosure (Broadcom VirtualSES) is shown as what it is:** the HBA's ports, not a chassis. Ports are listed in port order as "Port 12" with the drive on them (or "No drive"), the unused slots are hidden, ports cabled to another enclosure say so ("cabled to NETAPP DS424IOM12A"), and the HBA's model and firmware version are shown, on the Enclosure Devices page and on the Enclosures page.
- **Alerts name the drives.** The notification for a bay says which disks are in it ("Affected drives - drive bay 10: disk22 + disk23 (SEAGATE ST14000NM0001, one drive presenting 2 disks)"), also for a drive that has just dropped off the bus: the last mapping seen is kept in memory while the service runs and used, marked "last known". The Alert tables show the drive too. The shipped notify.sh does this; an installed notify.sh that you never edited is replaced by the new one on install, one you edited is left alone (add the lines yourself, see the shipped file).
- Elements an enclosure reports no status for are hidden in the Friendly view (Raw still shows everything).
- A quiet line on the Enclosure Devices page points to Disk Location Next for a physical tray map (it opens the plugin's page if it is installed, otherwise its repository). It can be dismissed.

## 2026.09.20

First release of the fork of desertwitch's sesmon-unRAID (upstream version 2025.11.29).

- Renamed so it does not clash with the original: the plugin, its files and its service are now called `sesmon-ext`. It cannot be installed together with the original `dwsesmon` plugin; the installer stops and tells you to remove that one first.
- The default configuration no longer contains example devices. The original shipped three, one of them enabled with a made-up SAS address, so a fresh install with the service switched on failed to start with "SAS address ... is not resolvable". A fresh install now has nothing configured and offers the enclosures it finds; until one is chosen the service has nothing to monitor and the Service Settings tab says so.
- Package integrity is checked with SHA-256 instead of MD5.
- New Enclosures page: pick the enclosures found on the server instead of typing SAS addresses, and choose poll, back-off and notification settings from dropdowns. The sesmon configuration is generated from the choices, and the configuration files can still be edited directly under Advanced (checked with sesmon before saving, previous version kept as config.yaml.bak).
- A configured enclosure that cannot be found is reported next to the device, with a one-click choice among the enclosures that were found, and on the Service Settings tab. SAS addresses in capital letters, which sesmon can never resolve, are recognised and written in lower case.
- The Enclosure Devices page has a Friendly view (the default) next to the original Raw one, remembered per browser. It groups the elements by kind (drive bays, power supplies, fans, temperatures, ...), names them ("Drive bay 10"), shows status in plain words with a tooltip explaining what it means ("Noncritical" reads Warning, an empty bay reads Empty), puts readings in one column with units (42 °C, 12.18 V, 3.43 A; temperatures can be switched to °F, remembered per browser), shows the failure-predicted / disabled / swapped flags only when one is set, and hides the per-type "overall" entries that an enclosure does not report. Alerts read the same way (OK to Critical, 41 °C to 58 °C).
- The Dashboard tile says Warning and Failed instead of Non-Critical and Unrecoverable, counts "Components" (most of what it counts are drive bays, not sensors) and explains each heading on hover.
- "Send test notification" on the Enclosures page sends a clearly marked test message through a device's notification path, to check that alerts get through. It asks first and can only run the built-in script or a script of the configuration folder.
- "Restore previous version" under Advanced puts back the version a file had before its last save; restoring twice undoes it.
- The service can be started, stopped and restarted from the Enclosures page, which also shows whether it is running.
- The old file editor could read and write any file on the server; the new one is limited to the plugin's configuration folder.
- Upgrades remove the pages and scripts that a newer version no longer ships. This happens in the package's own install script, after the download has been verified, so a download that fails leaves the installed version working.
