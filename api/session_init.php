<?php
declare(strict_types=1);

$sessionPath = __DIR__ . '/_sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}
session_save_path($sessionPath);
session_set_cookie_params([
    'path' => '/',
    'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

