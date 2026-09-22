<?php
/** config/gastos_helpers.php - Utilidades del modulo de gastos */

/**
 * Genera folio de gasto: G-{CODIGO_AREA}-{AÑO}-{consecutivo}
 * El consecutivo sale del ultimo folio existente, no de un COUNT(*): asi no se
 * repite cuando se borra un gasto intermedio.
 */
function generar_folio_gasto(int $area_id, ?string $fecha = null): string {
    $anio = $fecha ? date('Y', strtotime($fecha)) : date('Y');
    $a    = db_one("SELECT codigo FROM areas WHERE id=:id", ['id' => $area_id]);
    $cod  = $a['codigo'] ?? 'GEN';
    $pref = "G-{$cod}-{$anio}-";
    $row  = db_one("SELECT folio FROM gastos WHERE folio LIKE :p ORDER BY folio DESC LIMIT 1",
                   ['p' => $pref . '%']);
    $n = $row ? (int) substr((string) $row['folio'], strlen($pref)) : 0;
    return $pref . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
}

/** Guarda un archivo de comprobante/cotizacion (PDF o imagen). Devuelve ['ruta'=>?, 'error'=>?]. */
function archivo_subir(array $file, int $max_mb = 15): array {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE || empty($file['name'])) return ['ruta'=>null, 'error'=>null];
    if ($err !== UPLOAD_ERR_OK) return ['ruta'=>null, 'error'=>'No se pudo subir el archivo.'];
    if ((int)($file['size'] ?? 0) > $max_mb*1024*1024) return ['ruta'=>null, 'error'=>"El archivo excede el máximo de {$max_mb} MB."];
    $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)pathinfo($file['name'], PATHINFO_EXTENSION)));
    $permitidas = ['pdf','jpg','jpeg','png','webp','xml'];
    if (!in_array($ext, $permitidas, true)) return ['ruta'=>null, 'error'=>'Solo se permiten PDF o imágenes (JPG, PNG, WEBP).'];
    if ($ext !== 'xml' && function_exists('mime_content_type')) {
        $mime = @mime_content_type($file['tmp_name']);
        $ok = ['application/pdf','image/jpeg','image/png','image/webp'];
        if ($mime && !in_array($mime, $ok, true)) return ['ruta'=>null, 'error'=>'El contenido del archivo no es un PDF ni una imagen válida.'];
    }
    $sub = date('Y/m');
    $dir = __DIR__ . '/../assets/uploads/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $nombre = 'gasto_' . bin2hex(random_bytes(10)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($file['tmp_name'], "$dir/$nombre")) return ['ruta'=>null, 'error'=>'No se pudo guardar el archivo en el servidor.'];
    return ['ruta'=>"uploads/$sub/$nombre", 'error'=>null];
}

/** Badge HTML del tipo de gasto: fijo (recurrente) o variable. */
function badge_tipo_gasto(string $tipo): string {
    return $tipo === 'fijo'
        ? '<span class="text-xs font-medium px-2 py-0.5 rounded-full bg-marca-50 text-marca-700">Fijo</span>'
        : '<span class="text-xs font-medium px-2 py-0.5 rounded-full bg-zinc-100 text-zinc-600">Variable</span>';
}

/**
 * Badge HTML del estatus de pago. Los colores salen del semaforo de tema.php,
 * que es independiente del color de marca a proposito.
 */
function badge_estatus_pago(string $estatus): string {
    $c = color_estatus($estatus);
    $t = texto_estatus($estatus);
    return "<span class='text-xs font-semibold px-2 py-0.5 rounded-full' "
         . "style='background-color:{$c}1f;color:{$c}'>" . e($t) . "</span>";
}

// ---------------------------------------------------------------------------
//  Renglones de detalle
// ---------------------------------------------------------------------------

/** Importe de un renglon: cantidad x precio unitario, redondeado a centavos. */
function item_importe(float $cantidad, float $precio): float {
    return round($cantidad * $precio, 2);
}

