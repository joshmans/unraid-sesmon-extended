<?
/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * A small YAML reader and writer for the subset that the sesmon configuration
 * needs (Unraid's PHP has no yaml extension): block mappings and sequences,
 * quoted and plain scalars, comments. Anything else (anchors, tags, block
 * scalars, non-empty flow collections, multiple documents, tabs) is refused
 * with SesextYamlUnsupported, so the caller can fall back to raw editing
 * instead of silently mangling a file it does not understand.
 */

class SesextYamlError extends Exception {}
class SesextYamlUnsupported extends Exception {}

/* ---------------------------------------------------------------- reading */

/** Cut a trailing comment (a "#" that starts a word, outside of quotes). */
function sesext_yaml_strip_comment($line) {
    $q = null; $len = strlen($line);
    for ($i = 0; $i < $len; $i++) {
        $c = $line[$i];
        if ($q === '"') {
            if ($c === '\\') { $i++; } elseif ($c === '"') { $q = null; }
        } elseif ($q === "'") {
            if ($c === "'") { $q = null; }
        } elseif ($c === '"' || $c === "'") {
            // a quote only opens a scalar at the start of a value, not inside a plain word
            $prev = $i > 0 ? $line[$i - 1] : ' ';
            if ($prev === ' ' || $prev === '-' || $prev === ':') { $q = $c; }
        } elseif ($c === '#' && ($i === 0 || $line[$i - 1] === ' ')) {
            return rtrim(substr($line, 0, $i));
        }
    }
    return rtrim($line);
}

/** @return array of [indent, content, lineno] for every non-empty, non-comment line */
function sesext_yaml_tokenize($text) {
    $text = str_replace("\r", '', $text);
    $tokens = [];
    foreach (explode("\n", $text) as $n => $line) {
        $lineno = $n + 1;
        if (strpos($line, "\t") !== false && preg_match('/^\s*\t/', $line)) {
            throw new SesextYamlUnsupported("line $lineno: tab indentation is not supported");
        }
        $content = sesext_yaml_strip_comment($line);
        if (trim($content) === '') { continue; }
        if (trim($content) === '---' || trim($content) === '...') {
            if (count($tokens) === 0 && trim($content) === '---') { continue; }
            throw new SesextYamlUnsupported("line $lineno: multiple documents are not supported");
        }
        $indent = strlen($content) - strlen(ltrim($content, ' '));
        $tokens[] = [$indent, ltrim($content, ' '), $lineno];
    }
    return $tokens;
}

function sesext_yaml_scalar($s, $lineno) {
    $s = trim($s);
    if ($s === '' || $s === '~' || $s === 'null') { return null; }
    if ($s === '[]') { return []; }
    if ($s === '{}') { return new stdClass(); }
    $f = $s[0];
    if ($f === '"') {
        if (strlen($s) < 2 || substr($s, -1) !== '"') { throw new SesextYamlError("line $lineno: unterminated double-quoted string"); }
        $inner = substr($s, 1, -1); $out = ''; $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $c = $inner[$i];
            if ($c === '"') { throw new SesextYamlError("line $lineno: unexpected quote inside a double-quoted string"); }
            if ($c !== '\\') { $out .= $c; continue; }
            $i++;
            $e = $inner[$i] ?? '';
            $map = ['"' => '"', '\\' => '\\', '/' => '/', 'n' => "\n", 't' => "\t"];
            if (!isset($map[$e])) { throw new SesextYamlUnsupported("line $lineno: escape sequence \\$e is not supported"); }
            $out .= $map[$e];
        }
        return $out;
    }
    if ($f === "'") {
        if (strlen($s) < 2 || substr($s, -1) !== "'") { throw new SesextYamlError("line $lineno: unterminated single-quoted string"); }
        $inner = substr($s, 1, -1);
        if (preg_match("/(?<!')'(?!')/", str_replace("''", '', $inner)) ) { throw new SesextYamlError("line $lineno: unexpected quote inside a single-quoted string"); }
        return str_replace("''", "'", $inner);
    }
    if (strpos('&*!|>[{%@`', $f) !== false) {
        throw new SesextYamlUnsupported("line $lineno: YAML feature starting with \"$f\" is not supported (anchors, tags, block scalars, flow collections)");
    }
    if ($s === 'true') { return true; }
    if ($s === 'false') { return false; }
    if (preg_match('/^(yes|no|on|off|y|n)$/i', $s)) {
        throw new SesextYamlUnsupported("line $lineno: ambiguous boolean \"$s\" (write true or false)");
    }
    if (preg_match('/^-?(0|[1-9][0-9]*)$/', $s)) { return (int)$s; }
    if (preg_match('/^-?[0-9]+\.[0-9]+$/', $s)) { return (float)$s; }
    return $s; // plain string, including 0x... addresses and durations like 1m30s
}

