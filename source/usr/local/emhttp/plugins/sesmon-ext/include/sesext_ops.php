<?
/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * What the configuration page does on the server: read the state, save files (checked
 * with sesmon itself before anything is written), control the service. Every function
 * returns a plain array, so the same code serves the web endpoint and the tests.
 */
require_once __DIR__ . '/sesext_model.php';

const SESEXT_CONFIG_FILE = 'config.yaml';

function sesext_sesmon_bin() { return getenv('SESEXT_SESMON') ?: 'sesmon'; }

/** Run a command without a shell; returns [exit code, output]. */
function sesext_run($argv) {
    $cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    $out = []; $code = 0;
    exec($cmd, $out, $code);
    return [$code, trim(implode("\n", $out))];
}

function sesext_sesmon_available() {
    [$code] = sesext_run([sesext_sesmon_bin(), '--version']);
    return $code === 0;
}

function sesext_daemon_running() {
    [$code] = sesext_run(['pgrep', '-x', 'sesmon']);
    return $code === 0;
}

/** A file name inside the configuration folder that the page may read and write. */
function sesext_valid_name($name) {
    return is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\.(yaml|sh)$/', $name) === 1
        && strpos($name, '..') === false;
}

function sesext_list_files() {
    $out = [];
    foreach (glob(sesext_paths()['etc'] . '/*') ?: [] as $f) {
        if (is_file($f) && sesext_valid_name(basename($f))) { $out[] = basename($f); }
    }
    sort($out);
    return $out;
}

function sesext_read_file($name) {
    if (!sesext_valid_name($name)) { return ['ok' => false, 'errors' => ['Not a configuration file name']]; }
    $f = sesext_paths()['etc'] . '/' . $name;
    if (!is_file($f)) { return ['ok' => false, 'errors' => ["$name does not exist"]]; }
    return ['ok' => true, 'name' => $name, 'text' => (string)file_get_contents($f)];
}

/** Ask sesmon whether a configuration text is acceptable (the same parse the daemon does at start). */
function sesext_check_text($text) {
    if (!sesext_sesmon_available()) { return ['checked' => false, 'ok' => true, 'output' => 'sesmon is not available here, the file was not checked']; }
    $tmp = tempnam(sys_get_temp_dir(), 'sesext');
    file_put_contents($tmp, $text);
    [$code, $out] = sesext_run([sesext_sesmon_bin(), 'check', $tmp]);
    @unlink($tmp);
    return ['checked' => true, 'ok' => $code === 0, 'output' => $out];
}

/** Ask sesmon whether the enabled devices of the saved file can be found (does not start anything). */
function sesext_test_saved() {
    if (!sesext_sesmon_available()) { return ['checked' => false, 'ok' => true, 'output' => '']; }
    [$code, $out] = sesext_run([sesext_sesmon_bin(), 'test', sesext_paths()['etc'] . '/' . SESEXT_CONFIG_FILE]);
    // shared drive addresses are logged on every start and are harmless: keep the output about what matters
    $out = implode("\n", array_filter(explode("\n", $out), fn($l) => strpos($l, 'came up for multiple devices') === false));
    return ['checked' => true, 'ok' => $code === 0, 'output' => trim($out)];
}

/**
 * Save a file of the configuration folder: check it, keep the previous version as .bak, write it to the
 * flash (survives reboots) and to /etc (what the service reads).
 */
function sesext_save_file($name, $text) {
    if (!sesext_valid_name($name)) { return ['ok' => false, 'errors' => ['Not a configuration file name']]; }
    $text = str_replace("\r", '', (string)$text);
    if ($text !== '' && substr($text, -1) !== "\n") { $text .= "\n"; }
    $isYaml = substr($name, -5) === '.yaml';
    $warnings = [];

    if ($isYaml) {
        $c = sesext_check_text($text);
        if (!$c['ok']) { return ['ok' => false, 'errors' => ['sesmon rejects this file: ' . $c['output']]]; }
        if (!$c['checked']) { $warnings[] = $c['output']; }
    } else {
        $tmp = tempnam(sys_get_temp_dir(), 'sesext');
        file_put_contents($tmp, $text);
        [$code, $out] = sesext_run(['bash', '-n', $tmp]);
        @unlink($tmp);
        if ($code !== 0) { return ['ok' => false, 'errors' => ['The script has a syntax error: ' . str_replace($tmp, $name, $out)]]; }
    }

    $p = sesext_paths();
    foreach ([$p['boot'], $p['etc']] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return ['ok' => false, 'errors' => ["Cannot create $dir"]]; }
    }
    $boot = $p['boot'] . '/' . $name; $etc = $p['etc'] . '/' . $name;
    if (is_file($boot)) { @copy($boot, $boot . '.bak'); }
    if (file_put_contents($boot, $text) === false) { return ['ok' => false, 'errors' => ["Cannot write $boot (the flash drive may be read-only)"]]; }
    if (file_put_contents($etc, $text) === false) { return ['ok' => false, 'errors' => ["Cannot write $etc"]]; }
    @chmod($etc, $isYaml ? 0644 : 0755);

    $res = ['ok' => true, 'name' => $name, 'warnings' => $warnings, 'errors' => []];
    if ($name === SESEXT_CONFIG_FILE) { $res['test'] = sesext_test_saved(); }
    return $res;
}

