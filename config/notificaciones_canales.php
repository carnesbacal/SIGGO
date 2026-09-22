<?php
/**
 * ============================================================================
 * config/notificaciones_canales.php
 * ============================================================================
 * Envío de notificaciones externas: Email (SMTP nativo) y Telegram (Bot API).
 * No requiere composer ni extensiones extra más allá de las estándar de PHP.
 *
 * Punto de entrada principal:
 *   dispatch_notificacion(int $usuario_id, string $tipo, string $titulo,
 *                         string $mensaje, ?string $url, ?int $notif_id)
 * ============================================================================
 */

require_once __DIR__ . '/db.php';

// ============================================================================
// Leer configuración global (cache estático por request)
// ============================================================================

function _nc_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    try {
        $row = db_one("SELECT * FROM configuracion_notificaciones WHERE id = 1");
        $cfg = $row ?: [];
    } catch (Throwable $e) {
        $cfg = [];
    }
    return $cfg;
}

// ============================================================================
// Leer preferencias de un usuario para un tipo dado
// ============================================================================

function _nc_preferencias(int $usuario_id, string $tipo): array {
    // Cache por request: [usuario_id => [tipo => [...prefs]]]
    static $cache = [];
    if (isset($cache[$usuario_id][$tipo])) return $cache[$usuario_id][$tipo];

    try {
        $row = db_one(
            "SELECT canal_inapp, canal_email, canal_telegram
             FROM notificacion_preferencias
             WHERE usuario_id = :uid AND tipo = :tipo",
            ['uid' => $usuario_id, 'tipo' => $tipo]
        );
        // Defaults: siempre in-app, email y telegram desactivados
        // Si no hay preferencia guardada, activar email por defecto en asignacion y mencion
        $email_default = in_array($tipo, ['asignacion', 'mencion'], true) ? 1 : 0;
        $prefs = $row ?: ['canal_inapp' => 1, 'canal_email' => $email_default, 'canal_telegram' => 0];
    } catch (Throwable $e) {
        $prefs = ['canal_inapp' => 1, 'canal_email' => 0, 'canal_telegram' => 0];
    }
    $cache[$usuario_id][$tipo] = $prefs;
    return $prefs;
}

// ============================================================================
// Dispatch principal — llamado desde crear_notificacion()
// ============================================================================

/**
 * Revisa las preferencias del usuario y despacha la notificación
 * via email y/o Telegram si corresponde.
 *
 * @param int    $usuario_id   Destinatario
 * @param string $tipo         Tipo de evento (NOTIF_TIPOS)
 * @param string $titulo       Título corto
 * @param string $mensaje      Cuerpo del mensaje
 * @param string|null $url     Enlace relacionado
 * @param int|null $notif_id   ID de la notificación in-app ya creada
 */
function dispatch_notificacion(
    int $usuario_id,
    string $tipo,
    string $titulo,
    string $mensaje,
    ?string $url = null,
    ?int $notif_id = null
): void {
    $cfg   = _nc_config();
    $prefs = _nc_preferencias($usuario_id, $tipo);

    // --- Email ---
    if (!empty($prefs['canal_email']) && !empty($cfg['smtp_activo'])) {
        try {
            $dest = db_one("SELECT email, nombre_completo FROM usuarios WHERE id = :id", ['id' => $usuario_id]);
            if ($dest && !empty($dest['email'])) {
                $ok = _nc_enviar_email($cfg, $dest['email'], $dest['nombre_completo'] ?? '', $titulo, $mensaje, $url);
                _nc_log_envio($notif_id, $usuario_id, 'email', $tipo, $titulo, $ok['ok'], $ok['error'] ?? null);
            }
        } catch (Throwable $e) {
            _nc_log_envio($notif_id, $usuario_id, 'email', $tipo, $titulo, false, $e->getMessage());
        }
    }

    // --- Telegram ---
    if (!empty($prefs['canal_telegram']) && !empty($cfg['telegram_activo'])) {
        try {
            $dest = db_one("SELECT telegram_chat_id FROM usuarios WHERE id = :id", ['id' => $usuario_id]);
            if ($dest && !empty($dest['telegram_chat_id'])) {
                $ok = _nc_enviar_telegram($cfg['telegram_bot_token'], $dest['telegram_chat_id'], $titulo, $mensaje, $url);
                _nc_log_envio($notif_id, $usuario_id, 'telegram', $tipo, $titulo, $ok['ok'], $ok['error'] ?? null);
            }
        } catch (Throwable $e) {
            _nc_log_envio($notif_id, $usuario_id, 'telegram', $tipo, $titulo, false, $e->getMessage());
        }
    }
}

// ============================================================================
// Registro de envío en BD
// ============================================================================