/**
 * Reemplaza los renglones de un gasto. $items es una lista de arreglos con
 * descripcion, cantidad, precio_unitario y opcionalmente insumo_id, codigo,
 * unidad_id, subcategoria_id y notas. Devuelve la suma de los importes.
 *
 * Si el renglon trae insumo_id, el nombre, el codigo y la unidad se toman del
 * catalogo: asi "bolsa camiseta" siempre se llama igual, capture quien capture.
 * Si no lo trae, es un "Otro" que queda pendiente de clasificar.
 */
function gasto_items_guardar(int $gasto_id, array $items): float {
    $conInsumos = modulo_insumos_instalado();
    db_exec("DELETE FROM gasto_items WHERE gasto_id=:g", ['g' => $gasto_id]);
    $total = 0.0; $orden = 0;
    foreach ($items as $it) {
        $insumoId = $conInsumos ? (((int)($it['insumo_id'] ?? 0)) ?: null) : null;
        $desc     = trim((string)($it['descripcion'] ?? ''));
        $codigo   = trim((string)($it['codigo'] ?? ''));
        $unidad   = ((int)($it['unidad_id'] ?? 0)) ?: null;
        $subcat   = ((int)($it['subcategoria_id'] ?? 0)) ?: null;

        if ($insumoId) {
            $ins = db_one("SELECT nombre, codigo, unidad_id, subcategoria_id FROM insumos WHERE id=:i",
                          ['i' => $insumoId]);
            if ($ins) {
                $desc   = (string) $ins['nombre'];
                $codigo = (string) ($ins['codigo'] ?? '');
                if (!$unidad) $unidad = $ins['unidad_id'] !== null ? (int)$ins['unidad_id'] : null;
                if (!$subcat) $subcat = $ins['subcategoria_id'] !== null ? (int)$ins['subcategoria_id'] : null;
            } else {
                $insumoId = null;   // el insumo ya no existe: se trata como texto libre
            }
        }
        if ($desc === '') continue;

        $cant   = (float) ($it['cantidad'] ?? 0);
        $precio = (float) ($it['precio_unitario'] ?? 0);
        if ($cant <= 0) $cant = 1;
        $imp = item_importe($cant, $precio);
        $total += $imp;
        $orden++;

        $cols = $conInsumos
            ? "(gasto_id, orden, subcategoria_id, insumo_id, no_es_insumo, codigo, descripcion, cantidad, unidad_id, precio_unitario, importe, notas)
               VALUES (:g,:o,:s,:ins,:nei,:c,:d,:ca,:u,:p,:i,:n)"
            : "(gasto_id, orden, subcategoria_id, codigo, descripcion, cantidad, unidad_id, precio_unitario, importe, notas)
               VALUES (:g,:o,:s,:c,:d,:ca,:u,:p,:i,:n)";
        $vals = [
            'g'   => $gasto_id,
            'o'   => $orden,
            's'   => $subcat,
            'c'   => $codigo !== '' ? $codigo : null,
            'd'   => mb_substr($desc, 0, 255),
            'ca'  => $cant,
            'u'   => $unidad,
            'p'   => $precio,
            'i'   => $imp,
            'n'   => (trim((string)($it['notas'] ?? '')) !== '') ? mb_substr(trim((string)$it['notas']), 0, 255) : null,
        ];
        if ($conInsumos) {
            $vals['ins'] = $insumoId;
            // La renta o la luz no son consumibles: se marcan así para que no
            // caigan cada mes en la bandeja "Por clasificar" del catálogo.
            $vals['nei'] = (!$insumoId && !empty($it['no_es_insumo'])) ? 1 : 0;
        }
        db_exec("INSERT INTO gasto_items $cols", $vals);
    }
    return round($total, 2);
}

// ---------------------------------------------------------------------------
//  Insumos (catalogo cerrado de consumibles)
// ---------------------------------------------------------------------------

