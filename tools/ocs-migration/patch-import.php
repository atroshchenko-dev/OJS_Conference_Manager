<?php
// Patches import.php of ocs-to-ojs (commit 94b421e) for the official OJS
// Docker images, which run on Alpine Linux. The musl iconv there has no
// //TRANSLIT or //IGNORE, so iconv() returned false for every non-ASCII
// value read from the OJS database. The Ukrainian name of the Author group
// ("Автор") became an empty string and every paper failed with
// "There is no user group defined ... with the user group ''".
// Usage: php patch-import.php <path to ocs-to-ojs/import.php>
declare(strict_types=1);

$file = $argv[1] ?? '';
$code = (string) @file_get_contents($file);
$old = "if ((\$newValue = iconv('UTF-8', 'UTF-8//TRANSLIT', \$value)) === false) {\n"
    . "                        // Fallback to removing them\n"
    . "                        \$newValue = iconv('UTF-8', 'UTF-8//IGNORE', \$value);\n"
    . "                    }";
$new = "\$newValue = mb_convert_encoding((string) \$value, 'UTF-8', 'UTF-8');";
if (str_contains($code, $new)) {
    echo "import.php is already patched\n";
    exit(0);
}
if (!str_contains($code, $old)) {
    fwrite(STDERR, "import.php: the iconv block was not found, check the ocs-to-ojs version\n");
    exit(1);
}
file_put_contents($file, str_replace($old, $new, $code));
echo "import.php patched\n";
