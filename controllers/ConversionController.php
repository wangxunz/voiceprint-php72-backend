<?php
// controllers/ConversionController.php - 变声任务管理 (PHP 7.2 兼容)

class ConversionController
{
    /** @var array */
    private $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config.php';
    }

    /**
     * POST /v1/conversion/submit
     */
    public function submit()
    {
        $uploader = new FileUpload('songs');
        $file = $uploader->receive(
            'song_file',
            $this->config['upload']['allowed_audio_types'],
            $this->config['upload']['song_max_size']
        );

        $voiceprintId = isset($_POST['voiceprintId']) ? $_POST['voiceprintId'] : '';
        $songName     = isset($_POST['songName']) ? $_POST['songName'] : $file['name'];
        $pitchShift   = isset($_POST['pitchShift']) ? (int)$_POST['pitchShift'] : 0;

        if (empty($voiceprintId)) {
            Response::error('缺少 voiceprintId 参数');
        }

        // 验证声纹
        $voiceprint = Database::fetch(
            'SELECT * FROM voiceprints WHERE voiceprint_id = ? AND status = ?',
            array($voiceprintId, 'ready')
        );
        if (!$voiceprint) {
            Response::error('声纹不存在或尚未就绪，请先录制声纹');
        }

        $taskId = $this->generateTaskId();
        $now = date('Y-m-d H:i:s');

        Database::insert('conversion_tasks', array(
            'task_id'            => $taskId,
            'voiceprint_id'      => $voiceprintId,
            'song_file_path'     => $file['path'],
            'song_name'          => $songName,
            'song_original_name' => $file['name'],
            'song_size'          => $file['size'],
            'song_duration'      => 0,
            'pitch_shift'        => $pitchShift,
            'state'              => 'pending',
            'progress'           => 0,
            'created_at'         => $now,
            'updated_at'         => $now,
        ));

        $this->dispatchConversion($taskId);

        Response::success(array(
            'taskId'   => $taskId,
            'state'    => 'pending',
            'progress' => 0,
        ), '变声任务已提交');
    }

    /**
     * GET /v1/conversion/status
     */
    public function status()
    {
        $taskId = isset($_GET['taskId']) ? $_GET['taskId'] : '';
        if (empty($taskId)) {
            Response::error('缺少 taskId 参数');
        }

        $task = Database::fetch(
            'SELECT task_id, state, progress, error_message, song_duration, created_at, updated_at
             FROM conversion_tasks WHERE task_id = ?',
            array($taskId)
        );

        if (!$task) {
            Response::error('任务不存在', 404);
        }

        Response::success(array(
            'taskId'    => $task['task_id'],
            'state'     => $task['state'],
            'progress'  => (int)$task['progress'],
            'duration'  => (int)$task['song_duration'],
            'error'     => isset($task['error_message']) ? $task['error_message'] : null,
            'createdAt' => $task['created_at'],
            'updatedAt' => $task['updated_at'],
        ));
    }

    /**
     * GET /v1/conversion/result
     */
    public function getResult()
    {
        $taskId = isset($_GET['taskId']) ? $_GET['taskId'] : '';
        if (empty($taskId)) {
            Response::error('缺少 taskId 参数');
        }

        $task = Database::fetch(
            'SELECT task_id, state, result_path, song_name, song_duration, voiceprint_id
             FROM conversion_tasks WHERE task_id = ?',
            array($taskId)
        );

        if (!$task) {
            Response::error('任务不存在', 404);
        }

        if ($task['state'] !== 'completed') {
            Response::error(sprintf('任务尚未完成，当前状态: %s', $task['state']));
        }

        $resultUrl = FileUpload::getResultUrl($taskId);
        $originalUrl = '';

        // 检查伴奏文件
        $accompanimentPath = $this->config['paths']['temp'] . sprintf('/%s_accompaniment.wav', $taskId);
        if (file_exists($accompanimentPath)) {
            $originalUrl = rtrim($this->config['result_base_url'], '/')
                . sprintf('/temp/%s_accompaniment.wav', $taskId);
        }

        Response::success(array(
            'taskId'      => $task['task_id'],
            'resultUrl'   => $resultUrl,
            'originalUrl' => $originalUrl,
            'songName'    => $task['song_name'],
            'duration'    => (int)$task['song_duration'],
        ));
    }

    /**
     * POST /v1/conversion/delete
     */
    public function delete()
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        $taskId = is_array($input) && isset($input['taskId']) ? $input['taskId'] : '';

        if (empty($taskId)) {
            Response::error('缺少 taskId 参数');
        }

        $task = Database::fetch(
            'SELECT song_file_path, result_path FROM conversion_tasks WHERE task_id = ?',
            array($taskId)
        );

        if (!$task) {
            Response::error('任务不存在', 404);
        }

        // 删除关联文件
        if (!empty($task['song_file_path']) && file_exists($task['song_file_path'])) {
            @unlink($task['song_file_path']);
        }
        if (!empty($task['result_path']) && file_exists($task['result_path'])) {
            @unlink($task['result_path']);
        }

        // 删除临时文件
        $tempPattern = $this->config['paths']['temp'] . sprintf('/%s_*', $taskId);
        $tempFiles = glob($tempPattern);
        if ($tempFiles !== false) {
            foreach ($tempFiles as $f) {
                @unlink($f);
            }
        }

        Database::delete('conversion_tasks', 'task_id = ?', array($taskId));

        Response::success(null, '已删除');
    }

    /**
     * GET /v1/conversion/history
     */
    public function history()
    {
        $page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $pageSize = isset($_GET['pageSize']) ? min(50, max(1, (int)$_GET['pageSize'])) : $this->config['history_page_size'];
        $offset   = ($page - 1) * $pageSize;

        $totalRow = Database::fetch('SELECT COUNT(*) as cnt FROM conversion_tasks', array());
        $total = isset($totalRow['cnt']) ? (int)$totalRow['cnt'] : 0;

        $tasks = Database::fetchAll(
            'SELECT task_id, voiceprint_id, song_name, state, progress, song_duration,
                    pitch_shift, created_at, updated_at
             FROM conversion_tasks
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?',
            array($pageSize, $offset)
        );

        $list = array_map(function ($t) {
            return array(
                'taskId'     => $t['task_id'],
                'songName'   => $t['song_name'],
                'state'      => $t['state'],
                'progress'   => (int)$t['progress'],
                'duration'   => (int)$t['song_duration'],
                'pitchShift' => (int)$t['pitch_shift'],
                'createdAt'  => $t['created_at'],
            );
        }, $tasks);

        Response::paginate($list, $total, $page, $pageSize);
    }

    /**
     * @param string $taskId
     */
    private function dispatchConversion($taskId)
    {
        $pythonPath = $this->config['python']['path'];
        $scriptPath = $this->config['python']['convert_script'];
        $pythonCmd = escapeshellcmd($pythonPath);
        $scriptCmd = escapeshellarg($scriptPath);
        $idArg = escapeshellarg($taskId);
        $logFile = escapeshellarg($this->config['paths']['logs'] . '/convert.log');

        $cmd = sprintf(
            '%s %s --task-id %s >> %s 2>&1 &',
            $pythonCmd, $scriptCmd, $idArg, $logFile
        );

        if (stripos(PHP_OS, 'WIN') === 0) {
            pclose(popen(sprintf('start /B cmd /c "%s"', $cmd), 'r'));
        } else {
            exec($cmd);
        }

        error_log(sprintf('ConversionController: dispatched conversion for %s', $taskId));
    }

    /**
     * @return string
     */
    private function generateTaskId()
    {
        if (function_exists('random_bytes')) {
            return 'task_' . bin2hex(random_bytes(12));
        }
        return 'task_' . md5(uniqid(mt_rand(), true));
    }
}
