<?php
// public/index.php - 入口路由 (PHP 7.2 兼容)
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Database.php';
require_once __DIR__ . '/../utils/FileUpload.php';
require_once __DIR__ . '/../controllers/HealthController.php';
require_once __DIR__ . '/../controllers/VoiceprintController.php';
require_once __DIR__ . '/../controllers/ConversionController.php';

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri    = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
$uri    = rtrim($uri, '/');

$basePath = '/v1';
if (strpos($uri, $basePath) === 0) {
    $route = (string)substr($uri, strlen($basePath));
    if ($route === '') { $route = '/'; }
} else {
    $route = $uri;
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
        $ctrl = new HealthController();
        $ctrl->check();
    }
    elseif ($method === 'POST' && $route === '/voiceprint/enroll') {
        $ctrl = new VoiceprintController();
        $ctrl->enroll();
    }
    elseif ($method === 'POST' && $route === '/conversion/submit') {
        $ctrl = new ConversionController();
        $ctrl->submit();
    }
    elseif ($method === 'GET' && $route === '/conversion/status') {
        $ctrl = new ConversionController();
        $ctrl->status();
    }
    elseif ($method === 'GET' && $route === '/conversion/result') {
        $ctrl = new ConversionController();
        $ctrl->getResult();
    }
    elseif ($method === 'POST' && $route === '/conversion/delete') {
        $ctrl = new ConversionController();
        $ctrl->delete();
    }
    elseif ($method === 'GET' && $route === '/conversion/history') {
        $ctrl = new ConversionController();
        $ctrl->history();
    }
    else {
        Response::error('接口不存在', 404);
    }
} catch (\Throwable $e) {
    $config = require __DIR__ . '/../config.php';
    $msg = $config['debug']
        ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        : '服务器内部错误';
    Response::error($msg, 500);
}
