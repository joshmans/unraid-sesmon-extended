<?
/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * The configuration model: the sesmon schema (defaults, presets, comments), validation,
 * the view that the settings page renders, and merging of the page's choices into an
 * existing configuration. The file on disk is the only state: the page always builds its
 * selections from it, and saving writes it back (keeping every setting this code does
 * not know about).
 */
require_once __DIR__ . '/sesext_yaml.php';
require_once __DIR__ . '/sesext_discover.php';

const SESEXT_NOTIFY_SCRIPT = '/etc/sesmon-ext/notify.sh'; // wraps Unraid's own notification system
const SESEXT_OUTPUT_BASE   = '/var/lib/sesmon-ext';       // the dashboard only looks below this folder

/* ------------------------------------------------------------ the schema */

function sesext_monitor_defaults() {
    return [
        'poll_interval' => '1m30s', 'poll_attempts' => 3, 'poll_attempt_timeout' => '15s',
        'poll_attempt_interval' => '15s', 'poll_backoff_after' => 3, 'poll_backoff_time' => '3m0s',
        'poll_backoff_notify' => true, 'poll_backoff_stopmonitor' => false, 'verbose' => false,
    ];
}
function sesext_notify_defaults() {
    return ['notify_attempts' => 3, 'notify_attempt_timeout' => '15s', 'notify_attempt_interval' => '15s'];
}
/** Which settings are durations / whole numbers / switches. */
function sesext_field_types() {
    return [
        'poll_interval' => 'duration', 'poll_attempts' => 'int', 'poll_attempt_timeout' => 'duration',
        'poll_attempt_interval' => 'duration', 'poll_backoff_after' => 'int', 'poll_backoff_time' => 'duration',
        'poll_backoff_notify' => 'bool', 'poll_backoff_stopmonitor' => 'bool', 'verbose' => 'bool',
        'notify_attempts' => 'int', 'notify_attempt_timeout' => 'duration', 'notify_attempt_interval' => 'duration',
    ];
}
/** Dropdown choices for the numeric settings; anything else is offered as "Custom". */
function sesext_presets() {
    $d = fn($pairs) => array_map(fn($p) => ['value' => $p[0], 'label' => $p[1]], $pairs);
    $n = fn($list) => array_map(fn($v) => ['value' => $v, 'label' => (string)$v], $list);
    return [
        'poll_interval'         => $d([['30s', '30 seconds'], ['1m', '1 minute'], ['1m30s', '1 minute 30 seconds (default)'], ['2m', '2 minutes'], ['5m', '5 minutes'], ['10m', '10 minutes'], ['30m', '30 minutes']]),
        'poll_attempts'         => $n([1, 2, 3, 4, 5]),
        'poll_attempt_timeout'  => $d([['5s', '5 seconds'], ['10s', '10 seconds'], ['15s', '15 seconds (default)'], ['30s', '30 seconds'], ['1m', '1 minute']]),
        'poll_attempt_interval' => $d([['5s', '5 seconds'], ['15s', '15 seconds (default)'], ['30s', '30 seconds'], ['1m', '1 minute']]),
        'poll_backoff_after'    => $n([1, 2, 3, 5, 10]),
        'poll_backoff_time'     => $d([['1m', '1 minute'], ['3m0s', '3 minutes (default)'], ['5m', '5 minutes'], ['10m', '10 minutes'], ['30m', '30 minutes'], ['1h', '1 hour']]),
        'notify_attempts'       => $n([1, 2, 3, 4, 5]),
        'notify_attempt_timeout'  => $d([['5s', '5 seconds'], ['10s', '10 seconds'], ['15s', '15 seconds (default)'], ['30s', '30 seconds'], ['1m', '1 minute']]),
        'notify_attempt_interval' => $d([['5s', '5 seconds'], ['15s', '15 seconds (default)'], ['30s', '30 seconds'], ['1m', '1 minute']]),
    ];
}

/** Length of a Go duration string ("1m30s", "90s", "1h") in seconds; null if it is not one. */
function sesext_duration_seconds($s) {
    if (!is_string($s) || !preg_match('/^(?:[0-9]+(?:\.[0-9]+)?(?:ns|us|µs|ms|s|m|h))+$/u', $s)) { return null; }
    preg_match_all('/([0-9]+(?:\.[0-9]+)?)(ns|us|µs|ms|s|m|h)/u', $s, $m, PREG_SET_ORDER);
    $unit = ['ns' => 1e-9, 'us' => 1e-6, 'µs' => 1e-6, 'ms' => 1e-3, 's' => 1, 'm' => 60, 'h' => 3600];
    $sum = 0.0;
    foreach ($m as $p) { $sum += (float)$p[1] * $unit[$p[2]]; }
    return $sum;
}