function _nc_log_envio(?int $notif_id, int $usuario_id, string $canal, string $tipo, string $asunto, bool $ok, ?string $error): void {
    try {
        db_exec(
            "INSERT INTO notificacion_envios (notificacion_id, usuario_id, canal, tipo, asunto, estado, error_detalle)
             VALUES (:nid, :uid, :canal, :tipo, :asunto, :estado, :err)",
            [
                'nid'    => $notif_id,
                'uid'    => $usuario_id,
                'canal'  => $canal,
                'tipo'   => $tipo,
                'asunto' => mb_substr($asunto, 0, 255),
                'estado' => $ok ? 'ok' : 'error',
                'err'    => $error ? mb_substr($error, 0, 500) : null,
            ]
        );
    } catch (Throwable $e) {
        error_log('_nc_log_envio failed: ' . $e->getMessage());
    }
}

// ============================================================================
// EMAIL — SMTP nativo (sin extensiones extra, solo sockets PHP)
// ============================================================================

/**
 * Envía un email via SMTP nativo usando fsockopen.
 * Soporta TLS (STARTTLS en puerto 587), SSL (puerto 465) y sin cifrado (puerto 25).
 *
 * @return array ['ok' => bool, 'error' => string|null]
 */
function _nc_enviar_email(array $cfg, string $dest_email, string $dest_nombre, string $asunto, string $cuerpo, ?string $url = null): array {
    $host      = $cfg['smtp_host']       ?? '';
    $port      = (int) ($cfg['smtp_port']      ?? 587);
    $seguridad = $cfg['smtp_seguridad']  ?? 'tls';
    $usuario   = $cfg['smtp_usuario']    ?? '';
    $password  = $cfg['smtp_password']   ?? '';
    $from_mail = $cfg['smtp_from_email'] ?? $usuario;
    $from_name = $cfg['smtp_from_nombre'] ?? 'Bitácora Mantenimiento';

    if (!$host || !$from_mail) return ['ok' => false, 'error' => 'SMTP sin configurar'];

    // Construir HTML del email
    $html = _nc_email_html($asunto, $cuerpo, $url, $from_name);
    $text = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $html));

    $boundary = 'MP_' . md5(uniqid('', true));

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . _nc_encode_header($from_name) . ' <' . $from_mail . '>',
        'To: ' . _nc_encode_header($dest_nombre) . ' <' . $dest_email . '>',
        'Subject: ' . _nc_encode_header($asunto),
        'X-Mailer: PHP/' . PHP_VERSION,
        'Date: ' . date('r'),
        'Message-ID: <' . uniqid('notif.', true) . '@' . ($cfg['smtp_host'] ?? 'localhost') . '>',
    ];

    $body = "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text)) . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html)) . "\r\n"
          . "--{$boundary}--";

    try {
        // Abrir socket
        $timeout = 15;
        if ($seguridad === 'ssl') {
            $socket = @fsockopen("ssl://{$host}", $port, $errno, $errstr, $timeout);
        } else {
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        }
        if (!$socket) return ['ok' => false, 'error' => "No se pudo conectar al servidor SMTP: {$errstr} ({$errno})"];

        stream_set_timeout($socket, $timeout);

        $read = function() use ($socket): string {
            $resp = '';
            while (!feof($socket)) {
                $line = fgets($socket, 515);
                if ($line === false) break;
                $resp .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') break; // fin de respuesta multi-línea
            }
            return $resp;
        };
        $send = function(string $cmd) use ($socket): void {
            fwrite($socket, $cmd . "\r\n");
        };

        $greeting = $read();
        if (substr(trim($greeting), 0, 3) !== '220') {
            fclose($socket);
            return ['ok' => false, 'error' => 'Respuesta inesperada del servidor: ' . trim($greeting)];
        }

        $send("EHLO {$host}");
        $ehlo = $read();

        // STARTTLS (solo si TLS y no ya SSL)
        if ($seguridad === 'tls') {
            $send("STARTTLS");
            $tls_resp = $read();
            if (substr(trim($tls_resp), 0, 3) !== '220') {
                fclose($socket);
                return ['ok' => false, 'error' => 'STARTTLS rechazado: ' . trim($tls_resp)];
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['ok' => false, 'error' => 'No se pudo activar TLS'];
            }
            $send("EHLO {$host}");
            $read(); // Re-EHLO tras TLS
        }

        // AUTH LOGIN
        if ($usuario && $password) {
            $send("AUTH LOGIN");
            $auth_resp = $read();
            if (substr(trim($auth_resp), 0, 3) !== '334') {
                fclose($socket);
                return ['ok' => false, 'error' => 'AUTH LOGIN fallido: ' . trim($auth_resp)];
            }
            $send(base64_encode($usuario));
            $read(); // "334 UGFzc3dvcmQ6"
            $send(base64_encode($password));
            $auth_ok = $read();
            if (substr(trim($auth_ok), 0, 3) !== '235') {
                fclose($socket);
                return ['ok' => false, 'error' => 'Credenciales SMTP incorrectas: ' . trim($auth_ok)];
            }
        }

        $send("MAIL FROM:<{$from_mail}>");
        $read();

        $send("RCPT TO:<{$dest_email}>");
        $read();

        $send("DATA");
        $read();

        // Enviar cabeceras + cuerpo
        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        $data_resp = $read();

        $send("QUIT");
        fclose($socket);

        if (substr(trim($data_resp), 0, 3) !== '250') {
            return ['ok' => false, 'error' => 'Error al enviar DATA: ' . trim($data_resp)];
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Codifica cabecera de email en UTF-8 Base64 */
function _nc_encode_header(string $str): string {
    if (preg_match('/[^\x20-\x7E]/', $str)) {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
    return $str;
}

/** Genera el HTML del email */
function _nc_email_html(string $titulo, string $mensaje, ?string $url, string $app_nombre): string {
    $btn = $url
        ? '<p style="text-align:center;margin:24px 0"><a href="' . htmlspecialchars($url) . '" '
          . 'style="background:#b45309;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600">'
          . 'Ver detalle</a></p>'
        : '';
    $titulo_esc  = htmlspecialchars($titulo);
    $mensaje_esc = nl2br(htmlspecialchars($mensaje));
    $app_esc     = htmlspecialchars($app_nombre);
    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.12)">
        <tr><td style="background:#b45309;padding:20px 32px">
          <span style="color:#fff;font-size:18px;font-weight:700">{$app_esc}</span>
        </td></tr>
        <tr><td style="padding:28px 32px">
          <h2 style="margin:0 0 12px;font-size:17px;color:#111">{$titulo_esc}</h2>
          <p style="margin:0 0 16px;color:#374151;font-size:14px;line-height:1.6">{$mensaje_esc}</p>
          {$btn}
        </td></tr>
        <tr><td style="padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb">
          <p style="margin:0;color:#9ca3af;font-size:12px">Este es un mensaje automático de {$app_esc}. No respondas a este correo.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ============================================================================
// TELEGRAM — Bot API via HTTPS
// ============================================================================

/**
 * Envía un mensaje de Telegram usando la Bot API.
 * Usa file_get_contents con contexto HTTPS (siempre disponible en PHP).
 *
 * @return array ['ok' => bool, 'error' => string|null]
 */
function _nc_enviar_telegram(string $bot_token, string $chat_id, string $titulo, string $mensaje, ?string $url = null): array {
    if (!$bot_token || !$chat_id) return ['ok' => false, 'error' => 'Token o chat_id vacío'];

    // Formato Markdown v2 de Telegram
    $escape = fn(string $s): string => preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', $s);

    $text = "*{$escape($titulo)}*\n{$escape($mensaje)}";
    if ($url) $text .= "\n[Ver detalle]({$url})";

    $payload = json_encode([
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'MarkdownV2',
        'disable_web_page_preview' => true,
    ]);

    $api_url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
        ],
    ]);

    $response = @file_get_contents($api_url, false, $ctx);
    if ($response === false) {
        return ['ok' => false, 'error' => 'No se pudo conectar a la API de Telegram'];
    }

    $data = json_decode($response, true);
    if (!empty($data['ok'])) {
        return ['ok' => true];
    }

    $err = $data['description'] ?? 'Error desconocido de Telegram';
    return ['ok' => false, 'error' => $err];
}

// ============================================================================
// TEST de canales (usado desde el panel de admin)
// ============================================================================

/**
 * Envía un mensaje de prueba a un destino específico.
 *
 * @param string $canal  'email' | 'telegram'
 * @param string $destino  Email o Chat ID según el canal
 * @return array ['ok'=>bool, 'error'=>string|null]
 */
function nc_test_canal(string $canal, string $destino): array {
    $cfg = _nc_config();

    if ($canal === 'email') {
        if (empty($cfg['smtp_activo'])) return ['ok' => false, 'error' => 'El canal email no está activo en la configuración'];
        return _nc_enviar_email($cfg, $destino, 'Prueba', '✅ Conexión SMTP exitosa', 'Este es un mensaje de prueba del sistema de notificaciones.', null);
    }

    if ($canal === 'telegram') {
        if (empty($cfg['telegram_activo'])) return ['ok' => false, 'error' => 'El canal Telegram no está activo en la configuración'];
        return _nc_enviar_telegram($cfg['telegram_bot_token'] ?? '', $destino, '✅ Prueba exitosa', 'Conexión con el bot de Telegram funcionando correctamente.');
    }

    return ['ok' => false, 'error' => 'Canal desconocido'];
}