/** Save the page's choices: merge into the existing file, validate, render, save. */
function sesext_save_form($form) {
    $p = sesext_paths();
    $loaded = sesext_load($p['etc'] . '/' . SESEXT_CONFIG_FILE);
    if ($loaded['error'] && $loaded['error'][0] !== 'missing') {
        return ['ok' => false, 'errors' => ['The saved configuration cannot be read by the form (' . $loaded['error'][1] . '). Fix it in the raw editor first.']];
    }
    $cfg = sesext_apply_form($loaded['config'], is_array($form) ? $form : []);
    $v = sesext_validate($cfg, sesext_discover());
    if ($v['errors']) { return ['ok' => false, 'errors' => $v['errors'], 'warnings' => $v['warnings']]; }
    $res = sesext_save_file(SESEXT_CONFIG_FILE, sesext_render($cfg));
    $res['warnings'] = array_merge($res['warnings'] ?? [], $v['warnings']);
    return $res;
}

/** start | stop | restart through the service script the plugin installs. */
function sesext_service($do) {
    if (!in_array($do, ['start', 'stop', 'restart'], true)) { return ['ok' => false, 'errors' => ['Unknown action']]; }
    $rc = getenv('SESEXT_RC') ?: '/etc/rc.d/rc.sesmon-ext';
    [$code, $out] = sesext_run([$rc, $do]);
    return ['ok' => $code === 0, 'output' => $out, 'running' => sesext_daemon_running(), 'errors' => $code === 0 ? [] : [$out]];
}

/** Everything the page needs in one go. */
function sesext_state() {
    $p = sesext_paths();
    $disc = sesext_discover();
    $loaded = sesext_load($p['etc'] . '/' . SESEXT_CONFIG_FILE);
    // no file yet is not an error for the form: it starts empty
    if ($loaded['config'] === null && ($loaded['error'][0] ?? '') === 'missing') { $loaded['config'] = []; $loaded['error'] = null; }
    [$lc, $lsscsi] = sesext_run(['lsscsi', '-tg']);
    $state = [
        'file' => SESEXT_CONFIG_FILE, 'raw' => $loaded['raw'], 'error' => $loaded['error'],
        'enclosures' => $disc['enclosures'], 'duplicates' => $disc['duplicates'],
        'shared_drive_addresses' => count($disc['duplicates']),
        'presets' => sesext_presets(), 'field_types' => sesext_field_types(),
        'defaults' => ['config' => sesext_monitor_defaults(), 'notifier' => sesext_notify_defaults()],
        'notify_script' => SESEXT_NOTIFY_SCRIPT, 'scripts' => sesext_notifier_scripts(), 'files' => sesext_list_files(),
        'daemon' => ['running' => sesext_daemon_running()],
        'lsscsi' => $lc === 0 ? implode("\n", array_filter(explode("\n", $lsscsi), fn($l) => strpos($l, 'enclosu') !== false)) : '',
    ];
    if ($loaded['config'] !== null) {
        $state += sesext_view($loaded['config'], $disc);
        $state['problems'] = sesext_problems($loaded['config'], $disc);
        $state['form_ok'] = true;
    } else {
        $state['devices'] = []; $state['available'] = $disc['enclosures']; $state['problems'] = []; $state['form_ok'] = false;
    }
    return $state;
}

/** Route an action of the web endpoint. */
function sesext_handle($action, $in) {
    switch ($action) {
        case 'state':     return sesext_state();
        case 'status':    return ['ok' => true, 'running' => sesext_daemon_running()];
        case 'read':      return sesext_read_file($in['name'] ?? '');
        case 'save_raw':  return sesext_save_file($in['name'] ?? '', $in['text'] ?? '');
        case 'save_form':
            $form = json_decode((string)($in['form'] ?? ''), true);
            return is_array($form) ? sesext_save_form($form) : ['ok' => false, 'errors' => ['The form data was not understood']];
        case 'service':   return sesext_service($in['do'] ?? '');
        default:          return ['ok' => false, 'errors' => ['Unknown action']];
    }
}
?>