/** The dropdown choice that equals $value (same length, whatever the spelling: 1m == 60s), or 'custom'. */
function sesext_preset_for($field, $value) {
    $presets = sesext_presets()[$field] ?? [];
    $types = sesext_field_types();
    foreach ($presets as $p) {
        if ($types[$field] === 'duration') {
            $a = sesext_duration_seconds((string)$value); $b = sesext_duration_seconds($p['value']);
            if ($a !== null && $b !== null && abs($a - $b) < 1e-9) { return $p['value']; }
        } elseif ($p['value'] === $value) {
            return $p['value'];
        }
    }
    return 'custom';
}

/** How a configuration is written: key order and the comment above each key. */
function sesext_schema() {
    $monitor = [
        'order' => ['poll_interval', 'poll_attempts', 'poll_attempt_timeout', 'poll_attempt_interval', 'poll_backoff_after', 'poll_backoff_time', 'poll_backoff_notify', 'poll_backoff_stopmonitor', 'output_dir', 'verbose'],
        'comments' => [
            'poll_interval' => ['How often to poll the enclosure for data'],
            'poll_attempts' => ['How often to attempt a poll before it counts as failed (must be > 0)'],
            'poll_attempt_timeout' => ['How long a single poll attempt may take'],
            'poll_attempt_interval' => ['How long to wait between poll attempts after a failure'],
            'poll_backoff_after' => ['How many consecutive failed polls start a back-off period'],
            'poll_backoff_time' => ['How long to pause polling during a back-off period'],
            'poll_backoff_notify' => ['Send a notification when a back-off period starts'],
            'poll_backoff_stopmonitor' => ['Stop monitoring the enclosure for good when a back-off period starts'],
            'output_dir' => ['Where the device state and alerts are written as JSON', 'Must be unique per device and below ' . SESEXT_OUTPUT_BASE . ' for the dashboard'],
            'verbose' => ['Also log verbose operational information'],
        ],
    ];
    $notifierConfig = [
        'order' => ['notify_attempts', 'notify_attempt_timeout', 'notify_attempt_interval'],
        'comments' => [
            'notify_attempts' => ['How often to attempt a notification (must be > 0)'],
            'notify_attempt_timeout' => ['How long a single notification attempt may take'],
            'notify_attempt_interval' => ['How long to wait between notification attempts after a failure'],
        ],
    ];
    $notifier = [
        'order' => ['script', 'config'],
        'comments' => ['script' => ['Notification script, called with: device path, SAS address, description, message, change report (JSON)']],
        'children' => ['config' => $notifierConfig],
    ];
    $device = [
        'order' => ['address', 'device', 'type', 'description', 'enabled', 'config', 'script_notifier'],
        'comments' => [
            'address' => ['SAS address of the enclosure (stable across reboots)'],
            'device' => ['Device path, only used when there is no address (not stable across reboots)'],
            'type' => ['0 = enclosure, 1 = JSON test file'],
            'enabled' => ['Monitor this device'],
            'script_notifier' => ['Notifications: omit to only log alerts'],
        ],
        'children' => ['config' => $monitor, 'script_notifier' => $notifier],
    ];
    return [
        'order' => ['disable_timestamps', 'devices'],
        'comments' => [
            'disable_timestamps' => ['Keep this true on Unraid: the syslog adds its own timestamps'],
            'devices' => ['Devices to monitor'],
        ],
        'children' => ['devices' => $device],
    ];
}

const SESEXT_HEADER = <<<'TXT'
# sesmon configuration, written by the Configuration page of the sesmon-ext plugin.
# You may edit this file by hand: the page reads it back on load and, when it saves, rewrites
# the file (keeping any setting it does not know). Comments you add are lost by such a save;
# the previous file is kept as config.yaml.bak.
TXT;

/** The complete text of a configuration file. */
function sesext_render($cfg) {
    $lines = explode("\n", SESEXT_HEADER);
    $lines[] = '';
    foreach (sesext_yaml_emit($cfg, 0, sesext_schema()) as $l) { $lines[] = $l; }
    return implode("\n", $lines) . "\n";
}

/* --------------------------------------------------------------- loading */

function sesext_paths() {
    $r = sesext_root();
    return [
        'etc'  => $r . '/etc/sesmon-ext',
        'boot' => $r . '/boot/config/plugins/sesmon-ext/config',
    ];
}