/**
 * ¿Ya se corrió siggo_02_insumos.sql sobre esta base?
 * Se consulta una sola vez por petición. Sirve para que una instalación a la
 * que le falta el script avise en lugar de tirar la pantalla en blanco.
 */
function modulo_insumos_instalado(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $r = db_one("SELECT COUNT(*) c FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insumos'");
        $tabla = (int)($r['c'] ?? 0) === 1;
        $r2 = db_one("SELECT COUNT(*) c FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gasto_items'
                         AND COLUMN_NAME IN ('insumo_id','no_es_insumo')");
        $ok = $tabla && (int)($r2['c'] ?? 0) === 2;
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

/**
 * Catalogo de insumos activos, con el precio de la ultima compra.
 * El precio se muestra como referencia al capturar; no se llena solo, porque
 * un precio viejo metido sin querer es peor que teclearlo.
 */
function insumos_lista(): array {
    if (!modulo_insumos_instalado()) return [];
    return db_all("SELECT i.id, i.codigo, i.nombre, i.categoria_id, i.subcategoria_id,
                          i.unidad_id, i.area_sugerida_id,
                          u.clave AS unidad_clave,
                          (SELECT it.precio_unitario FROM gasto_items it
                            INNER JOIN gastos g ON g.id = it.gasto_id
                            WHERE it.insumo_id = i.id
                            ORDER BY g.fecha DESC, it.id DESC LIMIT 1) AS ultimo_precio
                     FROM insumos i
                     LEFT JOIN unidades_medida u ON u.id = i.unidad_id
                    WHERE i.activo = 1
                    ORDER BY i.orden, i.nombre");
}

/**
 * Renglones capturados como "Otro": traen descripcion pero no insumo del
 * catalogo. Es la bandeja que el administrador tiene que clasificar.
 * Se agrupan por descripcion para no repetir lo mismo veinte veces.
 */
function insumos_pendientes(): array {
    if (!modulo_insumos_instalado()) return [];
    return db_all("SELECT TRIM(i.descripcion) AS texto,
                          COUNT(*) AS veces,
                          SUM(i.importe) AS importe,
                          MAX(g.fecha) AS ultima,
                          MAX(u.clave) AS unidad,
                          MAX(g.categoria_id) AS categoria_id
                     FROM gasto_items i
                     INNER JOIN gastos g ON g.id = i.gasto_id
                     LEFT JOIN unidades_medida u ON u.id = i.unidad_id
                    WHERE i.insumo_id IS NULL AND i.no_es_insumo = 0 AND TRIM(i.descripcion) <> ''
                    GROUP BY TRIM(i.descripcion)
                    ORDER BY veces DESC, importe DESC");
}

/** Cuántos renglones están esperando que alguien los clasifique. */
function insumos_pendientes_total(): int {
    if (!modulo_insumos_instalado()) return 0;
    return (int) (db_one("SELECT COUNT(*) v FROM gasto_items
                           WHERE insumo_id IS NULL AND no_es_insumo = 0
                             AND TRIM(descripcion) <> ''")['v'] ?? 0);
}

/** Renglones de un gasto, con la unidad ya resuelta. */
function gasto_items(int $gasto_id): array {
    // El catálogo de insumos es un módulo aparte: si aún no se instaló, la
    // consulta tiene que seguir funcionando igual, sin ese pedazo.
    $conIns = modulo_insumos_instalado();
    $sel    = $conIns ? ", ins.nombre AS insumo_nombre, ins.codigo AS insumo_codigo" : "";
    $join   = $conIns ? " LEFT JOIN insumos ins ON ins.id = i.insumo_id" : "";

    return db_all("SELECT i.*, u.clave AS unidad_clave, s.nombre AS subcategoria {$sel}
                     FROM gasto_items i
                     LEFT JOIN unidades_medida u     ON u.id = i.unidad_id
                     LEFT JOIN subcategorias_gasto s ON s.id = i.subcategoria_id
                     {$join}
                    WHERE i.gasto_id = :g
                    ORDER BY i.orden, i.id", ['g' => $gasto_id]);
}

/** Suma de los renglones de un gasto. */
function gasto_items_total(int $gasto_id): float {
    return (float) (db_one("SELECT COALESCE(SUM(importe),0) v FROM gasto_items WHERE gasto_id=:g",
                           ['g' => $gasto_id])['v'] ?? 0);
}

/**
 * Reparte el monto de un gasto entre areas segun porcentajes.
 * $reparto es [area_id => porcentaje]. El ultimo renglon absorbe el redondeo
 * para que la suma cuadre exactamente con el monto del gasto.
 */
function gasto_reparto_guardar(int $gasto_id, float $monto, array $reparto): void {
    db_exec("DELETE FROM gasto_distribucion WHERE gasto_id=:g", ['g' => $gasto_id]);
    if (empty($reparto)) return;
    $acum = 0.0; $n = count($reparto); $k = 0;
    foreach ($reparto as $area_id => $pct) {
        $k++;
        $m = ($k === $n) ? round($monto - $acum, 2) : round($monto * $pct / 100, 2);
        if ($k !== $n) $acum += $m;
        db_exec("INSERT INTO gasto_distribucion (gasto_id, area_id, porcentaje, monto) VALUES (:g,:a,:p,:m)",
                ['g' => $gasto_id, 'a' => (int)$area_id, 'p' => $pct, 'm' => $m]);
    }
}

// ---------------------------------------------------------------------------
//  Catalogos
// ---------------------------------------------------------------------------

/** Subcategorias agrupadas por categoria, listas para el selector dependiente. */
function subcategorias_por_categoria(): array {
    $out = [];
    foreach (db_all("SELECT id, categoria_id, nombre, codigo FROM subcategorias_gasto
                      WHERE activo=1 ORDER BY orden, nombre") as $s) {
        $out[(int)$s['categoria_id']][] = [
            'id'     => (int)$s['id'],
            'nombre' => $s['nombre'],
            'codigo' => $s['codigo'],
        ];
    }
    return $out;
}

function unidades_lista(): array {
    return db_all("SELECT id, clave, nombre FROM unidades_medida WHERE activo=1 ORDER BY orden, clave");
}

function formas_pago_lista(): array {
    return db_all("SELECT id, nombre, requiere_referencia FROM formas_pago WHERE activo=1 ORDER BY orden, nombre");
}

function areas_lista(): array {
    return db_all("SELECT id, nombre, codigo FROM areas WHERE activo=1 ORDER BY nombre");
}

function categorias_lista(): array {
    return db_all("SELECT id, nombre, codigo, color FROM categorias_gasto
                    WHERE activo=1 AND ambito='gasto' ORDER BY orden, nombre");
}

// ---------------------------------------------------------------------------
//  CFDI
// ---------------------------------------------------------------------------

/**
 * Extrae los datos de un CFDI (XML) crudo. Devuelve null si no parece un CFDI.
 * Reutilizado por api/cfdi_parse.php (formulario) y gasto_ver.php (adjuntos).
 */
function cfdi_parse_raw(string $raw): ?array {
    if ($raw === '' || stripos($raw, 'Comprobante') === false) return null;
    libxml_use_internal_errors(true);
    $doc = @simplexml_load_string($raw);
    if (!$doc) return null;
    $ns = $doc->getNamespaces(true);
    $cfdiNs = $ns['cfdi'] ?? 'http://www.sat.gob.mx/cfd/4';
    $a = $doc->attributes();
    $total    = (float) ($a['Total'] ?? 0);
    $subtotal = (float) ($a['SubTotal'] ?? 0);
    $fechaRaw = (string) ($a['Fecha'] ?? '');
    $fecha    = $fechaRaw ? date('Y-m-d', strtotime($fechaRaw)) : '';
    $folio    = trim((string)($a['Serie'] ?? '') . ' ' . (string)($a['Folio'] ?? ''));
    $rfc = ''; $nombre = '';
    $em = $doc->children($cfdiNs)->Emisor;
    if ($em) { $ea = $em->attributes(); $rfc = strtoupper((string)($ea['Rfc'] ?? '')); $nombre = (string)($ea['Nombre'] ?? ''); }
    $iva = 0.0;
    $imp = $doc->children($cfdiNs)->Impuestos;
    if ($imp) { $ia = $imp->attributes(); $iva = (float)($ia['TotalImpuestosTrasladados'] ?? 0); }
    $uuid = '';
    $comp = $doc->children($cfdiNs)->Complemento;
    if ($comp) {
        foreach ($ns as $uri) {
            foreach ($comp->children($uri) as $node) {
                $na = $node->attributes();
                if (isset($na['UUID'])) { $uuid = strtoupper((string)$na['UUID']); break 2; }
            }
        }
    }
    // Conceptos del CFDI: se vuelven los renglones del gasto sin recapturarlos
    $conceptos = [];
    $cc = $doc->children($cfdiNs)->Conceptos;
    if ($cc) {
        foreach ($cc->children($cfdiNs)->Concepto as $cn) {
            $ca = $cn->attributes();
            $conceptos[] = [
                'codigo'          => (string)($ca['NoIdentificacion'] ?? ''),
                'descripcion'     => (string)($ca['Descripcion'] ?? ''),
                'cantidad'        => (float)($ca['Cantidad'] ?? 1),
                'unidad'          => (string)($ca['ClaveUnidad'] ?? ''),
                'precio_unitario' => (float)($ca['ValorUnitario'] ?? 0),
                'importe'         => (float)($ca['Importe'] ?? 0),
            ];
        }
    }
    return compact('total','subtotal','iva','fecha','folio','rfc','nombre','uuid','conceptos');
}

/**
 * Detecta un proveedor por RFC; si no existe, lo da de alta con los datos del
 * CFDI. Devuelve ['id'=>?int, 'nuevo'=>bool, 'nombre'=>string].
 */
function proveedor_detectar_o_alta(string $rfc, string $nombre = '', ?int $uid = null): array {
    $rfc = strtoupper(trim($rfc));
    if ($rfc === '') return ['id'=>null, 'nuevo'=>false, 'nombre'=>''];
    $p = db_one("SELECT id, nombre FROM proveedores WHERE rfc=:r LIMIT 1", ['r'=>$rfc]);
    if ($p) return ['id'=>(int)$p['id'], 'nuevo'=>false, 'nombre'=>(string)$p['nombre']];
    $nombreProv = $nombre !== '' ? $nombre : $rfc;
    try {
        db_exec("INSERT INTO proveedores (nombre, razon_social, rfc, creado_por_id, activo) VALUES (:n,:rs,:r,:u,1)",
                ['n'=>mb_substr($nombreProv,0,150), 'rs'=>($nombre ?: null), 'r'=>$rfc, 'u'=>$uid]);
        return ['id'=>db_last_id(), 'nuevo'=>true, 'nombre'=>$nombreProv];
    } catch (Throwable $e) {
        try {
            db_exec("INSERT INTO proveedores (nombre, razon_social, rfc, creado_por_id, activo) VALUES (:n,:rs,:r,:u,1)",
                    ['n'=>mb_substr($nombreProv.' ('.$rfc.')',0,150), 'rs'=>($nombre ?: null), 'r'=>$rfc, 'u'=>$uid]);
            return ['id'=>db_last_id(), 'nuevo'=>true, 'nombre'=>$nombreProv];
        } catch (Throwable $e2) {
            $pp = db_one("SELECT id, nombre FROM proveedores WHERE rfc=:r LIMIT 1", ['r'=>$rfc]);
            if ($pp) return ['id'=>(int)$pp['id'], 'nuevo'=>false, 'nombre'=>(string)$pp['nombre']];
            return ['id'=>null, 'nuevo'=>false, 'nombre'=>''];
        }
    }
}
