<?
/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * Which drive is in which bay. An enclosure reports, for every bay, the SAS address of the drive in it (SES
 * "additional element status"); the kernel knows the address of every drive (sysfs) and Unraid knows which disk
 * a drive is (its state file). Matching the three names a bay by its drive: "Drive bay 10" is disk22 and disk23,
 * two disks on one Seagate. Nothing here touches a disk: only sysfs, Unraid's own state file and the JSON that
 * sesmon writes, so no drive is woken up.
 *
 * A drive that has failed may vanish from sysfs, which is when the name is wanted most. The last mapping seen is
 * therefore kept (in RAM, below the sesmon-ext folder) and used, marked as last known, for a bay that still
 * reports a device but has no drive any more.
 */
require_once __DIR__ . '/sesext_discover.php';

const SESEXT_SLOT_TYPES = [1, 23]; // SES element types "Device slot" and "Array device slot"
const SESEXT_DEVICE_URL = '/Main/Device?name='; // Unraid's page of a disk

/* ------------------------------------------------------------- basics */

/** A SAS address as 0x + 16 lower case hex digits, from what sg_ses's JSON holds (an int, or a decimal string when huge); '' for none/zero. */
function sesext_addr_hex($v) {
    if (is_string($v) && preg_match('/^0x[0-9a-fA-F]+$/', $v)) { $v = strtolower($v); return $v === '0x0000000000000000' ? '' : $v; }
    if (is_int($v)) { return $v === 0 ? '' : sprintf('0x%016x', $v); }
    if (!is_string($v) || $v === '' || !ctype_digit($v) || ltrim($v, '0') === '') { return ''; }
    $hex = ''; $dec = ltrim($v, '0');
    while ($dec !== '') { // long division by 16, no bignum extension needed
        $rem = 0; $next = '';
        foreach (str_split($dec) as $d) { $cur = $rem * 10 + (int)$d; $next .= intdiv($cur, 16); $rem = $cur % 16; }
        $hex = dechex($rem) . $hex; $dec = ltrim($next, '0');
    }
    return '0x' . str_pad($hex, 16, '0', STR_PAD_LEFT);
}

/** "14 TB", "512 GB": capacity in the units drives are sold in (powers of ten). */
function sesext_size_text($bytes) {
    if (!$bytes) { return ''; }
    if ($bytes >= 1e12) { return rtrim(rtrim(number_format($bytes / 1e12, 1, '.', ''), '0'), '.') . ' TB'; }
    return number_format($bytes / 1e9, 0, '.', '') . ' GB';
}

/* ---------------------------------------------------- Unraid and sysfs */

/** Unraid's state files are INI with quoted sections and values: ["disk1"] / name="disk1". */
function sesext_ini_sections($file) {
    $out = []; $sec = null;
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';') { continue; }
        if (preg_match('/^\[\s*"?([^"\]]*)"?\s*\]$/', $line, $m)) { $sec = $m[1]; $out[$sec] = []; continue; }
        if ($sec !== null && ($p = strpos($line, '=')) !== false) { $out[$sec][trim(substr($line, 0, $p))] = trim(substr($line, $p + 1), " \t\""); }
    }
    return $out;
}

/** What Unraid knows about its disks, by block device name: ['sdl' => [name, role, temp_c, standby, ident, status]]. */
function sesext_unraid_disks($root = null) {
    $root = $root ?? sesext_root();
    $out = [];
    foreach (sesext_ini_sections($root . '/var/local/emhttp/disks.ini') as $sec => $d) {
        $dev = $d['device'] ?? '';
        if ($dev === '') { continue; } // a disk that is not present
        $temp = $d['temp'] ?? '';
        $out[$dev] = [
            'name' => ($d['name'] ?? '') !== '' ? $d['name'] : $sec,
            'role' => strtolower($d['type'] ?? ''),
            'temp_c' => ctype_digit($temp) ? (int)$temp : null, // "*" while the disk is spun down: nothing is read to find out
            'standby' => ($d['spundown'] ?? '0') === '1',
            'ident' => $d['id'] ?? '',
            'status' => $d['status'] ?? '',
        ];
    }
    return $out;
}

