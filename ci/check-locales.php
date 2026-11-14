<?php

/**
 * Fails when locale/uk and locale/en have different keys, a key is empty,
 * or code uses a static key that is missing (plan 16.4, "lint").
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$prefix = 'plugins.generic.conferenceManager.';

/** @return array<string, string> */
function readPo(string $file): array
{
    $keys = [];
    $src = (string) file_get_contents($file);
    preg_match_all('/^msgid "(.+)"\nmsgstr "(.*)"$/m', $src, $m, PREG_SET_ORDER);
    foreach ($m as [, $id, $str]) {
        $keys[$id] = $str;
    }
    return $keys;
}

$uk = readPo("$root/locale/uk/locale.po");
$en = readPo("$root/locale/en/locale.po");
$problems = [];
foreach (array_diff_key($uk, $en) as $k => $_) {
    $problems[] = "only in uk: $k";
}
foreach (array_diff_key($en, $uk) as $k => $_) {
    $problems[] = "only in en: $k";
}
foreach (['uk' => $uk, 'en' => $en] as $lang => $keys) {
    foreach ($keys as $k => $v) {
        if (trim($v) === '') {
            $problems[] = "empty in $lang: $k";
        }
    }
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = (string) $file;
    if (!preg_match('/\.(php|tpl)$/', $path) || preg_match('#/(vendor|tests|ci)/#', $path)) {
        continue;
    }
    preg_match_all('/' . preg_quote($prefix, '/') . '[A-Za-z0-9_.]*[A-Za-z0-9_]/', (string) file_get_contents($path), $m);
    foreach (array_unique($m[0]) as $key) {
        // Keys ending in "." are prefixes completed at run time (enum labels).
        if (!isset($en[$key]) && !isset($en[$key . '.']) && !str_ends_with($key, '.')) {
            $dynamic = false;
            foreach (array_keys($en) as $known) {
                if (str_starts_with($known, $key . '.')) {
                    $dynamic = true;
                    break;
                }
            }
            if (!$dynamic) {
                $problems[] = 'missing key ' . $key . ' used in ' . substr($path, strlen($root) + 1);
            }
        }
    }
}

if ($problems) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(1);
}
echo 'Locales OK: ' . count($en) . " keys in uk and en\n";
