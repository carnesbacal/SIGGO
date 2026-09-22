<?php
/** api/cfdi_parse.php - Lee un CFDI (XML) y devuelve los datos para autocompletar un gasto. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/gastos_helpers.php';

header('Content-Type: application/json; charset=utf-8');
requerir_login();
if (!(tiene_permiso('administrar') || tiene_permiso('crear_solicitud'))) { echo json_encode(['ok'=>false,'error'=>'Sin permiso.']); exit; }
if (!es_post() || !csrf_valido(input('_csrf'))) { echo json_encode(['ok'=>false,'error'=>'Solicitud invalida.']); exit; }
if (empty($_FILES['cfdi']) || ($_FILES['cfdi']['error'] ?? 1) !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'No se recibio el archivo XML.']); exit; }

$raw = @file_get_contents($_FILES['cfdi']['tmp_name']);
$cfdi = cfdi_parse_raw((string)$raw);
if (!$cfdi) { echo json_encode(['ok'=>false,'error'=>'El archivo no parece ser un CFDI.']); exit; }

$dup = null;
if ($cfdi['uuid']) { $d = db_one("SELECT folio FROM gastos WHERE uuid=:u LIMIT 1", ['u'=>$cfdi['uuid']]); if ($d) $dup = $d['folio']; }

$prov = proveedor_detectar_o_alta($cfdi['rfc'], $cfdi['nombre'], (int)(usuario_actual()['id'] ?? 0) ?: null);

$up = archivo_subir($_FILES['cfdi']);
$xml_url = $up['ruta'] ?? null;

echo json_encode([
    'ok'=>true,
    'total'=>$cfdi['total'], 'subtotal'=>$cfdi['subtotal'], 'iva'=>$cfdi['iva'],
    'fecha'=>$cfdi['fecha'], 'folio'=>$cfdi['folio'], 'rfc'=>$cfdi['rfc'], 'nombre'=>$cfdi['nombre'],
    'uuid'=>$cfdi['uuid'], 'xml_url'=>$xml_url, 'proveedor_id'=>$prov['id'], 'proveedor_nuevo'=>$prov['nuevo'], 'duplicado'=>$dup,
    // Los conceptos del CFDI se vuelven los renglones del gasto
    'conceptos'=>$cfdi['conceptos'] ?? [],
], JSON_UNESCAPED_UNICODE);
