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

/**
 * Every SCSI generic node the kernel knows: ['/dev/sg8' => [dev, type, address, vendor, model, rev]].
 * address is lower case (as the daemon lowercases it) or null when the device has none.
 */
function sesext_scan_nodes($root = null) {
    $root = $root ?? sesext_root();
    $nodes = [];
    foreach (glob($root . '/sys/class/scsi_generic/sg*/device') ?: [] as $d) {
        $sg = basename(dirname($d));
        $addr = sesext_sysfs_read("$d/sas_address");
        $type = sesext_sysfs_read("$d/type");
        $nodes["/dev/$sg"] = [
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

/**
 * The discovery result:
 *   nodes       every sg node (see above)
 *   enclosures  the nodes of type 13, each with 'label', 'unique' (its address is not shared with
 *               another node, so an "address:" entry can find it) and 'use' => ['address'|'device', value]
 *   by_address  address => [dev, ...] for every node that has one
 *   duplicates  the addresses that more than one node reports (the daemon ignores these for lookups)
 */
function sesext_discover($root = null) {
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
        $enclosures[] = $n + [
            'label'  => trim(preg_replace('/\s+/', ' ', $n['vendor'] . ' ' . $n['model'])) ?: 'Unknown enclosure',
            'unique' => $unique,
            'use'    => $unique ? ['address', $n['address']] : ['device', $n['dev']],
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
