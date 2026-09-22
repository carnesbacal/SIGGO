<?php
/**
 * ============================================================================
 * config/backups_helpers.php  ·  PROYECTO MANTENIMIENTO · VERSIÓN INFINITYFREE
 * ============================================================================
 * Igual que el original, pero blindado para hosting que DESHABILITA
 * exec()/shell_exec() (como InfinityFree). En ese caso salta automáticamente
 * al método "PHP puro" sin generar warnings.
 *
 * El backup se comprime con gzip. Cada backup se registra en la tabla
 * backups_realizados para auditoría.
 * ============================================================================
 */

require_once __DIR__ . '/db.php';

// Configuración general
define('BACKUPS_DIR', __DIR__ . '/../backups');
define('BACKUPS_RETENCION_DIAS', 30);
define('BACKUPS_MAX_GUARDAR', 60);

/**
 * ¿La función PHP existe y NO está en la lista disable_functions del hosting?
 * En InfinityFree shell_exec y exec están deshabilitadas, así que esto
 * devuelve false y el sistema usa el respaldo en PHP puro.
 */
function _func_habilitada(string $fn): bool {
    if (!function_exists($fn)) return false;
    $deshabilitadas = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
    return !in_array(strtolower($fn), $deshabilitadas, true);
}

// ============================================================================
// Detectar mysqldump
// ============================================================================

/**
 * Intenta localizar el ejecutable mysqldump.
 * Retorna la ruta completa o null si no se encuentra / no se puede ejecutar.
 */
function detectar_mysqldump(): ?string {
    static $cache = null;
    if ($cache !== null) return $cache === false ? null : $cache;

    // Si el hosting no permite ejecutar binarios, ni lo intentamos.
    if (!_func_habilitada('exec')) {
        $cache = false;
        return null;
    }

    $candidatos = [];

    if (PHP_OS_FAMILY === 'Windows') {
        $candidatos = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\xampp\\mariadb\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
            'C:\\Program Files\\MariaDB 10.6\\bin\\mysqldump.exe',
        ];
        if (_func_habilitada('shell_exec')) {
            $en_path = shell_exec('where mysqldump 2>nul');
            if ($en_path) {
                foreach (explode("\n", trim($en_path)) as $linea) {
                    $linea = trim($linea);
                    if ($linea && file_exists($linea)) $candidatos[] = $linea;
                }
            }
        }
    } else {
        $candidatos = ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/lampp/bin/mysqldump'];
        if (_func_habilitada('shell_exec')) {
            $en_path = shell_exec('which mysqldump 2>/dev/null');
            if ($en_path && file_exists(trim($en_path))) $candidatos[] = trim($en_path);
        }
    }

    foreach ($candidatos as $ruta) {
        if (file_exists($ruta) && is_executable($ruta)) {
            $cache = $ruta;
            return $ruta;
        }
    }

    $cache = false;
    return null;
}


// ============================================================================
// Generar backup
// ============================================================================

/**
 * Genera un respaldo de la BD completa.
 *
 * @param string $tipo  'manual' o 'automatico'
 * @param int|null $usuario_id  Quién lo solicitó (null si fue cron)
 * @param string $notas  Comentario opcional
 * @return array ['ok' => bool, 'archivo' => '...', 'tamano' => N, 'mensaje' => '...', 'metodo' => 'mysqldump' o 'php']
 */
function generar_backup(string $tipo = 'manual', ?int $usuario_id = null, string $notas = ''): array {
    if (!is_dir(BACKUPS_DIR)) {
        if (!@mkdir(BACKUPS_DIR, 0755, true)) {
            return ['ok' => false, 'mensaje' => 'No se pudo crear la carpeta backups/.'];
        }
    }

    $timestamp = date('Y-m-d_His');
    $nombre_archivo = "backup_{$timestamp}.sql.gz";
    $ruta_archivo = BACKUPS_DIR . '/' . $nombre_archivo;

    $mysqldump = detectar_mysqldump();
    $metodo = '';
    $ok = false;
    $mensaje_error = '';

    if ($mysqldump !== null) {
        $metodo = 'mysqldump';
        $ok = backup_via_mysqldump($mysqldump, $ruta_archivo, $mensaje_error);
    }

    if (!$ok) {
        // Fallback con PHP puro (el que usa InfinityFree)
        $metodo = 'php';
        try {
            backup_via_php($ruta_archivo);
            $ok = true;
            $mensaje_error = '';
        } catch (Throwable $e) {
            $ok = false;
            $mensaje_error = ($mensaje_error ? $mensaje_error . ' | ' : '') . 'PHP: ' . $e->getMessage();
        }
    }

    if (!$ok || !file_exists($ruta_archivo)) {
        registrar_backup_en_bd($nombre_archivo, 0, $tipo, $usuario_id, $notas, false, $mensaje_error);
        return [
            'ok' => false,
            'archivo' => null,
            'tamano' => 0,
            'mensaje' => 'Error al generar backup: ' . $mensaje_error,
            'metodo' => $metodo,
        ];
    }

    $tamano = filesize($ruta_archivo);
    registrar_backup_en_bd($nombre_archivo, $tamano, $tipo, $usuario_id, $notas, true, null);
    limpiar_backups_viejos();

    return [
        'ok' => true,
        'archivo' => $nombre_archivo,
        'tamano' => $tamano,
        'mensaje' => "Backup generado exitosamente con $metodo.",
        'metodo' => $metodo,
    ];
}


