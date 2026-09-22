<?php
/** gasto_ver.php - Detalle de un gasto con sus renglones */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/tema.php';
require_once __DIR__ . '/config/gastos_helpers.php';
require_once __DIR__ . '/config/controles_helpers.php';
requerir_login();

$id = (int) input('id');
$g  = db_one("SELECT g.*, a.nombre AS area, a.codigo AS area_cod,
                     c.nombre AS cat, c.color AS catcolor, s.nombre AS subcat,
                     pr.nombre AS prov, pr.rfc AS prov_rfc, f.nombre AS forma_pago,
                     u.nombre_completo AS registrado, r.nombre_completo AS responsable
                FROM gastos g
                INNER JOIN areas a            ON g.area_id = a.id
                INNER JOIN categorias_gasto c ON g.categoria_id = c.id
                LEFT JOIN subcategorias_gasto s ON g.subcategoria_id = s.id
                LEFT JOIN proveedores pr      ON g.proveedor_id = pr.id
                LEFT JOIN formas_pago f       ON g.forma_pago_id = f.id
                LEFT JOIN usuarios u          ON g.registrado_por = u.id
                LEFT JOIN usuarios r          ON g.responsable_id = r.id
               WHERE g.id = :id", ['id'=>$id]);
if (!$g) { flash_set('error','Gasto no encontrado.'); header('Location: '.url('gastos.php')); exit; }

$puede_editar = (tiene_permiso('administrar') || tiene_permiso('crear_solicitud'))
                && !fecha_en_mes_cerrado($g['fecha']);
$mes_cerrado_flag = fecha_en_mes_cerrado($g['fecha']);

// Borrado
if (es_post() && input('accion') === 'eliminar') {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada.'); }
    elseif (!tiene_permiso('administrar')) { flash_set('error','Solo un administrador puede eliminar gastos.'); }
    elseif ($mes_cerrado_flag) { flash_set('error','Ese mes está cerrado; no se puede eliminar.'); }
    else {
        if (!empty($g['archivo_url'])) imagen_borrar_archivo($g['archivo_url']);
        db_exec("DELETE FROM gastos WHERE id=:id", ['id'=>$id]);   // items y reparto caen por CASCADE
        registrar_auditoria('eliminar','gastos',$id,"Eliminó gasto {$g['folio']}");
        flash_set('success','Gasto eliminado.');
        header('Location: '.url('gastos.php')); exit;
    }
    header('Location: '.url('gasto_ver.php?id='.$id)); exit;
}

$items      = gasto_items($id);
$hayInsumos = modulo_insumos_instalado();
$reparto = db_all("SELECT d.*, a.nombre AS area FROM gasto_distribucion d
                   INNER JOIN areas a ON d.area_id = a.id WHERE d.gasto_id=:g ORDER BY d.id", ['g'=>$id]);
$sumaItems = 0.0; foreach ($items as $it) $sumaItems += (float)$it['importe'];
$descuadre = abs($sumaItems - (float)$g['monto']) > 0.01;

$titulo_pagina = 'Gasto ' . $g['folio'];
$pagina_activa = 'gastos';
require __DIR__ . '/config/header.php';
?>
<div class="max-w-4xl mx-auto">

  <a href="<?= url('gastos.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-2">
    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Gastos</a>

  <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
    <div>
      <div class="flex items-center gap-2.5 flex-wrap">
        <h2 class="font-display text-2xl font-extrabold text-zinc-900"><?= e($g['concepto']) ?></h2>
        <?= badge_estatus_pago($g['estatus_pago']) ?>
        <?= badge_tipo_gasto($g['tipo']) ?>
      </div>
      <div class="text-xs text-zinc-500 mt-1">
        <span class="font-mono text-marca-700"><?= e($g['folio']) ?></span> ·
        <?= fmt_fecha($g['fecha'], false) ?> · <?= e($g['area']) ?>
      </div>
    </div>
    <div class="flex items-center gap-2 flex-shrink-0">
      <?php if ($puede_editar): ?>
        <a href="<?= url('gasto_form.php?id='.$id) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-zinc-200 text-zinc-700 hover:bg-zinc-50 text-sm font-semibold">
          <i data-lucide="pencil" class="w-4 h-4"></i> Editar</a>
      <?php endif; ?>
      <?php if (tiene_permiso('administrar') && !$mes_cerrado_flag): ?>
        <form method="post" onsubmit="return confirm('¿Eliminar este gasto y sus renglones? No se puede deshacer.')">
          <?= csrf_input() ?><input type="hidden" name="accion" value="eliminar">
          <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-red-200 text-red-700 hover:bg-red-50 text-sm font-semibold">
            <i data-lucide="trash-2" class="w-4 h-4"></i> Eliminar</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($mes_cerrado_flag): ?>
    <div class="mb-4 border-l-4 border-orange-300 bg-orange-50 text-orange-800 rounded-lg px-4 py-3 text-sm flex items-start gap-2">
      <i data-lucide="lock" class="w-4 h-4 mt-0.5 flex-shrink-0"></i>
      <div>Este gasto pertenece a un mes ya cerrado, por eso no se puede editar ni eliminar.</div>
    </div>
  <?php endif; ?>

  <?php if ($descuadre): ?>
    <div class="mb-4 border-l-4 border-red-300 bg-red-50 text-red-800 rounded-lg px-4 py-3 text-sm flex items-start gap-2">
      <i data-lucide="alert-triangle" class="w-4 h-4 mt-0.5 flex-shrink-0"></i>
      <div>El total del gasto ($<?= number_format((float)$g['monto'],2) ?>) no coincide con la suma de sus renglones
           ($<?= number_format($sumaItems,2) ?>). Vuelve a guardarlo para recalcularlo.</div>
    </div>
  <?php endif; ?>

  <!-- Resumen -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <div class="bg-white rounded-2xl border border-marca-200 shadow-sm p-4"
         style="background:linear-gradient(150deg, rgba(124,58,237,.06), #fff)">
      <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Importe</div>
      <div class="font-display text-2xl font-extrabold text-marca-700 tabular-nums mt-1">$<?= number_format((float)$g['monto'],2) ?></div>
      <?php if ($g['iva'] !== null && (float)$g['iva'] > 0): ?>
        <div class="text-[11px] text-zinc-400 mt-0.5">IVA $<?= number_format((float)$g['iva'],2) ?></div>
      <?php endif; ?>
    </div>
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
      <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Categoría</div>
      <div class="flex items-center gap-1.5 mt-1.5">
        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= e($g['catcolor']) ?>"></span>
        <span class="text-sm font-semibold text-zinc-800"><?= e($g['cat']) ?></span>
      </div>
      <?php if ($g['subcat']): ?><div class="text-xs text-zinc-500 mt-0.5 pl-4"><?= e($g['subcat']) ?></div><?php endif; ?>
    </div>
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
      <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Proveedor</div>
      <div class="text-sm font-semibold text-zinc-800 mt-1.5"><?= e($g['prov'] ?: ($g['proveedor_texto'] ?: '—')) ?></div>
      <?php if ($g['numero_factura']): ?><div class="text-xs text-zinc-500 mt-0.5">Factura <?= e($g['numero_factura']) ?></div><?php endif; ?>
    </div>
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
      <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Pago</div>
      <div class="text-sm font-semibold text-zinc-800 mt-1.5"><?= e($g['forma_pago'] ?: '—') ?></div>
      <?php if ($g['fecha_pago']): ?><div class="text-xs text-zinc-500 mt-0.5"><?= fmt_fecha($g['fecha_pago'], false) ?></div><?php endif; ?>
      <?php if ($g['referencia_pago']): ?><div class="text-xs text-zinc-400 mt-0.5 font-mono"><?= e($g['referencia_pago']) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- Renglones -->
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-zinc-200 flex items-center justify-between">
      <h3 class="font-display font-bold text-sm text-zinc-800">Detalle</h3>
      <span class="text-xs text-zinc-400"><?= count($items) ?> <?= count($items)===1?'renglón':'renglones' ?></span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-[10px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
          <th class="px-4 py-2.5 font-semibold w-10">#</th>
          <th class="px-4 py-2.5 font-semibold">Código</th>
          <th class="px-4 py-2.5 font-semibold">Descripción</th>
          <th class="px-4 py-2.5 font-semibold text-right">Cantidad</th>
          <th class="px-4 py-2.5 font-semibold">Unidad</th>
          <th class="px-4 py-2.5 font-semibold text-right">P. unitario</th>
          <th class="px-4 py-2.5 font-semibold text-right">Importe</th>
        </tr></thead>
        <tbody class="divide-y divide-zinc-100">
        <?php if (!$items): ?>
          <tr><td colspan="7" class="px-4 py-8 text-center text-zinc-400">Este gasto no tiene renglones capturados.</td></tr>
        <?php else: foreach ($items as $i => $it): ?>
          <tr>
            <td class="px-4 py-2.5 text-zinc-300 tabular-nums"><?= $i+1 ?></td>
            <td class="px-4 py-2.5 font-mono text-xs text-zinc-500"><?= e($it['codigo'] ?: '—') ?></td>
            <td class="px-4 py-2.5 text-zinc-800">
              <?= e($it['descripcion']) ?>
              <?php if ($hayInsumos): ?>
                <?php if (!empty($it['insumo_nombre'])): ?>
                  <?php if (mb_strtolower(trim($it['insumo_nombre'])) === mb_strtolower(trim((string)$it['descripcion']))): ?>
                    <?php /* Mismo texto: con el icono basta, repetirlo estorba */ ?>
                    <i data-lucide="package" class="w-3.5 h-3.5 inline-block align-[-2px] text-marca-500"
                       title="Insumo del catálogo"></i>
                  <?php else: ?>
                    <div class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-medium px-1.5 py-0.5 rounded-full bg-marca-50 text-marca-700">
                      <i data-lucide="package" class="w-3 h-3"></i><?= e($it['insumo_nombre']) ?>
                    </div>
                  <?php endif; ?>
                <?php elseif (empty($it['no_es_insumo'])): ?>
                  <div class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-medium px-1.5 py-0.5 rounded-full"
                       style="background:<?= EST_WARN ?>14;color:<?= EST_WARN ?>" title="El admin lo dará de alta en el catálogo">
                    <i data-lucide="help-circle" class="w-3 h-3"></i>Sin insumo del catálogo
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="px-4 py-2.5 text-right text-zinc-600 tabular-nums"><?= rtrim(rtrim(number_format((float)$it['cantidad'],3),'0'),'.') ?></td>
            <td class="px-4 py-2.5 text-zinc-500 text-xs"><?= e($it['unidad_clave'] ?: '—') ?></td>
            <td class="px-4 py-2.5 text-right text-zinc-600 tabular-nums">$<?= number_format((float)$it['precio_unitario'],2) ?></td>
            <td class="px-4 py-2.5 text-right font-semibold text-zinc-800 tabular-nums">$<?= number_format((float)$it['importe'],2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <?php if ($items): ?>
        <tfoot><tr class="border-t-2 border-zinc-200 bg-zinc-50">
          <td colspan="6" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-zinc-500">Total</td>
          <td class="px-4 py-3 text-right font-display font-extrabold text-marca-700 tabular-nums">$<?= number_format($sumaItems,2) ?></td>
        </tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <!-- Reparto -->
    <?php if ($reparto): ?>
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
      <h3 class="font-display font-bold text-sm text-zinc-800 mb-3">Reparto entre áreas</h3>
      <div class="space-y-2">
        <?php foreach ($reparto as $d): ?>
        <div class="flex items-center gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between text-sm">
              <span class="text-zinc-700 truncate"><?= e($d['area']) ?></span>
              <span class="text-zinc-800 font-semibold tabular-nums ml-2">$<?= number_format((float)$d['monto'],2) ?></span>
            </div>
            <div class="mt-1 h-1.5 bg-zinc-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full" style="width:<?= min(100,(float)$d['porcentaje']) ?>%;background:<?= tono(500) ?>"></div>
            </div>
          </div>
          <span class="text-xs text-zinc-400 tabular-nums w-12 text-right"><?= number_format((float)$d['porcentaje'],1) ?>%</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Ficha -->
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
      <h3 class="font-display font-bold text-sm text-zinc-800 mb-3">Información</h3>
      <dl class="space-y-2 text-sm">
        <div class="flex justify-between gap-3"><dt class="text-zinc-500">Área</dt><dd class="text-zinc-800 font-medium text-right"><?= e($g['area']) ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-zinc-500">Responsable</dt>
          <dd class="text-zinc-800 font-medium text-right"><?= e($g['responsable'] ?: ($g['responsable_texto'] ?: '—')) ?></dd></div>
        <?php if ($g['rfc_emisor']): ?>
        <div class="flex justify-between gap-3"><dt class="text-zinc-500">RFC emisor</dt><dd class="text-zinc-800 font-mono text-xs text-right"><?= e($g['rfc_emisor']) ?></dd></div>
        <?php endif; ?>
        <?php if ($g['uuid']): ?>
        <div class="flex justify-between gap-3"><dt class="text-zinc-500">UUID CFDI</dt><dd class="text-zinc-500 font-mono text-[10px] text-right break-all"><?= e($g['uuid']) ?></dd></div>
        <?php endif; ?>
        <?php if ($g['subtotal'] !== null): ?>
        <div class="flex justify-between gap-3"><dt class="text-zinc-500">Subtotal</dt><dd class="text-zinc-800 tabular-nums text-right">$<?= number_format((float)$g['subtotal'],2) ?></dd></div>
        <?php endif; ?>
        <div class="flex justify-between gap-3"><dt class="text-zinc-500">Registró</dt>
          <dd class="text-zinc-800 font-medium text-right"><?= e($g['registrado'] ?: '—') ?></dd></div>
        <div class="flex justify-between gap-3"><dt class="text-zinc-500">Capturado</dt>
          <dd class="text-zinc-500 text-right text-xs"><?= fmt_fecha($g['creado_en']) ?></dd></div>
      </dl>

      <?php if ($g['notas']): ?>
        <div class="mt-4 pt-3 border-t border-zinc-100">
          <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400 mb-1">Observaciones</div>
          <p class="text-sm text-zinc-600 whitespace-pre-line"><?= e($g['notas']) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($g['archivo_url'] || $g['cfdi_xml_url']): ?>
        <div class="mt-4 pt-3 border-t border-zinc-100 flex flex-wrap gap-2">
          <?php if ($g['archivo_url']): ?>
            <a href="<?= url_archivo($g['archivo_url']) ?>" target="_blank"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-marca-50 text-marca-700 hover:bg-marca-100 text-xs font-semibold">
              <i data-lucide="paperclip" class="w-3.5 h-3.5"></i> Comprobante</a>
          <?php endif; ?>
          <?php if ($g['cfdi_xml_url']): ?>
            <a href="<?= url_archivo($g['cfdi_xml_url']) ?>" target="_blank"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-zinc-100 text-zinc-700 hover:bg-zinc-200 text-xs font-semibold">
              <i data-lucide="file-code" class="w-3.5 h-3.5"></i> XML del CFDI</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/config/footer.php'; ?>
