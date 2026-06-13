<?php
class VoiceprintController
{
    private array $config;
    private FileUpload $uploader;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config.php';
        $this->uploader = new FileUpload('voiceprints');
    }

    /**
     * POST /v1/voiceprint/enroll
     * 接收声纹样本音频，存入数据库，触发 Python 声纹提取
     */
    public function enroll(): void
    {
        $file = $this->uploader->receive(
            'voice_sample',
            ['wav', 'mp3', 'm4a', 'flac'],
            $this->config['upload']['voiceprint_max_size']
        );

        $duration = (int) ($_POST['duration'] ?? 0);

        // 创建声纹记录
        $voiceprintId = $this->generateId();
        $now = date('Y-m-d H:i:s');

        $vpId = Database::insert('voiceprints', [
            'voiceprint_id'  => $voiceprintId,
            'file_path'      => $file['path'],
            'file_name'      => $file['name'],
            'file_size'      => $file['size'],
            'duration'       => $duration,
            'status'         => 'pending',  // pending -> extracting -> ready -> failed
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // 异步调用 Python 提取声纹特征
        $this->dispatchExtraction($voiceprintId, $file['path']);

        Response::success([
            'voiceprintId' => $voiceprintId,
            'duration'     => $duration,
            'status'       => 'pending',
        ], '声纹样本已上传，正在提取特征');
    }

    /**
     * 异步调度 Python 声纹提取脚本
     */
    private function dispatchExtraction(string $voiceprintId, string $filePath): void
    {
        $pythonPath = $this->config['python']['path'];
        $scriptPath = $this->config['python']['enroll_script'];
        $pythonCmd = escapeshellcmd($pythonPath);
        $scriptCmd = escapeshellarg($scriptPath);
        $idArg = escapeshellarg($voiceprintId);
        $fileArg = escapeshellarg($filePath);
        $logFile = escapeshellarg($this->config['paths']['logs'] . '/enroll.log');

        $db = $this->config['db'];
        $envVars = sprintf(
            'DB_HOST=%s DB_PORT=%d DB_NAME=%s DB_USER=%s DB_PASS=%s PYTHONIOENCODING=utf-8',
            escapeshellarg($db['host']),
            $db['port'],
            escapeshellarg($db['database']),
            escapeshellarg($db['username']),
            escapeshellarg($db['password'])
        );

        $cmd = sprintf(
            '%s %s %s --voiceprint-id %s --audio-file %s >> %s 2>&1 &',
            $envVars, $pythonCmd, $scriptCmd, $idArg, $fileArg, $logFile
        );

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows 异步执行
            pclose(popen(sprintf('start /B cmd /c "%s"', $cmd), 'r'));
        } else {
            exec($cmd);
        }

        error_log(sprintf('VoiceprintController: dispatched extraction for %s', $voiceprintId));
    }

    private function generateId(): string
    {
        return 'vp_' . bin2hex(random_bytes(16));
    }
}
