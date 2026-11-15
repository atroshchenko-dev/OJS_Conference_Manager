<?php

/**
 * Dependency rules of plan 7.2 (a lightweight stand-in for deptrac):
 *  - classes/Domain and classes/Jurisdiction are plain PHP: no PKP\, APP\ (outside
 *    the plugin), Illuminate\ (Carbon is allowed);
 *  - Repo::, DAORegistry::, Hook:: are used only in classes/Core and the main class;
 *  - no floating point in fee and invoice code (FEE-03).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = 'APP\\plugins\\generic\\conferenceManager\\';
$problems = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/classes", FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = (string) $file;
    if (!str_ends_with($path, '.php')) {
        continue;
    }
    $rel = substr($path, strlen($root) + 1);
    $src = (string) file_get_contents($path);
    $pure = str_starts_with($rel, 'classes/Domain/') || str_starts_with($rel, 'classes/Jurisdiction/');

    if ($pure) {
        preg_match_all('/^use\s+([^;]+);/m', $src, $m);
        foreach ($m[1] as $import) {
            $import = ltrim(trim($import), '\\');
            $isPlugin = str_starts_with($import, $plugin);
            if (
                (!$isPlugin && (str_starts_with($import, 'PKP\\') || str_starts_with($import, 'APP\\') || str_starts_with($import, 'Illuminate\\')))
                || ($isPlugin && !preg_match('/^' . preg_quote($plugin, '/') . 'classes\\\\(Domain|Jurisdiction)\\\\/', $import))
            ) {
                $problems[] = "$rel: pure layer imports $import";
            }
        }
        if (preg_match('/\\\\?(PKP|Illuminate)\\\\[A-Za-z]/', preg_replace('/^use .*$/m', '', $src) ?? '')) {
            $problems[] = "$rel: pure layer references a framework class inline";
        }
    }

    // FEE-03: money is integer minor units; no floating point in financial code.
    if (str_starts_with($rel, 'classes/Domain/Fee/') || str_contains($rel, 'Fee') || str_contains($rel, 'Invoice')) {
        $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#', "/'(?:[^'\\\\]|\\\\.)*'/"], '', $src) ?? '';
        if (preg_match('/\b(float|floatval|round|number_format|fdiv)\b|\b\d+\.\d+\b/i', $code, $fm)) {
            $problems[] = "$rel: floating point in financial code ({$fm[0]})";
        }
    }

    if (!str_starts_with($rel, 'classes/Core/') && preg_match('/\b(Repo|DAORegistry|Hook)::/', $src, $mm)) {
        $problems[] = "$rel: {$mm[1]}:: outside classes/Core";
    }
}

if ($problems) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(1);
}
echo "Layers OK\n";