/**
 * Read a configuration file. Returns ['raw' => text, 'config' => array|null, 'error' => null|[type, message]]
 * where type is 'missing', 'syntax' (invalid YAML) or 'unsupported' (valid, but uses YAML the form cannot
 * represent: the page then offers raw editing only).
 */
function sesext_load($path) {
    if (!is_file($path)) { return ['raw' => '', 'config' => null, 'error' => ['missing', "$path does not exist"]]; }
    $raw = (string)file_get_contents($path);
    try {
        $cfg = sesext_yaml_parse($raw);
        if ($cfg === null) { $cfg = []; }
        if (!is_array($cfg) || ($cfg && array_is_list($cfg))) {
            return ['raw' => $raw, 'config' => null, 'error' => ['unsupported', 'The file is not a mapping of settings']];
        }
        if (isset($cfg['devices']) && (!is_array($cfg['devices']) || ($cfg['devices'] && !array_is_list($cfg['devices'])))) {
            return ['raw' => $raw, 'config' => null, 'error' => ['unsupported', '"devices" is not a list']];
        }
        foreach ($cfg['devices'] ?? [] as $i => $d) {
            if (!is_array($d) || ($d && array_is_list($d))) { return ['raw' => $raw, 'config' => null, 'error' => ['unsupported', 'Device ' . ($i + 1) . ' is not a mapping of settings']]; }
            foreach (['config', 'script_notifier'] as $k) {
                if (isset($d[$k]) && (!is_array($d[$k]) || ($d[$k] && array_is_list($d[$k])))) {
                    return ['raw' => $raw, 'config' => null, 'error' => ['unsupported', 'Device ' . ($i + 1) . ": \"$k\" is not a mapping of settings"]];
                }
            }
        }
        return ['raw' => $raw, 'config' => $cfg, 'error' => null];
    } catch (SesextYamlUnsupported $e) {
        return ['raw' => $raw, 'config' => null, 'error' => ['unsupported', $e->getMessage()]];
    } catch (SesextYamlError $e) {
        return ['raw' => $raw, 'config' => null, 'error' => ['syntax', $e->getMessage()]];
    }
}

/** Executable *.sh files in the config folder that can serve as notification script (besides the built-in one). */
function sesext_notifier_scripts($etc = null) {
    $etc = $etc ?? sesext_paths()['etc'];
    $out = [];
    foreach (glob($etc . '/*.sh') ?: [] as $f) {
        if (basename($f) === 'notify.sh' || !is_executable($f)) { continue; }
        $out[] = SESEXT_NOTIFY_SCRIPT === $f ? $f : '/etc/sesmon-ext/' . basename($f);
    }
    sort($out);
    return $out;
}

/* ------------------------------------------------------------------ view */

function sesext_notifier_mode($dev) {
    if (!isset($dev['script_notifier']) || !is_array($dev['script_notifier'])) { return 'log'; }
    return ($dev['script_notifier']['script'] ?? '') === SESEXT_NOTIFY_SCRIPT ? 'unraid' : 'custom';
}

function sesext_view_field($field, $value) {
    return ['value' => $value, 'preset' => sesext_preset_for($field, $value)];
}

/**
 * What the page shows for a configuration: one row per configured device (with its status and the
 * effective settings, defaults filled in), and the discovered enclosures nobody monitors yet.
 */
function sesext_view($cfg, $disc, $fileExists = null) {
    $rows = []; $claimed = [];
    foreach (($cfg['devices'] ?? []) as $i => $dev) {
        [$level, $code, $msg, $resolved] = sesext_device_status($dev, $disc, $fileExists);
        $isFile = (int)($dev['type'] ?? 0) === 1;
        $mon = array_merge(sesext_monitor_defaults(), array_intersect_key($dev['config'] ?? [], sesext_monitor_defaults()));
        $mode = sesext_notifier_mode($dev);
        $notif = array_merge(sesext_notify_defaults(), array_intersect_key($dev['script_notifier']['config'] ?? [], sesext_notify_defaults()));
        $view = [];
        foreach (array_merge($mon, $notif) as $k => $v) { $view[$k] = sesext_view_field($k, $v); }
        $rows[] = [
            'orig' => $i, 'kind' => $isFile ? 'file' : 'device',
            'address' => (string)($dev['address'] ?? ''), 'device' => (string)($dev['device'] ?? ''),
            'description' => (string)($dev['description'] ?? ''), 'enabled' => !empty($dev['enabled']),
            'config' => $mon, 'notifier' => ['mode' => $mode, 'script' => (string)($dev['script_notifier']['script'] ?? ''), 'config' => $notif],
            'fields' => $view,
            'status' => ['level' => $level, 'code' => $code, 'message' => $msg, 'resolved' => $resolved],
        ];
        foreach ($disc['enclosures'] as $e) {
            $a = strtolower(trim((string)($dev['address'] ?? '')));
            if ($resolved === $e['dev'] || ($a !== '' && $a === $e['address']) || (($dev['device'] ?? '') === $e['dev'])) { $claimed[$e['dev']] = true; }
        }
    }
    $available = array_values(array_filter($disc['enclosures'], fn($e) => !isset($claimed[$e['dev']])));
    return ['devices' => $rows, 'available' => $available];
}

