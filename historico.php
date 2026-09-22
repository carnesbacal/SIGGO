<?php
/** historico.php - Lo realmente gastado por año, mes y categoría */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/tema.php';
require_once __DIR__ . '/config/gastos_helpers.php';
require_once __DIR__ . '/config/controles_helpers.php';
requerir_login();

$anio    = (int) (input('anio') ?: date('Y'));
$comparar = (int) (input('comparar') ?: ($anio - 1));
$area    = (int) input('area_id');
$incluir_cancelados = input('cancelados') === '1';

$meses  = meses_nombres();
$mesesC = meses_cortos();

/** Matriz categoría × mes de un año. */
function matriz_anio(int $anio, int $area = 0, bool $cancelados = false): array {
    $w = ['v.anio = :a']; $p = ['a'=>$anio];
    if ($area > 0)   { $w[] = 'v.area_id = :ar'; $p['ar'] = $area; }
    if (!$cancelados) { $w[] = "v.estatus_pago <> 'cancelado'"; }
    $sql = "SELECT v.categoria_id, c.nombre AS cat, c.color, c.orden, v.mes, SUM(v.monto) AS monto
              FROM vista_gasto_area v
              INNER JOIN categorias_gasto c ON c.id = v.categoria_id
             WHERE " . implode(' AND ', $w) . "
             GROUP BY v.categoria_id, c.nombre, c.color, c.orden, v.mes";
    $out = [];
    foreach (db_all($sql, $p) as $r) {
        $cid = (int)$r['categoria_id'];
        if (!isset($out[$cid])) {
            $out[$cid] = ['cat'=>$r['cat'], 'color'=>$r['color'], 'orden'=>(int)$r['orden'],
                          'meses'=>array_fill(1,12,0.0), 'total'=>0.0];
        }
        $m = (int)$r['mes'];
        $out[$cid]['meses'][$m] = (float)$r['monto'];
        $out[$cid]['total']    += (float)$r['monto'];
    }
    uasort($out, fn($x,$y) => $x['orden'] <=> $y['orden'] ?: strcmp($x['cat'],$y['cat']));
    return $out;
}

$mat  = matriz_anio($anio, $area, $incluir_cancelados);
$matC = matriz_anio($comparar, $area, $incluir_cancelados);

$totMes  = array_fill(1,12,0.0); $granTotal = 0.0;
foreach ($mat as $f) { for ($m=1;$m<=12;$m++) $totMes[$m] += $f['meses'][$m]; $granTotal += $f['total']; }
$totalComp = 0.0; foreach ($matC as $f) $totalComp += $f['total'];
$varPct = $totalComp > 0 ? (($granTotal - $totalComp) / $totalComp) * 100 : null;

$maxCelda = 0.0;
foreach ($mat as $f) foreach ($f['meses'] as $v) if ($v > $maxCelda) $maxCelda = $v;

