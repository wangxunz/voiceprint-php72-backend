<?php
// utils/FileUpload.php - 文件上传处理 (PHP 7.2 兼容)

class FileUpload
{
    /** @var array */
    private $config;
    /** @var string */
    private $uploadDir;

    /**
     * @param string $subDir
     */
    public function __construct($subDir)
    {
        $this->config = require __DIR__ . '/../config.php';
        $paths = $this->config['paths'];
        $this->uploadDir = isset($paths[$subDir]) ? $paths[$subDir] : $paths['temp'];
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * @param string     $fieldName
     * @param array|null $allowedTypes
     * @param int|null   $maxSize
     * @return array     ['path','name','size','ext','mime','uniqueName']
     */
    public function receive($fieldName, $allowedTypes = null, $maxSize = null)
    {
        if (!isset($_FILES[$fieldName])) {
            Response::error(sprintf('缺少文件字段: %s', $fieldName));
        }

        $file = $_FILES[$fieldName];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $messages = array(
                UPLOAD_ERR_INI_SIZE   => '文件超过服务器限制',
                UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
                UPLOAD_ERR_PARTIAL    => '文件上传不完整',
                UPLOAD_ERR_NO_FILE    => '没有选择文件',
                UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录缺失',
                UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            );
            Response::error(isset($messages[$file['error']]) ? $messages[$file['error']] : '上传失败');
        }

        if ($allowedTypes === null) {
            $allowedTypes = $this->config['upload']['allowed_audio_types'];
        }
        if ($maxSize === null) {
            $maxSize = $this->config['upload']['voiceprint_max_size'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes, true)) {
            Response::error(sprintf(
                '不支持的文件类型: .%s，允许: %s', $ext,
                implode(', ', $allowedTypes)
            ));
        }

        if ($file['size'] > $maxSize) {
            $maxMB = round($maxSize / 1024 / 1024, 1);
            Response::error(sprintf('文件大小超过限制 (%sMB)', $maxMB));
        }

        // MIME 检测
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = array(
            'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp4',
            'audio/aac', 'audio/flac', 'audio/ogg', 'audio/x-m4a',
            'application/octet-stream'
        );
        if (!in_array($mime, $allowedMimes, true) && $mime !== 'application/octet-stream') {
            error_log(sprintf('FileUpload: unusual MIME type: %s for %s', $mime, $file['name']));
        }

        $uniqueName = uniqid('audio_', true) . '.' . $ext;
        $destPath = $this->uploadDir . '/' . $uniqueName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('文件保存失败', 500);
        }

        chmod($destPath, 0644);

        return array(
            'path'       => $destPath,
            'name'       => $file['name'],
            'size'       => $file['size'],
            'ext'        => $ext,
            'mime'       => $mime,
            'uniqueName' => $uniqueName,
        );
    }

    /**
     * @param string $taskId
     * @param string $ext
     * @return string
     */
    public static function getResultUrl($taskId, $ext = 'wav')
    {
        $config = require __DIR__ . '/../config.php';
        return rtrim($config['result_base_url'], '/') . sprintf('/%s.%s', $taskId, $ext);
    }
}
