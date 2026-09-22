<?php
/** dashboard.php - Tablero de gasto operativo */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/tema.php';
require_once __DIR__ . '/config/gastos_helpers.php';
require_once __DIR__ . '/config/controles_helpers.php';
requerir_login();

$puede_capturar = tiene_permiso('administrar') || tiene_permiso('crear_solicitud');
$hoy   = date('Y-m-d');
$anio  = (int) date('Y');
$mes   = (int) date('n');
$mesAnt  = $mes === 1 ? 12 : $mes - 1;
$anioAnt = $mes === 1 ? $anio - 1 : $anio;

$sinCancelar = "estatus_pago <> 'cancelado'";

$val = fn(?array $r) => (float) ($r['v'] ?? 0);

$gastoMes    = $val(db_one("SELECT COALESCE(SUM(monto),0) v FROM gastos WHERE anio=:a AND mes=:m AND $sinCancelar", ['a'=>$anio,'m'=>$mes]));
$gastoMesAnt = $val(db_one("SELECT COALESCE(SUM(monto),0) v FROM gastos WHERE anio=:a AND mes=:m AND $sinCancelar", ['a'=>$anioAnt,'m'=>$mesAnt]));
$gastoAnio   = $val(db_one("SELECT COALESCE(SUM(monto),0) v FROM gastos WHERE anio=:a AND $sinCancelar", ['a'=>$anio]));
$gastoAnioAnt= $val(db_one("SELECT COALESCE(SUM(monto),0) v FROM gastos WHERE anio=:a AND $sinCancelar", ['a'=>$anio-1]));
$porPagar    = $val(db_one("SELECT COALESCE(SUM(monto),0) v FROM gastos WHERE estatus_pago IN ('pendiente','parcial')"));
$nPorPagar   = (int) (db_one("SELECT COUNT(*) v FROM gastos WHERE estatus_pago IN ('pendiente','parcial')")['v'] ?? 0);

// El tablero SIEMPRE es del año en curso. Si hay gastos capturados pero en
// otros años, sin este aviso el tablero se ve "roto" (todo en $0.00) cuando en
// realidad la fecha del gasto es la que está fuera de lugar.
$fueraDeAnio = null;
if ($gastoAnio <= 0) {
    $otro = db_one("SELECT COUNT(*) n, MAX(fecha) ult FROM gastos WHERE anio <> :a AND $sinCancelar", ['a'=>$anio]);
    if ((int)($otro['n'] ?? 0) > 0) $fueraDeAnio = $otro;
}

$varMes  = $gastoMesAnt > 0 ? (($gastoMes - $gastoMesAnt) / $gastoMesAnt) * 100 : null;
$varAnio = $gastoAnioAnt > 0 ? (($gastoAnio - $gastoAnioAnt) / $gastoAnioAnt) * 100 : null;

// Serie de 12 meses
$serie = array_fill(1, 12, 0.0);
foreach (db_all("SELECT mes, SUM(monto) v FROM gastos WHERE anio=:a AND $sinCancelar GROUP BY mes", ['a'=>$anio]) as $r) {
    $serie[(int)$r['mes']] = (float)$r['v'];
}
$maxSerie = max(1.0, max($serie));

