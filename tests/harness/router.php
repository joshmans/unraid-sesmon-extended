<?php
// Router for `php -S`: serves the real Configuration page and the real API against a fixture "server".
// Started by serve.sh, which builds the fake server root and sets SESEXT_ROOT and friends.
$plugin = dirname(__DIR__, 2) . '/source/usr/local/emhttp/plugins/sesmon-ext';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/plugins/sesmon-ext/include/sesext_api.php') { require "$plugin/include/sesext_api.php"; return true; }
if ($path === '/plugins/sesmon-ext/include/sesext_bays_json.php') { require "$plugin/include/sesext_bays_json.php"; return true; }
if ($path === '/plugins/sesmon-ext/include/sesext_list.php') { require "$plugin/include/sesext_list.php"; return true; }
if (strpos($path, '/plugins/sesmon-ext/json/') === 0) { // the daemon's output folders
    $f = realpath(getenv('SESEXT_ROOT') . '/var/lib/sesmon-ext/' . substr($path, strlen('/plugins/sesmon-ext/json/')));
    if ($f && strpos($f, realpath(getenv('SESEXT_ROOT')) . '/var/lib/sesmon-ext/') === 0 && is_file($f)) { header('Content-Type: application/json'); readfile($f); return true; }
    http_response_code(404); return true;
}
if (strpos($path, '/plugins/sesmon-ext/') === 0) {
    $f = realpath($plugin . substr($path, strlen('/plugins/sesmon-ext')));
    if ($f && strpos($f, $plugin) === 0 && is_file($f)) {
        $types = ['js' => 'application/javascript', 'css' => 'text/css', 'png' => 'image/png'];
        header('Content-Type: ' . ($types[pathinfo($f, PATHINFO_EXTENSION)] ?? 'text/plain'));
        readfile($f); return true;
    }
    http_response_code(404); return true;
}
if ($path === '/' || $path === '/devices') {
    $pageFile = $path === '/' ? 'sesextConfig.page' : 'sesextDevices.page';
    // what Unraid does with a .page file: drop the header, run the PHP in the body
    function autov($p) { return $p; }
    $display = ['theme' => $_GET['theme'] ?? 'white'];
    $body = explode("\n---\n", file_get_contents("$plugin/$pageFile"), 2)[1];
    ?><!doctype html><html><head><meta charset="utf-8"><title>sesmon-ext harness</title>
    <style>body{font:14px sans-serif;margin:20px;<?= $display['theme'] === 'black' ? 'background:#1c1b1b;color:#ccc' : '' ?>}</style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script>
      // stand-in for Unraid's sweetalert
      window.timers = {}; // Unraid keeps its refresh timers here
      window.swal = function (opts, cb) { if (cb && window.confirm(opts.title + '\n' + (opts.text || ''))) { cb(); } };
      // Unraid appends its CSRF token to every POST; do the same so the endpoint sees what it will see there
      $.ajaxPrefilter(function (s) { if (s.type === 'POST') { s.data = (s.data ? s.data + '&' : '') + 'csrf_token=harness'; } });
    </script></head><body><h2>Enclosures <small>(harness)</small></h2>
    <?php eval('?>' . $body); ?></body></html><?php
    return true;
}
http_response_code(404);
