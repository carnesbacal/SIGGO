<?php
/** reportes/reporte_por_categoria.php - Gasto por categoria y subcategoria (+ año anterior, + XLSX) */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/tema.php';
require_once __DIR__ . '/../config/gastos_helpers.php';
require_once __DIR__ . '/../config/reportes_pdf.php';
requerir_login();

$anio    = (int) (input('anio') ?: date('Y'));
$anioAnt = $anio - 1;
function var_pct($act, $ant) { return $ant > 0 ? (($act - $ant) / $ant) * 100 : null; }

$rows = db_all("SELECT c.id, c.nombre, c.color,
    COALESCE((SELECT SUM(g.monto) FROM gastos g WHERE g.categoria_id=c.id AND g.anio=:a2 AND g.estatus_pago<>'cancelado'),0) AS gastado,
    COALESCE((SELECT SUM(g.monto) FROM gastos g WHERE g.categoria_id=c.id AND g.anio=:a3 AND g.estatus_pago<>'cancelado'),0) AS gastado_ant,
    COALESCE((SELECT COUNT(*)      FROM gastos g WHERE g.categoria_id=c.id AND g.anio=:a4 AND g.estatus_pago<>'cancelado'),0) AS n
  FROM categorias_gasto c
  WHERE c.activo=1 AND c.ambito='gasto'
  ORDER BY gastado DESC, c.orden", ['a2'=>$anio,'a3'=>$anioAnt,'a4'=>$anio]);

$tg = 0.0; $tga = 0.0;
foreach ($rows as $r) { $tg += (float)$r['gastado']; $tga += (float)$r['gastado_ant']; }

$subs = [];
foreach (db_all("SELECT g.categoria_id, COALESCE(s.nombre,'(sin subcategoría)') sub,
                        SUM(g.monto) monto, COUNT(*) n
                   FROM gastos g LEFT JOIN subcategorias_gasto s ON s.id=g.subcategoria_id
                  WHERE g.anio=:a AND g.estatus_pago<>'cancelado'
                  GROUP BY g.categoria_id, sub ORDER BY monto DESC", ['a'=>$anio]) as $r) {
    $subs[(int)$r['categoria_id']][] = $r;
}

if (input('export') === 'xlsx') {
    require_once __DIR__ . '/../config/xlsx_writer.php';
    $x = new XlsxWriter(); $x->addSheet("Por categoria $anio"); $x->setPageSetup(1,0,'landscape');
    $x->addHeaderRow(['Categoría','Subcategoría',"Gastado $anio","Gastado $anioAnt",'Diferencia','Var. %','% del total'], true);
    foreach ($rows as $r) {
        $ga = (float)$r['gastado']; $gaa = (float)$r['gastado_ant'];
        $vp = var_pct($ga, $gaa); $pc = $tg > 0 ? ($ga/$tg)*100 : 0;
        $x->addRow([['v'=>$r['nombre'],'s'=>1], '', ['v'=>$ga,'s'=>3], ['v'=>$gaa,'s'=>3],
                    ['v'=>$ga-$gaa,'s'=>3], ($vp===null?'—':['v'=>$vp,'s'=>8]), ['v'=>$pc,'s'=>8]]);
        foreach (($subs[(int)$r['id']] ?? []) as $s) {
            $x->addRow(['', (string)$s['sub'], ['v'=>(float)$s['monto'],'s'=>3], '', '', '', '']);
        }
    }
    $tvp = var_pct($tg, $tga);
    $x->addRow([['v'=>'TOTAL','s'=>1], '', ['v'=>$tg,'s'=>3], ['v'=>$tga,'s'=>3],
                ['v'=>$tg-$tga,'s'=>3], ($tvp===null?'—':['v'=>$tvp,'s'=>8]), ['v'=>100,'s'=>8]]);
    $x->download("gasto_por_categoria_$anio.xlsx");
}

if (input('export') === 'pdf') {
    $vpT = var_pct($tg, $tga);
    $pdf = pdf_reporte('Gasto por categoría', 'Año ' . $anio . ' · comparado con ' . $anioAnt);
    $pdf->kpis([
        ['Gastado ' . $anio, '$' . number_format($tg, 2)],
        ['Gastado ' . $anioAnt, '$' . number_format($tga, 2)],
        ['Diferencia', ($tg - $tga >= 0 ? '+' : '-') . '$' . number_format(abs($tg - $tga), 2),
         $vpT === null ? '' : number_format($vpT, 1) . '% contra ' . $anioAnt],
    ], 3);

    $rank = [];
    foreach ($rows as $r) if ((float)$r['gastado'] > 0) $rank[] = [$r['nombre'], (float)$r['gastado']];
    $pdf->bloqueRanking('Peso de cada categoría en ' . $anio, array_slice($rank, 0, 10), true, 'Categoría');

    $pdf->seccion('Comparativo por categoría');
    $filas = [];
    foreach ($rows as $r) {
        $ga = (float)$r['gastado']; $gaa = (float)$r['gastado_ant'];
        $vp = var_pct($ga, $gaa);
        $filas[] = [$r['nombre'], (int)$r['n'], $ga, $gaa, $ga - $gaa,
                    $vp === null ? '—' : number_format($vp, 1) . '%',
                    $tg > 0 ? ($ga / $tg) * 100 : 0];
        // Las subcategorías van debajo, con sangría, para no perder el desglose
        foreach (($subs[(int)$r['id']] ?? []) as $s) {
            // La sangría con espacios se pierde al maquetar el PDF, así que
            // la subcategoría se marca con un punto medio
            $filas[] = ['· ' . $s['sub'], (int)$s['n'], (float)$s['monto'], '', '', '', ''];
        }
    }
    $pdf->tabla([
        ['t'=>'Categoría / subcategoría','w'=>3],
        ['t'=>'Gastos','w'=>0.8,'a'=>'r','f'=>'int'],
        ['t'=>'Gastado ' . $anio,'w'=>1.4,'a'=>'r','f'=>'money'],
        ['t'=>'Gastado ' . $anioAnt,'w'=>1.4,'a'=>'r','f'=>'money'],
        ['t'=>'Diferencia','w'=>1.3,'a'=>'r','f'=>'money'],
        ['t'=>'Var.','w'=>0.7,'a'=>'r'],
        ['t'=>'% total','w'=>0.9,'a'=>'r','f'=>'pct'],
    ], $filas, ['TOTAL', '', $tg, $tga, $tg - $tga, $vpT === null ? '—' : number_format($vpT, 1) . '%', 100.0]);
    $pdf->descargar(pdf_nombre("gasto_por_categoria_$anio"));
}

$anio_actual = (int) date('Y');
$titulo_pagina = 'Gasto por categoría';
$pagina_activa = 'reportes';
require __DIR__ . '/../config/header.php';
?>
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
  <div>
    <a href="<?= url('reportes/reportes.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-1">
      <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Reportes</a>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Gasto por categoría · <?= $anio ?></h2>
    <p class="text-xs text-zinc-500 mt-0.5">Cuánto pesa cada categoría y cómo cambió contra <?= $anioAnt ?>.</p>
  </div>
  <div class="flex items-end gap-2">
    <form method="get"><select name="anio" onchange="this.form.submit()" class="h-9 px-2.5 rounded-lg border border-zinc-300 text-sm">
      <?php for($y=$anio_actual+1;$y>=$anio_actual-4;$y--): ?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
    </select></form>
    <?= botones_export('reporte_por_categoria.php', 'anio='.$anio) ?>
  </div>
</div>

<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-3 font-semibold">Categoría</th>
        <th class="px-4 py-3 font-semibold text-right">Gastado <?= $anio ?></th>
        <th class="px-4 py-3 font-semibold text-right">Gastado <?= $anioAnt ?></th>
        <th class="px-4 py-3 font-semibold text-right">Diferencia</th>
        <th class="px-4 py-3 font-semibold text-right">Variación</th>
        <th class="px-4 py-3 font-semibold text-right">% del total</th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="px-4 py-10 text-center text-zinc-400">No hay categorías activas.</td></tr>
      <?php else: foreach($rows as $r):
        $ga=(float)$r['gastado']; $gaa=(float)$r['gastado_ant'];
        $dif=$ga-$gaa; $vp=var_pct($ga,$gaa); $pc=$tg>0?($ga/$tg)*100:0;
        $lista = $subs[(int)$r['id']] ?? [];
      ?>
        <tr class="hover:bg-zinc-50" x-data="{abierto:false}">
          <td class="px-4 py-3">
            <button type="button" @click="abierto=!abierto" class="inline-flex items-center gap-1.5 text-left">
              <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= e($r['color']) ?>"></span>
              <span class="font-medium text-zinc-800"><?= e($r['nombre']) ?></span>
              <?php if ($lista): ?>
                <span class="text-[11px] text-zinc-400"><?= count($lista) ?></span>
                <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-zinc-400 transition-transform" :class="abierto && 'rotate-180'"></i>
              <?php endif; ?>
            </button>
            <?php if ($lista): ?>
            <div x-show="abierto" x-cloak class="mt-2 pl-4 space-y-1">
              <?php foreach ($lista as $s): ?>
                <div class="flex items-center justify-between text-xs">
                  <span class="text-zinc-500"><?= e($s['sub']) ?> <span class="text-zinc-300">· <?= (int)$s['n'] ?></span></span>
                  <span class="text-zinc-600 tabular-nums">$<?= number_format((float)$s['monto'],2) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-right font-semibold text-zinc-900 tabular-nums align-top">$<?= number_format($ga,2) ?></td>
          <td class="px-4 py-3 text-right text-zinc-500 tabular-nums align-top"><?= $gaa>0 ? '$'.number_format($gaa,2) : '—' ?></td>
          <td class="px-4 py-3 text-right tabular-nums align-top font-medium"
              style="color:<?= $dif>0 ? EST_BAD : ($dif<0 ? EST_OK : '#a1a1aa') ?>">
            <?= $dif==0 ? '—' : ($dif>0?'+':'−').number_format(abs($dif),2) ?></td>
          <td class="px-4 py-3 text-right tabular-nums align-top text-xs font-semibold"
              style="color:<?= $vp===null ? '#a1a1aa' : ($vp>0 ? EST_BAD : EST_OK) ?>">
            <?= $vp===null ? 'nuevo' : (($vp>0?'▲':'▼').' '.number_format(abs($vp),1).'%') ?></td>
          <td class="px-4 py-3 text-right align-top">
            <div class="flex items-center justify-end gap-2">
              <div class="w-16 h-1.5 bg-zinc-100 rounded-full overflow-hidden">
                <div class="h-full rounded-full" style="width:<?= min(100,$pc) ?>%;background:<?= e($r['color']) ?>"></div></div>
              <span class="text-xs text-zinc-500 tabular-nums w-10 text-right"><?= number_format($pc,1) ?>%</span></div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if ($rows): $tvp=var_pct($tg,$tga); $tdif=$tg-$tga; ?>
      <tfoot><tr class="border-t-2 border-zinc-200 bg-zinc-50 font-semibold">
        <td class="px-4 py-3 text-xs uppercase tracking-wider text-zinc-500">Total</td>
        <td class="px-4 py-3 text-right font-display font-extrabold text-marca-700 tabular-nums">$<?= number_format($tg,2) ?></td>
        <td class="px-4 py-3 text-right text-zinc-500 tabular-nums"><?= $tga>0?'$'.number_format($tga,2):'—' ?></td>
        <td class="px-4 py-3 text-right tabular-nums" style="color:<?= $tdif>0 ? EST_BAD : ($tdif<0 ? EST_OK : '#a1a1aa') ?>">
          <?= $tdif==0?'—':($tdif>0?'+':'−').number_format(abs($tdif),2) ?></td>
        <td class="px-4 py-3 text-right text-xs" style="color:<?= $tvp===null?'#a1a1aa':($tvp>0?EST_BAD:EST_OK) ?>">
          <?= $tvp===null?'—':(($tvp>0?'▲':'▼').' '.number_format(abs($tvp),1).'%') ?></td>
        <td class="px-4 py-3 text-right text-xs text-zinc-400">100%</td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<p class="text-[11px] text-zinc-400 mt-2 px-1">Haz clic en una categoría para ver el desglose de sus subcategorías.</p>
<?php require __DIR__ . '/../config/footer.php'; ?>