/**
 * Genera backup usando mysqldump (método rápido y completo).
 * En hosting sin exec() retorna false para forzar el fallback PHP.
 */
function backup_via_mysqldump(string $mysqldump, string $ruta_destino, string &$error): bool {
    if (!_func_habilitada('exec')) {
        $error = 'exec() deshabilitado en el servidor';
        return false;
    }

    $config = obtener_config_db();
    $temp_sql = $ruta_destino . '.tmp';

    $escape = PHP_OS_FAMILY === 'Windows' ? '"' : "'";
    $pass_arg = $config['pass'] !== '' ? "-p{$escape}{$config['pass']}{$escape}" : '';

    $cmd = sprintf(
        '%s%s%s -h %s -P %d -u %s%s %s --single-transaction --routines --triggers --events %s 2>&1',
        $escape, $mysqldump, $escape,
        escapeshellarg($config['host']),
        $config['port'],
        escapeshellarg($config['user']),
        $config['pass'] !== '' ? ' ' . $pass_arg : '',
        escapeshellarg($config['name']),
        '> ' . escapeshellarg($temp_sql)
    );

    exec($cmd, $output, $codigo);

    if ($codigo !== 0 || !file_exists($temp_sql) || filesize($temp_sql) === 0) {
        $error = "mysqldump falló (código $codigo)";
        if (!empty($output)) {
            $error .= ': ' . implode(' | ', array_slice($output, 0, 3));
        }
        if (file_exists($temp_sql)) @unlink($temp_sql);
        return false;
    }

    if (!comprimir_gzip($temp_sql, $ruta_destino)) {
        $error = 'No se pudo comprimir el archivo.';
        @unlink($temp_sql);
        return false;
    }

    @unlink($temp_sql);
    return true;
}


/**
 * Genera backup usando PHP puro (fallback portable).
 */
function backup_via_php(string $ruta_destino): void {
    $gz = gzopen($ruta_destino, 'wb6');
    if (!$gz) throw new RuntimeException('No se pudo crear el archivo gzip');

    $config = obtener_config_db();

    gzwrite($gz, "-- ============================================================\n");
    gzwrite($gz, "-- Backup de la base de datos: {$config['name']}\n");
    gzwrite($gz, "-- Generado: " . date('Y-m-d H:i:s') . "\n");
    gzwrite($gz, "-- Método: PHP puro (fallback - mysqldump no disponible)\n");
    gzwrite($gz, "-- ============================================================\n\n");
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
    gzwrite($gz, "SET NAMES utf8mb4;\n");
    gzwrite($gz, "SET time_zone = '+00:00';\n\n");

    $tablas = db_all("SHOW TABLES");
    $col_key = array_keys($tablas[0] ?? [])[0] ?? 'Tables_in_' . $config['name'];

    foreach ($tablas as $t) {
        $tabla = $t[$col_key];

        gzwrite($gz, "\n-- ----------------------------------------------------\n");
        gzwrite($gz, "-- Estructura de tabla `$tabla`\n");
        gzwrite($gz, "-- ----------------------------------------------------\n");
        gzwrite($gz, "DROP TABLE IF EXISTS `$tabla`;\n");

        $create = db_one("SHOW CREATE TABLE `$tabla`");
        $create_sql = $create['Create Table'] ?? $create['Create View'] ?? '';
        gzwrite($gz, "$create_sql;\n\n");

        $count_row = db_one("SELECT COUNT(*) c FROM `$tabla`");
        $total = (int) ($count_row['c'] ?? 0);

        if ($total === 0) continue;

        gzwrite($gz, "-- Datos de tabla `$tabla` ($total filas)\n");

        $batch = 200;
        for ($offset = 0; $offset < $total; $offset += $batch) {
            $filas = db_all("SELECT * FROM `$tabla` LIMIT $batch OFFSET $offset");
            if (empty($filas)) break;

            $columnas = array_keys($filas[0]);
            $cols_sql = '`' . implode('`,`', $columnas) . '`';

            $valores_sql = [];
            foreach ($filas as $f) {
                $vals = [];
                foreach ($columnas as $c) {
                    $v = $f[$c];
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($v) || is_float($v)) {
                        $vals[] = $v;
                    } else {
                        $vals[] = db()->quote((string) $v);
                    }
                }
                $valores_sql[] = '(' . implode(',', $vals) . ')';
            }

            gzwrite($gz, "INSERT INTO `$tabla` ($cols_sql) VALUES\n" . implode(",\n", $valores_sql) . ";\n");
        }
        gzwrite($gz, "\n");
    }

    gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
}


