<?
/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * The bays of every monitored enclosure, named by their drives, for the Enclosure Devices page (see sesext_bays.php).
 * Read only apart from refreshing the last-known cache.
 */
require_once __DIR__ . '/sesext_bays.php';

header('Content-Type: application/json');
try {
    echo json_encode(sesext_all_bays(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $t) {
    error_log('sesmon-ext: ' . $t);
    http_response_code(500);
    echo json_encode(['error' => $t->getMessage()]);
}
?>
