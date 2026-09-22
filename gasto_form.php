<?php
/** gasto_form.php - Alta y edicion de gastos con detalle por renglon */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/tema.php';
require_once __DIR__ . '/config/gastos_helpers.php';
require_once __DIR__ . '/config/controles_helpers.php';
requerir_login();
if (!(tiene_permiso('administrar') || tiene_permiso('crear_solicitud'))) {
    flash_set('error','No tienes permiso para capturar o editar gastos.');
    header('Location: '.url('gastos.php')); exit;
}

$id   = (int) input('id');
$edit = false;
$g    = null;
if ($id > 0) {
    $g = db_one("SELECT * FROM gastos WHERE id=:id", ['id'=>$id]);
    if (!$g) { flash_set('error','Gasto no encontrado.'); header('Location: '.url('gastos.php')); exit; }
    $edit = true;
}

$form = [
    'fecha'=>date('Y-m-d'), 'area_id'=>'', 'tipo'=>'variable', 'categoria_id'=>'', 'subcategoria_id'=>'',
    'concepto'=>'', 'proveedor_id'=>'', 'proveedor_texto'=>'', 'numero_factura'=>'',
    'forma_pago_id'=>'', 'referencia_pago'=>'', 'estatus_pago'=>'pagado', 'fecha_pago'=>'',
    'responsable_id'=>'', 'responsable_texto'=>'', 'notas'=>'', 'archivo_url'=>null,
    'subtotal'=>'', 'iva'=>'', 'uuid'=>'', 'rfc_emisor'=>'', 'cfdi_xml_url'=>null,
];
if ($edit) { foreach ($form as $k=>$v) { if (array_key_exists($k, $g)) $form[$k] = $g[$k]; } }

$errores = [];
$itemsPost = [];
$pdfAjax   = '';   // PDF de factura que ya se subió al leerlo (queda de comprobante)

