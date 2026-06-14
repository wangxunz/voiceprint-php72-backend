<?php
// utils/Database.php - PDO 数据库封装 (PHP 7.2 兼容)

class Database
{
    /** @var PDO|null */
    private static $instance = null;

    /**
     * @return PDO
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config.php';
            $db = $config['db'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'], $db['port'], $db['database'], $db['charset']
            );
            $options = array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            );
            self::$instance = new PDO($dsn, $db['username'], $db['password'], $options);
        }
        return self::$instance;
    }

    /**
     * @param string $sql
     * @param array  $params
     * @return PDOStatement
     */
    public static function query($sql, array $params = array())
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param string $sql
     * @param array  $params
     * @return array|null
     */
    public static function fetch($sql, array $params = array())
    {
        $result = self::query($sql, $params)->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * @param string $sql
     * @param array  $params
     * @return array
     */
    public static function fetchAll($sql, array $params = array())
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * @param string $table
     * @param array  $data
     * @return int
     */
    public static function insert($table, array $data)
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $columns, $placeholders);
        self::query($sql, array_values($data));
        return (int) self::getInstance()->lastInsertId();
    }

    /**
     * @param string $table
     * @param array  $data
     * @param string $where
     * @param array  $whereParams
     * @return int
     */
    public static function update($table, array $data, $where, array $whereParams = array())
    {
        $mapFunc = function ($col) {
            return sprintf('%s = ?', $col);
        };
        $sets = implode(', ', array_map($mapFunc, array_keys($data)));
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, $sets, $where);
        $stmt = self::query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    /**
     * @param string $table
     * @param string $where
     * @param array  $params
     * @return int
     */
    public static function delete($table, $where, array $params = array())
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', $table, $where);
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }
}
