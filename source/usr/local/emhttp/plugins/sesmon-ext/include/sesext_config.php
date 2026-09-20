<?
/* Copyright Derek Macias (parts of code from NUT package)
 * Copyright macester (parts of code from NUT package)
 * Copyright gfjardim (parts of code from NUT package)
 * Copyright SimonF (parts of code from NUT package)
 * Copyright Dan Landon (parts of code from Web GUI)
 * Copyright Bergware International (parts of code from Web GUI)
 * Copyright Lime Technology (any and all other parts of Unraid)
 *
 * Copyright desertwitch (as author and maintainer of this file)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 */
$sesext_cfg = file_exists("/boot/config/plugins/sesmon-ext/sesmon-ext.cfg") ? parse_ini_file("/boot/config/plugins/sesmon-ext/sesmon-ext.cfg") : [];

$sesext_service = trim(isset($sesext_cfg['SERVICE']) ? htmlspecialchars($sesext_cfg['SERVICE']) : 'disable');
$sesext_dashboards = trim(isset($sesext_cfg['DASHBOARDS']) ? htmlspecialchars($sesext_cfg['DASHBOARDS']) : 'disable');
$sesext_start_notify = trim(isset($sesext_cfg['STARTNOTIFY']) ? htmlspecialchars($sesext_cfg['STARTNOTIFY']) : 'disable');

$sesext_running = !empty(shell_exec("pgrep -x sesmon 2>/dev/null"));
$sesext_version = htmlspecialchars(trim(shell_exec("sesmon --version") ?? "n/a"));

$sgout = []; $sgcode = -1; exec("sg_ses --version 2>&1", $sgout, $sgcode);
$sesext_sg_version = htmlspecialchars(trim($sgcode === 0 && !empty($sgout) ? $sgout[0] : 'n/a'));

$sesext_backend = htmlspecialchars(trim(shell_exec("find /var/log/packages/ -type f -iname 'sesmon-[0-9]*' -printf '%f\n' 2> /dev/null") ?? "n/a"));
$sesext_sg_backend = htmlspecialchars(trim(shell_exec("find /var/log/packages/ -type f -iname 'sg3_utils-*' -printf '%f\n' 2> /dev/null") ?? "n/a"));
?>
