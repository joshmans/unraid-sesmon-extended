<?php
/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch). GPL 2.
 *
 * Refreshes the last-known bay map (which drive was in which bay), so a drive that vanishes from the bus can still be
 * named in the alert about it. Run by bay_cache_loop while the service runs.
 */
try {
    require_once __DIR__ . '/../include/sesext_bays.php';
    sesext_all_bays(null, true);
} catch (Throwable $t) {
    error_log('sesmon-ext bay_cache: ' . $t->getMessage());
}
