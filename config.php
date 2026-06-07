<?php
// config.php - 应用配置 (PHP 7.2 兼容)
return array(
    'db' => array(
        'host'     => getenv('DB_HOST') ? getenv('DB_HOST') : '127.0.0.1',
        'port'     => getenv('DB_PORT') ? intval(getenv('DB_PORT')) : 3306,
        'database' => getenv('DB_NAME') ? getenv('DB_NAME') : 'voiceprint_converter',
        'username' => getenv('DB_USER') ? getenv('DB_USER') : 'root',
        'password' => getenv('DB_PASS') ? getenv('DB_PASS') : '',
        'charset'  => 'utf8mb4',
    ),
    'paths' => array(
        'uploads'      => __DIR__ . '/uploads',
        'voiceprints'  => __DIR__ . '/uploads/voiceprints',
        'songs'        => __DIR__ . '/uploads/songs',
        'temp'         => __DIR__ . '/uploads/temp',
        'results'      => __DIR__ . '/results',
        'logs'         => __DIR__ . '/logs',
    ),
    'upload' => array(
        'voiceprint_max_size' => 10 * 1024 * 1024,
        'song_max_size'       => 30 * 1024 * 1024,
        'voiceprint_min_duration' => 5,
        'voiceprint_max_duration' => 120,
        'allowed_audio_types' => array('wav', 'mp3', 'm4a', 'aac', 'flac', 'ogg'),
    ),
    'python' => array(
        'path'          => getenv('PYTHON_PATH') ? getenv('PYTHON_PATH') : 'python3',
        'enroll_script' => __DIR__ . '/workers/voiceprint_enroll.py',
        'convert_script'=> __DIR__ . '/workers/voice_convert.py',
        'spleeter_path' => getenv('SPLEETER_PATH') ? getenv('SPLEETER_PATH') : 'spleeter',
        'rvc_path'      => getenv('RVC_PATH') ? getenv('RVC_PATH') : '',
        'timeout'       => 600,
    ),
    'result_base_url' => getenv('RESULT_BASE_URL') ? getenv('RESULT_BASE_URL') : 'https://api.example.com/results',
    'history_page_size' => 20,
    'debug' => getenv('APP_DEBUG') === 'true',
);
