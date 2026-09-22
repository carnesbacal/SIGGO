<?php
/** reportes/reporte_pendientes.php - Lo que se debe, por antiguedad */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/tema.php';
require_once __DIR__ . '/../config/gastos_helpers.php';
require_once __DIR__ . '/../config/reportes_pdf.php';
requerir_login();

$rows = db_all("SELECT g.id, g.folio, g.fecha, g.concepto, g.monto, g.estatus_pago, g.fecha_pago,
                       a.nombre AS area, c.nombre AS cat,
                       COALESCE(p.nombre, g.proveedor_texto) AS prov,
                       f.nombre AS forma_pago,
                       DATEDIFF(CURDATE(), g.fecha) AS dias
                  FROM gastos g
                  INNER JOIN areas a ON a.id = g.area_id
                  INNER JOIN categorias_gasto c ON c.id = g.categoria_id
                  LEFT JOIN proveedores p ON p.id = g.proveedor_id
                  LEFT JOIN formas_pago f ON f.id = g.forma_pago_id
                 WHERE g.estatus_pago IN ('pendiente','parcial')
                 ORDER BY g.fecha ASC");
$total = 0.0; foreach ($rows as $r) $total += (float)$r['monto'];

// Antiguedad
$tramos = ['0-30'=>0.0, '31-60'=>0.0, '61-90'=>0.0, '90+'=>0.0];
foreach ($rows as $r) {
    $d = (int)$r['dias'];
    if ($d <= 30)      $tramos['0-30']  += (float)$r['monto'];
    elseif ($d <= 60)  $tramos['31-60'] += (float)$r['monto'];
    elseif ($d <= 90)  $tramos['61-90'] += (float)$r['monto'];
    else               $tramos['90+']   += (float)$r['monto'];
}

if (input('export') === 'xlsx') {
    require_once __DIR__ . '/../config/xlsx_writer.php';
    $x = new XlsxWriter(); $x->addSheet('Pendientes de pago'); $x->setPageSetup(1,0,'landscape');
    $x->addHeaderRow(['Folio','Fecha','Días','Concepto','Proveedor','Área','Categoría','Estatus','Importe'], true);
    foreach ($rows as $r) {
        $x->addRow([(string)$r['folio'], (string)$r['fecha'], (int)$r['dias'], (string)$r['concepto'],
                    (string)($r['prov'] ?? ''), (string)$r['area'], (string)$r['cat'],
                    texto_estatus($r['estatus_pago']), ['v'=>(float)$r['monto'],'s'=>3]]);
    }
    $x->addRow([['v'=>'TOTAL','s'=>1],'','','','','','','',['v'=>$total,'s'=>3]]);
    $x->download('pendientes_de_pago.xlsx');
}

if (input('export') === 'pdf') {
    $pdf = pdf_reporte('Pendientes de pago', 'Al ' . date('d/m/Y'));
    $pdf->kpis([
        ['Total por pagar', '$' . number_format($total, 2), count($rows) . ' gastos'],
        ['Más de 60 días', '$' . number_format($tramos['61-90'] + $tramos['90+'], 2)],
        ['Más de 90 días', '$' . number_format($tramos['90+'], 2)],
    ], 3);

    $rank = [];
    foreach ($tramos as $etq => $mto) if ($mto > 0) $rank[] = [$etq . ' días', $mto];
    $pdf->bloqueRanking('Antigüedad de lo que se debe', $rank, true, 'Antigüedad');

    // Cuánto se le debe a cada proveedor: es la pregunta que sigue siempre
    $porProv = [];
    foreach ($rows as $r) { $n = $r['prov'] ?: 'Sin proveedor'; $porProv[$n] = ($porProv[$n] ?? 0) + (float)$r['monto']; }
    arsort($porProv);
    $pdf->bloqueRanking('A quién se le debe',
        array_map(null, array_slice(array_keys($porProv), 0, 10), array_slice(array_values($porProv), 0, 10)),
        true, 'Proveedor');

    $pdf->seccion('Detalle, del más viejo al más nuevo');
    $filas = [];
    foreach ($rows as $r) {
        $filas[] = [$r['folio'], date('d/m/Y', strtotime($r['fecha'])), (int)$r['dias'], $r['concepto'],
                    $r['prov'] ?: '—', $r['area'], texto_estatus($r['estatus_pago']), (float)$r['monto']];
    }
    $pdf->tabla([
        ['t'=>'Folio','w'=>1.4], ['t'=>'Fecha','w'=>1], ['t'=>'Días','w'=>0.6,'a'=>'r','f'=>'int'],
        ['t'=>'Concepto','w'=>2.6], ['t'=>'Proveedor','w'=>1.8], ['t'=>'Área','w'=>1.2],
        ['t'=>'Estatus','w'=>1], ['t'=>'Importe','w'=>1.3,'a'=>'r','f'=>'money'],
    ], $filas, ['TOTAL','','','','','','', $total], 'No hay nada pendiente de pago.');
    $pdf->descargar(pdf_nombre('pendientes_de_pago'));
}

$titulo_pagina = 'Pendientes de pago';
$pagina_activa = 'reportes';
require __DIR__ . '/../config/header.php';
?>
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
  <div>
    <a href="<?= url('reportes/reportes.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-1">
      <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Reportes</a>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Pendientes de pago</h2>
    <p class="text-xs text-zinc-500 mt-0.5">Todo lo que está marcado como pendiente o pagado a medias, del más viejo al más nuevo.</p>
  </div>
  <?php if ($rows): ?><?= botones_export('reporte_pendientes.php') ?><?php endif; ?>
</div>

<?php if ($rows): ?>
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
  <div class="bg-white rounded-2xl border shadow-sm p-4" style="border-color:<?= EST_BAD ?>55;background:<?= EST_BAD ?>0d">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Total por pagar</div>
    <div class="font-display text-2xl font-extrabold tabular-nums mt-1" style="color:<?= EST_BAD ?>">$<?= number_format($total,2) ?></div>
    <div class="text-[11px] text-zinc-400"><?= count($rows) ?> gastos</div>
  </div>
  <?php foreach ($tramos as $etq => $mto): ?>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400"><?= $etq ?> días</div>
    <div class="font-display text-xl font-extrabold text-zinc-800 tabular-nums mt-1">$<?= number_format($mto,2) ?></div>
    <div class="mt-1.5 h-1.5 bg-zinc-100 rounded-full overflow-hidden">
      <div class="h-full rounded-full" style="width:<?= $total>0 ? ($mto/$total)*100 : 0 ?>%;background:<?= $etq==='90+' ? EST_BAD : ($etq==='61-90' ? EST_WARN : tono(400)) ?>"></div></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-3 font-semibold">Folio</th><th class="px-4 py-3 font-semibold">Fecha</th>
        <th class="px-4 py-3 font-semibold text-right">Días</th><th class="px-4 py-3 font-semibold">Concepto</th>
        <th class="px-4 py-3 font-semibold">Proveedor</th><th class="px-4 py-3 font-semibold">Área</th>
        <th class="px-4 py-3 font-semibold">Estatus</th><th class="px-4 py-3 font-semibold text-right">Importe</th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="px-4 py-10 text-center text-zinc-400">No hay nada pendiente de pago. Todo al corriente.</td></tr>
      <?php else: foreach ($rows as $r): $d=(int)$r['dias'];
        $colD = $d > 90 ? EST_BAD : ($d > 60 ? EST_WARN : '#71717a'); ?>
        <tr class="hover:bg-zinc-50 cursor-pointer" onclick="location.href='<?= url('gasto_ver.php?id='.(int)$r['id']) ?>'">
          <td class="px-4 py-2.5 font-mono text-xs text-marca-700 whitespace-nowrap"><?= e($r['folio']) ?></td>
          <td class="px-4 py-2.5 text-zinc-500 whitespace-nowrap"><?= fmt_fecha($r['fecha'], false) ?></td>
          <td class="px-4 py-2.5 text-right font-semibold tabular-nums" style="color:<?= $colD ?>"><?= $d ?></td>
          <td class="px-4 py-2.5 text-zinc-800"><?= e($r['concepto']) ?></td>
          <td class="px-4 py-2.5 text-zinc-500"><?= e($r['prov'] ?: '—') ?></td>
          <td class="px-4 py-2.5 text-zinc-500 whitespace-nowrap"><?= e($r['area']) ?></td>
          <td class="px-4 py-2.5"><?= badge_estatus_pago($r['estatus_pago']) ?></td>
          <td class="px-4 py-2.5 text-right font-semibold text-zinc-800 tabular-nums whitespace-nowrap">$<?= number_format((float)$r['monto'],2) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../config/footer.php'; ?>