/** Split "key: value" (value may be empty). Returns [key, valueString|null] or null if not a mapping line. */
function sesext_yaml_split_pair($content, $lineno) {
    if (!preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^\s:#\'"\[\]{}&*!|>%@`,-][^:#]*?|-[^\s:][^:#]*?)\s*:(?:\s+(.*))?$/', $content, $m)) {
        return null;
    }
    // keys are names, never values: only unquote them (a key "y" or "true" stays text)
    $key = ($m[1][0] === '"' || $m[1][0] === "'") ? (string)sesext_yaml_scalar($m[1], $lineno) : trim($m[1]);
    return [$key, isset($m[2]) && $m[2] !== '' ? $m[2] : null];
}

function sesext_yaml_parse_block(&$t, &$i, $indent) {
    if (!isset($t[$i])) { return null; }
    if ($t[$i][0] !== $indent) {
        throw new SesextYamlError("line {$t[$i][2]}: unexpected indentation");
    }
    $c = $t[$i][1];
    return ($c === '-' || strpos($c, '- ') === 0)
        ? sesext_yaml_parse_seq($t, $i, $indent)
        : sesext_yaml_parse_map($t, $i, $indent);
}

function sesext_yaml_parse_map(&$t, &$i, $indent) {
    $map = [];
    while (isset($t[$i]) && $t[$i][0] === $indent) {
        [, $content, $lineno] = $t[$i];
        if ($content === '-' || strpos($content, '- ') === 0) { break; }
        $pair = sesext_yaml_split_pair($content, $lineno);
        if ($pair === null) { throw new SesextYamlError("line $lineno: expected \"key: value\""); }
        [$key, $val] = $pair;
        if (array_key_exists($key, $map)) { throw new SesextYamlError("line $lineno: duplicate key \"$key\""); }
        $i++;
        if ($val !== null) {
            $map[$key] = sesext_yaml_scalar($val, $lineno);
        } elseif (isset($t[$i]) && $t[$i][0] > $indent) {
            $map[$key] = sesext_yaml_parse_block($t, $i, $t[$i][0]);
        } elseif (isset($t[$i]) && $t[$i][0] === $indent && ($t[$i][1] === '-' || strpos($t[$i][1], '- ') === 0)) {
            $map[$key] = sesext_yaml_parse_seq($t, $i, $indent); // sequence at the key's own indent
        } else {
            $map[$key] = null;
        }
    }
    if (isset($t[$i]) && $t[$i][0] > $indent) { throw new SesextYamlError("line {$t[$i][2]}: unexpected indentation"); }
    return $map;
}

function sesext_yaml_parse_seq(&$t, &$i, $indent) {
    $list = [];
    while (isset($t[$i]) && $t[$i][0] === $indent && ($t[$i][1] === '-' || strpos($t[$i][1], '- ') === 0)) {
        [, $content, $lineno] = $t[$i];
        $rest = ltrim(substr($content, 1), ' ');
        if ($rest === '') {
            $i++;
            $list[] = (isset($t[$i]) && $t[$i][0] > $indent) ? sesext_yaml_parse_block($t, $i, $t[$i][0]) : null;
            continue;
        }
        $offset = strlen($content) - strlen($rest); // where the item's own content starts
        if (sesext_yaml_split_pair($rest, $lineno) !== null) {
            // "- key: value": the mapping lives at indent+offset; re-read this line as its first key
            $t[$i] = [$indent + $offset, $rest, $lineno];
            $list[] = sesext_yaml_parse_map($t, $i, $indent + $offset);
        } else {
            $list[] = sesext_yaml_scalar($rest, $lineno);
            $i++;
        }
    }
    return $list;
}

/** Parse YAML text. Returns a PHP array (or null for an empty document). */
function sesext_yaml_parse($text) {
    $tokens = sesext_yaml_tokenize($text);
    if (!$tokens) { return null; }
    $i = 0;
    $result = sesext_yaml_parse_block($tokens, $i, $tokens[0][0]);
    if ($i < count($tokens)) { throw new SesextYamlError("line {$tokens[$i][2]}: unexpected content"); }
    return $result;
}

