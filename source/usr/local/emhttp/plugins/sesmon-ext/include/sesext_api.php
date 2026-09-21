<?
/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * The web endpoint of the Configuration page. Reading ("state", "read") works with GET; everything that
 * changes something needs POST (which Unraid protects with its CSRF token).
 */
require_once __DIR__ . '/sesext_ops.php';

header('Content-Type: application/json');
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$readOnly = in_array($action, ['state', 'status', 'read'], true);
if (!$readOnly && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'errors' => ['This action needs POST']]);
    exit;
}
try {
    echo json_encode(sesext_handle($action, $_POST + $_GET), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $t) {
    error_log('sesmon-ext: ' . $t);
    http_response_code(500);
    echo json_encode(['ok' => false, 'errors' => ['Internal error: ' . $t->getMessage()]]);
}
?>
