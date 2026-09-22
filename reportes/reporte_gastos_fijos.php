<?php
/** reportes/reporte_gastos_fijos.php - Compromiso anual de los gastos recurrentes */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/tema.php';
require_once __DIR__ . '/../config/gastos_helpers.php';
require_once __DIR__ . '/../config/recurrentes_helpers.php';
require_once __DIR__ . '/../config/reportes_pdf.php';
requerir_login();

$anio   = (int) (input('anio') ?: date('Y'));
$mesHoy = ((int)date('Y') === $anio) ? (int)date('n') : (($anio < (int)date('Y')) ? 12 : 0);

$recs = db_all("SELECT r.*, c.nombre AS cat, c.color AS catcolor, a.nombre AS area,
                       COALESCE(p.nombre, r.proveedor_texto) AS prov
                  FROM gastos_recurrentes r
                  INNER JOIN categorias_gasto c ON c.id = r.categoria_id
                  INNER JOIN areas a            ON a.id = r.area_id
                  LEFT JOIN proveedores p       ON p.id = r.proveedor_id
                 WHERE r.activo = 1
                 ORDER BY c.orden, r.concepto");

$det = [];
$tOcur = 0; $tComp = 0.0; $tGen = 0.0; $tPend = 0.0;
foreach ($recs as $r) {
    $ocur = 0; $meses = [];
    for ($m = 1; $m <= 12; $m++) {
        if (recurrente_aplica_mes($r, $anio, $m)) { $ocur++; $meses[] = $m; }
    }
    $monto = (float) $r['monto'];
    $comp  = $monto * $ocur;
    $gen   = (float) (db_one("SELECT COALESCE(SUM(monto),0) v FROM gastos
                               WHERE recurrente_id=:r AND anio=:a AND estatus_pago<>'cancelado'",
                             ['r'=>(int)$r['id'],'a'=>$anio])['v'] ?? 0);
    $nGen  = (int) (db_one("SELECT COUNT(*) v FROM gastos WHERE recurrente_id=:r AND anio=:a",
                           ['r'=>(int)$r['id'],'a'=>$anio])['v'] ?? 0);
    $pend  = max(0.0, $comp - $gen);
    $det[(int)$r['id']] = ['ocur'=>$ocur,'meses'=>$meses,'comp'=>$comp,'gen'=>$gen,'n_gen'=>$nGen,'pend'=>$pend];
    $tOcur += $ocur; $tComp += $comp; $tGen += $gen; $tPend += $pend;
}

if (input('export') === 'xlsx') {
    require_once __DIR__ . '/../config/xlsx_writer.php';
    $x = new XlsxWriter(); $x->addSheet("Gastos fijos $anio"); $x->setPageSetup(1,0,'landscape');
    $x->addHeaderRow(['Concepto','Categoría','Área','Proveedor','Frecuencia','Monto','Ocurrencias','Comprometido','Generado','Pendiente'], true);
    foreach ($recs as $r) { $d = $det[(int)$r['id']];
        $x->addRow([(string)$r['concepto'], (string)$r['cat'], (string)$r['area'], (string)($r['prov'] ?? ''),
                    ucfirst((string)$r['frecuencia']), ['v'=>(float)$r['monto'],'s'=>3], ['v'=>$d['ocur'],'s'=>2],
                    ['v'=>$d['comp'],'s'=>3], ['v'=>$d['gen'],'s'=>3], ['v'=>$d['pend'],'s'=>3]]);
    }
    $x->addRow([['v'=>'TOTAL','s'=>1],'','','','','',['v'=>$tOcur,'s'=>2],
                ['v'=>$tComp,'s'=>3], ['v'=>$tGen,'s'=>3], ['v'=>$tPend,'s'=>3]]);
    $x->download("gastos_fijos_$anio.xlsx");
}

if (input('export') === 'pdf') {
    $pdf = pdf_reporte('Gastos fijos', 'Año ' . $anio, 'horizontal');
    $pdf->kpis([
        ['Comprometido ' . $anio, '$' . number_format($tComp, 2), count($recs) . ' gastos fijos'],
        ['Ya generado', '$' . number_format($tGen, 2)],
        ['Falta por generar', '$' . number_format($tPend, 2)],
        ['Ocurrencias al año', number_format($tOcur, 0)],
    ]);
    $pdf->parrafo('Comprometido es lo que costarán en el año los gastos fijos activos. Generado es lo que el '
        . 'sistema ya dio de alta como gasto. La diferencia es lo que falta de aquí a diciembre.');

    $rank = [];
    foreach ($recs as $r) { $d = $det[(int)$r['id']]; if ($d['comp'] > 0) $rank[] = [(string)$r['concepto'], $d['comp']]; }
    usort($rank, fn($a, $b) => $b[1] <=> $a[1]);
    $pdf->bloqueRanking('Los gastos fijos más caros del año', array_slice($rank, 0, 12), true, 'Concepto');

    $pdf->seccion('Detalle de los gastos fijos activos');
    $filas = [];
    foreach ($recs as $r) {
        $d = $det[(int)$r['id']];
        $filas[] = [(string)$r['concepto'], (string)$r['cat'], (string)$r['area'], (string)($r['prov'] ?? '—'),
                    ucfirst((string)$r['frecuencia']), (float)$r['monto'], $d['ocur'],
                    $d['comp'], $d['gen'], $d['pend']];
    }
    $pdf->tabla([
        ['t'=>'Concepto','w'=>2.4], ['t'=>'Categoría','w'=>1.3], ['t'=>'Área','w'=>1.1],
        ['t'=>'Proveedor','w'=>1.6], ['t'=>'Frecuencia','w'=>1],
        ['t'=>'Monto','w'=>1.1,'a'=>'r','f'=>'money'], ['t'=>'Veces','w'=>0.6,'a'=>'r','f'=>'int'],
        ['t'=>'Comprometido','w'=>1.2,'a'=>'r','f'=>'money'],
        ['t'=>'Generado','w'=>1.2,'a'=>'r','f'=>'money'],
        ['t'=>'Pendiente','w'=>1.2,'a'=>'r','f'=>'money'],
    ], $filas, ['TOTAL','','','','','', $tOcur, $tComp, $tGen, $tPend], 'No hay gastos fijos activos.');
    $pdf->descargar(pdf_nombre("gastos_fijos_$anio"));
}

$anio_actual = (int) date('Y');
$titulo_pagina = 'Gastos fijos';
$pagina_activa = 'reportes';
require __DIR__ . '/../config/header.php';
$fm = fn($v) => '$' . number_format($v, 2);
?>
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
  <div>
    <a href="<?= url('reportes/reportes.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-1">
      <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Reportes</a>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Gastos fijos · <?= $anio ?></h2>
    <p class="text-xs text-zinc-500 mt-0.5">Lo que la tienda tiene comprometido al año por gastos recurrentes, y cuánto ya se generó.</p>
  </div>
  <div class="flex items-end gap-2">
    <form method="get"><select name="anio" onchange="this.form.submit()" class="h-9 px-2.5 rounded-lg border border-zinc-300 text-sm">
      <?php for($y=$anio_actual+1;$y>=$anio_actual-4;$y--): ?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
    </select></form>
    <?php if ($recs): ?>
    <?= botones_export('reporte_gastos_fijos.php', 'anio='.$anio) ?>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
  <div class="bg-white rounded-2xl border border-marca-200 shadow-sm p-4" style="background:linear-gradient(150deg, rgba(124,58,237,.06), #fff)">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Comprometido al año</div>
    <div class="font-display text-2xl font-extrabold text-marca-700 tabular-nums mt-1"><?= $fm($tComp) ?></div>
    <div class="text-[11px] text-zinc-400"><?= count($recs) ?> gastos fijos · <?= $tOcur ?> ocurrencias</div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Ya generado</div>
    <div class="font-display text-2xl font-extrabold text-zinc-800 tabular-nums mt-1"><?= $fm($tGen) ?></div>
    <div class="mt-1.5 h-1.5 bg-zinc-100 rounded-full overflow-hidden">
      <div class="h-full rounded-full" style="width:<?= $tComp>0 ? min(100,($tGen/$tComp)*100) : 0 ?>%;background:<?= tono(500) ?>"></div></div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Por generar</div>
    <div class="font-display text-2xl font-extrabold text-zinc-800 tabular-nums mt-1"><?= $fm($tPend) ?></div>
    <div class="text-[11px] text-zinc-400">resto del año</div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Promedio mensual</div>
    <div class="font-display text-2xl font-extrabold text-zinc-800 tabular-nums mt-1"><?= $fm($tComp/12) ?></div>
    <div class="text-[11px] text-zinc-400">si se reparte parejo</div>
  </div>
</div>

<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-3 font-semibold">Concepto</th>
        <th class="px-4 py-3 font-semibold">Categoría</th>
        <th class="px-4 py-3 font-semibold">Frecuencia</th>
        <th class="px-4 py-3 font-semibold text-right">Monto</th>
        <th class="px-4 py-3 font-semibold text-right">Veces</th>
        <th class="px-4 py-3 font-semibold text-right">Comprometido</th>
        <th class="px-4 py-3 font-semibold text-right">Generado</th>
        <th class="px-4 py-3 font-semibold text-right">Pendiente</th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php if (!$recs): ?>
        <tr><td colspan="8" class="px-4 py-10 text-center text-zinc-400">
          No hay gastos fijos dados de alta.
          <a href="<?= url('gastos_recurrentes.php') ?>" class="text-marca-700 font-semibold">Créalos aquí</a>.</td></tr>
      <?php else: foreach ($recs as $r): $d = $det[(int)$r['id']]; ?>
        <tr class="hover:bg-zinc-50">
          <td class="px-4 py-3">
            <div class="font-medium text-zinc-800"><?= e($r['concepto']) ?></div>
            <div class="text-xs text-zinc-400"><?= e($r['area']) ?><?= $r['prov'] ? ' · '.e($r['prov']) : '' ?></div>
          </td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center gap-1.5 text-zinc-600 whitespace-nowrap">
              <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= e($r['catcolor']) ?>"></span>
              <?= e($r['cat']) ?></span></td>
          <td class="px-4 py-3 text-zinc-500"><?= e(ucfirst((string)$r['frecuencia'])) ?></td>
          <td class="px-4 py-3 text-right text-zinc-700 tabular-nums"><?= $fm((float)$r['monto']) ?></td>
          <td class="px-4 py-3 text-right text-zinc-500 tabular-nums"><?= $d['ocur'] ?></td>
          <td class="px-4 py-3 text-right font-semibold text-zinc-900 tabular-nums"><?= $fm($d['comp']) ?></td>
          <td class="px-4 py-3 text-right tabular-nums">
            <span class="text-zinc-700"><?= $fm($d['gen']) ?></span>
            <?php if ($d['n_gen'] > 0): ?><div class="text-[10px] text-zinc-400"><?= $d['n_gen'] ?> generados</div><?php endif; ?>
          </td>
          <td class="px-4 py-3 text-right tabular-nums" style="color:<?= $d['pend']>0 ? EST_WARN : EST_OK ?>">
            <?= $d['pend'] > 0 ? $fm($d['pend']) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if ($recs): ?>
      <tfoot><tr class="border-t-2 border-zinc-200 bg-zinc-50 font-semibold">
        <td colspan="4" class="px-4 py-3 text-xs uppercase tracking-wider text-zinc-500">Total</td>
        <td class="px-4 py-3 text-right tabular-nums text-zinc-700"><?= $tOcur ?></td>
        <td class="px-4 py-3 text-right font-display font-extrabold text-marca-700 tabular-nums"><?= $fm($tComp) ?></td>
        <td class="px-4 py-3 text-right tabular-nums text-zinc-700"><?= $fm($tGen) ?></td>
        <td class="px-4 py-3 text-right tabular-nums" style="color:<?= $tPend>0 ? EST_WARN : EST_OK ?>"><?= $fm($tPend) ?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<p class="text-[11px] text-zinc-400 mt-2 px-1">
  "Comprometido" es el monto por las veces que toca en el año según su frecuencia.
  "Generado" es lo que el sistema ya convirtió en gastos reales.</p>
<?php require __DIR__ . '/../config/footer.php'; ?>
