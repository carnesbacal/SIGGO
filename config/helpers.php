<?php
/**
 * ============================================================================
 * config/helpers.php - Funciones comunes del sistema
 * ============================================================================
 */

/**
 * Escapa texto para mostrar en HTML de forma segura.
 */
function e(?string $texto): string {
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Genera una URL relativa al sistema.
 */
function url(string $ruta = ''): string {
    return rtrim(APP_URL, '/') . '/' . ltrim($ruta, '/');
}

/**
 * Devuelve una URL RELATIVA (sin protocolo+host).
 * Útil para guardar en BD donde la URL debe funcionar para todos los clientes,
 * sin importar desde dónde acceden (localhost, IP local, dominio público).
 *
 * Ejemplo: url_relativa('incidencia_ver.php?id=5')
 *  → '/UtilidadesBacal/BitacoraSistemas/incidencia_ver.php?id=5'
 */
/**
 * URL pública de un archivo subido por el sistema.
 * Los archivos se guardan en assets/uploads/... así que se sirven desde ahí.
 */
function url_archivo(?string $ruta): string {
    if (!$ruta) return '';
    return url('assets/' . ltrim($ruta, '/'));
}

function url_relativa(string $ruta = ''): string {
    $base = parse_url(APP_URL, PHP_URL_PATH) ?: '';
    return rtrim($base, '/') . '/' . ltrim($ruta, '/');
}

/**
 * Devuelve un valor de $_POST o $_GET con valor por defecto.
 */
function input(string $clave, $default = null) {
    if (isset($_POST[$clave])) return $_POST[$clave];
    if (isset($_GET[$clave])) return $_GET[$clave];
    return $default;
}

/**
 * Verifica si la petición es POST.
 */
function es_post(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/**
 * Genera un token CSRF para formularios.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida un token CSRF recibido.
 */
function csrf_valido(?string $token): bool {
    return !empty($_SESSION['csrf_token'])
        && !empty($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Devuelve un input hidden con el token CSRF para los formularios.
 */
function csrf_input(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

/**
 * ¿La petición POST viene del MISMO sitio? Compara el host de la cabecera
 * Origin/Referer contra el host del servidor. Sirve como respaldo del token
 * CSRF cuando la sesión se pierde entre el GET y el POST (subcarpetas, otra
 * app en el mismo XAMPP, pestaña abierta mucho tiempo), sin abrir la puerta a
 * peticiones forjadas desde otro dominio (esas traen un Origin distinto).
 */
function origen_mismo_sitio(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return false;
    $ref = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($ref === '') return false;                    // sin cabecera: no confiar
    $refHost = strtolower((string)(parse_url($ref, PHP_URL_HOST) ?? ''));
    if ($refHost === '') return false;
    $hostSolo = strtolower((string)(parse_url('http://' . $host, PHP_URL_HOST) ?? ''));
    return $refHost !== '' && $refHost === $hostSolo; // mismo host (ignora puerto/esquema)
}

/**
 * Mensaje flash (se muestra una vez y se borra).
 */
function flash_set(string $tipo, string $mensaje): void {
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function flash_get(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Formatea una fecha (datetime) en formato amigable.
 */
function fmt_fecha(?string $fecha, bool $con_hora = true): string {
    if (!$fecha) return '—';
    $ts = strtotime($fecha);
    if (!$ts) return '—';
    $meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    $mes = $meses[(int)date('n', $ts) - 1];
    return date('d', $ts) . ' ' . $mes . ' ' . date('Y', $ts) .
           ($con_hora ? ', ' . date('H:i', $ts) : '');
}

/**
 * Formatea fecha con hora. Alias de fmt_fecha(\$fecha, true).
 * Usado en combustible, viajes, siniestros, checklist y otras vistas.
 */
function fmt_fecha_hora(?string $fecha): string {
    return fmt_fecha($fecha, true);
}

/**
 * Tiempo relativo legible: "hace 5 minutos", "hace 2 horas", etc.
 */
function fmt_tiempo_relativo(?string $fecha): string {
    if (!$fecha) return '—';
    $diff = time() - strtotime($fecha);
    if ($diff < 60)         return 'hace un momento';
    if ($diff < 3600)       return 'hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400)      return 'hace ' . floor($diff / 3600) . ' h';
    if ($diff < 2592000)    return 'hace ' . floor($diff / 86400) . ' días';
    return fmt_fecha($fecha, false);
}

/**
 * Convierte minutos a formato legible: "2h 15min", "45 min", etc.
 */
function fmt_duracion(?int $minutos): string {
    if ($minutos === null) return '—';
    if ($minutos < 60) return $minutos . ' min';
    $h = intdiv($minutos, 60);
    $m = $minutos % 60;
    return $h . 'h' . ($m > 0 ? " {$m}min" : '');
}

/**
 * Genera un badge HTML con color de fondo (estilo Notion).
 */
function badge(?string $texto, ?string $color = '#6B7280', string $clase_extra = ''): string {
    if (!$texto) return '—';
    $color = $color ?: '#6B7280';
    $texto = e($texto);
    // Fondo semi-transparente, texto del color sólido para legibilidad
    return "<span class='inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium {$clase_extra}'
                  style='background-color: {$color}1f; color: {$color}; border: 1px solid {$color}40;'>{$texto}</span>";
}

/**
 * Devuelve las iniciales de un nombre completo (para avatares).
 */
function iniciales(string $nombre): string {
    $partes = preg_split('/\s+/', trim($nombre));
    $a = $partes[0][0] ?? '';
    $b = '';
    if (count($partes) > 1) {
        $b = $partes[1][0] ?? '';
    }
    return strtoupper($a . $b);
}

/**
 * Genera un color de fondo determinístico basado en un string (para avatares).
 * Garantiza siempre devolver un color válido, incluso con texto vacío o caracteres especiales.
 */
function color_avatar(?string $texto): string {
    $colores = ['#DC2626','#EA580C','#D97706','#16A34A','#0EA5E9','#2563EB','#7C3AED','#9333EA','#DB2777'];

    // Si el texto está vacío o es null, devolver un color por defecto
    $texto = trim((string) $texto);
    if ($texto === '') {
        return $colores[0];
    }

    // crc32 siempre devuelve un entero positivo de 32 bits, sin overflow
    $hash = crc32($texto);
    $indice = $hash % count($colores);
    return $colores[$indice];
}

/**
 * Renderiza un avatar: si el usuario tiene foto la muestra, si no, iniciales con color.
 *
 * @param array|null $usuario  Array con nombre_completo y avatar_url (opcional)
 * @param string $tamano       Clases Tailwind para el tamaño (ej. 'w-8 h-8', 'w-10 h-10', 'w-16 h-16')
 * @param string $clases_extra Clases extra para el contenedor
 * @return string HTML del avatar
 */
/**
 * Lee una preferencia de UI del usuario en sesión.
 * Si la clave no existe devuelve $defecto (null por omisión).
 *
 * Para activar una preferencia desde la BD:
 *   UPDATE usuarios SET preferencias = '{"sucursal_selector":"radio"}' WHERE id = X;
 */
function usuario_preferencia(string $clave, mixed $defecto = null): mixed {
    $prefs = usuario_actual()['preferencias'] ?? [];
    return $prefs[$clave] ?? $defecto;
}

/**
 * ¿El usuario prefiere el selector de sucursal como radio buttons?
 * Se activa con: preferencias → {"sucursal_selector":"radio"}
 */
function usuario_prefiere_radio_sucursal(): bool {
    return usuario_preferencia('sucursal_selector') === 'radio';
}

function render_avatar(?array $usuario, string $tamano = 'w-8 h-8', string $clases_extra = ''): string {
    if (!$usuario) {
        return '<div class="' . $tamano . ' rounded-full bg-zinc-200 ' . $clases_extra . '"></div>';
    }

    $nombre = (string) ($usuario['nombre_completo'] ?? $usuario['nombre'] ?? '');
    $avatar = (string) ($usuario['avatar_url'] ?? '');

    // Determinar tamaño de fuente según el tamaño del avatar
    $clase_texto = 'text-xs';
    if (strpos($tamano, 'w-16') !== false || strpos($tamano, 'w-20') !== false) $clase_texto = 'text-sm';
    if (strpos($tamano, 'w-24') !== false || strpos($tamano, 'w-32') !== false) $clase_texto = 'text-base';
    if (strpos($tamano, 'w-12') !== false || strpos($tamano, 'w-14') !== false) $clase_texto = 'text-xs';

    if ($avatar !== '') {
        // Mostrar la foto
        $src = url_archivo($avatar);
        $alt_e = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        return sprintf(
            '<div class="%s rounded-full overflow-hidden flex-shrink-0 %s"><img src="%s" alt="%s" class="w-full h-full object-cover"></div>',
            $tamano, $clases_extra, htmlspecialchars($src, ENT_QUOTES, 'UTF-8'), $alt_e
        );
    }

    // Sin foto: iniciales con color
    $iniciales_e = htmlspecialchars(iniciales($nombre), ENT_QUOTES, 'UTF-8');
    $color = color_avatar($nombre);
    return sprintf(
        '<div class="%s rounded-full flex items-center justify-center text-white %s font-bold shadow-sm flex-shrink-0 %s" style="background-color: %s">%s</div>',
        $tamano, $clase_texto, $clases_extra, htmlspecialchars($color, ENT_QUOTES, 'UTF-8'), $iniciales_e
    );
}


/**
 * Guarda una imagen subida (solo imágenes). Genérico y reutilizable.
 * Devuelve ['ruta'=>?string, 'error'=>?string]. Ruta relativa: "uploads/AAAA/MM/archivo".
 * Si no se envió archivo, devuelve ['ruta'=>null,'error'=>null] (no es error: es opcional).
 */
function imagen_subir(array $file, int $max_mb = 15): array {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($file['name'])) return ['ruta' => null, 'error' => null];
    if ($err !== UPLOAD_ERR_OK) return ['ruta' => null, 'error' => 'No se pudo subir la imagen.'];
    if ((int) ($file['size'] ?? 0) > $max_mb * 1024 * 1024) return ['ruta' => null, 'error' => "La imagen excede el tamaño máximo ({$max_mb} MB)."];
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || empty($info['mime'])
        || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        return ['ruta' => null, 'error' => 'El archivo debe ser una imagen (JPG, PNG, WEBP o GIF).'];
    }
    $sub = date('Y/m');
    $dir = __DIR__ . '/../assets/uploads/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ext = preg_replace('/[^a-z0-9]/i', '', strtolower(pathinfo($file['name'], PATHINFO_EXTENSION))) ?: 'jpg';
    $nombre = 'img_' . bin2hex(random_bytes(12)) . ".$ext";
    if (!move_uploaded_file($file['tmp_name'], "$dir/$nombre")) {
        return ['ruta' => null, 'error' => 'No se pudo guardar la imagen en el servidor.'];
    }
    return ['ruta' => "uploads/$sub/$nombre", 'error' => null];
}

/** Borra el archivo físico de una imagen previamente guardada (ruta relativa "uploads/..."). */
function imagen_borrar_archivo(?string $ruta): void {
    if (!$ruta) return;
    $fs = __DIR__ . '/../assets/' . ltrim($ruta, '/');
    if (is_file($fs)) @unlink($fs);
}
