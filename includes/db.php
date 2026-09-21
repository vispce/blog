<?php
// =========================================
// includes/db.php  —  数据库连接配置
// =========================================
// 修改下方常量以匹配你的服务器环境

// 确保上传目录存在（自动创建，无需手动建文件夹）
foreach ([__DIR__.'/../uploads', __DIR__.'/../uploads/picture'] as $_d) {
    if (!is_dir($_d)) mkdir($_d, 0755, true);
}
unset($_d);

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'blog');          // 改为你的数据库名
define('DB_USER', 'root');          // 改为你的数据库用户名
define('DB_PASS', 'login');          // 改为你的数据库密码
define('DB_CHARSET', 'utf8mb4');

/**
 * 返回全局单例 PDO 连接
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => '数据库连接失败: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

/**
 * 输出 JSON 并终止
 */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