/* ---------------------------------------------------------------- writing */

function sesext_yaml_quote($s) {
    return '"' . str_replace(['\\', '"', "\n", "\t", "\r"], ['\\\\', '\\"', '\\n', '\\t', ''], $s) . '"';
}

function sesext_yaml_scalar_out($v) {
    if ($v === null) { return 'null'; }
    if ($v === true) { return 'true'; }
    if ($v === false) { return 'false'; }
    if (is_int($v)) { return (string)$v; }
    if (is_float($v)) { return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.'); }
    return sesext_yaml_quote((string)$v);
}

/**
 * Write a value as block YAML. $schema (optional) describes a map:
 *   ['order' => [keys in output order], 'comments' => [key => [lines]],
 *    'children' => [key => schema of that key's map, or of each item if it is a list]]
 * Keys that are not in 'order' are written afterwards, so unknown settings survive.
 * Returns an array of lines. $firstPrefix is used in place of the indent for the first
 * line (for "- " list items).
 */
function sesext_yaml_emit($v, $indent, $schema = null) {
    $pad = str_repeat(' ', $indent);
    if ($v instanceof stdClass) { return ["$pad{}"]; }
    if (!is_array($v)) { return [$pad . sesext_yaml_scalar_out($v)]; }
    if ($v === []) { return [$pad . '[]']; }
    return array_is_list($v) ? sesext_yaml_emit_list($v, $indent, $schema) : sesext_yaml_emit_map($v, $indent, $schema);
}

function sesext_yaml_emit_map($map, $indent, $schema, $listItem = false) {
    $pad = str_repeat(' ', $indent);
    $order = $schema['order'] ?? [];
    $comments = $schema['comments'] ?? [];
    $children = $schema['children'] ?? [];
    $keys = [];
    foreach ($order as $k) { if (array_key_exists($k, $map)) { $keys[] = $k; } }
    $unknown = array_values(array_diff(array_keys($map), $order));
    $lines = [];
    $first = true;
    $all = array_merge($keys, $unknown);
    foreach ($all as $idx => $k) {
        $isUnknown = $idx >= count($keys);
        $kc = $comments[$k] ?? [];
        if ($isUnknown && $idx === count($keys) && $keys) {
            $kc = ['Further settings kept from the previous configuration'];
        }
        if ($kc && !$first) { $lines[] = ''; }
        foreach ($kc as $c) { $lines[] = ($listItem && $first ? substr($pad, 0, max(0, $indent - 2)) : $pad) . '# ' . $c; }
        $val = $map[$k];
        $label = (preg_match('/^[A-Za-z0-9_.-]+$/', (string)$k) ? $k : sesext_yaml_quote((string)$k)) . ':';
        $lead = ($listItem && $first) ? substr($pad, 0, max(0, $indent - 2)) . '- ' : $pad;
        $child = $children[$k] ?? null;
        if (is_array($val) && $val !== []) {
            $lines[] = $lead . $label;
            foreach (sesext_yaml_emit($val, $indent + 2, $child) as $l) { $lines[] = $l; }
        } else {
            $lines[] = $lead . $label . ' ' . ltrim(sesext_yaml_emit($val, 0)[0]);
        }
        $first = false;
    }
    return $lines;
}

function sesext_yaml_emit_list($list, $indent, $itemSchema) {
    $lines = [];
    foreach ($list as $n => $item) {
        if ($n > 0) { $lines[] = ''; }
        if (is_array($item) && $item !== [] && !array_is_list($item)) {
            // the first key shares the line with the dash, so the item's map sits 2 deeper
            foreach (sesext_yaml_emit_map($item, $indent + 2, $itemSchema, true) as $l) { $lines[] = $l; }
        } elseif (is_array($item) && $item !== [] && array_is_list($item)) {
            $lines[] = str_repeat(' ', $indent) . '-';
            foreach (sesext_yaml_emit_list($item, $indent + 2, null) as $l) { $lines[] = $l; }
        } else {
            $lines[] = str_repeat(' ', $indent) . '- ' . ltrim(sesext_yaml_emit($item, 0)[0]);
        }
    }
    return $lines;
}
?>