/** block device => an identifying name (model and serial) from /dev/disk/by-id, for disks Unraid does not manage. */
function sesext_by_id($root = null) {
    $root = $root ?? sesext_root();
    $out = [];
    foreach (glob($root . '/dev/disk/by-id/*') ?: [] as $l) {
        $name = basename($l);
        if (preg_match('/^(wwn|nvme-eui|usb-)|-part\d+$/', $name)) { continue; }
        $target = @readlink($l);
        if ($target === false) { continue; }
        $out[basename($target)] = $out[basename($target)] ?? preg_replace('/^(ata|scsi)-/', '', $name);
    }
    return $out;
}

/**
 * Every disk the kernel exposes as a SCSI generic node, one entry per logical unit (a dual-actuator drive shows up
 * twice under one address): ['byAddress' => [address => [unit, ...]], 'units' => [sg => unit]].
 */
function sesext_drives($root = null, $disc = null) {
    $root = $root ?? sesext_root();
    $disc = $disc ?? sesext_discover($root);
    $unraid = sesext_unraid_disks($root); $byId = sesext_by_id($root);
    $units = []; $by = [];
    foreach ($disc['nodes'] as $n) {
        if ($n['type'] !== 0 || $n['block'] === '') { continue; }
        $u = $unraid[$n['block']] ?? null;
        $title = trim(preg_replace('/\s+/', ' ', ($n['vendor'] === 'ATA' ? '' : $n['vendor'] . ' ') . $n['model']));
        $unit = [
            'sg' => basename($n['dev']), 'dev' => $n['block'], 'address' => $n['address'], 'title' => $title, 'ssd' => $n['ssd'],
            'size' => sesext_size_text($n['bytes']), 'bytes' => $n['bytes'],
            'name' => $u ? $u['name'] : $n['block'], 'role' => $u ? ($u['role'] ?: 'disk') : 'unassigned', 'unraid' => $u !== null,
            'temp_c' => $u['temp_c'] ?? null, 'standby' => $u['standby'] ?? false,
            'ident' => $u ? $u['ident'] : ($byId[$n['block']] ?? ''),
            'url' => $u ? SESEXT_DEVICE_URL . rawurlencode($u['name']) : null,
        ];
        $units[$unit['sg']] = $unit;
        if ($n['address'] !== null) { $by[$n['address']][] = $unit; }
    }
    return ['byAddress' => $by, 'units' => $units];
}

/* ------------------------------------------------------------ SES data */

/**
 * The drive slots of an enclosure from sg_ses's joined JSON (or from sesmon's snapshot, which wraps it in "raw"):
 * ['1#10' => [type, number, slot, status, status_desc, phys => [[address, attached], ...]]].
 */
function sesext_ses_slots($ses) {
    if (isset($ses['raw'])) { $ses = $ses['raw']; }
    $out = [];
    foreach (($ses['join_of_diagnostic_pages']['element_list'] ?? []) as $e) {
        $type = $e['element_type']['i'] ?? null; $number = $e['element_number'] ?? -1;
        if (!in_array($type, SESEXT_SLOT_TYPES, true) || $number < 0) { continue; }
        $ad = $e['additional_element_status_descriptor'] ?? [];
        $phys = [];
        foreach (($ad['phy_descriptor_list'] ?? []) as $ph) {
            $phys[] = ['address' => sesext_addr_hex($ph['sas_address'] ?? 0), 'attached' => sesext_addr_hex($ph['attached_sas_address'] ?? 0)];
        }
        $st = $e['status_descriptor']['status'] ?? [];
        $out["$type#$number"] = ['type' => $type, 'number' => $number, 'slot' => $ad['device_slot_number'] ?? null,
                                 'status' => $st['i'] ?? null, 'status_desc' => $st['meaning'] ?? '', 'phys' => $phys];
    }
    return $out;
}