/** Problems that stop the daemon from starting: what the Settings page warns about. */
function sesext_problems($cfg, $disc) {
    $out = [];
    foreach (($cfg['devices'] ?? []) as $i => $dev) {
        [$level, $code, $msg] = sesext_device_status($dev, $disc);
        if ($level === 'error') { $out[] = ['index' => $i, 'name' => (string)($dev['description'] ?? '') ?: 'Device ' . ($i + 1), 'code' => $code, 'message' => $msg]; }
    }
    return $out;
}

/* ------------------------------------------------------------ validation */

/** @return array{errors: string[], warnings: string[]} */
function sesext_validate($cfg, $disc = null, $root = null) {
    $root = $root ?? sesext_root();
    $err = []; $warn = [];
    $types = sesext_field_types();
    $seenDirs = []; $seenTargets = [];
    foreach (($cfg['devices'] ?? []) as $i => $dev) {
        $name = 'Device ' . ($i + 1) . ((string)($dev['description'] ?? '') !== '' ? ' (' . $dev['description'] . ')' : '');
        if (!in_array($dev['type'] ?? 0, [0, 1], true)) { $err[] = "$name: type must be 0 (enclosure) or 1 (JSON test file)"; }
        if (!empty($dev['enabled']) && trim((string)($dev['address'] ?? '')) === '' && trim((string)($dev['device'] ?? '')) === '') {
            $err[] = "$name: choose an enclosure (there is neither an address nor a device path)";
        }
        foreach (['config' => $dev['config'] ?? [], 'notifier settings' => $dev['script_notifier']['config'] ?? []] as $group => $vals) {
            foreach ($vals as $k => $v) {
                $t = $types[$k] ?? null;
                if ($t === 'duration' && sesext_duration_seconds($v) === null) { $err[] = "$name: $k \"" . (is_scalar($v) ? $v : json_encode($v)) . '" is not a valid duration (examples: 30s, 1m30s, 2h)'; }
                if ($t === 'duration' && sesext_duration_seconds($v) !== null && sesext_duration_seconds($v) <= 0 && in_array($k, ['poll_interval', 'poll_attempt_timeout', 'notify_attempt_timeout'], true)) { $err[] = "$name: $k must be longer than zero"; }
                if ($t === 'int' && (!is_int($v) || (in_array($k, ['poll_attempts', 'notify_attempts'], true) && $v <= 0) || $v < 0)) { $err[] = "$name: $k must be a whole number" . (in_array($k, ['poll_attempts', 'notify_attempts'], true) ? ' above zero' : ''); }
                if ($t === 'bool' && !is_bool($v)) { $err[] = "$name: $k must be true or false"; }
            }
        }
        if (empty($dev['enabled'])) { continue; }
        $dir = $dev['config']['output_dir'] ?? null;
        if ($dir !== null && $dir !== '') {
            $dir = rtrim($dir, '/');
            if (isset($seenDirs[$dir])) { $err[] = "$name: output folder $dir is already used by another device"; }
            $seenDirs[$dir] = true;
            if (strpos($dir, SESEXT_OUTPUT_BASE . '/') !== 0) { $warn[] = "$name: output folder $dir is not below " . SESEXT_OUTPUT_BASE . ', so the dashboard will not show this device'; }
        }
        if (isset($dev['script_notifier'])) {
            $script = (string)($dev['script_notifier']['script'] ?? '');
            $file = $root . $script;
            if ($script === '') { $err[] = "$name: the notification script is empty"; }
            elseif (!is_file($file) || !is_executable($file)) { $err[] = "$name: notification script $script does not exist or is not executable"; }
        }
        if ($disc !== null) {
            [$level, , $msg, $resolved] = sesext_device_status($dev, $disc);
            if ($level === 'error') { $err[] = "$name: $msg"; }
            elseif ($level === 'warn') { $warn[] = "$name: $msg"; }
            if ($resolved !== null) {
                if (isset($seenTargets[$resolved])) { $err[] = "$name: $resolved is already monitored by another device"; }
                $seenTargets[$resolved] = true;
            }
        }
    }
    return ['errors' => $err, 'warnings' => $warn];
}

