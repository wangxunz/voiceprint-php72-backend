<?php
// utils/Response.php - JSON 响应工具 (PHP 7.2 兼容)

class Response
{
    /**
     * @param mixed  $data
     * @param string $message
     * @param int    $httpCode
     */
    public static function success($data = null, $message = 'ok', $httpCode = 200)
    {
        http_response_code($httpCode);
        echo json_encode(array(
            'code'    => 0,
            'message' => $message,
            'data'    => $data,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param string $message
     * @param int    $httpCode
     * @param mixed  $data
     */
    public static function error($message = 'error', $httpCode = 400, $data = null)
    {
        http_response_code($httpCode);
        echo json_encode(array(
            'code'    => $httpCode,
            'message' => $message,
            'data'    => $data,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param array $list
     * @param int   $total
     * @param int   $page
     * @param int   $pageSize
     */
    public static function paginate(array $list, $total, $page, $pageSize)
    {
        self::success(array(
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
            'hasMore'  => ($page * $pageSize) < $total,
        ));
    }
}