/**
 * Comprime un archivo a gzip y borra el original.
 */
function comprimir_gzip(string $entrada, string $salida): bool {
    $in = fopen($entrada, 'rb');
    if (!$in) return false;

    $out = gzopen($salida, 'wb6');
    if (!$out) {
        fclose($in);
        return false;
    }

    while (!feof($in)) {
        gzwrite($out, fread($in, 65536));
    }
    fclose($in);
    gzclose($out);
    return true;
}


/**
 * Lee la configuración de BD del archivo db.php.
 */
function obtener_config_db(): array {
    return [
        'host' => defined('DB_HOST') ? DB_HOST : 'localhost',
        'port' => defined('DB_PORT') ? (int) DB_PORT : 3306,
        'name' => defined('DB_NAME') ? DB_NAME : 'mantenimiento_bacal',
        'user' => defined('DB_USER') ? DB_USER : 'root',
        'pass' => defined('DB_PASS') ? DB_PASS : '',
    ];
}


// ============================================================================
// Registro en BD
// ============================================================================

function registrar_backup_en_bd(
    string $nombre_archivo,
    int $tamano,
    string $tipo,
    ?int $usuario_id,
    string $notas,
    bool $exitoso,
    ?string $mensaje_error
): void {
    db_exec(
        "INSERT INTO backups_realizados
         (nombre_archivo, tamano_bytes, tipo, realizado_por_id, notas, exitoso, mensaje_error)
         VALUES (:n, :t, :tp, :uid, :nt, :ok, :err)",
        [
            'n'   => $nombre_archivo,
            't'   => $tamano,
            'tp'  => $tipo,
            'uid' => $usuario_id,
            'nt'  => $notas ?: null,
            'ok'  => $exitoso ? 1 : 0,
            'err' => $mensaje_error,
        ]
    );
}


// ============================================================================
// Listado, descarga, limpieza
// ============================================================================

function listar_backups(int $limite = 100): array {
    return db_all(
        "SELECT b.*, u.nombre_completo realizado_por_nombre
         FROM backups_realizados b
         LEFT JOIN usuarios u ON b.realizado_por_id = u.id
         ORDER BY b.creado_en DESC
         LIMIT $limite"
    );
}

function backup_existe_en_disco(string $nombre_archivo): bool {
    return file_exists(BACKUPS_DIR . '/' . basename($nombre_archivo));
}

function limpiar_backups_viejos(): int {
    if (!is_dir(BACKUPS_DIR)) return 0;

    $borrados = 0;
    $umbral = strtotime('-' . BACKUPS_RETENCION_DIAS . ' days');

    $archivos = glob(BACKUPS_DIR . '/backup_*.sql.gz') ?: [];

    if (count($archivos) > BACKUPS_MAX_GUARDAR) {
        usort($archivos, fn($a, $b) => filemtime($a) <=> filemtime($b));
        $exceso = count($archivos) - BACKUPS_MAX_GUARDAR;
        for ($i = 0; $i < $exceso; $i++) {
            @unlink($archivos[$i]);
            $borrados++;
        }
    }

    foreach ($archivos as $archivo) {
        if (file_exists($archivo) && filemtime($archivo) < $umbral) {
            @unlink($archivo);
            $borrados++;
        }
    }

    return $borrados;
}

function eliminar_backup(int $backup_id): bool {
    $b = db_one("SELECT nombre_archivo FROM backups_realizados WHERE id = :id", ['id' => $backup_id]);
    if (!$b) return false;

    $ruta = BACKUPS_DIR . '/' . basename($b['nombre_archivo']);
    if (file_exists($ruta)) @unlink($ruta);

    db_exec("DELETE FROM backups_realizados WHERE id = :id", ['id' => $backup_id]);
    return true;
}

function fmt_bytes(int $bytes): string {
    if ($bytes < 1024) return "$bytes B";
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / (1024 * 1024), 2) . ' MB';
    return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}
