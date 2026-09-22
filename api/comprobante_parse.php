<?php
/**
 * api/comprobante_parse.php
 * Lee el comprobante que sube el usuario —XML del SAT o PDF— y devuelve los
 * datos para llenar solo el gasto.
 *
 * Por qué uno solo y no dos: al gerente le llega casi siempre el PDF. Si hay
 * dos botones, sube el PDF al que dice XML y se queda sin nada. Aquí se sube
 * el archivo y el servidor decide qué es.
 *
 * El PDF se guarda siempre como comprobante del gasto (devuelve archivo_url),
 * aunque no se le haya podido leer nada: vale como evidencia igual.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/gastos_helpers.php';
require_once __DIR__ . '/../config/pdf_parse.php';

header('Content-Type: application/json; charset=utf-8');
requerir_login();

$salir = function (string $msg) { echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; };

if (!(tiene_permiso('administrar') || tiene_permiso('crear_solicitud'))) $salir('Sin permiso.');
// Archivo más grande que el límite de PHP: el POST llega vacío y el error
// real (el tamaño) se perdería detrás de un "solicitud inválida".
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $salir('El archivo pesa más de lo que acepta el servidor (' . (ini_get('post_max_size') ?: '?') . ').');
}
if (!es_post() || !csrf_valido(input('_csrf')))                          $salir('Solicitud inválida.');

// El campo se llama "cfdi" para no romper nada que ya lo mande así
$f = $_FILES['comprobante'] ?? $_FILES['cfdi'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) $salir('No se recibió el archivo.');

$raw = (string) @file_get_contents($f['tmp_name']);
if ($raw === '') $salir('El archivo llegó vacío.');

$esPdf = strncmp($raw, '%PDF', 4) === 0;
$datos = null;

if ($esPdf) {
    $datos = pdf_parse_factura($raw);
    if (!$datos) $salir('El archivo dice ser PDF pero no se pudo abrir.');
} else {
    $datos = cfdi_parse_raw($raw);
    if (!$datos) $salir('El archivo no es un CFDI del SAT ni un PDF.');
    $datos['origen']    = 'xml';
    $datos['confiable'] = true;
    $datos['faltantes'] = [];
}

// El archivo se guarda siempre: es el comprobante del gasto
$up = archivo_subir($f);
if ($up['error']) $salir($up['error']);
$ruta = $up['ruta'];

// ¿Ya se capturó esta misma factura?
$dup = null;
if (!empty($datos['uuid'])) {
    $d = db_one("SELECT folio FROM gastos WHERE uuid=:u LIMIT 1", ['u'=>$datos['uuid']]);
    if ($d) $dup = $d['folio'];
}

// Proveedor: solo se da de alta si el RFC se leyó con certeza
$prov = ['id'=>null, 'nuevo'=>false];
if (!empty($datos['rfc'])) {
    $prov = proveedor_detectar_o_alta($datos['rfc'], (string)($datos['nombre'] ?? ''),
                                      (int)(usuario_actual()['id'] ?? 0) ?: null);
}

// La fecha leída de un PDF de texto puede ser cualquier número que se le
// pareció: si cae muy lejos, no se pisa la fecha del formulario.
$fechaLeida = (string)($datos['fecha'] ?? '');
$fechaRara  = false;
if ($fechaLeida !== '') {
    $ts = strtotime($fechaLeida);
    if ($ts === false || $ts > strtotime('+2 days') || $ts < strtotime('-18 months')) {
        $fechaRara = true;
    }
}

// Mensaje en español de lo que NO se pudo leer, para mostrarlo tal cual
$aviso   = '';
$titulo  = '';
$origen  = $datos['origen'] ?? 'texto';
$nRengs  = count((array)($datos['conceptos'] ?? []));

if ($origen === 'xml') {
    $titulo = 'CFDI leído correctamente';
} elseif ($origen === 'xml_incrustado') {
    $titulo = 'PDF leído (traía el XML adentro)';
    $aviso  = 'Los datos y los artículos son exactos.';
} elseif (!empty($datos['sin_texto'])) {
    $titulo = 'Este PDF no se puede leer: captura a mano';
    $aviso  = 'Es una imagen escaneada (una foto dentro del PDF), no trae texto. '
            . 'El PDF ya quedó guardado como comprobante del gasto: solo captura los artículos, '
            . 'las cantidades y los precios aquí abajo.';
} elseif ($nRengs === 0) {
    // El caso más común con facturas reales en PDF: el encabezado sí se lee,
    // la tabla no, porque cada proveedor la arma distinto.
    $titulo = 'Los artículos hay que capturarlos a mano';
    $aviso  = 'Este PDF no trae el XML adentro, así que no se pudieron leer los renglones de la compra. '
            . 'El PDF ya quedó guardado como comprobante del gasto: captura abajo los artículos, '
            . 'cantidades y precios, y revisa los datos que sí se leyeron.';
} else {
    $titulo = 'Revisa lo que se leyó del PDF';
    $aviso  = 'Este PDF no trae el XML adentro, así que los artículos se leyeron del texto de la factura. '
            . 'Compara los renglones y los montos con el papel antes de guardar.';
}
if ($fechaRara && $fechaLeida !== '') {
    $aviso .= ' La fecha que traía el PDF (' . date('d/m/Y', strtotime($fechaLeida)) . ') está fuera de rango, '
            . 'así que se dejó la del formulario: cámbiala si la compra sí es de ese día.';
}

echo json_encode([
    'ok'           => true,
    'origen'       => $origen,                       // xml | xml_incrustado | texto
    'confiable'    => (bool)($datos['confiable'] ?? false),
    'faltantes'    => array_values((array)($datos['faltantes'] ?? [])),
    'titulo'       => $titulo,
    'aviso'        => $aviso,
    'fecha_rara'   => $fechaRara,
    'es_pdf'       => $esPdf,
    'total'        => $datos['total']    ?? 0,
    'subtotal'     => $datos['subtotal'] ?? 0,
    'iva'          => $datos['iva']      ?? 0,
    'fecha'        => $datos['fecha']    ?? '',
    'folio'        => $datos['folio']    ?? '',
    'rfc'          => $datos['rfc']      ?? '',
    'nombre'       => $datos['nombre']   ?? '',
    'uuid'         => $datos['uuid']     ?? '',
    // El XML suelto se guarda como XML del CFDI; el PDF, como comprobante
    'xml_url'      => $esPdf ? null : $ruta,
    'archivo_url'  => $esPdf ? $ruta : null,
    'archivo_nombre' => $f['name'] ?? '',
    'proveedor_id'   => $prov['id'],
    'proveedor_nuevo'=> $prov['nuevo'],
    'duplicado'    => $dup,
    'conceptos'    => $datos['conceptos'] ?? [],
], JSON_UNESCAPED_UNICODE);
