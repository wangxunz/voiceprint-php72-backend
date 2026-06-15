<?php
// public/index.php - 入口路由 (PATH_INFO 模式，不需要 .htaccess)
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Database.php';
require_once __DIR__ . '/../utils/FileUpload.php';
require_once __DIR__ . '/../controllers/HealthController.php';
require_once __DIR__ . '/../controllers/VoiceprintController.php';
require_once __DIR__ . '/../controllers/ConversionController.php';

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

// PATH_INFO 路由: /VoicePrint/index.php/health -> route = /health
$pathInfo = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
$reqUri   = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';

if ($pathInfo !== '' && $pathInfo !== '/') {
    $route = rtrim($pathInfo, '/');
} else {
    $route = rtrim($reqUri, '/');
    $prefixes = array('/v1', '/VoicePrint');
    foreach ($prefixes as $prefix) {
        if (strpos($route, $prefix) === 0) {
            $route = (string)substr($route, strlen($prefix));
            if ($route === '') { $route = '/'; }
            break;
        }
    }
    if (strpos($route, '/index.php') === 0) {
        $route = (string)substr($route, strlen('/index.php'));
        if ($route === '') { $route = '/'; }
    }
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($method === 'GET' && $route === '/health') {
        (new HealthController())->check();
    }
    elseif ($method === 'POST' && $route === '/voiceprint/enroll') {
        (new VoiceprintController())->enroll();
    }
    elseif ($method === 'POST' && $route === '/conversion/submit') {
        (new ConversionController())->submit();
    }
    elseif ($method === 'GET' && $route === '/conversion/status') {
        (new ConversionController())->status();
    }
    elseif ($method === 'GET' && $route === '/conversion/result') {
        (new ConversionController())->result();
    }
    elseif ($method === 'POST' && $route === '/conversion/delete') {
        (new ConversionController())->delete();
    }
    elseif ($method === 'GET' && $route === '/conversion/history') {
        (new ConversionController())->history();
    }
    else {
        Response::error('接口不存在: ' . $route, 404);
    }
} catch (Throwable $e) {
    $config = require __DIR__ . '/../config.php';
    $msg = $config['debug']
        ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        : '服务器内部错误';
    Response::error($msg, 500);
}
