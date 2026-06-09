<?php
// public/index.php - ??????????????PHP 7.2 ???
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Database.php';
require_once __DIR__ . '/../utils/FileUpload.php';
require_once __DIR__ . '/../controllers/HealthController.php';
require_once __DIR__ . '/../controllers/VoiceprintController.php';
require_once __DIR__ . '/../controllers/ConversionController.php';

\ = isset(\['REQUEST_METHOD']) ? \['REQUEST_METHOD'] : 'GET';
\    = isset(\['REQUEST_URI']) ? parse_url(\['REQUEST_URI'], PHP_URL_PATH) : '/';
\    = rtrim(\, '/');

// ????????????????/v1?/VoicePrint ??
\ = \;
\ = array('/v1', '/VoicePrint');
foreach (\ as \) {
    if (strpos(\, \) === 0) {
        \ = (string)substr(\, strlen(\));
        if (\ === '') { \ = '/'; }
        break;
    }
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if (\ === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if (\ === 'GET' && \ === '/health') {
        (new HealthController())->check();
    }
    elseif (\ === 'POST' && \ === '/voiceprint/enroll') {
        (new VoiceprintController())->enroll();
    }
    elseif (\ === 'POST' && \ === '/conversion/submit') {
        (new ConversionController())->submit();
    }
    elseif (\ === 'GET' && \ === '/conversion/status') {
        (new ConversionController())->status();
    }
    elseif (\ === 'GET' && \ === '/conversion/result') {
        (new ConversionController())->getResult();
    }
    elseif (\ === 'POST' && \ === '/conversion/delete') {
        (new ConversionController())->delete();
    }
    elseif (\ === 'GET' && \ === '/conversion/history') {
        (new ConversionController())->history();
    }
    else {
        Response::error('?????: ' . \, 404);
    }
} catch (\Throwable \) {
    \ = require __DIR__ . '/../config.php';
    \ = \['debug']
        ? \->getMessage() . ' in ' . \->getFile() . ':' . \->getLine()
        : '???????';
    Response::error(\, 500);
}
