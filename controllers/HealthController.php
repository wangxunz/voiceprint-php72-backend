<?php
// controllers/HealthController.php - 健康检查 (PHP 7.2 兼容)

class HealthController
{
    public function check()
    {
        $config = require __DIR__ . '/../config.php';

        // 数据库连接
        $dbOk = false;
        try {
            Database::query('SELECT 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            // 不可用
        }

        // 目录权限
        $paths = $config['paths'];
        $uploadsWritable = is_writable($paths['uploads']);
        $resultsWritable = is_writable($paths['results']);

        // Python 检查
        $pythonPath = $config['python']['path'];
        $pythonOk = false;
        $pythonVersion = '';
        $pythonCmd = escapeshellcmd($pythonPath);
        $output = array();
        $retCode = 0;
        exec(sprintf('%s --version 2>&1', $pythonCmd), $output, $retCode);
        if ($retCode === 0 && !empty($output)) {
            $pythonOk = true;
            $pythonVersion = isset($output[0]) ? $output[0] : '';
        }

        Response::success(array(
            'status' => $dbOk ? 'online' : 'degraded',
            'checks' => array(
                'database'   => $dbOk ? 'ok' : 'error',
                'uploads'    => $uploadsWritable ? 'ok' : 'error',
                'results'    => $resultsWritable ? 'ok' : 'error',
                'python'     => $pythonOk ? 'ok' : 'error',
                'python_ver' => $pythonVersion,
            ),
            'time' => date('c'),
        ));
    }
}