/* --------------------------------------------------- applying the choices */

function sesext_slug($s) {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return $s !== '' ? $s : 'enclosure';
}

/** Give every enclosure without an output folder its own one below the dashboard's base folder. */
function sesext_assign_output_dirs(&$cfg) {
    $used = [];
    foreach ($cfg['devices'] ?? [] as $d) { if (!empty($d['config']['output_dir'])) { $used[rtrim($d['config']['output_dir'], '/')] = true; } }
    foreach ($cfg['devices'] ?? [] as $i => $d) {
        if ((int)($d['type'] ?? 0) === 1 || !empty($d['config']['output_dir'])) { continue; }
        $base = SESEXT_OUTPUT_BASE . '/' . sesext_slug((string)($d['description'] ?? '') ?: 'enclosure');
        $dir = $base; $n = 2;
        while (isset($used[$dir])) { $dir = $base . '-' . $n++; }
        $used[$dir] = true;
        $cfg['devices'][$i]['config']['output_dir'] = $dir;
    }
}

function sesext_cast($type, $v) {
    if ($type === 'bool') {
        if (is_bool($v)) { return $v; }
        if ($v === 'true' || $v === '1' || $v === 1) { return true; }
        if ($v === 'false' || $v === '0' || $v === 0 || $v === '') { return false; }
        return $v;
    }
    if ($type === 'int') {
        if (is_int($v)) { return $v; }
        return (is_string($v) && preg_match('/^-?[0-9]+$/', trim($v))) ? (int)trim($v) : $v;
    }
    return is_string($v) ? trim($v) : $v;
}

/**
 * Merge the page's choices into the existing configuration. $form['devices'] is a list of
 *   orig (index in the existing file, or null for a newly chosen enclosure), address, device,
 *   description, enabled, config{...}, notifier{mode: unraid|log|custom, script, config{...}}.
 * Everything the form does not carry (unknown settings, the type, output_dir, ...) is kept from the
 * existing entry; devices the form leaves out are dropped.
 */
function sesext_apply_form($existing, $form) {
    $existing = $existing ?? [];
    $old = $existing['devices'] ?? [];
    $types = sesext_field_types();
    $new = $existing;
    if (!array_key_exists('disable_timestamps', $new)) { $new['disable_timestamps'] = true; }
    $devices = [];
    foreach (($form['devices'] ?? []) as $f) {
        $orig = isset($f['orig']) && $f['orig'] !== '' && $f['orig'] !== null ? (int)$f['orig'] : null;
        $dev = ($orig !== null && isset($old[$orig])) ? $old[$orig] : ['type' => 0];
        foreach (['address', 'device'] as $k) {
            $v = trim((string)($f[$k] ?? ''));
            if ($k === 'address' && preg_match('/^0x[0-9a-f]+$/i', $v)) { $v = strtolower($v); } // sesmon compares addresses exactly, in lower case
            if ($v === '') { unset($dev[$k]); } else { $dev[$k] = $v; }
        }
        if (isset($f['description'])) { $dev['description'] = trim((string)$f['description']); }
        $dev['enabled'] = sesext_cast('bool', $f['enabled'] ?? false) === true;
        if ((int)($dev['type'] ?? 0) !== 1) {
            foreach (($f['config'] ?? []) as $k => $v) {
                if (isset($types[$k]) && array_key_exists($k, sesext_monitor_defaults())) { $dev['config'][$k] = sesext_cast($types[$k], $v); }
            }
            $mode = $f['notifier']['mode'] ?? null;
            if ($mode === 'log') {
                unset($dev['script_notifier']);
            } elseif ($mode === 'unraid' || $mode === 'custom') {
                $dev['script_notifier']['script'] = $mode === 'unraid' ? SESEXT_NOTIFY_SCRIPT : trim((string)($f['notifier']['script'] ?? ''));
                foreach (($f['notifier']['config'] ?? []) as $k => $v) {
                    if (isset($types[$k]) && array_key_exists($k, sesext_notify_defaults())) { $dev['script_notifier']['config'][$k] = sesext_cast($types[$k], $v); }
                }
            }
        }
        $devices[] = $dev;
    }
    $new['devices'] = $devices;
    sesext_assign_output_dirs($new);
    return $new;
}
?>
