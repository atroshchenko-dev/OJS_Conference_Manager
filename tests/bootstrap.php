<?php

declare(strict_types=1);

/*
 * Test bootstrap. Works with the project's composer vendor/ (CI) or, when
 * that is absent, with the vendor of an OJS 3.5 checkout given in
 * OJS_VENDOR_AUTOLOAD (for Illuminate in integration tests).
 */

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} elseif (($ojs = getenv('OJS_VENDOR_AUTOLOAD')) && is_file($ojs)) {
    require $ojs;
}

spl_autoload_register(static function (string $class) use ($root): void {
    foreach ([
        'APP\\plugins\\generic\\conferenceManager\\tests\\' => $root . '/tests/',
        'APP\\plugins\\generic\\conferenceManager\\' => $root . '/',
    ] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
