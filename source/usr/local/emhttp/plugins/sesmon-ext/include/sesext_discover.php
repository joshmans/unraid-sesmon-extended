<?
/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * Finds the SES-capable enclosures of this server and says whether each configured
 * device would resolve. It reads sysfs the same way the sesmon daemon does
 * (/sys/class/scsi_generic/sg*), so what is offered here is exactly what the daemon
 * can find. Paths are relative to SESEXT_ROOT (empty on a real server, a fixture
 * directory in the tests).
 */

const SESEXT_SCSI_TYPE_ENCLOSURE = 13;

function sesext_root() {
    return rtrim((string)getenv('SESEXT_ROOT'), '/');
}

/** Read one small sysfs attribute, trimmed; null when it does not exist. */
function sesext_sysfs_read($file) {
    $v = @file_get_contents($file);
    return $v === false ? null : trim($v);
}

/** Read a small sysfs attribute that may hold trailing NUL bytes (the mpt3sas board attributes do). */
function sesext_sysfs_text($file) {
    $v = @file_get_contents($file);
    return $v === false ? '' : trim(explode("\0", $v)[0]);
}

/**
 * Every SCSI generic node the kernel knows: ['/dev/sg8' => [dev, type, address, vendor, model, rev, hctl, block,
 * bytes, ssd]]. address is lower case (as the daemon lowercases it) or null when the device has none; hctl is
 * "host:channel:target:lun", block the disk's name ("sdb") and bytes its capacity, for nodes that are disks.
 */
function sesext_scan_nodes($root = null) {
    $root = $root ?? sesext_root();
    $nodes = [];
    foreach (glob($root . '/sys/class/scsi_generic/sg*/device') ?: [] as $d) {
        $sg = basename(dirname($d));
        $addr = sesext_sysfs_read("$d/sas_address");
        $type = sesext_sysfs_read("$d/type");
        $link = @readlink($d); // the real sysfs entry links to .../<host:channel:target:lun>; fixtures hold a file
        $hctl = $link !== false ? basename($link) : (sesext_sysfs_read("$d/hctl") ?? '');
        $blk = glob("$d/block/*") ?: [];
        $block = $blk ? basename($blk[0]) : '';
        $sectors = $block !== '' ? sesext_sysfs_read("$d/block/$block/size") : null;
        $rot = $block !== '' ? sesext_sysfs_read("$d/block/$block/queue/rotational") : null;
        $nodes["/dev/$sg"] = [
            'hctl'    => $hctl, 'block' => $block,
            'bytes'   => ($sectors !== null && ctype_digit($sectors)) ? (int)$sectors * 512 : null,
            'ssd'     => $rot === '0',
            'dev'     => "/dev/$sg",
            'type'    => $type === null ? null : (int)$type,
            'address' => ($addr === null || $addr === '') ? null : strtolower($addr),
            'vendor'  => sesext_sysfs_read("$d/vendor") ?? '',
            'model'   => sesext_sysfs_read("$d/model") ?? '',
            'rev'     => sesext_sysfs_read("$d/rev") ?? '',
        ];
    }
    uksort($nodes, fn($a, $b) => (int)substr($a, 7) <=> (int)substr($b, 7));
    return $nodes;
}

/** An enclosure that is really an HBA's own virtual SES (it lists the HBA's ports, not a chassis). */
function sesext_enclosure_kind($vendor, $model) {
    return preg_match('/virtual\s*ses/i', $model) ? 'hba' : 'shelf';
}

/**
 * The HBA (SCSI host) behind a device, from sysfs: ['driver', 'name', 'chip', 'firmware', 'bios'], or null when the
 * host is no HBA that reports a firmware version (an AHCI port, a USB stick).
 */
function sesext_hba_info($hctl, $root = null) {
    $root = $root ?? sesext_root();
    if (!preg_match('/^(\d+):/', (string)$hctl, $m)) { return null; }
    $h = "$root/sys/class/scsi_host/host{$m[1]}";
    $fw = sesext_sysfs_text("$h/version_fw");
    if ($fw === '') { return null; }
    $chip = sesext_sysfs_text("$h/version_product");
    return ['driver' => sesext_sysfs_text("$h/proc_name"), 'name' => sesext_sysfs_text("$h/board_name") ?: $chip, 'chip' => $chip,
            'firmware' => $fw, 'bios' => sesext_sysfs_text("$h/version_bios")];
}

/** The HBA as a person names it, from sesext_hba_info(): "430-16i SAS HBA (SAS3416)", or '' when nothing is known. */
function sesext_hba_title($hba) {
    if (!$hba) { return ''; }
    $name = trim((string)($hba['name'] ?? ''));
    $chip = trim((string)($hba['chip'] ?? ''));
    if ($name === '') { return $chip; }
    return ($chip !== '' && stripos($name, $chip) === false) ? "$name ($chip)" : $name;
}

/**
 * The name for a dashboard tile: the configured description, except that the HBA's virtual enclosure is named after the
 * HBA while its description is the generated one ("Internal HBA ports", the enclosure's own "VirtualSES" label) or empty.
 * A description someone typed is left alone. $device is the 'device' of the parsed status (path, address).
 */
function sesext_tile_name($description, $fallback, $device, $disc = null) {
    $description = trim((string)$description);
    $generated = $description === '' || preg_match('/virtual\s*ses|^internal hba ports$/i', $description);
    if ($generated) {
        $disc = $disc ?? sesext_discover();
        foreach ($disc['enclosures'] as $e) {
            $same = ($e['address'] !== null && $e['address'] === ($device['address'] ?? null)) || $e['dev'] === ($device['path'] ?? null);
            if ($same && $e['kind'] === 'hba') {
                $t = sesext_hba_title($e['hba']);
                if ($t !== '') { return $t; }
            }
        }
    }
    return $description !== '' ? $description : $fallback;
}

