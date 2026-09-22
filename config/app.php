<?php
/**
 * config/app.php - Configuracion publica de SIG-GO
 * Sistema Integral de Gestion de Gastos de Operacion.
 * Las credenciales van en config/db.php (excluido de git).
 */
define('EMPRESA_NOMBRE', 'El Grano de Oro');
define('EMPRESA_CORTO',  'GDO');
define('SUCURSAL_NOMBRE', 'Sucursal Ferias');
define('APP_NAME',    'SIG-GO');
define('APP_TITULO',  'SIG-GO · Gestion de Gastos de Operacion');
define('APP_DESCRIPCION', 'Sistema Integral de Gestion de Gastos de Operacion');
define('APP_VERSION', '2.0.0');

$_protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_raiz_proyecto = realpath(__DIR__ . '/..');
$_doc_root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
if ($_doc_root && $_raiz_proyecto && str_starts_with($_raiz_proyecto, $_doc_root)) {
    $_ruta_relativa = substr($_raiz_proyecto, strlen($_doc_root));
    $_ruta_relativa = str_replace('\\', '/', $_ruta_relativa);
    define('APP_URL', $_protocolo . '://' . $_host . $_ruta_relativa);
} else {
    define('APP_URL', $_protocolo . '://' . $_host);
}
unset($_protocolo, $_host, $_raiz_proyecto, $_doc_root, $_ruta_relativa);

date_default_timezone_set('America/Tijuana');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $port = defined('DB_PORT') ? (int) DB_PORT : 3306;
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, $port, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Error de conexion a la base de datos: " . $e->getMessage());
        }
    }
    return $pdo;
}
function db_one(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql); $stmt->execute($params);
    $row = $stmt->fetch(); return $row === false ? null : $row;
}
function db_all(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}
function db_exec(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt->rowCount();
}
function db_last_id(): int { return (int) db()->lastInsertId(); }