/** The drive fields the page and the alerts use. The temperature and standby flag are for display only: the cache drops them (they change every poll). */
function sesext_drive_lite($u) {
    return array_intersect_key($u, array_flip(['sg', 'dev', 'name', 'role', 'unraid', 'title', 'size', 'bytes', 'ssd', 'ident', 'url', 'temp_c', 'standby']));
}

/**
 * Name the bays of one enclosure by their drives. $ctx (all optional): root, disc, drives, cache (see the cache
 * functions). Returns ['address', 'kind', 'label', 'hba', 'bays' => ['1#10' => bay]] where a bay is
 * [id, slot, label, status, status_desc, drives => [...], multi, last_known, linked_to].
 */
function sesext_bays($ses, $address, $ctx = []) {
    $root = $ctx['root'] ?? sesext_root();
    $disc = $ctx['disc'] ?? sesext_discover($root);
    $drives = $ctx['drives'] ?? sesext_drives($root, $disc);
    $cache = $ctx['cache'][$address] ?? [];
    $enc = null;
    foreach ($disc['enclosures'] as $e) { if ($e['address'] === $address) { $enc = $e; } }
    $kind = $enc['kind'] ?? 'shelf';

    $bays = [];
    foreach (sesext_ses_slots($ses) as $id => $s) {
        $slot = $s['slot'] ?? $s['number'];
        $units = []; $linked = null;
        foreach ($s['phys'] as $ph) {
            foreach ($drives['byAddress'][$ph['address']] ?? [] as $u) { $units[$u['sg']] = $u; }
            if ($kind === 'hba' && $ph['address'] !== '') { // a port without a drive lists what is at the far end of its cable: another enclosure
                foreach ($disc['enclosures'] as $e) {
                    if ($e['address'] !== $address && $e['address'] !== null && substr($e['address'], 0, 17) === substr($ph['address'], 0, 17)) { $linked = $e['label']; }
                }
            }
        }
        $list = array_map('sesext_drive_lite', array_values($units));
        $lastKnown = null;
        // the bay still reports a device (not "not installed"/"not reported") but nothing in sysfs has its address: use what was seen last
        if (!$list && !in_array($s['status'], [0, 5, null], true) && isset($cache[$id]['drives'])) {
            $list = $cache[$id]['drives']; $lastKnown = $cache[$id]['at'] ?? null;
        }
        $models = array_unique(array_column($list, 'title'));
        $multi = count($list) > 1 && count($models) === 1; // one physical drive that presents several disks (dual-actuator)
        $bays[$id] = [
            'id' => $id, 'slot' => $slot, 'label' => ($kind === 'hba' ? 'Port ' : 'Drive bay ') . $slot,
            'status' => $s['status'], 'status_desc' => $s['status_desc'], 'drives' => $list,
            'multi' => $multi, 'total_size' => $multi ? sesext_size_text(array_sum(array_column($list, 'bytes'))) : '', // the physical drive's capacity
            'last_known' => $lastKnown, 'linked_to' => $linked,
        ];
    }
    return ['address' => $address, 'kind' => $kind, 'label' => $enc['label'] ?? '', 'hba' => $enc['hba'] ?? null, 'bays' => $bays];
}

/* --------------------------------------------------------------- cache */

function sesext_bay_cache_path($root = null) { return ($root ?? sesext_root()) . '/var/lib/sesmon-ext/.cache/bays.json'; }

function sesext_bay_cache_load($root = null) {
    $j = @json_decode((string)@file_get_contents(sesext_bay_cache_path($root)), true);
    return is_array($j) ? $j : [];
}

/** Remember the drives seen in each bay (a bay that is empty now forgets its drive: nobody should be blamed for an old one). */
function sesext_bay_cache_update($cache, $info, $now = null) {
    $now = $now ?? time(); $addr = $info['address'];
    foreach ($info['bays'] as $id => $b) {
        if ($b['drives'] && $b['last_known'] === null) {
            $keep = array_map(fn($u) => array_diff_key($u, ['temp_c' => 1, 'standby' => 1]), $b['drives']);
            $old = $cache[$addr][$id] ?? null;
            if (!$old || $old['drives'] !== $keep) { $cache[$addr][$id] = ['at' => $now, 'drives' => $keep]; }
        } elseif (in_array($b['status'], [0, 5], true)) {
            unset($cache[$addr][$id]);
        }
    }
    return $cache;
}

