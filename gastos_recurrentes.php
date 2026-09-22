<?php
/** gastos_recurrentes.php - Gastos fijos recurrentes: plantillas y generación por mes */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/gastos_helpers.php';
require_once __DIR__ . '/config/tema.php';
require_once __DIR__ . '/config/recurrentes_helpers.php';
requerir_login();
// Vuelve a ser su propio modulo: SIG-GO ya no tiene Plan Anual.
$puede_capturar = tiene_permiso('administrar') || tiene_permiso('crear_solicitud');

$meses_nom = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada, intenta de nuevo.'); header('Location: '.url('gastos_recurrentes.php')); exit; }
    if (!$puede_capturar) { flash_set('error','No tienes permiso para esta acción.'); header('Location: '.url('gastos_recurrentes.php')); exit; }
    $accion = input('accion');
    if ($accion === 'generar') {
        $ga = (int) input('gen_anio') ?: (int) date('Y');
        $gm = (int) input('gen_mes')  ?: (int) date('n');
        if ($gm < 1 || $gm > 12) $gm = (int) date('n');
        $res = generar_gastos_recurrentes($ga, $gm, (int)(usuario_actual()['id'] ?? 0) ?: null);
        registrar_auditoria('generar','gastos_recurrentes',0,"Generó recurrentes {$meses_nom[$gm]} $ga: {$res['creados']} creados");
        $mesnom = $meses_nom[$gm];
        if (!empty($res['cerrado'])) {
            flash_set('warn', "El mes de {$mesnom} $ga esta cerrado. Reabrelo en Cierre de mes para generar.");
        } elseif ($res['creados'] > 0) {
            flash_set('success', "Se generaron {$res['creados']} gasto(s) fijo(s) de {$mesnom} $ga." . ($res['omitidos']>0 ? " ({$res['omitidos']} ya existían.)" : ''));
        } elseif ($res['programados'] > 0) {
            flash_set('info', "No había nada nuevo: los {$res['omitidos']} gasto(s) programado(s) de {$mesnom} $ga ya estaban generados.");
        } else {
            flash_set('info', "Ningún gasto fijo aplica para {$mesnom} $ga.");
        }
        header('Location: '.url('gastos_recurrentes.php?gen_anio='.$ga.'&gen_mes='.$gm)); exit;
    }
    if ($accion === 'toggle') {
        $rid = (int) input('id');
        $rec = db_one("SELECT id, activo, concepto FROM gastos_recurrentes WHERE id=:id", ['id'=>$rid]);
        if ($rec) {
            $nuevo = ((int)$rec['activo']) ? 0 : 1;
            db_exec("UPDATE gastos_recurrentes SET activo=:a WHERE id=:id", ['a'=>$nuevo,'id'=>$rid]);
            registrar_auditoria('editar','gastos_recurrentes',$rid,($nuevo?'Activó':'Desactivó')." gasto fijo {$rec['concepto']}");
            flash_set('success', $nuevo ? 'Gasto fijo activado.' : 'Gasto fijo desactivado.');
        }
        header('Location: '.url('gastos_recurrentes.php')); exit;
    }
}

$anio_actual = (int) date('Y');
$mes_actual  = (int) date('n');
$gen_anio = (int) (input('gen_anio') ?: $anio_actual);
$gen_mes  = (int) (input('gen_mes')  ?: $mes_actual);
if ($gen_mes < 1 || $gen_mes > 12) $gen_mes = $mes_actual;

