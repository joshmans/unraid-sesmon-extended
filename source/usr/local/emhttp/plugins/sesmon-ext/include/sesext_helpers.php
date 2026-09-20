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
function sesext_device_folders() {
    $result = [];
    $baseDir = '/var/lib/sesmon-ext';

    try {
        $subdirs = array_filter(glob($baseDir . '/*'), 'is_dir');
        sort($subdirs);

        foreach ($subdirs as $subdir) {
            $folderName = basename($subdir);
            $folderData = [
                'raw' => null,
                'parsed' => null,
                'alerts' => []
            ];

            $files = scandir($subdir);
            $found = false;

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;

                if ($file === 'current.json') {
                    $folderData['raw'] = $file;
                } elseif ($file === 'current_parsed.json') {
                    $folderData['parsed'] = $file;
                    $found = true; // Need at least this file
                } elseif (strpos($file, 'change-') === 0) {
                    $folderData['alerts'][] = $file;
                }
            }
            if (!$found) {
                continue;
            }

            rsort($folderData['alerts']);

            $result[$folderName] = $folderData;
        }
    } catch (\Throwable $t) {
        error_log($t);
        $result = [];
    }

    return $result;
}
?>
