<?php
/** reportes/reporte_por_area.php - Gasto por area, con los repartos ya aplicados */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/tema.php';
require_once __DIR__ . '/../config/gastos_helpers.php';
require_once __DIR__ . '/../config/controles_helpers.php';
require_once __DIR__ . '/../config/reportes_pdf.php';
requerir_login();

$anio = (int) (input('anio') ?: date('Y'));
$mesesC = meses_cortos();

$rows = db_all("SELECT a.id, a.nombre, a.codigo,
                       SUM(v.monto) AS total,
                       SUM(CASE WHEN v.mes=1 THEN v.monto ELSE 0 END) m1,
                       SUM(CASE WHEN v.mes=2 THEN v.monto ELSE 0 END) m2,
                       SUM(CASE WHEN v.mes=3 THEN v.monto ELSE 0 END) m3,
                       SUM(CASE WHEN v.mes=4 THEN v.monto ELSE 0 END) m4,
                       SUM(CASE WHEN v.mes=5 THEN v.monto ELSE 0 END) m5,
                       SUM(CASE WHEN v.mes=6 THEN v.monto ELSE 0 END) m6,
                       SUM(CASE WHEN v.mes=7 THEN v.monto ELSE 0 END) m7,
                       SUM(CASE WHEN v.mes=8 THEN v.monto ELSE 0 END) m8,
                       SUM(CASE WHEN v.mes=9 THEN v.monto ELSE 0 END) m9,
                       SUM(CASE WHEN v.mes=10 THEN v.monto ELSE 0 END) m10,
                       SUM(CASE WHEN v.mes=11 THEN v.monto ELSE 0 END) m11,
                       SUM(CASE WHEN v.mes=12 THEN v.monto ELSE 0 END) m12
                  FROM vista_gasto_area v
                  INNER JOIN areas a ON a.id = v.area_id
                 WHERE v.anio = :a AND v.estatus_pago <> 'cancelado'
                 GROUP BY a.id, a.nombre, a.codigo
                 ORDER BY total DESC", ['a'=>$anio]);
$total = 0.0; foreach ($rows as $r) $total += (float)$r['total'];

if (input('export') === 'xlsx') {
    require_once __DIR__ . '/../config/xlsx_writer.php';
    $x = new XlsxWriter(); $x->addSheet("Por area $anio"); $x->setPageSetup(1,0,'landscape');
    $enc = ['Código','Área']; foreach ($mesesC as $mc) $enc[] = $mc; $enc[] = 'Total';
    $x->addHeaderRow($enc, true);
    foreach ($rows as $r) {
        $fila = [(string)$r['codigo'], (string)$r['nombre']];
        for ($m=1;$m<=12;$m++) $fila[] = ['v'=>(float)$r['m'.$m], 's'=>3];
        $fila[] = ['v'=>(float)$r['total'], 's'=>3];
        $x->addRow($fila);
    }
    $x->download("gasto_por_area_$anio.xlsx");
}

if (input('export') === 'pdf') {
    $pdf = pdf_reporte('Gasto por área', 'Año ' . $anio, 'horizontal');
    $pdf->kpis([
        ['Gasto del año', '$' . number_format($total, 2)],
        ['Áreas con gasto', number_format(count($rows), 0)],
        ['Área con más gasto', $rows ? $rows[0]['nombre'] : '—',
         $rows ? '$' . number_format((float)$rows[0]['total'], 2) : ''],
    ], 3);
    $pdf->parrafo('Los gastos repartidos entre varias áreas se cuentan en cada una según su porcentaje, no completos.');

    $pdf->bloqueRanking('Peso de cada área',
        array_map(fn($r) => [$r['nombre'], (float)$r['total']], $rows), true, 'Área');

    // Serie del año, sumando todas las áreas: da la estacionalidad de la tienda
    $serie = [];
    for ($m = 1; $m <= 12; $m++) { $s = 0.0; foreach ($rows as $r) $s += (float)$r['m'.$m]; $serie[$m] = $s; }
    $pdf->bloqueMeses('Gasto por mes (todas las áreas)', $serie);

    $pdf->seccion('Mes a mes por área');
    $cols = [['t'=>'Área','w'=>2.2]];
    foreach ($mesesC as $mc) $cols[] = ['t'=>$mc, 'w'=>0.85, 'a'=>'r', 'f'=>'money0'];
    $cols[] = ['t'=>'Total', 'w'=>1.25, 'a'=>'r', 'f'=>'money'];

    $filas = []; $tm = array_fill(1, 12, 0.0);
    foreach ($rows as $r) {
        $f = [$r['nombre']];
        for ($m = 1; $m <= 12; $m++) { $f[] = (float)$r['m'.$m]; $tm[$m] += (float)$r['m'.$m]; }
        $f[] = (float)$r['total'];
        $filas[] = $f;
    }
    $tot = ['TOTAL']; for ($m = 1; $m <= 12; $m++) $tot[] = $tm[$m]; $tot[] = $total;
    $pdf->tabla($cols, $filas, $tot, 'Sin gastos en ' . $anio . '.');
    $pdf->parrafo('Las columnas de los meses van redondeadas al peso para que quepan; el total sí lleva centavos.');
    $pdf->descargar(pdf_nombre("gasto_por_area_$anio"));
}

$anio_actual = (int) date('Y');
$titulo_pagina = 'Gasto por área';
$pagina_activa = 'reportes';
require __DIR__ . '/../config/header.php';
?>
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
  <div>
    <a href="<?= url('reportes/reportes.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-1">
      <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Reportes</a>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Gasto por área · <?= $anio ?></h2>
    <p class="text-xs text-zinc-500 mt-0.5">Los gastos repartidos se cuentan en cada área según su porcentaje, no completos.</p>
  </div>
  <div class="flex items-end gap-2">
    <form method="get"><select name="anio" onchange="this.form.submit()" class="h-9 px-2.5 rounded-lg border border-zinc-300 text-sm">
      <?php for($y=$anio_actual+1;$y>=$anio_actual-4;$y--): ?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
    </select></form>
    <?= botones_export('reporte_por_area.php', 'anio='.$anio) ?>
  </div>
</div>

<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm" style="min-width:900px">
      <thead><tr class="text-[10px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-2.5 font-semibold text-left sticky left-0 bg-zinc-50">Área</th>
        <?php foreach ($mesesC as $mc): ?><th class="px-2 py-2.5 font-semibold text-right"><?= $mc ?></th><?php endforeach; ?>
        <th class="px-4 py-2.5 font-semibold text-right">Total</th>
        <th class="px-4 py-2.5 font-semibold text-right">%</th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php if (!$rows): ?>
        <tr><td colspan="15" class="px-4 py-10 text-center text-zinc-400">No hay gastos en <?= $anio ?>.</td></tr>
      <?php else: foreach ($rows as $r): $t=(float)$r['total']; $p = $total>0 ? ($t/$total)*100 : 0; ?>
        <tr class="hover:bg-zinc-50">
          <td class="px-4 py-2.5 sticky left-0 bg-white">
            <span class="font-medium text-zinc-800"><?= e($r['nombre']) ?></span>
            <span class="font-mono text-[10px] text-zinc-400 ml-1"><?= e($r['codigo']) ?></span></td>
          <?php for ($m=1;$m<=12;$m++): $v=(float)$r['m'.$m]; ?>
            <td class="px-2 py-2.5 text-right tabular-nums <?= $v>0?'text-zinc-700':'text-zinc-300' ?>">
              <?= $v>0 ? number_format($v,0) : '·' ?></td>
          <?php endfor; ?>
          <td class="px-4 py-2.5 text-right font-semibold text-zinc-900 tabular-nums whitespace-nowrap">$<?= number_format($t,2) ?></td>
          <td class="px-4 py-2.5 text-right text-xs text-zinc-500 tabular-nums"><?= number_format($p,1) ?>%</td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot><tr class="border-t-2 border-zinc-200 bg-zinc-50 font-semibold">
        <td class="px-4 py-3 text-xs uppercase tracking-wider text-zinc-500 sticky left-0 bg-zinc-50">Total</td>
        <?php for ($m=1;$m<=12;$m++): $s=0.0; foreach($rows as $r) $s += (float)$r['m'.$m]; ?>
          <td class="px-2 py-3 text-right tabular-nums <?= $s>0?'text-zinc-800':'text-zinc-300' ?>"><?= $s>0?number_format($s,0):'·' ?></td>
        <?php endfor; ?>
        <td class="px-4 py-3 text-right font-display font-extrabold text-marca-700 tabular-nums">$<?= number_format($total,2) ?></td>
        <td class="px-4 py-3 text-right text-xs text-zinc-400">100%</td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../config/footer.php'; ?>
