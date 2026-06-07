<?php
// controllers/VoiceprintController.php - 声纹注册 (PHP 7.2 兼容)

class VoiceprintController
{
    /** @var array */
    private $config;
    /** @var FileUpload */
    private $uploader;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config.php';
        $this->uploader = new FileUpload('voiceprints');
    }

    /**
     * POST /v1/voiceprint/enroll
     */
    public function enroll()
    {
        $file = $this->uploader->receive(
            'voice_sample',
            array('wav', 'mp3', 'm4a', 'flac'),
            $this->config['upload']['voiceprint_max_size']
        );

        $duration = isset($_POST['duration']) ? (int)$_POST['duration'] : 0;

        $voiceprintId = $this->generateId();
        $now = date('Y-m-d H:i:s');

        $vpId = Database::insert('voiceprints', array(
            'voiceprint_id' => $voiceprintId,
            'file_path'     => $file['path'],
            'file_name'     => $file['name'],
            'file_size'     => $file['size'],
            'duration'      => $duration,
            'status'        => 'pending',
            'created_at'    => $now,
            'updated_at'    => $now,
        ));

        // 异步提取声纹
        $this->dispatchExtraction($voiceprintId, $file['path']);

        Response::success(array(
            'voiceprintId' => $voiceprintId,
            'duration'     => $duration,
            'status'       => 'pending',
        ), '声纹样本已上传，正在提取特征');
    }

    /**
     * @param string $voiceprintId
     * @param string $filePath
     */
    private function dispatchExtraction($voiceprintId, $filePath)
    {
        $pythonPath = $this->config['python']['path'];
        $scriptPath = $this->config['python']['enroll_script'];
        $pythonCmd = escapeshellcmd($pythonPath);
        $scriptCmd = escapeshellarg($scriptPath);
        $idArg = escapeshellarg($voiceprintId);
        $fileArg = escapeshellarg($filePath);
        $logFile = escapeshellarg($this->config['paths']['logs'] . '/enroll.log');

        $cmd = sprintf(
            '%s %s --voiceprint-id %s --audio-file %s >> %s 2>&1 &',
            $pythonCmd, $scriptCmd, $idArg, $fileArg, $logFile
        );

        if (stripos(PHP_OS, 'WIN') === 0) {
            pclose(popen(sprintf('start /B cmd /c "%s"', $cmd), 'r'));
        } else {
            exec($cmd);
        }

        error_log(sprintf('VoiceprintController: dispatched extraction for %s', $voiceprintId));
    }

    /**
     * @return string
     */
    private function generateId()
    {
        if (function_exists('random_bytes')) {
            return 'vp_' . bin2hex(random_bytes(16));
        }
        // PHP 7.2 fallback
        return 'vp_' . md5(uniqid(mt_rand(), true) . microtime(true));
    }
}
