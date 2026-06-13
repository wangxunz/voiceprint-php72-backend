<?php
class ConversionController
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config.php';
    }

    /**
     * POST /v1/conversion/submit
     * 上传歌曲文件，提交变声任务
     */
    public function submit(): void
    {
        $uploader = new FileUpload('songs');
        $file = $uploader->receive(
            'song_file',
            $this->config['upload']['allowed_audio_types'],
            $this->config['upload']['song_max_size']
        );

        $voiceprintId = $_POST['voiceprintId'] ?? '';
        $songName     = $_POST['songName'] ?? $file['name'];
        $pitchShift   = (int) ($_POST['pitchShift'] ?? 0);

        if (empty($voiceprintId)) {
            Response::error('缺少 voiceprintId 参数');
        }

        // 验证声纹是否存在且可用
        $voiceprint = Database::fetch(
            'SELECT * FROM voiceprints WHERE voiceprint_id = ? AND status = ?',
            [$voiceprintId, 'ready']
        );
        if (!$voiceprint) {
            Response::error('声纹不存在或尚未就绪，请先录制声纹');
        }

        // 创建任务
        $taskId = $this->generateTaskId();
        $now = date('Y-m-d H:i:s');

        Database::insert('conversion_tasks', [
            'task_id'        => $taskId,
            'voiceprint_id'  => $voiceprintId,
            'song_file_path' => $file['path'],
            'song_name'      => $songName,
            'song_original_name' => $file['name'],
            'song_size'      => $file['size'],
            'song_duration'  => 0,
            'pitch_shift'    => $pitchShift,
            'state'          => 'pending',  // pending -> separating -> converting -> rendering -> completed / failed
            'progress'       => 0,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // 异步启动变声流程
        $this->dispatchConversion($taskId);

        Response::success([
            'taskId'    => $taskId,
            'state'     => 'pending',
            'progress'  => 0,
        ], '变声任务已提交');
    }

    /**
     * GET /v1/conversion/status
     * 查询任务状态
     */
    public function status(): void
    {
        $taskId = $_GET['taskId'] ?? '';
        if (empty($taskId)) {
            Response::error('缺少 taskId 参数');
        }

        $task = Database::fetch(
            'SELECT task_id, state, progress, error_message, song_duration, created_at, updated_at
             FROM conversion_tasks WHERE task_id = ?',
            [$taskId]
        );

        if (!$task) {
            Response::error('任务不存在', 404);
        }

        Response::success([
            'taskId'    => $task['task_id'],
            'state'     => $task['state'],
            'progress'  => (int) $task['progress'],
            'duration'  => (int) $task['song_duration'],
            'error'     => $task['error_message'] ?? null,
            'createdAt' => $task['created_at'],
            'updatedAt' => $task['updated_at'],
        ]);
    }

    /**
     * GET /v1/conversion/result
     * 获取变声结果
     */
    public function result(): void
    {
        $taskId = $_GET['taskId'] ?? '';
        if (empty($taskId)) {
            Response::error('缺少 taskId 参数');
        }

        $task = Database::fetch(
            'SELECT task_id, state, result_path, song_name, song_duration, voiceprint_id
             FROM conversion_tasks WHERE task_id = ?',
            [$taskId]
        );

        if (!$task) {
            Response::error('任务不存在', 404);
        }

        if ($task['state'] !== 'completed') {
            Response::error(sprintf('任务尚未完成，当前状态: %s', $task['state']));
        }

        $resultUrl = FileUpload::getResultUrl($taskId);
        $originalUrl = '';

        // 检查是否存在分离后的伴奏（用于对比试听）
        $accompanimentPath = $this->config['paths']['temp'] . sprintf('/%s_accompaniment.wav', $taskId);
        if (file_exists($accompanimentPath)) {
            $originalUrl = rtrim($this->config['result_base_url'], '/')
                . sprintf('/temp/%s_accompaniment.wav', $taskId);
        }

        Response::success([
            'taskId'      => $task['task_id'],
            'resultUrl'   => $resultUrl,
            'originalUrl' => $originalUrl,
            'songName'    => $task['song_name'],
            'duration'    => (int) $task['song_duration'],
        ]);
    }

    /**
     * POST /v1/conversion/delete
     * 删除任务及关联文件
     */
    public function delete(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $taskId = $input['taskId'] ?? '';

        if (empty($taskId)) {
            Response::error('缺少 taskId 参数');
        }

        $task = Database::fetch(
            'SELECT song_file_path, result_path FROM conversion_tasks WHERE task_id = ?',
            [$taskId]
        );

        if (!$task) {
            Response::error('任务不存在', 404);
        }

        // 删除关联文件
        if ($task['song_file_path'] && file_exists($task['song_file_path'])) {
            @unlink($task['song_file_path']);
        }
        if ($task['result_path'] && file_exists($task['result_path'])) {
            @unlink($task['result_path']);
        }

        // 删除临时文件
        $tempPattern = $this->config['paths']['temp'] . sprintf('/%s_*', $taskId);
        foreach (glob($tempPattern) as $f) {
            @unlink($f);
        }

        Database::delete('conversion_tasks', 'task_id = ?', [$taskId]);

        Response::success(null, '已删除');
    }

    /**
     * GET /v1/conversion/history
     * 获取历史记录
     */
    public function history(): void
    {
        $page     = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($_GET['pageSize'] ?? $this->config['history_page_size'])));
        $offset   = ($page - 1) * $pageSize;

        $total = Database::fetch('SELECT COUNT(*) as cnt FROM conversion_tasks')['cnt'];

        $tasks = Database::fetchAll(
            'SELECT task_id, voiceprint_id, song_name, state, progress, song_duration,
                    pitch_shift, created_at, updated_at
             FROM conversion_tasks
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?',
            [$pageSize, $offset]
        );

        $list = array_map(function ($t) {
            return [
                'taskId'      => $t['task_id'],
                'songName'    => $t['song_name'],
                'state'       => $t['state'],
                'progress'    => (int) $t['progress'],
                'duration'    => (int) $t['song_duration'],
                'pitchShift'  => (int) $t['pitch_shift'],
                'createdAt'   => $t['created_at'],
            ];
        }, $tasks);

        Response::paginate($list, (int) $total, $page, $pageSize);
    }

    /**
     * 异步调度变声流程
     */
    private function dispatchConversion(string $taskId): void
    {
        $pythonPath = $this->config['python']['path'];
        $scriptPath = $this->config['python']['convert_script'];
        $pythonCmd = escapeshellcmd($pythonPath);
        $scriptCmd = escapeshellarg($scriptPath);
        $idArg = escapeshellarg($taskId);
        $logFile = escapeshellarg($this->config['paths']['logs'] . '/convert.log');

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
            '%s %s %s --task-id %s >> %s 2>&1 &',
            $envVars, $pythonCmd, $scriptCmd, $idArg, $logFile
        );

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen(sprintf('start /B cmd /c "%s"', $cmd), 'r'));
        } else {
            exec($cmd);
        }

        error_log(sprintf('ConversionController: dispatched conversion for %s', $taskId));
    }

    private function generateTaskId(): string
    {
        return 'task_' . bin2hex(random_bytes(12));
    }
}
