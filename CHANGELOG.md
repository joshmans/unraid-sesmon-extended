# SCSI Enclosure Monitor Extended

## 2026.09.20

First release of the fork of desertwitch's sesmon-unRAID (upstream version 2025.11.29).

- Renamed so it does not clash with the original: the plugin, its files and its service are now called `sesmon-ext`. It cannot be installed together with the original `dwsesmon` plugin; the installer stops and tells you to remove that one first.
- The example enclosure in the default configuration is now disabled. It used to be enabled with a made-up SAS address, so a fresh install with the service switched on failed to start.
- Package integrity is checked with SHA-256 instead of MD5.
- New Enclosures page: pick the enclosures found on the server instead of typing SAS addresses, and choose poll, back-off and notification settings from dropdowns. The sesmon configuration is generated from the choices, and the configuration files can still be edited directly under Advanced (checked with sesmon before saving, previous version kept as config.yaml.bak).
- A configured enclosure that cannot be found is reported next to the device, with a one-click choice among the enclosures that were found, and on the Service Settings tab. SAS addresses in capital letters, which sesmon can never resolve, are recognised and written in lower case.
- The Enclosure Devices page has a Friendly view (the default) next to the original Raw one, remembered per browser. It groups the elements by kind (drive bays, power supplies, fans, temperatures, ...), names them ("Drive bay 10"), shows status in plain words with a tooltip explaining what it means ("Noncritical" reads Warning, an empty bay reads Empty), puts readings in one column with units (42 °C, 12.18 V, 3.43 A; temperatures can be switched to °F, remembered per browser), shows the failure-predicted / disabled / swapped flags only when one is set, and hides the per-type "overall" entries that an enclosure does not report. Alerts read the same way (OK to Critical, 41 °C to 58 °C).
- The service can be started, stopped and restarted from the Enclosures page, which also shows whether it is running.
- The old file editor could read and write any file on the server; the new one is limited to the plugin's configuration folder.
- Upgrades start from an empty web folder, so pages and scripts dropped by a newer version are not left behind.
