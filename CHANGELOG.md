# SCSI Enclosure Monitor Extended

## 2026.09.20

First release of the fork of desertwitch's sesmon-unRAID (upstream version 2025.11.29).

- Renamed so it does not clash with the original: the plugin, its files and its service are now called `sesmon-ext`. It cannot be installed together with the original `dwsesmon` plugin; the installer stops and tells you to remove that one first.
- The example enclosure in the default configuration is now disabled. It used to be enabled with a made-up SAS address, so a fresh install with the service switched on failed to start.
- Package integrity is checked with SHA-256 instead of MD5.