// Subcategorías del año, para el desglose
$subs = [];
$ws = ['g.anio = :a']; $ps = ['a'=>$anio];
if ($area > 0)   { $ws[] = 'g.area_id = :ar'; $ps['ar'] = $area; }
if (!$incluir_cancelados) { $ws[] = "g.estatus_pago <> 'cancelado'"; }
foreach (db_all("SELECT g.categoria_id, COALESCE(s.nombre,'(sin subcategoría)') AS sub,
                        SUM(g.monto) AS monto, COUNT(*) AS n
                   FROM gastos g LEFT JOIN subcategorias_gasto s ON s.id = g.subcategoria_id
                  WHERE " . implode(' AND ', $ws) . "
                  GROUP BY g.categoria_id, sub ORDER BY monto DESC", $ps) as $r) {
    $subs[(int)$r['categoria_id']][] = ['sub'=>$r['sub'], 'monto'=>(float)$r['monto'], 'n'=>(int)$r['n']];
}

$areas = areas_lista();
$anios = db_all("SELECT DISTINCT anio FROM gastos ORDER BY anio DESC");
$anioLista = array_map(fn($r)=>(int)$r['anio'], $anios);
foreach ([(int)date('Y'), $anio, $comparar] as $extra) if (!in_array($extra, $anioLista, true)) $anioLista[] = $extra;
rsort($anioLista);

$titulo_pagina = 'Histórico';
$pagina_activa = 'historico';
require __DIR__ . '/config/header.php';

$fm = fn($v) => '$' . number_format($v, 2);
?>
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
  <div>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Histórico anual</h2>
    <p class="text-xs text-zinc-500 mt-0.5">Lo que realmente se gastó, por categoría y mes.</p>
  </div>
  <form method="get" class="flex flex-wrap items-end gap-2">
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Año</label>
      <select name="anio" class="h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <?php foreach ($anioLista as $y): ?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Comparar con</label>
      <select name="comparar" class="h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <?php foreach ($anioLista as $y): ?><option value="<?= $y ?>" <?= $comparar===$y?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Área</label>
      <select name="area_id" class="h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="0">Todas</option>
        <?php foreach ($areas as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $area===(int)$a['id']?'selected':'' ?>><?= e($a['nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <button type="submit" class="h-9 px-4 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm">Ver</button>
  </form>
</div>

<!-- Resumen -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
  <div class="bg-white rounded-2xl border border-marca-200 shadow-sm p-4" style="background:linear-gradient(150deg, rgba(124,58,237,.06), #fff)">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Total <?= $anio ?></div>
    <div class="font-display text-2xl font-extrabold text-marca-700 tabular-nums mt-1"><?= $fm($granTotal) ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Total <?= $comparar ?></div>
    <div class="font-display text-2xl font-extrabold text-zinc-700 tabular-nums mt-1"><?= $fm($totalComp) ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Variación</div>
    <?php if ($varPct === null): ?>
      <div class="font-display text-2xl font-extrabold text-zinc-300 mt-1">—</div>
      <div class="text-[11px] text-zinc-400">Sin datos en <?= $comparar ?></div>
    <?php else: $sube = $varPct >= 0; ?>
      <div class="font-display text-2xl font-extrabold tabular-nums mt-1" style="color:<?= $sube ? EST_BAD : EST_OK ?>">
        <?= $sube ? '▲' : '▼' ?> <?= number_format(abs($varPct),1) ?>%</div>
      <div class="text-[11px] text-zinc-400"><?= $fm(abs($granTotal-$totalComp)) ?> <?= $sube ? 'más' : 'menos' ?></div>
    <?php endif; ?>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Promedio mensual</div>
    <?php $mesesConGasto = count(array_filter($totMes, fn($v)=>$v>0)); ?>
    <div class="font-display text-2xl font-extrabold text-zinc-700 tabular-nums mt-1">
      <?= $fm($mesesConGasto ? $granTotal/$mesesConGasto : 0) ?></div>
    <div class="text-[11px] text-zinc-400"><?= $mesesConGasto ?> <?= $mesesConGasto===1?'mes':'meses' ?> con gasto</div>
  </div>
</div>

<!-- Matriz -->
<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden mb-4">
  <div class="px-5 py-3 border-b border-zinc-200 flex items-center justify-between">
    <h3 class="font-display font-bold text-sm text-zinc-800">Categoría × mes · <?= $anio ?></h3>
    <span class="text-[11px] text-zinc-400">La intensidad del fondo marca dónde pesó más el gasto</span>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" style="min-width:900px">
      <thead><tr class="text-[10px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-2.5 font-semibold text-left sticky left-0 bg-zinc-50">Categoría</th>
        <?php foreach ($mesesC as $mc): ?><th class="px-2 py-2.5 font-semibold text-right"><?= $mc ?></th><?php endforeach; ?>
        <th class="px-4 py-2.5 font-semibold text-right">Total</th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
        <?php if (!$mat): ?>
          <tr><td colspan="14" class="px-4 py-10 text-center text-zinc-400">No hay gastos registrados en <?= $anio ?>.</td></tr>
        <?php else: foreach ($mat as $cid => $f): ?>
          <tr class="hover:bg-zinc-50">
            <td class="px-4 py-2.5 sticky left-0 bg-white">
              <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= e($f['color']) ?>"></span>
                <span class="font-medium text-zinc-800"><?= e($f['cat']) ?></span></span>
            </td>
            <?php for ($m=1;$m<=12;$m++): $v = $f['meses'][$m];
              $int = ($maxCelda > 0 && $v > 0) ? ($v / $maxCelda) : 0;
              $bg  = $v > 0 ? 'background:rgba(124,58,237,' . number_format(0.05 + $int*0.28, 3) . ')' : '';
            ?>
              <td class="px-2 py-2.5 text-right tabular-nums <?= $v>0 ? 'text-zinc-800' : 'text-zinc-300' ?>" style="<?= $bg ?>">
                <?= $v > 0 ? number_format($v, 0) : '·' ?></td>
            <?php endfor; ?>
            <td class="px-4 py-2.5 text-right font-semibold text-zinc-900 tabular-nums whitespace-nowrap"><?= $fm($f['total']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($mat): ?>
      <tfoot><tr class="border-t-2 border-zinc-200 bg-zinc-50 font-semibold">
        <td class="px-4 py-3 text-xs uppercase tracking-wider text-zinc-500 sticky left-0 bg-zinc-50">Total</td>
        <?php for ($m=1;$m<=12;$m++): ?>
          <td class="px-2 py-3 text-right tabular-nums <?= $totMes[$m]>0 ? 'text-zinc-800' : 'text-zinc-300' ?>">
            <?= $totMes[$m] > 0 ? number_format($totMes[$m],0) : '·' ?></td>
        <?php endfor; ?>
        <td class="px-4 py-3 text-right font-display font-extrabold text-marca-700 tabular-nums"><?= $fm($granTotal) ?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <div class="px-5 py-2 border-t border-zinc-100 text-[11px] text-zinc-400">Cifras en pesos, sin centavos en la matriz.</div>
</div>

<?php if ($mat): ?>
<!-- Comparativa contra el otro año -->
<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden mb-4">
  <div class="px-5 py-3 border-b border-zinc-200">
    <h3 class="font-display font-bold text-sm text-zinc-800"><?= $anio ?> contra <?= $comparar ?></h3>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-[10px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-2.5 font-semibold text-left">Categoría</th>
        <th class="px-4 py-2.5 font-semibold text-right"><?= $comparar ?></th>
        <th class="px-4 py-2.5 font-semibold text-right"><?= $anio ?></th>
        <th class="px-4 py-2.5 font-semibold text-right">Diferencia</th>
        <th class="px-4 py-2.5 font-semibold text-right w-24">Variación</th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
        <?php
        $ids = array_unique(array_merge(array_keys($mat), array_keys($matC)));
        foreach ($ids as $cid):
          $act = $mat[$cid]['total']  ?? 0.0;
          $ant = $matC[$cid]['total'] ?? 0.0;
          $nom = $mat[$cid]['cat']    ?? ($matC[$cid]['cat'] ?? '—');
          $col = $mat[$cid]['color']  ?? ($matC[$cid]['color'] ?? '#6B7280');
          $dif = $act - $ant;
          $pct = $ant > 0 ? ($dif / $ant) * 100 : null;
        ?>
        <tr class="hover:bg-zinc-50">
          <td class="px-4 py-2.5">
            <span class="inline-flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= e($col) ?>"></span>
              <span class="text-zinc-800"><?= e($nom) ?></span></span></td>
          <td class="px-4 py-2.5 text-right text-zinc-500 tabular-nums"><?= $ant>0 ? $fm($ant) : '—' ?></td>
          <td class="px-4 py-2.5 text-right text-zinc-800 font-medium tabular-nums"><?= $act>0 ? $fm($act) : '—' ?></td>
          <td class="px-4 py-2.5 text-right tabular-nums font-medium"
              style="color:<?= $dif > 0 ? EST_BAD : ($dif < 0 ? EST_OK : '#a1a1aa') ?>">
            <?= $dif == 0 ? '—' : ($dif > 0 ? '+' : '−') . number_format(abs($dif),2) ?></td>
          <td class="px-4 py-2.5 text-right tabular-nums text-xs font-semibold"
              style="color:<?= $pct === null ? '#a1a1aa' : ($pct > 0 ? EST_BAD : EST_OK) ?>">
            <?= $pct === null ? 'nuevo' : (($pct>0?'▲':'▼') . ' ' . number_format(abs($pct),1) . '%') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Desglose a subcategoría -->
<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
  <div class="px-5 py-3 border-b border-zinc-200">
    <h3 class="font-display font-bold text-sm text-zinc-800">Desglose por subcategoría · <?= $anio ?></h3>
  </div>
  <div class="divide-y divide-zinc-100">
    <?php foreach ($mat as $cid => $f): $lista = $subs[$cid] ?? []; if (!$lista) continue; ?>
    <div class="px-5 py-3" x-data="{abierto:false}">
      <button type="button" @click="abierto=!abierto" class="w-full flex items-center gap-2 text-left">
        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= e($f['color']) ?>"></span>
        <span class="font-medium text-zinc-800 flex-1"><?= e($f['cat']) ?></span>
        <span class="text-xs text-zinc-400"><?= count($lista) ?></span>
        <span class="font-semibold text-zinc-800 tabular-nums"><?= $fm($f['total']) ?></span>
        <i data-lucide="chevron-down" class="w-4 h-4 text-zinc-400 transition-transform" :class="abierto && 'rotate-180'"></i>
      </button>
      <div x-show="abierto" x-cloak class="mt-2.5 pl-5 space-y-1.5">
        <?php foreach ($lista as $s): $p = $f['total']>0 ? ($s['monto']/$f['total'])*100 : 0; ?>
        <div class="flex items-center gap-3 text-sm">
          <span class="text-zinc-600 flex-1 truncate"><?= e($s['sub']) ?>
            <span class="text-zinc-300 text-xs">· <?= $s['n'] ?></span></span>
          <div class="w-24 h-1.5 bg-zinc-100 rounded-full overflow-hidden flex-shrink-0">
            <div class="h-full rounded-full" style="width:<?= min(100,$p) ?>%;background:<?= tono(400) ?>"></div></div>
          <span class="text-zinc-700 tabular-nums w-24 text-right"><?= $fm($s['monto']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/config/footer.php'; ?>
