<?php
// index_debug.php - 诊断入口（显示所有错误）
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

echo "<pre>=== PHP ===\n";
echo "Version: " . phpversion() . "\n";
echo "exec(): " . (function_exists('exec') ? 'YES' : 'NO') . "\n";
echo "PDO: " . (class_exists('PDO') ? 'YES' : 'NO') . "\n";

echo "\n=== Files ===\n";
$dirs = ['utils', 'controllers'];
foreach ($dirs as $d) {
    $path = __DIR__ . '/../' . $d;
    if (is_dir($path)) {
        foreach (scandir($path) as $f) {
            if ($f === '.' || $f === '..') continue;
            echo "  $d/$f: " . (file_exists("$path/$f") ? 'OK' : 'MISSING') . "\n";
        }
    } else {
        echo "  $d/: MISSING directory\n";
    }
}

echo "\n=== Config ===\n";
try {
    $config = require __DIR__ . '/../config.php';
    echo "config.php loaded\n";
} catch (Throwable $e) {
    echo "config.php ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Include Tests ===\n";
$includes = [
    'utils/Response.php',
    'utils/Database.php',
    'utils/FileUpload.php',
    'controllers/HealthController.php',
];
foreach ($includes as $f) {
    try {
        require_once __DIR__ . '/../' . $f;
        echo "  $f: loaded\n";
    } catch (Throwable $e) {
        echo "  $f: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n=== END ===";