/**
 * The discovery result:
 *   nodes       every sg node (see above)
 *   enclosures  the nodes of type 13, each with 'label', 'unique' (its address is not shared with
 *               another node, so an "address:" entry can find it) and 'use' => ['address'|'device', value]
 *   by_address  address => [dev, ...] for every node that has one
 *   duplicates  the addresses that more than one node reports (the daemon ignores these for lookups)
 */
function sesext_discover($root = null) {
    $root = $root ?? sesext_root();
    $nodes = sesext_scan_nodes($root);
    $by = [];
    foreach ($nodes as $n) {
        if ($n['address'] !== null) { $by[$n['address']][] = $n['dev']; }
    }
    $dups = array_filter($by, fn($l) => count($l) > 1);

    $enclosures = [];
    foreach ($nodes as $n) {
        if ($n['type'] !== SESEXT_SCSI_TYPE_ENCLOSURE) { continue; }
        $unique = $n['address'] !== null && !isset($dups[$n['address']]);
        $kind = sesext_enclosure_kind($n['vendor'], $n['model']);
        $enclosures[] = $n + [
            'label'  => trim(preg_replace('/\s+/', ' ', $n['vendor'] . ' ' . $n['model'])) ?: 'Unknown enclosure',
            'unique' => $unique,
            'use'    => $unique ? ['address', $n['address']] : ['device', $n['dev']],
            'kind'   => $kind,
            'hba'    => $kind === 'hba' ? sesext_hba_info($n['hctl'], $root) : null,
            'title'  => $kind === 'hba' ? (sesext_hba_title(sesext_hba_info($n['hctl'], $root)) ?: 'Internal HBA ports') : '',
            'note'   => $kind === 'hba' ? "The HBA's own virtual enclosure: it lists the HBA's ports and the drives on them, not a chassis (no fans, power supplies or temperatures). Monitoring it alerts you when a drive drops off a port." : '',
        ];
    }
    return ['nodes' => $nodes, 'enclosures' => $enclosures, 'by_address' => $by, 'duplicates' => $dups];
}

/**
 * Say what the daemon will do with one configured device (mirrors lookupDevice() in sesmon).
 * Returns [level, code, message, resolved]:
 *   level: off | ok | warn | error   code: a short stable id   resolved: '/dev/sgN' or null
 */
function sesext_device_status($dev, $disc, $fileExists = null) {
    $fileExists = $fileExists ?? 'file_exists';
    if (empty($dev['enabled'])) { return ['off', 'disabled', 'Not monitored', null]; }

    if ((int)($dev['type'] ?? 0) === 1) {
        $p = (string)($dev['device'] ?? '');
        return $fileExists($p)
            ? ['ok', 'testfile', 'JSON test file', $p]
            : ['warn', 'testfile-missing', "JSON test file $p does not exist", null];
    }

    $addr = trim((string)($dev['address'] ?? ''));
    $path = trim((string)($dev['device'] ?? ''));
    $nodes = $disc['nodes']; $by = $disc['by_address']; $dups = $disc['duplicates'];
    $pathKnown = $path !== '' && isset($nodes[$path]);

    $describe = function ($dv) use ($nodes) {
        $n = $nodes[$dv] ?? null;
        return $n && $n['type'] !== SESEXT_SCSI_TYPE_ENCLOSURE ? " (note: $dv is not an enclosure, its SCSI type is " . var_export($n['type'], true) . ')' : '';
    };

    if ($addr !== '') {
        if (isset($by[$addr]) && !isset($dups[$addr])) {
            $dv = $by[$addr][0];
            $extra = $describe($dv);
            return $extra ? ['warn', 'not-enclosure', "Resolves to $dv$extra", $dv] : ['ok', 'ok', "Found as $dv", $dv];
        }
        if (isset($dups[$addr])) {
            $list = implode(', ', $dups[$addr]);
            return $pathKnown
                ? ['warn', 'ambiguous-fallback', "Address is shared by $list, so it cannot be looked up; the device path $path is used instead", $path]
                : ['error', 'ambiguous', "Address is shared by $list, so it cannot be looked up: monitor one of them by device path instead", null];
        }
        $lower = strtolower($addr);
        if ($lower !== $addr && (isset($by[$lower]) || isset($dups[$lower]))) {
            return ['error', 'case', "The address differs from the enclosure's only in letter case: sesmon needs it in lower case ($lower)", null];
        }
        return $pathKnown
            ? ['warn', 'address-missing-fallback', "Address $addr was not found; the device path $path is used instead", $path]
            : ['error', 'unresolvable', "Address $addr was not found on this server (the enclosure is gone, powered off, or the address is wrong)", null];
    }
    if ($path !== '') {
        if (!isset($nodes[$path])) { return ['error', 'path-missing', "$path does not exist", null]; }
        $extra = $describe($path);
        $n = $nodes[$path];
        $hint = $n['address'] !== null && !isset($dups[$n['address']]) ? ' (an address would be more stable across reboots)' : '';
        return $extra ? ['warn', 'not-enclosure', "$path exists$extra", $path] : ['ok', 'ok-path', "Found by device path$hint", $path];
    }
    return ['error', 'no-target', 'Neither an address nor a device path is set', null];
}
?>
