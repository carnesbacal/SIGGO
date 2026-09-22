<?php
/**
 * reportes/reporte_resumen.php
 * Resumen general: una sola hoja que responde "¿cómo vamos?" sin entrar a los
 * seis reportes de detalle. Cada bloque es la versión corta de uno de ellos y
 * tiene liga al reporte completo.
 *
 * Periodo: el año en curso, con opción de ver un solo mes.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/tema.php';
require_once __DIR__ . '/../config/gastos_helpers.php';
require_once __DIR__ . '/../config/recurrentes_helpers.php';
require_once __DIR__ . '/../config/reportes_pdf.php';
requerir_login();

$anio    = (int) (input('anio') ?: date('Y'));
$mes     = (int) input('mes');
if ($mes < 1 || $mes > 12) $mes = 0;
$anioAnt = $anio - 1;
$mesesN  = meses_nombres();
$periodo = 'Año ' . $anio . ($mes ? ' · ' . $mesesN[$mes] : '');

// Filtro común: el periodo elegido, sin cancelados
$w  = "g.anio = :a AND g.estatus_pago <> 'cancelado'";
$p  = ['a' => $anio];
if ($mes) { $w .= ' AND g.mes = :m'; $p['m'] = $mes; }

// --- Totales ---------------------------------------------------------------
$tot = db_one("SELECT COALESCE(SUM(monto),0) v, COUNT(*) n FROM gastos g WHERE $w", $p);
$gTotal = (float)($tot['v'] ?? 0);
$gCount = (int)($tot['n'] ?? 0);

// Mismo periodo del año pasado, para saber si vamos arriba o abajo
$pA = $p; $pA['a'] = $anioAnt;
$totAnt  = db_one("SELECT COALESCE(SUM(monto),0) v FROM gastos g WHERE $w", $pA);
$gAnt    = (float)($totAnt['v'] ?? 0);
$varPct  = $gAnt > 0 ? (($gTotal - $gAnt) / $gAnt) * 100 : null;

// --- Serie del año ---------------------------------------------------------
$serie = array_fill(1, 12, 0.0);
foreach (db_all("SELECT mes, SUM(monto) v FROM gastos g
                  WHERE g.anio=:a AND g.estatus_pago <> 'cancelado' GROUP BY mes", ['a'=>$anio]) as $r) {
    $serie[(int)$r['mes']] = (float)$r['v'];
}
$mesesConGasto = count(array_filter($serie, fn($v) => $v > 0));
$promedioMes   = $mesesConGasto ? array_sum($serie) / $mesesConGasto : 0.0;

// --- Por categoría ---------------------------------------------------------
$cats = db_all("SELECT c.nombre, c.color, SUM(g.monto) v, COUNT(*) n
                  FROM gastos g INNER JOIN categorias_gasto c ON c.id = g.categoria_id
                 WHERE $w GROUP BY c.id, c.nombre, c.color ORDER BY v DESC", $p);

// --- Por área (con los repartos ya aplicados) ------------------------------
$wv = "v.anio = :a AND v.estatus_pago <> 'cancelado'" . ($mes ? ' AND v.mes = :m' : '');
$areas = db_all("SELECT a.nombre, SUM(v.monto) v
                   FROM vista_gasto_area v INNER JOIN areas a ON a.id = v.area_id
                  WHERE $wv GROUP BY a.id, a.nombre ORDER BY v DESC", $p);

// --- Productos y proveedores ----------------------------------------------
$productos = db_all("SELECT MAX(i.descripcion) prod, SUM(i.importe) v, SUM(i.cantidad) cant,
                            COUNT(DISTINCT i.gasto_id) n
                       FROM gasto_items i INNER JOIN gastos g ON g.id = i.gasto_id
                      WHERE $w GROUP BY TRIM(i.descripcion) ORDER BY v DESC LIMIT 10", $p);

$provs = db_all("SELECT COALESCE(pr.nombre, g.proveedor_texto, 'Sin proveedor') prov,
                        SUM(g.monto) v, COUNT(*) n
                   FROM gastos g LEFT JOIN proveedores pr ON pr.id = g.proveedor_id
                  WHERE $w GROUP BY prov ORDER BY v DESC LIMIT 10", $p);

// --- Pendientes de pago (estado a hoy, no del periodo) ---------------------
$pend = db_all("SELECT g.monto, DATEDIFF(CURDATE(), g.fecha) dias FROM gastos g
                 WHERE g.estatus_pago IN ('pendiente','parcial')");
$pendTotal = 0.0; $tramos = ['0-30'=>0.0, '31-60'=>0.0, '61-90'=>0.0, '90+'=>0.0];
foreach ($pend as $r) {
    $m = (float)$r['monto']; $d = (int)$r['dias']; $pendTotal += $m;
    if ($d <= 30) $tramos['0-30'] += $m; elseif ($d <= 60) $tramos['31-60'] += $m;
    elseif ($d <= 90) $tramos['61-90'] += $m; else $tramos['90+'] += $m;
}

// --- Sin comprobante -------------------------------------------------------
$sc = db_one("SELECT COUNT(*) n, COALESCE(SUM(monto),0) v,
                     SUM(CASE WHEN recurrente_id IS NOT NULL THEN 1 ELSE 0 END) fijos,
                     SUM(CASE WHEN recurrente_id IS NULL THEN 1 ELSE 0 END) cap
                FROM gastos g
               WHERE $w AND (g.archivo_url IS NULL OR g.archivo_url = '')
                     AND (g.cfdi_xml_url IS NULL OR g.cfdi_xml_url = '')
                     AND (g.uuid IS NULL OR g.uuid = '')", $p);
$scN = (int)($sc['n'] ?? 0); $scV = (float)($sc['v'] ?? 0);
$scFijos = (int)($sc['fijos'] ?? 0); $scCap = (int)($sc['cap'] ?? 0);

// --- Gastos fijos ----------------------------------------------------------
$recs = db_all("SELECT r.* FROM gastos_recurrentes r WHERE r.activo = 1");
$fComp = 0.0; $fGen = 0.0;
foreach ($recs as $r) {
    $ocur = 0;
    for ($m2 = 1; $m2 <= 12; $m2++) {
        if ($mes && $m2 !== $mes) continue;
        if (recurrente_aplica_mes($r, $anio, $m2)) $ocur++;
    }
    $fComp += (float)$r['monto'] * $ocur;
    $fGen  += (float)(db_one("SELECT COALESCE(SUM(monto),0) v FROM gastos
                               WHERE recurrente_id=:r AND anio=:a" . ($mes ? ' AND mes=:m' : '')
                              . " AND estatus_pago<>'cancelado'",
                             ['r'=>(int)$r['id']] + $p)['v'] ?? 0);
}
$fPend = max(0.0, $fComp - $fGen);
$fijoPct = $gTotal > 0 ? ($fGen / $gTotal) * 100 : 0;

// --- Insumos por clasificar (solo si el módulo está instalado) -------------
$insPend = modulo_insumos_instalado() ? insumos_pendientes_total() : 0;

// ---------------------------------------------------------------------------
//  PDF
// ---------------------------------------------------------------------------
if (input('export') === 'pdf') {
    $pdf = pdf_reporte('Resumen general', $periodo);

    $pdf->kpis([
        ['Gasto del periodo', '$' . number_format($gTotal, 2), $gCount . ' gastos'],
        ['Contra ' . $anioAnt, $varPct === null ? 'Sin comparativo' : (($varPct >= 0 ? '+' : '') . number_format($varPct, 1) . '%'),
         $gAnt > 0 ? '$' . number_format($gAnt, 2) . ' el año pasado' : ''],
        ['Promedio mensual', '$' . number_format($promedioMes, 2), $mesesConGasto . ' meses con gasto'],
        ['Promedio por gasto', '$' . number_format($gCount ? $gTotal / $gCount : 0, 2)],
    ]);
    $pdf->kpis([
        ['Por pagar (a hoy)', '$' . number_format($pendTotal, 2), count($pend) . ' gastos'],
        ['Sin comprobante', number_format($scN, 0), '$' . number_format($scV, 2)],
        ['Gastos fijos generados', '$' . number_format($fGen, 2), number_format($fijoPct, 1) . '% del gasto'],
        ['Falta por generar', '$' . number_format($fPend, 2), 'de los gastos fijos'],
    ]);

    if (!$mes) $pdf->bloqueMeses('Cómo se movió el gasto en ' . $anio, $serie);

    $pdf->bloqueRanking('Gasto por categoría',
        array_map(fn($r) => [(string)$r['nombre'], (float)$r['v']], array_slice($cats, 0, 10)), true, 'Categoría');

    $pdf->bloqueRanking('Gasto por área',
        array_map(fn($r) => [(string)$r['nombre'], (float)$r['v']], array_slice($areas, 0, 10)), true, 'Área');

    $pdf->bloqueRanking('Proveedores con más compra',
        array_map(fn($r) => [(string)$r['prov'], (float)$r['v']], $provs), true, 'Proveedor');

    $pdf->bloqueRanking('Productos con más gasto',
        array_map(fn($r) => [(string)$r['prod'], (float)$r['v']], $productos), true, 'Producto');

    $pdf->seccion('Pendientes de pago por antigüedad');
    $pdf->tabla([
        ['t'=>'Antigüedad','w'=>2], ['t'=>'Importe','w'=>1.4,'a'=>'r','f'=>'money'],
        ['t'=>'% de lo que se debe','w'=>1.2,'a'=>'r','f'=>'pct'],
    ], array_map(fn($k, $v) => [$k . ' días', $v, $pendTotal > 0 ? ($v / $pendTotal) * 100 : 0],
                 array_keys($tramos), array_values($tramos)),
       ['TOTAL', $pendTotal, $pendTotal > 0 ? 100.0 : 0.0]);

    $pdf->seccion('Control de comprobantes');
    $pdf->tabla([
        ['t'=>'Concepto','w'=>2.4], ['t'=>'Gastos','w'=>1,'a'=>'r','f'=>'int'], ['t'=>'Importe','w'=>1.4,'a'=>'r','f'=>'money'],
    ], [
        ['Gastos del periodo', $gCount, $gTotal],
        ['Sin comprobante (total)', $scN, $scV],
        ['  · nacidos de un gasto fijo', $scFijos, ''],
        ['  · capturados a mano', $scCap, ''],
    ]);
    if ($scCap > 0) {
        $pdf->parrafo('Hay ' . $scCap . ' gasto' . ($scCap === 1 ? '' : 's') . ' capturado'
            . ($scCap === 1 ? '' : 's') . ' a mano sin comprobante. Esos son los que hay que revisar: '
            . 'los de gastos fijos nacen sin nota a propósito.');
    }

    $pdf->descargar(pdf_nombre('resumen_general_' . $anio . ($mes ? '_' . $mes : '')));
}

// ---------------------------------------------------------------------------
//  Pantalla
// ---------------------------------------------------------------------------
$anio_actual   = (int) date('Y');
$qs            = 'anio=' . $anio . '&mes=' . $mes;
$titulo_pagina = 'Resumen general';
$pagina_activa = 'reportes';
require __DIR__ . '/../config/header.php';

$fm  = fn($v) => '$' . number_format((float)$v, 2);
$max = fn(array $l, string $k) => $l ? max(array_map(fn($x) => (float)$x[$k], $l)) : 1.0;
?>
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
  <div>
    <a href="<?= url('reportes/reportes.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-1">
      <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Reportes</a>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Resumen general</h2>
    <p class="text-xs text-zinc-500 mt-0.5"><?= e($periodo) ?> · lo esencial de todos los reportes en una hoja.</p>
  </div>
  <div class="flex flex-wrap items-end gap-2">
    <form method="get" class="flex gap-2">
      <select name="anio" onchange="this.form.submit()" class="h-9 px-2.5 rounded-lg border border-zinc-300 text-sm">
        <?php for ($y=$anio_actual+1; $y>=$anio_actual-4; $y--): ?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
      </select>
      <select name="mes" onchange="this.form.submit()" class="h-9 px-2.5 rounded-lg border border-zinc-300 text-sm">
        <option value="0">Todo el año</option>
        <?php foreach ($mesesN as $mk => $mv): ?><option value="<?= $mk ?>" <?= $mes===$mk?'selected':'' ?>><?= e($mv) ?></option><?php endforeach; ?>
      </select>
    </form>
    <?= botones_export('reporte_resumen.php', $qs, false) ?>
  </div>
</div>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
  <div class="bg-white rounded-2xl border border-marca-200 shadow-sm p-4" style="background:linear-gradient(150deg, rgba(124,58,237,.07), #fff)">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Gasto del periodo</div>
    <div class="font-display text-2xl font-extrabold text-marca-700 tabular-nums mt-1"><?= $fm($gTotal) ?></div>
    <div class="text-[11px] text-zinc-400 mt-0.5"><?= $gCount ?> gasto<?= $gCount===1?'':'s' ?>
      <?php if ($varPct !== null): ?>
        · <span style="color:<?= $varPct > 0 ? EST_BAD : EST_OK ?>"><?= $varPct>0?'▲':'▼' ?> <?= number_format(abs($varPct),1) ?>% vs <?= $anioAnt ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Promedio mensual</div>
    <div class="font-display text-2xl font-extrabold text-zinc-800 tabular-nums mt-1"><?= $fm($promedioMes) ?></div>
    <div class="text-[11px] text-zinc-400 mt-0.5">sobre <?= $mesesConGasto ?> mes<?= $mesesConGasto===1?'':'es' ?> con gasto</div>
  </div>
  <div class="bg-white rounded-2xl border shadow-sm p-4" style="border-color:<?= EST_BAD ?>44;background:<?= EST_BAD ?>08">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Por pagar (a hoy)</div>
    <div class="font-display text-2xl font-extrabold tabular-nums mt-1" style="color:<?= EST_BAD ?>"><?= $fm($pendTotal) ?></div>
    <div class="text-[11px] text-zinc-400 mt-0.5"><?= count($pend) ?> gasto<?= count($pend)===1?'':'s' ?></div>
  </div>
  <div class="bg-white rounded-2xl border shadow-sm p-4" style="border-color:<?= $scN ? EST_WARN.'44' : '#e4e4e7' ?>;background:<?= $scN ? EST_WARN.'08' : '#fff' ?>">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Sin comprobante</div>
    <div class="font-display text-2xl font-extrabold tabular-nums mt-1" style="color:<?= $scN ? EST_WARN : '#3f3f46' ?>"><?= $scN ?></div>
    <div class="text-[11px] text-zinc-400 mt-0.5"><?= $fm($scV) ?> · <?= $scCap ?> capturado<?= $scCap===1?'':'s' ?> a mano</div>
  </div>
</div>

<?php if (!$mes): ?>
<!-- Gasto por mes -->
<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5 mb-4">
  <div class="flex items-center justify-between mb-4">
    <h3 class="font-display font-bold text-sm text-zinc-800">Gasto por mes · <?= $anio ?></h3>
    <a href="<?= url('historico.php?anio='.$anio) ?>" class="text-xs font-semibold text-marca-700 hover:underline">Ver histórico</a>
  </div>
  <?php $maxS = max(1.0, max($serie)); $mesHoy = ((int)date('Y') === $anio) ? (int)date('n') : 0; ?>
  <div class="flex items-end gap-1.5" style="height:160px">
    <?php foreach (range(1,12) as $m2): $v = $serie[$m2];
      // Altura en píxeles, no en porcentaje: dentro de un flex el porcentaje
      // no siempre resuelve y las barras se quedan invisibles
      $h = max(2, ($v / $maxS) * 130);
    ?>
      <div class="flex-1 flex flex-col items-center justify-end h-full group">
        <div class="text-[9px] text-zinc-400 mb-1 tabular-nums opacity-0 group-hover:opacity-100 transition whitespace-nowrap">
          <?= $v > 0 ? '$'.number_format($v/1000,1).'k' : '' ?></div>
        <div class="w-full rounded-t transition-all"
             style="height:<?= $h ?>px;background:<?= $m2===$mesHoy ? tono(600) : ($v>0 ? tono(400) : '#f4f4f5') ?>"
             title="<?= e($mesesN[$m2]) ?>: <?= $fm($v) ?>"></div>
        <div class="text-[10px] mt-1.5 text-zinc-400"><?= mb_substr($mesesN[$m2],0,3) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
  <?php
  $bloques = [
    ['Categorías', 'tags', $cats, 'nombre', 'v', 'reportes/reporte_por_categoria.php?anio='.$anio],
    ['Áreas', 'layout-grid', $areas, 'nombre', 'v', 'reportes/reporte_por_area.php?anio='.$anio],
    ['Proveedores', 'truck', $provs, 'prov', 'v', 'reportes/reporte_gastos.php?'.$qs],
    ['Productos', 'package', $productos, 'prod', 'v', 'reportes/reporte_por_producto.php?anio='.$anio],
  ];
  foreach ($bloques as [$tit, $ico, $lista, $kNom, $kVal, $liga]):
    $mx = $max($lista, $kVal);
  ?>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-display font-bold text-sm text-zinc-800 inline-flex items-center gap-1.5">
        <i data-lucide="<?= $ico ?>" class="w-4 h-4 text-marca-600"></i><?= e($tit) ?></h3>
      <a href="<?= url($liga) ?>" class="text-xs font-semibold text-marca-700 hover:underline">Ver detalle</a>
    </div>
    <?php if (!$lista): ?>
      <p class="text-sm text-zinc-400 py-4 text-center">Sin datos en el periodo.</p>
    <?php else: ?>
      <div class="space-y-2">
      <?php foreach (array_slice($lista, 0, 6) as $r): $v=(float)$r[$kVal]; ?>
        <div>
          <div class="flex items-baseline justify-between gap-2 text-sm">
            <span class="text-zinc-700 truncate"><?= e((string)$r[$kNom]) ?></span>
            <span class="font-semibold text-zinc-800 tabular-nums flex-shrink-0"><?= $fm($v) ?></span>
          </div>
          <div class="mt-1 h-1.5 bg-zinc-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full" style="width:<?= $mx>0 ? ($v/$mx)*100 : 0 ?>%;background:<?= tono(600) ?>"></div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <?php if (count($lista) > 6): ?>
        <p class="text-[11px] text-zinc-400 mt-3"><?= count($lista)-6 ?> más en el reporte completo.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
  <!-- Pendientes -->
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-display font-bold text-sm text-zinc-800">Por pagar, por antigüedad</h3>
      <a href="<?= url('reportes/reporte_pendientes.php') ?>" class="text-xs font-semibold text-marca-700 hover:underline">Ver</a>
    </div>
    <?php foreach ($tramos as $etq => $mto): ?>
      <div class="flex items-center justify-between py-1.5 border-b border-zinc-100 last:border-0">
        <span class="text-sm text-zinc-600"><?= $etq ?> días</span>
        <span class="text-sm font-semibold tabular-nums" style="color:<?= $etq==='90+' ? EST_BAD : ($etq==='61-90' ? EST_WARN : '#3f3f46') ?>"><?= $fm($mto) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Comprobantes -->
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-display font-bold text-sm text-zinc-800">Comprobantes</h3>
      <a href="<?= url('reportes/reporte_sin_comprobante.php?'.$qs) ?>" class="text-xs font-semibold text-marca-700 hover:underline">Ver</a>
    </div>
    <div class="space-y-1.5 text-sm">
      <div class="flex justify-between"><span class="text-zinc-600">Gastos del periodo</span><span class="font-semibold tabular-nums"><?= $gCount ?></span></div>
      <div class="flex justify-between"><span class="text-zinc-600">Sin comprobante</span>
        <span class="font-semibold tabular-nums" style="color:<?= $scN ? EST_WARN : EST_OK ?>"><?= $scN ?></span></div>
      <div class="flex justify-between text-xs text-zinc-400"><span>· de gastos fijos</span><span><?= $scFijos ?></span></div>
      <div class="flex justify-between text-xs text-zinc-400"><span>· capturados a mano</span><span><?= $scCap ?></span></div>
      <?php if ($insPend > 0): ?>
        <div class="flex justify-between pt-2 mt-2 border-t border-zinc-100">
          <a href="<?= url('admin/insumos.php') ?>" class="text-zinc-600 hover:text-marca-700">Insumos por clasificar</a>
          <span class="font-semibold tabular-nums" style="color:<?= EST_WARN ?>"><?= $insPend ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Gastos fijos -->
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-display font-bold text-sm text-zinc-800">Gastos fijos</h3>
      <a href="<?= url('reportes/reporte_gastos_fijos.php?anio='.$anio) ?>" class="text-xs font-semibold text-marca-700 hover:underline">Ver</a>
    </div>
    <div class="space-y-1.5 text-sm">
      <div class="flex justify-between"><span class="text-zinc-600">Comprometido</span><span class="font-semibold tabular-nums"><?= $fm($fComp) ?></span></div>
      <div class="flex justify-between"><span class="text-zinc-600">Ya generado</span><span class="font-semibold tabular-nums"><?= $fm($fGen) ?></span></div>
      <div class="flex justify-between"><span class="text-zinc-600">Falta</span><span class="font-semibold tabular-nums" style="color:<?= EST_WARN ?>"><?= $fm($fPend) ?></span></div>
      <div class="text-xs text-zinc-400 pt-2 mt-2 border-t border-zinc-100">
        Los gastos fijos son <?= number_format($fijoPct,1) ?>% de lo gastado en el periodo.</div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../config/footer.php'; ?>