$rows = db_all("SELECT r.*, d.nombre AS dep, c.nombre AS cat, c.color AS catcolor, pr.nombre AS prov
                FROM gastos_recurrentes r
                INNER JOIN areas d ON r.area_id=d.id
                INNER JOIN categorias_gasto c ON r.categoria_id=c.id
                LEFT JOIN proveedores pr ON r.proveedor_id=pr.id
                ORDER BY r.activo DESC, d.nombre, r.concepto");

$comprometido = recurrentes_comprometido_restante($anio_actual, null, $mes_actual + 1);
$mensual_activo = 0.0;
foreach ($rows as $rr) { if ((int)$rr['activo'] && recurrente_aplica_mes($rr, $anio_actual, $mes_actual)) $mensual_activo += (float)$rr['monto']; }

$titulo_pagina = 'Gastos fijos';
$pagina_activa = 'recurrentes';
require __DIR__ . '/config/header.php';
?>
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
  <div>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Gastos fijos recurrentes</h2>
    <p class="text-xs text-zinc-500 mt-0.5">Plantillas de gastos que se repiten. Genéralos como gastos reales del mes con un clic.</p>
  </div>
  <?php if($puede_capturar): ?><a href="<?= url('gasto_recurrente_form.php') ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm"><i data-lucide="plus" class="w-4 h-4"></i> Nuevo gasto fijo</a><?php endif; ?>
</div>

<!-- Resumen + generador -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
  <div class="bg-white rounded-2xl border border-zinc-200 p-5 shadow-sm">
    <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">Fijos de <?= e($meses_nom[$mes_actual]) ?></div>
    <div class="mt-2 text-2xl font-display font-bold text-zinc-800 tabular-nums">$<?= number_format($mensual_activo,2) ?></div>
    <div class="text-xs text-zinc-400 mt-1">Suma de plantillas activas que aplican este mes.</div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 p-5 shadow-sm">
    <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">Comprometido resto de <?= $anio_actual ?></div>
    <div class="mt-2 text-2xl font-display font-bold text-marca-700 tabular-nums">$<?= number_format($comprometido,2) ?></div>
    <div class="text-xs text-zinc-400 mt-1">Recurrentes programados aún no generados.</div>
  </div>
  <?php if($puede_capturar): ?>
  <div class="bg-white rounded-2xl border border-zinc-200 p-5 shadow-sm">
    <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400 mb-2">Generar gastos del periodo</div>
    <form method="post" class="space-y-2">
      <?= csrf_input() ?><input type="hidden" name="accion" value="generar">
      <div class="flex gap-2">
        <select name="gen_mes" class="flex-1 px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm">
          <?php foreach ($meses_nom as $mk=>$mv): ?><option value="<?= $mk ?>" <?= $gen_mes===$mk?'selected':'' ?>><?= $mv ?></option><?php endforeach; ?>
        </select>
        <select name="gen_anio" class="w-24 px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm">
          <?php for ($y=$anio_actual+1;$y>=$anio_actual-2;$y--): ?><option value="<?= $y ?>" <?= $gen_anio===$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
        </select>
      </div>
      <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold"><i data-lucide="play" class="w-4 h-4"></i> Generar programados</button>
    </form>
    <p class="text-[11px] text-zinc-400 mt-2">No duplica: omite los que ya se generaron.</p>
  </div>
  <?php endif; ?>
</div>

<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-3 font-semibold">Concepto</th><th class="px-4 py-3 font-semibold">Área</th>
        <th class="px-4 py-3 font-semibold">Categoría</th><th class="px-4 py-3 font-semibold text-right">Monto</th>
        <th class="px-4 py-3 font-semibold">Frecuencia</th><th class="px-4 py-3 font-semibold">Vigencia</th>
        <th class="px-4 py-3 font-semibold text-center"><?= e($meses_nom[$mes_actual]) ?></th>
        <th class="px-4 py-3 font-semibold text-right"></th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="px-4 py-10 text-center text-zinc-400">Aún no hay gastos fijos. <?php if($puede_capturar): ?><a href="<?= url('gasto_recurrente_form.php') ?>" class="text-marca-700 font-semibold">Crea el primero</a>.<?php endif; ?></td></tr>
      <?php else: foreach ($rows as $r): $inact = !((int)$r['activo']); ?>
        <tr class="hover:bg-zinc-50 <?= $inact?'opacity-60':'' ?>">
          <td class="px-4 py-3">
            <div class="font-medium text-zinc-800"><?= e($r['concepto']) ?><?php if($inact): ?> <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-zinc-100 text-zinc-500 align-middle">Inactivo</span><?php endif; ?></div>
            <?php if ($r['prov'] || $r['proveedor_texto']): ?><div class="text-xs text-zinc-400"><?= e($r['prov'] ?: $r['proveedor_texto']) ?></div><?php endif; ?>
          </td>
          <td class="px-4 py-3 text-zinc-500"><?= e($r['dep']) ?></td>
          <td class="px-4 py-3"><span class="inline-flex items-center gap-1.5 text-zinc-600"><span class="w-2.5 h-2.5 rounded-full" style="background:<?= e($r['catcolor']) ?>"></span><?= e($r['cat']) ?></span></td>
          <td class="px-4 py-3 text-right font-semibold text-zinc-800 tabular-nums">$<?= number_format((float)$r['monto'],2) ?></td>
          <td class="px-4 py-3 text-zinc-500"><?= e(frecuencia_label($r['frecuencia'])) ?> · día <?= (int)$r['dia_mes'] ?></td>
          <td class="px-4 py-3 text-zinc-500 whitespace-nowrap text-xs"><?= fmt_fecha($r['fecha_inicio'], false) ?> → <?= $r['fecha_fin'] ? fmt_fecha($r['fecha_fin'], false) : 'sin fin' ?></td>
          <td class="px-4 py-3 text-center"><?= badge_recurrente_estado($r, $anio_actual, $mes_actual) ?></td>
          <td class="px-4 py-3 text-right whitespace-nowrap">
            <?php if($puede_capturar): ?>
            <a href="<?= url('gasto_recurrente_form.php?id='.(int)$r['id']) ?>" class="text-marca-700 hover:text-marca-800 text-sm font-semibold">Editar</a>
            <form method="post" class="inline ml-2">
              <?= csrf_input() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="text-zinc-400 hover:text-zinc-700 text-sm"><?= $inact?'Activar':'Desactivar' ?></button>
            </form>
            <?php else: ?><span class="text-zinc-300">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/config/footer.php'; ?>
