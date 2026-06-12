<?php
// 根目录入口 - 转发到 public/（调试模式）
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// 先确认自己还活着
echo "OK: root index.php reached\n";

// 检查 public/index.php 是否存在
$publicIndex = __DIR__ . '/public/index.php';
echo "Looking for: $publicIndex\n";
echo "Exists: " . (file_exists($publicIndex) ? 'YES' : 'NO - FILE MISSING!') . "\n";

if (!file_exists($publicIndex)) {
    // 列出当前目录内容
    echo "\nDirectory listing of " . __DIR__ . ":\n";
    $files = scandir(__DIR__);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = __DIR__ . '/' . $f;
        echo "  " . (is_dir($full) ? '[DIR]' : '[FILE]') . " $f\n";
    }
    exit;
}

echo "\nRequiring $publicIndex ...\n";
require $publicIndex;
