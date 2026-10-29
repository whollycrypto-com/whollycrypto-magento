<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefix = 'WhollyCrypto\\Payment\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $relative = substr($class, strlen($prefix));
    $base = __DIR__ . '/';
    if (str_starts_with($relative, 'Sdk\\')) {
        $relative = substr($relative, 4);
        $base .= 'vendor/whollycrypto/src/';
    }
    if (!preg_match('/\A[A-Za-z0-9_\\\\]+\z/D', $relative)) { return; }
    $file = $base . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) { require_once $file; }
});