$topCats = db_all("SELECT c.nombre, c.color, SUM(g.monto) v FROM gastos g
                   INNER JOIN categorias_gasto c ON c.id=g.categoria_id
                   WHERE g.anio=:a AND g.$sinCancelar GROUP BY c.id, c.nombre, c.color
                   ORDER BY v DESC LIMIT 6", ['a'=>$anio]);
$totalTopCats = 0.0; foreach ($topCats as $t) $totalTopCats += (float)$t['v'];

$topProv = db_all("SELECT COALESCE(p.nombre, g.proveedor_texto, '(sin proveedor)') nom, SUM(g.monto) v, COUNT(*) n
                     FROM gastos g LEFT JOIN proveedores p ON p.id=g.proveedor_id
                    WHERE g.anio=:a AND g.$sinCancelar GROUP BY nom ORDER BY v DESC LIMIT 5", ['a'=>$anio]);

$topAreas = db_all("SELECT a.nombre, SUM(v.monto) val FROM vista_gasto_area v
                    INNER JOIN areas a ON a.id=v.area_id
                    WHERE v.anio=:a AND v.estatus_pago <> 'cancelado'
                    GROUP BY a.id, a.nombre ORDER BY val DESC LIMIT 6", ['a'=>$anio]);
$maxArea = 0.0; foreach ($topAreas as $t) if ((float)$t['val'] > $maxArea) $maxArea = (float)$t['val'];

$ultimos = db_all("SELECT g.id, g.folio, g.fecha, g.concepto, g.monto, g.estatus_pago,
                          a.nombre area, c.nombre cat, c.color catcolor
                     FROM gastos g
                     INNER JOIN areas a ON a.id=g.area_id
                     INNER JOIN categorias_gasto c ON c.id=g.categoria_id
                    ORDER BY g.creado_en DESC, g.id DESC LIMIT 8");

$pendientes = db_all("SELECT g.id, g.folio, g.fecha, g.concepto, g.monto, g.estatus_pago,
                             COALESCE(p.nombre, g.proveedor_texto) prov
                        FROM gastos g LEFT JOIN proveedores p ON p.id=g.proveedor_id
                       WHERE g.estatus_pago IN ('pendiente','parcial')
                       ORDER BY g.fecha ASC LIMIT 6");

$mesesC = meses_cortos();
$fm  = fn($v) => '$' . number_format($v, 2);
$fm0 = fn($v) => '$' . number_format($v, 0);

$titulo_pagina = 'Tablero';
$pagina_activa = 'dashboard';
require __DIR__ . '/config/header.php';
?>
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
  <div>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Tablero</h2>
    <p class="text-xs text-zinc-500 mt-0.5"><?= e(meses_nombres()[$mes]) ?> <?= $anio ?> · <?= e(SUCURSAL_NOMBRE) ?></p>
  </div>
  <?php if($puede_capturar): ?>
  <a href="<?= url('gasto_form.php') ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm">
    <i data-lucide="plus" class="w-4 h-4"></i> Nuevo gasto</a>
  <?php endif; ?>
</div>

<?php if ($fueraDeAnio): ?>
<div class="mb-4 border-l-4 rounded-lg px-4 py-3 text-sm flex items-start gap-2"
     style="border-color:<?= EST_WARN ?>;background:<?= EST_WARN ?>0f;color:<?= EST_WARN ?>">
  <i data-lucide="calendar-x" class="w-4 h-4 mt-0.5 flex-shrink-0"></i>
  <div>
    <b>El tablero solo muestra <?= $anio ?>, y no hay gastos con fecha de este año.</b>
    Hay <?= (int)$fueraDeAnio['n'] ?> gasto<?= (int)$fueraDeAnio['n']===1?'':'s' ?> capturado<?= (int)$fueraDeAnio['n']===1?'':'s' ?>
    con fecha de otro año (el más reciente, <?= fmt_fecha($fueraDeAnio['ult'], false) ?>).
    Si te equivocaste de fecha al capturar, corrígela en el gasto y aparecerá aquí.
    <a href="<?= url('gastos.php') ?>" class="font-semibold underline">Ver los gastos</a>.
  </div>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
  <div class="bg-white rounded-2xl border border-marca-200 shadow-sm p-4" style="background:linear-gradient(150deg, rgba(124,58,237,.07), #fff)">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Gasto del mes</div>
    <div class="font-display text-2xl font-extrabold text-marca-700 tabular-nums mt-1"><?= $fm($gastoMes) ?></div>
    <?php if ($varMes === null): ?>
      <div class="text-[11px] text-zinc-400 mt-0.5">Sin comparativo</div>
    <?php else: $sube = $varMes >= 0; ?>
      <div class="text-[11px] font-semibold mt-0.5" style="color:<?= $sube ? EST_BAD : EST_OK ?>">
        <?= $sube ? '▲' : '▼' ?> <?= number_format(abs($varMes),1) ?>% vs <?= e($mesesC[$mesAnt]) ?></div>
    <?php endif; ?>
  </div>

  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Acumulado <?= $anio ?></div>
    <div class="font-display text-2xl font-extrabold text-zinc-800 tabular-nums mt-1"><?= $fm($gastoAnio) ?></div>
    <?php if ($varAnio === null): ?>
      <div class="text-[11px] text-zinc-400 mt-0.5"><?= count(array_filter($serie, fn($v)=>$v>0)) ?> meses con gasto</div>
    <?php else: $sube = $varAnio >= 0; ?>
      <div class="text-[11px] font-semibold mt-0.5" style="color:<?= $sube ? EST_BAD : EST_OK ?>">
        <?= $sube ? '▲' : '▼' ?> <?= number_format(abs($varAnio),1) ?>% vs <?= $anio-1 ?></div>
    <?php endif; ?>
  </div>

  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Por pagar</div>
    <div class="font-display text-2xl font-extrabold tabular-nums mt-1"
         style="color:<?= $porPagar > 0 ? EST_BAD : '#3f3f46' ?>"><?= $fm($porPagar) ?></div>
    <div class="text-[11px] text-zinc-400 mt-0.5"><?= $nPorPagar ?> <?= $nPorPagar===1?'gasto':'gastos' ?></div>
  </div>

  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Promedio mensual</div>
    <?php $nm = count(array_filter($serie, fn($v)=>$v>0)); ?>
    <div class="font-display text-2xl font-extrabold text-zinc-800 tabular-nums mt-1"><?= $fm($nm ? $gastoAnio/$nm : 0) ?></div>
    <div class="text-[11px] text-zinc-400 mt-0.5">sobre <?= $nm ?> <?= $nm===1?'mes':'meses' ?></div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">

  <!-- Serie mensual -->
  <div class="lg:col-span-2 bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
    <div class="flex items-center justify-between mb-4">
      <h3 class="font-display font-bold text-sm text-zinc-800">Gasto por mes · <?= $anio ?></h3>
      <a href="<?= url('historico.php?anio='.$anio) ?>" class="text-xs font-semibold text-marca-700 hover:underline">Ver histórico</a>
    </div>
    <?php if ($gastoAnio <= 0): ?>
      <div class="py-12 text-center text-sm text-zinc-400">Todavía no hay gastos en <?= $anio ?>.</div>
    <?php else: ?>
      <div class="flex items-end gap-1.5" style="height:160px">
        <?php for ($m=1;$m<=12;$m++):
          $v = $serie[$m];
          $h = $maxSerie > 0 ? max(2, ($v / $maxSerie) * 140) : 2;
          $esActual = ($m === $mes);
        ?>
          <div class="flex-1 flex flex-col items-center justify-end h-full group">
            <div class="text-[9px] text-zinc-400 mb-1 tabular-nums opacity-0 group-hover:opacity-100 transition whitespace-nowrap">
              <?= $v > 0 ? $fm0($v) : '' ?></div>
            <div class="w-full rounded-t transition-all"
                 style="height:<?= $h ?>px;background:<?= $esActual ? tono(600) : ($v>0 ? tono(200) : '#f4f4f5') ?>"
                 title="<?= e($mesesC[$m]) ?>: <?= $fm($v) ?>"></div>
            <div class="text-[10px] mt-1.5 <?= $esActual ? 'font-bold text-marca-700' : 'text-zinc-400' ?>"><?= $mesesC[$m] ?></div>
          </div>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Categorías -->
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
    <h3 class="font-display font-bold text-sm text-zinc-800 mb-4">Categorías · <?= $anio ?></h3>
    <?php if (!$topCats): ?>
      <div class="py-8 text-center text-sm text-zinc-400">Sin datos.</div>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($topCats as $t): $p = $totalTopCats>0 ? ((float)$t['v']/$totalTopCats)*100 : 0; ?>
        <div>
          <div class="flex items-center justify-between text-sm mb-1">
            <span class="inline-flex items-center gap-1.5 min-w-0">
              <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= e($t['color']) ?>"></span>
              <span class="text-zinc-700 truncate"><?= e($t['nombre']) ?></span></span>
            <span class="text-zinc-800 font-semibold tabular-nums ml-2 flex-shrink-0"><?= $fm0((float)$t['v']) ?></span>
          </div>
          <div class="h-1.5 bg-zinc-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full" style="width:<?= $p ?>%;background:<?= e($t['color']) ?>"></div></div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">

  <!-- Áreas -->
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
    <h3 class="font-display font-bold text-sm text-zinc-800 mb-4">Gasto por área · <?= $anio ?></h3>
    <?php if (!$topAreas): ?>
      <div class="py-8 text-center text-sm text-zinc-400">Sin datos.</div>
    <?php else: ?>
      <div class="space-y-2.5">
        <?php foreach ($topAreas as $t): $p = $maxArea>0 ? ((float)$t['val']/$maxArea)*100 : 0; ?>
        <div class="flex items-center gap-3">
          <span class="text-sm text-zinc-600 w-28 truncate flex-shrink-0"><?= e($t['nombre']) ?></span>
          <div class="flex-1 h-5 bg-zinc-50 rounded overflow-hidden">
            <div class="h-full rounded" style="width:<?= $p ?>%;background:<?= tono(400) ?>"></div></div>
          <span class="text-sm text-zinc-800 font-semibold tabular-nums w-24 text-right flex-shrink-0"><?= $fm0((float)$t['val']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <p class="text-[11px] text-zinc-400 mt-3">Los gastos repartidos se cuentan en cada área según su porcentaje.</p>
    <?php endif; ?>
  </div>

  <!-- Proveedores -->
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5">
    <h3 class="font-display font-bold text-sm text-zinc-800 mb-4">Principales proveedores · <?= $anio ?></h3>
    <?php if (!$topProv): ?>
      <div class="py-8 text-center text-sm text-zinc-400">Sin datos.</div>
    <?php else: ?>
      <table class="w-full text-sm">
        <tbody class="divide-y divide-zinc-100">
        <?php foreach ($topProv as $t): ?>
          <tr><td class="py-2 text-zinc-700"><?= e($t['nom']) ?></td>
              <td class="py-2 text-right text-zinc-400 text-xs"><?= (int)$t['n'] ?></td>
              <td class="py-2 text-right text-zinc-800 font-semibold tabular-nums"><?= $fm0((float)$t['v']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

  <!-- Pendientes de pago -->
  <?php if ($pendientes): ?>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-zinc-200 flex items-center gap-2">
      <i data-lucide="alert-circle" class="w-4 h-4" style="color:<?= EST_BAD ?>"></i>
      <h3 class="font-display font-bold text-sm text-zinc-800 flex-1">Pendientes de pago</h3>
      <a href="<?= url('gastos.php?anio='.$anio.'&estatus=pendiente') ?>" class="text-xs font-semibold text-marca-700 hover:underline">Ver todos</a>
    </div>
    <div class="divide-y divide-zinc-100">
      <?php foreach ($pendientes as $p): ?>
      <a href="<?= url('gasto_ver.php?id='.(int)$p['id']) ?>" class="flex items-center gap-3 px-5 py-2.5 hover:bg-zinc-50">
        <div class="flex-1 min-w-0">
          <div class="text-sm text-zinc-800 truncate"><?= e($p['concepto']) ?></div>
          <div class="text-[11px] text-zinc-400"><?= fmt_fecha($p['fecha'], false) ?><?= $p['prov'] ? ' · '.e($p['prov']) : '' ?></div>
        </div>
        <?= badge_estatus_pago($p['estatus_pago']) ?>
        <span class="text-sm font-semibold text-zinc-800 tabular-nums flex-shrink-0"><?= $fm((float)$p['monto']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Últimos capturados -->
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden <?= $pendientes ? '' : 'lg:col-span-2' ?>">
    <div class="px-5 py-3 border-b border-zinc-200 flex items-center justify-between">
      <h3 class="font-display font-bold text-sm text-zinc-800">Últimos gastos capturados</h3>
      <a href="<?= url('gastos.php') ?>" class="text-xs font-semibold text-marca-700 hover:underline">Ver todos</a>
    </div>
    <div class="divide-y divide-zinc-100">
      <?php if (!$ultimos): ?>
        <div class="px-5 py-10 text-center text-sm text-zinc-400">
          Aún no hay gastos capturados.
          <?php if($puede_capturar): ?><a href="<?= url('gasto_form.php') ?>" class="text-marca-700 font-semibold">Registra el primero</a>.<?php endif; ?>
        </div>
      <?php else: foreach ($ultimos as $u): ?>
        <a href="<?= url('gasto_ver.php?id='.(int)$u['id']) ?>" class="flex items-center gap-3 px-5 py-2.5 hover:bg-zinc-50">
          <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= e($u['catcolor']) ?>"></span>
          <div class="flex-1 min-w-0">
            <div class="text-sm text-zinc-800 truncate"><?= e($u['concepto']) ?></div>
            <div class="text-[11px] text-zinc-400"><?= fmt_fecha($u['fecha'], false) ?> · <?= e($u['area']) ?> · <?= e($u['cat']) ?></div>
          </div>
          <span class="text-sm font-semibold text-zinc-800 tabular-nums flex-shrink-0"><?= $fm((float)$u['monto']) ?></span>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/config/footer.php'; ?>