if (es_post()) {
    // Una foto de celular pesa varios MB. Si pasa del límite del servidor, PHP
    // tira TODO el POST y esto llegaría vacío: sin este aviso el usuario ve
    // "sesión expirada" y nunca entiende que el problema fue el tamaño.
    $envio = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && $envio > 0) {
        $max = ini_get('post_max_size') ?: '?';
        $errores[] = 'La foto o el archivo pesan más de lo que acepta el servidor (' . $max . '). '
                   . 'Vuelve a tomar la foto con menos resolución o pide al administrador subir ese límite.';
    }
    if (!$errores && !csrf_valido(input('_csrf'))) { $errores[] = 'Sesión expirada, vuelve a intentar.'; }
    foreach (array_keys($form) as $k) {
        if ($k === 'archivo_url') continue;
        $form[$k] = is_string(input($k)) ? trim((string)input($k)) : (input($k) ?? '');
    }
    $form['tipo'] = $form['tipo'] === 'fijo' ? 'fijo' : 'variable';
    if (!in_array($form['estatus_pago'], ['pendiente','parcial','pagado','cancelado'], true)) {
        $form['estatus_pago'] = 'pagado';
    }

    $area = (int) $form['area_id'];
    $cat  = (int) $form['categoria_id'];
    $sub  = (int) $form['subcategoria_id'] ?: null;

    // --- Renglones -------------------------------------------------------
    $iInsumo = (array) (input('item_insumo') ?? []);
    $iDesc   = (array) (input('item_descripcion') ?? []);
    $iCod    = (array) (input('item_codigo') ?? []);
    $iCant   = (array) (input('item_cantidad') ?? []);
    $iUnidad = (array) (input('item_unidad') ?? []);
    $iPrecio = (array) (input('item_precio') ?? []);
    foreach ($iCant as $ix => $_ignorado) {
        $ins = (int) ($iInsumo[$ix] ?? 0);
        $d   = trim((string)($iDesc[$ix] ?? ''));
        // Con insumo del catálogo basta; sin él, hace falta la descripción del "Otro"
        if ($ins <= 0 && $d === '') continue;
        $itemsPost[] = [
            'insumo_id'       => $ins,
            'codigo'          => trim((string)($iCod[$ix] ?? '')),
            'descripcion'     => $d,
            'cantidad'        => (float) str_replace(',', '', (string)($iCant[$ix] ?? 1)),
            'unidad_id'       => (int) ($iUnidad[$ix] ?? 0),
            'precio_unitario' => (float) str_replace(',', '', (string)($iPrecio[$ix] ?? 0)),
        ];
    }
    // El nombre del insumo del catálogo manda sobre lo que se haya tecleado
    foreach ($itemsPost as $ix => $it) {
        if (!empty($it['insumo_id'])) {
            $ins = db_one("SELECT nombre FROM insumos WHERE id=:i", ['i'=>(int)$it['insumo_id']]);
            if ($ins) $itemsPost[$ix]['descripcion'] = (string)$ins['nombre'];
        }
    }
    $monto = 0.0;
    foreach ($itemsPost as $it) {
        $c = $it['cantidad'] > 0 ? $it['cantidad'] : 1;
        $monto += item_importe($c, $it['precio_unitario']);
    }
    $monto = round($monto, 2);

    // Concepto: si no lo escribieron, se toma del primer renglon
    if ($form['concepto'] === '' && !empty($itemsPost)) {
        $form['concepto'] = mb_substr($itemsPost[0]['descripcion'], 0, 200);
    }

    // --- Validaciones ----------------------------------------------------
    if ($form['fecha'] === '' || !strtotime($form['fecha'])) $errores[] = 'La fecha es obligatoria.';
    if ($area <= 0) $errores[] = 'Selecciona un área.';
    if ($cat  <= 0) $errores[] = 'Selecciona una categoría.';
    if ($form['concepto'] === '') $errores[] = 'El concepto es obligatorio.';
    if (empty($itemsPost)) $errores[] = 'Captura al menos un renglón: elige el insumo o describe qué se compró.';
    if ($monto <= 0) $errores[] = 'El importe total debe ser mayor a cero. Revisa cantidades y precios.';

    if ($sub) {
        $sv = db_one("SELECT id FROM subcategorias_gasto WHERE id=:s AND categoria_id=:c", ['s'=>$sub,'c'=>$cat]);
        if (!$sv) $errores[] = 'La subcategoría no corresponde a la categoría elegida.';
    }
    if ($form['estatus_pago'] === 'pagado' && $form['fecha_pago'] === '') {
        $form['fecha_pago'] = $form['fecha'];
    }
    if ($form['fecha_pago'] !== '' && !strtotime($form['fecha_pago'])) {
        $errores[] = 'La fecha de pago no es válida.';
    }

    // Cierre de mes
    if ($form['fecha'] !== '' && strtotime($form['fecha']) && fecha_en_mes_cerrado($form['fecha'])) {
        $errores[] = 'El mes de esa fecha está cerrado. Elige una fecha en un mes abierto.';
    }
    if ($edit && !empty($g['fecha']) && fecha_en_mes_cerrado($g['fecha'])) {
        $errores[] = 'Este gasto pertenece a un mes cerrado y no puede modificarse.';
    }

    // Reparto por area
    $rar = (array) (input('reparto_area') ?? []);
    $rpc = (array) (input('reparto_pct') ?? []);
    $reparto = []; $sumpct = 0.0;
    foreach ($rar as $ri=>$aa) {
        $ai = (int) $aa;
        $pv = (float) str_replace(',', '', (string)($rpc[$ri] ?? 0));
        if ($ai > 0 && $pv > 0) {
            if (isset($reparto[$ai])) $errores[] = 'Hay un área repetida en el reparto.';
            $reparto[$ai] = $pv; $sumpct += $pv;
        }
    }
    if (!empty($reparto)) {
        if (abs($sumpct - 100) > 0.05) $errores[] = 'El reparto por área debe sumar 100% (va en ' . number_format($sumpct,2) . '%).';
        foreach (array_keys($reparto) as $ai) {
            if (!db_one("SELECT id FROM areas WHERE id=:a", ['a'=>$ai])) { $errores[] = 'Área inválida en el reparto.'; break; }
        }
    }

    // CFDI duplicado
    if ($form['uuid'] !== '') {
        $uu = strtoupper(trim((string)$form['uuid']));
        $dupq = $edit
            ? db_one("SELECT folio FROM gastos WHERE uuid=:u AND id<>:id", ['u'=>$uu,'id'=>$id])
            : db_one("SELECT folio FROM gastos WHERE uuid=:u", ['u'=>$uu]);
        if ($dupq) $errores[] = 'Ya existe un gasto con este CFDI (folio ' . $dupq['folio'] . ').';
    }

    // Archivo
    $archivo = $edit ? ($g['archivo_url'] ?? null) : null;
    if (input('quitar_archivo') === '1' && $archivo) { imagen_borrar_archivo($archivo); $archivo = null; }
    // Hay dos botones (cámara y explorador). Se toma el que traiga archivo.
    $subido = null;
    foreach (['archivo', 'archivo_subido'] as $campo) {
        if (!empty($_FILES[$campo]['name']) && ($_FILES[$campo]['error'] ?? 1) === UPLOAD_ERR_OK) {
            $subido = $_FILES[$campo]; break;
        }
    }
    if (empty($errores) && $subido) {
        $up = archivo_subir($subido);
        if ($up['error']) { $errores[] = $up['error']; }
        elseif ($up['ruta']) { if ($edit && $archivo) imagen_borrar_archivo($archivo); $archivo = $up['ruta']; }
    }

    // Si la factura se subió en PDF al leerla, ese PDF es el comprobante.
    // La ruta llega del navegador, así que se valida a mano: solo se acepta
    // el formato exacto que produce archivo_subir() y que el archivo exista.
    $pdfAjax = trim((string) input('comprobante_url'));
    if ($pdfAjax !== '' && !preg_match('#^uploads/\d{4}/\d{2}/gasto_[a-f0-9]{20}\.(pdf|jpg|png|webp|xml)$#', $pdfAjax)) {
        $pdfAjax = '';
    }
    if ($pdfAjax !== '' && !is_file(__DIR__ . '/assets/' . $pdfAjax)) $pdfAjax = '';
    if ($pdfAjax !== '' && !$subido && $pdfAjax !== $archivo && input('quitar_archivo') !== '1') {
        if ($edit && $archivo) imagen_borrar_archivo($archivo);
        $archivo = $pdfAjax;
    }
    $form['archivo_url'] = $archivo;

    // COMPROBANTE OBLIGATORIO: sin factura XML, no se guarda sin foto o PDF.
    // Excepción: los gastos que generó el cron desde un recurrente nacen sin
    // comprobante a propósito (la renta domiciliada no trae ticket). Si aquí se
    // exigiera, esos gastos quedarían imposibles de corregir para siempre.
    $tieneXml     = trim((string)$form['cfdi_xml_url']) !== '' || trim((string)$form['uuid']) !== '';
    $esGenerado   = $edit && !empty($g['recurrente_id']);
    if (!$tieneXml && !$archivo && !$esGenerado) {
        $errores[] = 'Falta el comprobante. Si la compra no tiene factura XML, toma la foto de la nota o el ticket antes de guardar.';
    }

    // --- Guardar ---------------------------------------------------------
    if (empty($errores)) {
        $params = [
            'f'    => $form['fecha'],
            'a'    => $area,
            'c'    => $cat,
            's'    => $sub,
            't'    => $form['tipo'],
            'co'   => mb_substr($form['concepto'], 0, 200),
            'm'    => $monto,
            'prov' => (int)$form['proveedor_id'] ?: null,
            'provt'=> $form['proveedor_texto'] !== '' ? $form['proveedor_texto'] : null,
            'fac'  => $form['numero_factura'] !== '' ? $form['numero_factura'] : null,
            'fp'   => (int)$form['forma_pago_id'] ?: null,
            'ref'  => $form['referencia_pago'] !== '' ? $form['referencia_pago'] : null,
            'est'  => $form['estatus_pago'],
            'fpag' => $form['fecha_pago'] !== '' ? $form['fecha_pago'] : null,
            'resp' => (int)$form['responsable_id'] ?: null,
            'respt'=> $form['responsable_texto'] !== '' ? $form['responsable_texto'] : null,
            'arch' => $archivo,
            'not'  => $form['notas'] !== '' ? $form['notas'] : null,
            'sub'  => $form['subtotal'] !== '' ? (float)str_replace(',','',(string)$form['subtotal']) : null,
            'ivac' => $form['iva'] !== '' ? (float)str_replace(',','',(string)$form['iva']) : null,
            'uuidc'=> $form['uuid'] !== '' ? strtoupper(trim((string)$form['uuid'])) : null,
            'rfcc' => $form['rfc_emisor'] !== '' ? strtoupper(trim((string)$form['rfc_emisor'])) : null,
            'xmlc' => $form['cfdi_xml_url'] ?: null,
        ];

        try {
            db()->beginTransaction();
            if ($edit) {
                $params['id'] = $id;
                db_exec("UPDATE gastos SET fecha=:f, area_id=:a, categoria_id=:c, subcategoria_id=:s, tipo=:t,
                            concepto=:co, monto=:m, proveedor_id=:prov, proveedor_texto=:provt, numero_factura=:fac,
                            forma_pago_id=:fp, referencia_pago=:ref, estatus_pago=:est, fecha_pago=:fpag,
                            responsable_id=:resp, responsable_texto=:respt, archivo_url=:arch, notas=:not,
                            subtotal=:sub, iva=:ivac, uuid=:uuidc, rfc_emisor=:rfcc, cfdi_xml_url=:xmlc
                          WHERE id=:id", $params);
            } else {
                $params['folio'] = generar_folio_gasto($area, $form['fecha']);
                $params['u'] = (int)(usuario_actual()['id'] ?? 0) ?: null;
                db_exec("INSERT INTO gastos
                           (folio,fecha,area_id,categoria_id,subcategoria_id,tipo,concepto,monto,
                            proveedor_id,proveedor_texto,numero_factura,forma_pago_id,referencia_pago,
                            estatus_pago,fecha_pago,responsable_id,responsable_texto,archivo_url,notas,
                            subtotal,iva,uuid,rfc_emisor,cfdi_xml_url,registrado_por)
                         VALUES (:folio,:f,:a,:c,:s,:t,:co,:m,:prov,:provt,:fac,:fp,:ref,:est,:fpag,
                                 :resp,:respt,:arch,:not,:sub,:ivac,:uuidc,:rfcc,:xmlc,:u)", $params);
                $id = db_last_id();
            }

            // Los renglones mandan: el monto del gasto sale de su suma
            $total = gasto_items_guardar($id, $itemsPost);
            db_exec("UPDATE gastos SET monto=:m WHERE id=:id", ['m'=>$total, 'id'=>$id]);
            gasto_reparto_guardar($id, $total, $reparto);

            db()->commit();
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $errores[] = 'No se pudo guardar el gasto: ' . $ex->getMessage();
        }

        if (empty($errores)) {
            registrar_auditoria($edit ? 'editar' : 'crear', 'gastos', $id,
                ($edit ? 'Editó' : 'Registró') . " gasto {$form['concepto']}");
            flash_set('success', $edit ? 'Gasto actualizado.' : 'Gasto registrado con folio ' . ($params['folio'] ?? '') . '.');
            header('Location: '.url('gasto_ver.php?id='.$id)); exit;
        }
    }
}

// --- Datos para la vista ----------------------------------------------------
$areas      = areas_lista();
$cats       = categorias_lista();
$subsPorCat = subcategorias_por_categoria();
$unidades   = unidades_lista();
$insumos    = insumos_lista();
$formasPago = formas_pago_lista();
$provs      = db_all("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
$usuarios   = db_all("SELECT id, nombre_completo FROM usuarios WHERE activo=1 ORDER BY nombre_completo");

// Renglones que se pintan al abrir
if (es_post()) {
    $itemsInit = $itemsPost;
} elseif ($edit) {
    $itemsInit = [];
    foreach (gasto_items($id) as $it) {
        $itemsInit[] = [
            'insumo_id'       => (int)($it['insumo_id'] ?? 0),
            'codigo'          => (string)($it['codigo'] ?? ''),
            'descripcion'     => (string)$it['descripcion'],
            'cantidad'        => (float)$it['cantidad'],
            'unidad_id'       => (int)($it['unidad_id'] ?? 0),
            'precio_unitario' => (float)$it['precio_unitario'],
        ];
    }
} else {
    $itemsInit = [];
}
if (empty($itemsInit)) {
    $itemsInit = [['insumo_id'=>0,'codigo'=>'','descripcion'=>'','cantidad'=>1,'unidad_id'=>0,'precio_unitario'=>0]];
}

$repartoInit = [];
if ($edit && !es_post()) {
    foreach (db_all("SELECT area_id, porcentaje FROM gasto_distribucion WHERE gasto_id=:g ORDER BY id", ['g'=>$id]) as $rr)
        $repartoInit[] = ['area'=>(string)$rr['area_id'], 'pct'=>(float)$rr['porcentaje']];
}

$titulo_pagina = $edit ? 'Editar gasto' : 'Nuevo gasto';
$pagina_activa = 'gastos';
require __DIR__ . '/config/header.php';
?>
<div class="max-w-4xl mx-auto" x-data="gastoForm()">
  <a href="<?= url('gastos.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-2">
    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Gastos</a>
  <h2 class="font-display text-2xl font-extrabold text-zinc-900 mb-1"><?= $edit ? 'Editar gasto' : 'Registrar gasto' ?></h2>
  <p class="text-xs text-zinc-500 mb-5">Una línea por producto, artículo o servicio. El importe se calcula solo: cantidad × precio unitario.</p>

  <?php if ($errores): ?>
    <div class="mb-4 border-l-4 border-red-300 bg-red-50 text-red-800 rounded-lg px-4 py-3 text-sm">
      <ul class="list-disc pl-4 space-y-0.5"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="space-y-4">
    <?= csrf_input() ?><?php if ($edit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <input type="hidden" name="subtotal"     :value="subtotal">
    <input type="hidden" name="iva"          :value="iva">
    <input type="hidden" name="uuid"         :value="uuid">
    <input type="hidden" name="rfc_emisor"   :value="rfc_emisor">
    <input type="hidden" name="cfdi_xml_url" :value="cfdi_xml_url">
    <input type="hidden" name="comprobante_url" :value="archivo_url_ajax">

    <!-- ============ FACTURA (XML o PDF) ============ -->
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
      <div class="rounded-xl border border-dashed border-marca-300 bg-marca-50/50 p-4">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-marca-100 text-marca-700 flex items-center justify-center flex-shrink-0">
            <i data-lucide="file-text" class="w-5 h-5"></i></div>
          <div class="flex-1">
            <div class="text-sm font-semibold text-zinc-800">Factura del proveedor (XML o PDF)</div>
            <div class="text-xs text-zinc-500">
              El <b>XML</b> carga solo todo, incluidos los artículos. El <b>PDF</b> casi siempre
              solo carga proveedor y montos.
            </div>
          </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-3">
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold cursor-pointer shadow-sm">
            <i data-lucide="upload" class="w-4 h-4"></i> Subir factura
            <input type="file" accept=".xml,.pdf,text/xml,application/xml,application/pdf"
                   @change="subirComprobante($event)" class="hidden">
          </label>
          <span x-show="compCargando" x-cloak class="text-xs text-zinc-500">Leyendo el archivo…</span>
          <span x-show="compNombre && !compCargando" x-cloak class="text-xs text-zinc-600 inline-flex items-center gap-1.5">
            <i data-lucide="file-check" class="w-4 h-4" style="color:<?= EST_OK ?>"></i><span x-text="compNombre"></span>
          </span>
        </div>

        <!-- Regla para el que captura, dicha una sola vez y en corto -->
        <div class="mt-3 rounded-lg bg-white/70 border border-marca-100 p-3 text-xs text-zinc-600 leading-relaxed space-y-1.5">
          <div class="font-semibold text-zinc-700">Cómo queda cada caso</div>
          <div><b>Subes el XML</b> (o un PDF que trae el XML adentro): se cargan solos el proveedor,
               los montos y los artículos. No capturas nada.</div>
          <div><b>Subes solo el PDF:</b> el PDF vale como comprobante y se guarda, pero
               <b>los artículos casi siempre hay que capturarlos a mano</b> aquí abajo, porque cada
               proveedor arma su factura distinto. Revisa también el total y la fecha.</div>
          <div><b>No hay factura</b> (nota, recibo o ticket): captura los artículos a mano y
               <b>toma la foto</b> en el apartado <i>Comprobante</i>.</div>
          <div class="text-zinc-500">Sin comprobante —factura, PDF o foto— el gasto no se guarda.</div>
        </div>

        <!-- Leído bien: XML suelto o PDF que traía el XML adentro -->
        <template x-if="compOk && compConf">
          <div class="mt-3 text-xs bg-white border border-emerald-200 rounded-lg p-3 text-zinc-600">
            <div class="font-semibold text-emerald-700 mb-1">
              <span x-text="compOrigen==='xml_incrustado' ? 'PDF leído (traía el XML adentro)' : 'CFDI leído correctamente'"></span>
            </div>
            <div>Emisor: <span x-text="rfc_emisor"></span> · Subtotal: <span x-text="fmtm(subtotal)"></span> ·
                 IVA: <span x-text="fmtm(iva)"></span> · Total: <span x-text="fmtm(cfdiTotal)"></span></div>
            <div x-show="cfdiProv" class="text-emerald-700 font-semibold mt-1" x-text="cfdiProv"></div>
            <div class="text-zinc-400" x-text="uuid ? 'UUID '+uuid : ''"></div>
          </div>
        </template>

        <!-- PDF sin XML: se leyó lo que se pudo, y se dice con todas sus letras -->
        <template x-if="compOk && !compConf">
          <div class="mt-3 text-xs rounded-lg p-3 border"
               style="background:<?= EST_WARN ?>0f;border-color:<?= EST_WARN ?>40;color:<?= EST_WARN ?>">
            <div class="font-semibold mb-1 flex items-start gap-1.5">
              <i data-lucide="pencil-line" class="w-4 h-4 flex-shrink-0 mt-px"></i>
              <span x-text="compTitulo"></span>
            </div>
            <div x-text="compAviso"></div>
            <div class="mt-2 pt-2 border-t text-zinc-600" style="border-color:<?= EST_WARN ?>30">
              <span class="font-semibold">Lo que sí se leyó:</span>
              proveedor <b x-text="rfc_emisor || '—'"></b> ·
              total <b x-text="cfdiTotal ? fmtm(cfdiTotal) : '—'"></b> ·
              artículos <b x-text="compConceptos"></b>
              <span x-show="compConceptos===0">(captúralos abajo)</span>
            </div>
          </div>
        </template>

        <template x-if="cfdiDup"><div class="mt-3 text-xs bg-orange-50 border border-orange-200 rounded-lg p-3 text-orange-800">
          <b>Ojo:</b> ya existe un gasto con esta factura (<span x-text="cfdiDup"></span>).</div></template>
        <template x-if="cfdiErr"><div class="mt-3 text-xs bg-red-50 border border-red-200 rounded-lg p-3 text-red-700" x-text="cfdiErr"></div></template>
      </div>
    </div>

    <!-- ============ DATOS GENERALES ============ -->
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5 space-y-4">
      <h3 class="font-display font-bold text-sm text-zinc-800">Datos generales</h3>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Fecha *</label>
          <input type="date" name="fecha" x-model="fecha" required
                 class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Área *</label>
          <select name="area_id" x-model="area_id" required
                  class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <option value="">— Selecciona —</option>
            <?php foreach ($areas as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['nombre']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1.5">Tipo</label>
          <div class="flex gap-2">
            <label class="flex-1 cursor-pointer">
              <input type="radio" name="tipo" value="variable" x-model="tipo" class="peer sr-only">
              <div class="px-2 py-2 rounded-lg border text-xs text-center peer-checked:border-marca-500 peer-checked:bg-marca-50 peer-checked:text-marca-700 border-zinc-200 text-zinc-600">Variable</div>
            </label>
            <label class="flex-1 cursor-pointer">
              <input type="radio" name="tipo" value="fijo" x-model="tipo" class="peer sr-only">
              <div class="px-2 py-2 rounded-lg border text-xs text-center peer-checked:border-marca-500 peer-checked:bg-marca-50 peer-checked:text-marca-700 border-zinc-200 text-zinc-600">Fijo</div>
            </label>
          </div></div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Categoría *</label>
          <select name="categoria_id" x-model="categoria_id" @change="subcategoria_id=''" required
                  class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <option value="">— Selecciona —</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Subcategoría</label>
          <select name="subcategoria_id" x-model="subcategoria_id"
                  class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <option value="">— Sin subcategoría —</option>
            <template x-for="s in subcategorias" :key="s.id"><option :value="s.id" x-text="s.nombre"></option></template>
          </select>
          <p x-show="categoria_id && subcategorias.length===0" class="text-[11px] text-zinc-400 mt-1">Esa categoría no tiene subcategorías.</p>
        </div>
      </div>

      <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Concepto *</label>
        <input type="text" name="concepto" x-model="concepto" :placeholder="items.length ? (items[0].descripcion || 'Ej. Compra de limpieza — septiembre') : 'Ej. Compra de limpieza — septiembre'"
               maxlength="200" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
        <p class="text-[11px] text-zinc-400 mt-1">Si lo dejas vacío se usa la descripción del primer renglón.</p></div>
    </div>

    <!-- ============ DETALLE ============ -->
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-display font-bold text-sm text-zinc-800">Detalle</h3>
        <span class="text-xs text-zinc-400" x-text="items.length + (items.length===1 ? ' renglón' : ' renglones')"></span>
      </div>

      <div class="overflow-x-auto -mx-5 px-5">
        <table class="w-full text-sm" style="min-width:720px">
          <thead>
            <tr class="text-[10px] uppercase tracking-wider text-zinc-400 border-b border-zinc-200">
              <th class="text-left font-bold pb-2">Insumo *</th>
              <th class="text-right font-bold pb-2 w-24">Cantidad</th>
              <th class="text-left font-bold pb-2 w-24">Unidad</th>
              <th class="text-right font-bold pb-2 w-28">P. unitario</th>
              <th class="text-right font-bold pb-2 w-28">Importe</th>
              <th class="w-8 pb-2"></th>
            </tr>
          </thead>
          <tbody>
            <template x-for="(r,i) in items" :key="i">
              <tr class="border-b border-zinc-100 align-top">
                <td class="py-1.5 pr-1.5">
                  <?php if (!$insumos): /* sin catálogo instalado: texto libre como antes */ ?>
                    <input type="text" name="item_descripcion[]" x-model="r.descripcion" maxlength="255"
                           placeholder="¿Qué se compró?" class="w-full px-2 py-1.5 rounded-lg border border-zinc-300 text-sm">
                    <input type="hidden" name="item_insumo[]" value="0">
                    <input type="hidden" name="item_codigo[]" :value="r.codigo">
                  <?php else: ?>
                  <select name="item_insumo[]" x-model.number="r.insumo_id" @change="onInsumo(r)"
                          class="w-full px-2 py-1.5 rounded-lg border border-zinc-300 text-sm">
                    <option value="0">— Elige el insumo —</option>
                    <?php
                      $porGrupo = [];
                      foreach ($insumos as $ins) {
                          $gk = $ins['subcategoria_id'] ? (string)$ins['subcategoria_id'] : 'otros';
                          $porGrupo[$gk][] = $ins;
                      }
                      $nombreSub = [];
                      foreach (db_all("SELECT id,nombre FROM subcategorias_gasto") as $sc) $nombreSub[(int)$sc['id']] = $sc['nombre'];
                      foreach ($porGrupo as $gk => $lista):
                        $etiqueta = $gk === 'otros' ? 'Varios' : ($nombreSub[(int)$gk] ?? 'Varios');
                    ?>
                      <optgroup label="<?= e($etiqueta) ?>">
                        <?php foreach ($lista as $ins): ?>
                          <option value="<?= (int)$ins['id'] ?>"><?= e($ins['nombre']) ?></option>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endforeach; ?>
                    <option value="-1">Otro (no está en la lista)</option>
                  </select>
                  <!-- Se ve el texto cuando eligen "Otro" y también cuando el
                       renglón vino de una factura: si no, el artículo leído del
                       XML o del PDF quedaría escondido y nadie podría revisarlo. -->
                  <template x-if="r.insumo_id === -1 || (r.insumo_id === 0 && r.descripcion)">
                    <div class="mt-1.5">
                      <input type="text" name="item_descripcion[]" x-model="r.descripcion" maxlength="255"
                             placeholder="¿Qué se compró? Ej. Manguera de 1/2 pulgada"
                             class="w-full px-2 py-1.5 rounded-lg border text-sm"
                             style="border-color:<?= EST_WARN ?>66;background:<?= EST_WARN ?>0a">
                      <p class="text-[10px] mt-0.5" style="color:<?= EST_WARN ?>"
                         x-text="r.insumo_id === -1
                           ? 'Queda pendiente de que un administrador lo agregue al catálogo.'
                           : 'Así vino en la factura. Si está en el catálogo, elígelo arriba.'"></p>
                    </div>
                  </template>
                  <template x-if="!(r.insumo_id === -1 || (r.insumo_id === 0 && r.descripcion))">
                    <input type="hidden" name="item_descripcion[]" :value="r.descripcion">
                  </template>
                  <input type="hidden" name="item_codigo[]" :value="r.codigo">
                  <p x-show="r.insumo_id > 0 && precioAnterior(r)" x-cloak class="text-[10px] text-zinc-400 mt-0.5">
                    Última compra a <span x-text="fmtm(precioAnterior(r))"></span></p>
                  <?php endif; ?>
                </td>
                <td class="py-1.5 pr-1.5"><input type="number" step="0.001" min="0" name="item_cantidad[]" x-model.number="r.cantidad"
                    class="w-full px-2 py-1.5 rounded-lg border border-zinc-300 text-sm text-right tabular-nums"></td>
                <td class="py-1.5 pr-1.5">
                  <select name="item_unidad[]" x-model.number="r.unidad_id" class="w-full px-1.5 py-1.5 rounded-lg border border-zinc-300 text-xs">
                    <option value="0">—</option>
                    <?php foreach ($unidades as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['clave']) ?></option><?php endforeach; ?>
                  </select></td>
                <td class="py-1.5 pr-1.5"><input type="number" step="0.0001" min="0" name="item_precio[]" x-model.number="r.precio_unitario"
                    class="w-full px-2 py-1.5 rounded-lg border border-zinc-300 text-sm text-right tabular-nums"></td>
                <td class="py-1.5 pr-1.5 text-right text-sm font-semibold text-zinc-800 tabular-nums pt-3" x-text="fmtm(importe(r))"></td>
                <td class="py-1.5 text-right pt-2">
                  <button type="button" @click="quitar(i)" :disabled="items.length===1"
                          class="p-1 text-zinc-300 hover:text-red-600 disabled:opacity-30 disabled:hover:text-zinc-300">
                    <i data-lucide="x" class="w-4 h-4"></i></button></td>
              </tr>
            </template>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4" class="pt-3 text-right text-xs font-bold uppercase tracking-wider text-zinc-500">Total</td>
              <td class="pt-3 text-right font-display font-extrabold text-lg text-marca-700 tabular-nums" x-text="fmtm(total)"></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <button type="button" @click="agregar()" class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-marca-200 text-marca-700 hover:bg-marca-50 text-xs font-semibold">
        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Agregar renglón</button>
    </div>

    <!-- ============ COMPROBANTE ============ -->
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5 space-y-4">
      <h3 class="font-display font-bold text-sm text-zinc-800">Comprobante</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Proveedor</label>
          <select name="proveedor_id" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <option value="">— Del catálogo (opcional) —</option>
            <?php foreach ($provs as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= (string)$form['proveedor_id']===(string)$pr['id']?'selected':'' ?>><?= e($pr['nombre']) ?></option><?php endforeach; ?>
          </select>
          <input type="text" name="proveedor_texto" value="<?= e((string)$form['proveedor_texto']) ?>" placeholder="…o escribe otro proveedor"
                 class="w-full mt-2 px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Factura / ticket</label>
          <input type="text" name="numero_factura" value="<?= e((string)$form['numero_factura']) ?>" maxlength="60"
                 class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-zinc-500 mb-1">Comprobante</label>

        <!-- Sin factura XML el comprobante es obligatorio: se avisa antes de intentar guardar -->
        <?php $__exento = $edit && !empty($g['recurrente_id']); ?>
        <?php if ($__exento): ?>
          <div class="mb-2 rounded-lg px-3 py-2 text-xs flex items-start gap-2 bg-zinc-50 text-zinc-500">
            <i data-lucide="repeat" class="w-4 h-4 mt-0.5 flex-shrink-0"></i>
            <div>Este gasto lo generó un gasto fijo, por eso el comprobante es opcional.
                 Si consigues la nota o el recibo, súbelo aquí.</div>
          </div>
        <?php endif; ?>
        <template x-if="!tieneXml && !tienePdf && !archivoElegido && !<?= (($edit && $form['archivo_url']) || $__exento) ? 'true' : 'false' ?>">
          <div class="mb-2 rounded-lg px-3 py-2 text-xs flex items-start gap-2"
               style="background:<?= EST_WARN ?>12;color:<?= EST_WARN ?>">
            <i data-lucide="camera" class="w-4 h-4 mt-0.5 flex-shrink-0"></i>
            <div><b>Falta el comprobante.</b> Esta compra no trae factura, así que necesitas la
                 foto de la nota, el recibo o el ticket para poder guardar.</div>
          </div>
        </template>
        <template x-if="tieneXml || tienePdf">
          <div class="mb-2 rounded-lg px-3 py-2 text-xs flex items-start gap-2"
               style="background:<?= EST_OK ?>12;color:<?= EST_OK ?>">
            <i data-lucide="check" class="w-4 h-4 mt-0.5 flex-shrink-0"></i>
            <div x-text="tienePdf && !tieneXml
                 ? 'El PDF de la factura ya quedó como comprobante. La foto es opcional.'
                 : 'La factura XML es el comprobante. La foto es opcional.'"></div>
          </div>
        </template>

        <div class="flex flex-wrap gap-2">
          <!-- En celular abre la camara directo; en computadora abre el explorador -->
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold cursor-pointer shadow-sm">
            <i data-lucide="camera" class="w-4 h-4"></i> Tomar foto
            <input type="file" name="archivo" accept="image/*" capture="environment"
                   @change="onArchivo($event)" class="hidden">
          </label>
          <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-zinc-200 text-zinc-700 hover:bg-zinc-50 text-sm font-semibold cursor-pointer">
            <i data-lucide="paperclip" class="w-4 h-4"></i> Subir archivo
            <input type="file" name="archivo_subido" accept=".pdf,image/*"
                   @change="onArchivo($event)" class="hidden">
          </label>
          <span x-show="archivoElegido" x-cloak class="inline-flex items-center gap-1.5 text-xs text-zinc-600 self-center">
            <i data-lucide="file-check" class="w-4 h-4" style="color:<?= EST_OK ?>"></i>
            <span x-text="archivoNombre"></span>
          </span>
        </div>
        <?php if ($edit && $form['archivo_url']): ?>
          <div class="mt-2 flex items-center gap-3 text-sm">
            <a href="<?= url_archivo($form['archivo_url']) ?>" target="_blank" class="inline-flex items-center gap-1 text-marca-700 hover:underline">
              <i data-lucide="paperclip" class="w-4 h-4"></i> Ver adjunto actual</a>
            <label class="inline-flex items-center gap-1.5 text-zinc-500"><input type="checkbox" name="quitar_archivo" value="1" class="rounded"> Quitar</label>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ============ PAGO ============ -->
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5 space-y-4">
      <h3 class="font-display font-bold text-sm text-zinc-800">Pago</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Forma de pago</label>
          <select name="forma_pago_id" x-model="forma_pago_id" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <option value="">— Selecciona —</option>
            <?php foreach ($formasPago as $fp): ?>
              <option value="<?= (int)$fp['id'] ?>" data-ref="<?= (int)$fp['requiere_referencia'] ?>"><?= e($fp['nombre']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Referencia</label>
          <input type="text" name="referencia_pago" value="<?= e((string)$form['referencia_pago']) ?>" maxlength="60"
                 placeholder="Folio, cheque…" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Estatus</label>
          <select name="estatus_pago" x-model="estatus_pago" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <?php foreach (['pagado','pendiente','parcial','cancelado'] as $es): ?>
              <option value="<?= $es ?>"><?= e(texto_estatus($es)) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Fecha de pago</label>
          <input type="date" name="fecha_pago" x-model="fecha_pago"
                 class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
      </div>
    </div>

    <!-- ============ RESPONSABLE Y REPARTO ============ -->
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5 space-y-4">
      <h3 class="font-display font-bold text-sm text-zinc-800">Responsable y notas</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Responsable</label>
          <select name="responsable_id" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <option value="">— Del sistema (opcional) —</option>
            <?php foreach ($usuarios as $us): ?><option value="<?= (int)$us['id'] ?>" <?= (string)$form['responsable_id']===(string)$us['id']?'selected':'' ?>><?= e($us['nombre_completo']) ?></option><?php endforeach; ?>
          </select>
          <input type="text" name="responsable_texto" value="<?= e((string)$form['responsable_texto']) ?>" placeholder="…o escribe quién autorizó"
                 class="w-full mt-2 px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Observaciones</label>
          <textarea name="notas" rows="3" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"><?= e((string)$form['notas']) ?></textarea></div>
      </div>

      <div class="pt-3 border-t border-zinc-100">
        <div class="flex items-center justify-between mb-1">
          <label class="block text-xs font-semibold text-zinc-500">Reparto entre áreas</label>
          <button type="button" @click="toggleReparto()" class="text-xs font-semibold text-marca-700 hover:underline"
                  x-text="repartoOn ? 'Quitar reparto' : '+ Repartir entre áreas'"></button>
        </div>
        <p x-show="!repartoOn" class="text-[11px] text-zinc-400">Sin reparto: el 100% se atribuye al área seleccionada arriba.</p>
        <div x-show="repartoOn" x-cloak class="rounded-xl border border-zinc-200 p-3 space-y-2 mt-2">
          <template x-for="(r,i) in reparto" :key="i">
            <div class="flex items-center gap-2">
              <select name="reparto_area[]" x-model="r.area" class="flex-1 px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm">
                <option value="">— Área —</option>
                <?php foreach ($areas as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['nombre']) ?></option><?php endforeach; ?>
              </select>
              <div class="relative"><input type="number" step="0.01" min="0" max="100" name="reparto_pct[]" x-model.number="r.pct"
                   class="w-20 pr-5 pl-2 py-1.5 rounded-lg border border-zinc-300 text-sm text-right tabular-nums">
                <span class="absolute right-2 top-1.5 text-zinc-400 text-xs">%</span></div>
              <span class="text-xs text-zinc-500 w-24 text-right tabular-nums" x-text="fmtm(total*(parseFloat(r.pct)||0)/100)"></span>
              <button type="button" @click="reparto.splice(i,1)" class="p-1 text-zinc-300 hover:text-red-600"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
          </template>
          <div class="flex items-center justify-between pt-1 border-t border-zinc-100">
            <button type="button" @click="reparto.push({area:'',pct:0}); iconos()" class="text-xs font-semibold text-marca-700 hover:underline">+ Agregar área</button>
            <span class="text-xs font-semibold" :class="Math.abs(sumaPct()-100)<0.05 ? 'text-emerald-600' : 'text-orange-600'">
              Suma: <span x-text="sumaPct().toFixed(2)"></span>%</span>
          </div>
        </div>
      </div>
    </div>

    <div class="flex items-center justify-between gap-2 pb-6">
      <div class="text-sm text-zinc-500">Total del gasto:
        <span class="font-display font-extrabold text-xl text-marca-700 tabular-nums" x-text="fmtm(total)"></span></div>
      <div class="flex gap-2">
        <a href="<?= url('gastos.php') ?>" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</a>
        <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-marca-600 hover:bg-marca-700 shadow-sm">
          <i data-lucide="save" class="w-4 h-4"></i> <?= $edit ? 'Guardar cambios' : 'Registrar gasto' ?></button>
      </div>
    </div>
  </form>
</div>

<script>
const SUBCATS = <?= json_encode($subsPorCat, JSON_UNESCAPED_UNICODE) ?>;
const INSUMOS = <?= json_encode(array_map(fn($i)=>[
    'id'=>(int)$i['id'], 'nombre'=>$i['nombre'], 'codigo'=>(string)($i['codigo']??''),
    'unidad_id'=>(int)($i['unidad_id']??0), 'subcategoria_id'=>(int)($i['subcategoria_id']??0),
    'ultimo'=>$i['ultimo_precio']!==null ? (float)$i['ultimo_precio'] : null,
], $insumos), JSON_UNESCAPED_UNICODE) ?>;
const UNIDADES = <?= json_encode(array_map(fn($u)=>['id'=>(int)$u['id'],'clave'=>$u['clave']], $unidades), JSON_UNESCAPED_UNICODE) ?>;

function gastoForm(){
  return {
    fecha:            <?= json_encode((string)$form['fecha']) ?>,
    area_id:          <?= json_encode((string)$form['area_id']) ?>,
    tipo:             <?= json_encode((string)$form['tipo']) ?>,
    categoria_id:     <?= json_encode((string)$form['categoria_id']) ?>,
    subcategoria_id:  <?= json_encode((string)$form['subcategoria_id']) ?>,
    concepto:         <?= json_encode((string)$form['concepto']) ?>,
    forma_pago_id:    <?= json_encode((string)$form['forma_pago_id']) ?>,
    estatus_pago:     <?= json_encode((string)$form['estatus_pago']) ?>,
    fecha_pago:       <?= json_encode((string)$form['fecha_pago']) ?>,
    subtotal:         <?= json_encode((string)$form['subtotal']) ?>,
    iva:              <?= json_encode((string)$form['iva']) ?>,
    uuid:             <?= json_encode((string)$form['uuid']) ?>,
    rfc_emisor:       <?= json_encode((string)$form['rfc_emisor']) ?>,
    cfdi_xml_url:     <?= json_encode((string)($form['cfdi_xml_url'] ?? '')) ?>,
    items:            <?= json_encode($itemsInit, JSON_UNESCAPED_UNICODE) ?>,
    reparto:          <?= json_encode($repartoInit ?: [], JSON_UNESCAPED_UNICODE) ?>,
    repartoOn:        <?= !empty($repartoInit) ? 'true' : 'false' ?>,
    archivo_url_ajax: <?= json_encode((string)$pdfAjax) ?>,
    cfdiDup:'', cfdiErr:'', cfdiTotal:'', cfdiProv:'',
    compOk:false, compConf:false, compCargando:false, compAviso:'', compTitulo:'', compOrigen:'',
    compNombre:'', compConceptos:0,
    archivoElegido:false, archivoNombre:'',

    get tieneXml(){ return (this.uuid||'') !== '' || (this.cfdi_xml_url||'') !== ''; },
    get tienePdf(){ return (this.archivo_url_ajax||'') !== ''; },
    onArchivo(ev){
      const f = ev.target.files && ev.target.files[0];
      // Al elegir en un botón se limpia el otro, para que no se manden los dos
      document.querySelectorAll('input[type=file][name^=archivo]').forEach(el => {
        if (el !== ev.target) el.value = '';
      });
      this.archivoElegido = !!f;
      this.archivoNombre  = f ? f.name : '';
      this.iconos();
    },

    get subcategorias(){ return SUBCATS[this.categoria_id] || []; },
    get total(){ return this.items.reduce((a,r)=>a+this.importe(r), 0); },

    insumoDe(r){ return INSUMOS.find(x=>x.id === Number(r.insumo_id)) || null; },
    precioAnterior(r){ const i=this.insumoDe(r); return i && i.ultimo ? i.ultimo : 0; },
    onInsumo(r){
      const i = this.insumoDe(r);
      if (i) {
        r.descripcion = i.nombre;
        r.codigo = i.codigo;
        if (i.unidad_id) r.unidad_id = i.unidad_id;
        // Si la categoria del gasto aun no se elige, la sugiere el insumo
        if (!this.subcategoria_id && i.subcategoria_id) {
          for (const [cid, lista] of Object.entries(SUBCATS)) {
            if (lista.some(s => Number(s.id) === i.subcategoria_id)) {
              if (!this.categoria_id) this.categoria_id = String(cid);
              this.subcategoria_id = String(i.subcategoria_id);
              break;
            }
          }
        }
      } else if (Number(r.insumo_id) === -1) {
        r.descripcion = ''; r.codigo = '';
      }
      this.iconos();
    },
    importe(r){
      const c = parseFloat(r.cantidad); const p = parseFloat(r.precio_unitario);
      return Math.round(((c>0?c:1) * (p||0)) * 100) / 100;
    },
    fmtm(v){ v=parseFloat(v)||0; return '$'+v.toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2}); },
    iconos(){ this.$nextTick(()=>window.lucide&&lucide.createIcons()); },
    agregar(){ this.items.push({insumo_id:0,codigo:'',descripcion:'',cantidad:1,unidad_id:0,precio_unitario:0}); this.iconos(); },
    quitar(i){ if(this.items.length>1){ this.items.splice(i,1); this.iconos(); } },
    sumaPct(){ return this.reparto.reduce((a,r)=>a+(parseFloat(r.pct)||0),0); },
    toggleReparto(){
      this.repartoOn=!this.repartoOn;
      if(this.repartoOn){ if(!this.reparto.length) this.reparto=[{area:this.area_id||'',pct:100}]; }
      else { this.reparto=[]; }
      this.iconos();
    },

    // Un solo botón para XML y PDF: el servidor decide qué llegó.
    async subirComprobante(ev){
      const f=ev.target.files[0]; if(!f) return;
      this.cfdiErr=''; this.compOk=false; this.compConf=false; this.cfdiDup='';
      this.cfdiProv=''; this.compAviso=''; this.compTitulo=''; this.compOrigen=''; this.compConceptos=0;
      this.compNombre=f.name; this.compCargando=true;
      const fd=new FormData(); fd.append('_csrf', <?= json_encode(csrf_token()) ?>); fd.append('comprobante', f);
      try{
        const r=await fetch('<?= url('api/comprobante_parse.php') ?>', {method:'POST', body:fd});
        const d=await r.json();
        if(!d.ok){ this.cfdiErr=d.error||'No se pudo leer el archivo.'; this.compNombre=''; this.compCargando=false; return; }
        this.compOrigen=d.origen||''; this.compConf=!!d.confiable;
        this.compAviso=d.aviso||''; this.compTitulo=d.titulo||'';
        this.subtotal=d.subtotal||''; this.iva=d.iva||''; this.uuid=d.uuid||'';
        this.rfc_emisor=d.rfc||''; this.cfdiTotal=d.total||'';
        if(d.xml_url)     this.cfdi_xml_url=d.xml_url;
        if(d.archivo_url) this.archivo_url_ajax=d.archivo_url;   // el PDF queda de comprobante
        // Una fecha fuera de rango no pisa la del formulario: un número mal
        // leído del PDF mandaría el gasto a otro año y desaparecería del tablero
        if(d.fecha && !d.fecha_rara){ this.fecha=d.fecha; }
        const set=(n,v)=>{ const el=document.querySelector('[name="'+n+'"]'); if(el && v!=null && v!==''){ el.value=v; } };
        set('numero_factura', d.folio);
        if(d.proveedor_id){ set('proveedor_id', d.proveedor_id);
          this.cfdiProv=(d.proveedor_nuevo?'Proveedor dado de alta: ':'Proveedor detectado: ')+(d.nombre||d.rfc||'');
        } else if(d.nombre){ set('proveedor_texto', d.nombre); }

        // Los conceptos del CFDI se vuelven los renglones
        if(Array.isArray(d.conceptos) && d.conceptos.length){
          this.items = d.conceptos.map(c=>{
            const u = UNIDADES.find(x=>x.clave===(c.unidad||'').toUpperCase());
            return { insumo_id:0, codigo:c.codigo||'', descripcion:c.descripcion||'',
                     cantidad:parseFloat(c.cantidad)||1, unidad_id:u?u.id:0,
                     precio_unitario:parseFloat(c.precio_unitario)||0 };
          });
          if(!this.concepto && this.items.length) this.concepto=this.items[0].descripcion.substring(0,200);
          this.compConceptos=this.items.length;
        }
        this.compOk=true; if(d.duplicado){ this.cfdiDup=d.duplicado; }
        this.iconos();
      }catch(e){ this.cfdiErr='Error al procesar el archivo.'; }
      this.compCargando=false;
    }
  };
}
</script>
<?php require __DIR__ . '/config/footer.php'; ?>