function sesext_bay_cache_save($cache, $root = null) {
    $p = sesext_bay_cache_path($root);
    if (!is_dir(dirname($p))) { @mkdir(dirname($p), 0755, true); }
    return @file_put_contents($p, json_encode($cache, JSON_UNESCAPED_SLASHES)) !== false;
}

/* ------------------------------------------- sesmon's folders, the page */

/**
 * The bays of every enclosure sesmon monitors (its folders below /var/lib/sesmon-ext), keyed by folder name. The cache
 * is refreshed as a side effect ($persist), so it stays current whenever anybody looks and while the service runs.
 */
function sesext_all_bays($root = null, $persist = true) {
    $root = $root ?? sesext_root();
    $disc = sesext_discover($root); $drives = sesext_drives($root, $disc);
    $cache = sesext_bay_cache_load($root); $newCache = $cache; $out = [];
    foreach (glob($root . '/var/lib/sesmon-ext/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $parsed = @json_decode((string)@file_get_contents("$dir/current_parsed.json"), true);
        $snap = @json_decode((string)@file_get_contents("$dir/current.json"), true, 512, JSON_BIGINT_AS_STRING);
        $addr = strtolower($parsed['device']['address'] ?? '');
        if (!is_array($snap) || $addr === '') { continue; }
        $info = sesext_bays($snap, $addr, ['root' => $root, 'disc' => $disc, 'drives' => $drives, 'cache' => $cache]);
        $info['path'] = $parsed['device']['path'] ?? '';
        $out[basename($dir)] = $info;
        $newCache = sesext_bay_cache_update($newCache, $info);
    }
    if ($persist && $newCache !== $cache) { sesext_bay_cache_save($newCache, $root); }
    return $out;
}

/* -------------------------------------------------------------- alerts */

/**
 * One line naming the drives in the bays that a sesmon change report touches, for the alert text; '' when the report
 * has no bay in it or the enclosure is unknown. $reportJson is the JSON sesmon passes to a notification script.
 */
function sesext_alert_context($address, $devicePath, $reportJson, $root = null) {
    $report = @json_decode((string)$reportJson, true);
    if (!is_array($report) || empty($report['changes'])) { return ''; }
    $address = strtolower((string)$address);
    $mine = null;
    foreach (sesext_all_bays($root, true) as $info) {
        if (($address !== '' && $info['address'] === $address) || ($devicePath !== '' && $info['path'] === $devicePath)) { $mine = $info; }
    }
    if (!$mine) { return ''; }
    $parts = [];
    foreach ($report['changes'] as $c) {
        if (!in_array($c['element_type'] ?? null, SESEXT_SLOT_TYPES, true) || !isset($mine['bays'][$c['id'] ?? ''])) { continue; }
        $b = $mine['bays'][$c['id']];
        $what = strtolower($b['label']);
        if (!$b['drives']) { $parts[$b['id']] = "$what: no drive known"; continue; }
        $names = implode(' + ', array_column($b['drives'], 'name'));
        $titles = array_unique(array_column($b['drives'], 'title'));
        $text = $b['multi'] ? "$what: $names (" . $titles[array_key_first($titles)] . ', one drive presenting ' . count($b['drives']) . ' disks)'
                            : "$what: " . implode(', ', array_map(fn($u) => $u['name'] . ($u['title'] !== '' ? ' (' . $u['title'] . ')' : ''), $b['drives']));
        $parts[$b['id']] = $text . ($b['last_known'] !== null ? ' [last known]' : '');
    }
    return $parts ? 'Affected drives - ' . implode('; ', $parts) : '';
}
?>
