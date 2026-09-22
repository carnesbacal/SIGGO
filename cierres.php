<?php
/** cierres.php - Cierre de mes: bloquea captura/edición de gastos de meses cerrados */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/controles_helpers.php';
requerir_login();
requerir_permiso('administrar');

$meses_nom = meses_nombres();

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada, intenta de nuevo.'); header('Location: '.url('cierres.php')); exit; }
    $accion = input('accion');
    $anio = (int) input('anio') ?: (int) date('Y');
    $mes  = (int) input('mes');
    if ($mes >= 1 && $mes <= 12) {
        if ($accion === 'cerrar') {
            cerrar_mes($anio, $mes, (int)(usuario_actual()['id'] ?? 0) ?: null, trim((string)input('nota')) ?: null);
            flash_set('success', "Mes de {$meses_nom[$mes]} $anio cerrado. Ya no se podrán capturar ni editar gastos de ese mes.");
        } elseif ($accion === 'reabrir') {
            reabrir_mes($anio, $mes);
            flash_set('success', "Mes de {$meses_nom[$mes]} $anio reabierto.");
        }
    }
    header('Location: '.url('cierres.php?anio='.$anio)); exit;
}

$anio_actual = (int) date('Y');
$mes_actual  = (int) date('n');
$anio = (int) (input('anio') ?: $anio_actual);
$cerrados = meses_cerrados_anio($anio);

// Conteo y suma de gastos por mes del año
$gpm = array_fill(1, 12, ['n'=>0, 'monto'=>0.0]);
foreach (db_all("SELECT MONTH(fecha) m, COUNT(*) n, COALESCE(SUM(monto),0) s FROM gastos WHERE YEAR(fecha)=:a GROUP BY MONTH(fecha)", ['a'=>$anio]) as $r) {
    $gpm[(int)$r['m']] = ['n'=>(int)$r['n'], 'monto'=>(float)$r['s']];
}

$titulo_pagina = 'Cierre de mes';
$pagina_activa = 'cierres';
require __DIR__ . '/config/header.php';
?>
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
  <div>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Cierre de mes</h2>
    <p class="text-xs text-zinc-500 mt-0.5">Cierra un mes para congelar sus gastos: no se podrán capturar ni editar, y los gastos fijos no se generarán en ese mes hasta reabrirlo.</p>
  </div>
  <form method="get"><select name="anio" onchange="this.form.submit()" class="px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm">
    <?php for ($y=$anio_actual+1;$y>=$anio_actual-3;$y--): ?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
  </select></form>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php for ($m=1;$m<=12;$m++): $cer = isset($cerrados[$m]); $info = $gpm[$m]; $futuro = ($anio > $anio_actual) || ($anio===$anio_actual && $m > $mes_actual); ?>
  <div class="bg-white rounded-2xl border <?= $cer?'border-rosa-200':'border-zinc-200' ?> shadow-sm p-5">
    <div class="flex items-center justify-between mb-2">
      <div class="font-display font-bold text-zinc-800"><?= e($meses_nom[$m]) ?></div>
      <?php if ($cer): ?><span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-rosa-50 text-rosa-700"><i data-lucide="lock" class="w-3 h-3"></i> Cerrado</span>
      <?php else: ?><span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700"><i data-lucide="lock-open" class="w-3 h-3"></i> Abierto</span><?php endif; ?>
    </div>
    <div class="text-xs text-zinc-500 mb-3"><?= $info['n'] ?> gasto<?= $info['n']===1?'':'s' ?> · <span class="tabular-nums">$<?= number_format($info['monto'],2) ?></span></div>
    <?php if ($cer): ?>
      <div class="text-[11px] text-zinc-400 mb-3">Cerrado por <?= e($cerrados[$m]['por'] ?? '—') ?> · <?= fmt_fecha($cerrados[$m]['cerrado_en'], false) ?><?php if($cerrados[$m]['nota']): ?><br><span class="italic"><?= e($cerrados[$m]['nota']) ?></span><?php endif; ?></div>
      <form method="post"><?= csrf_input() ?><input type="hidden" name="accion" value="reabrir"><input type="hidden" name="anio" value="<?= $anio ?>"><input type="hidden" name="mes" value="<?= $m ?>">
        <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg border border-zinc-200 text-zinc-600 hover:bg-zinc-50 text-sm font-semibold"><i data-lucide="lock-open" class="w-4 h-4"></i> Reabrir</button>
      </form>
    <?php else: ?>
      <form method="post" x-data="{n:''}"><?= csrf_input() ?><input type="hidden" name="accion" value="cerrar"><input type="hidden" name="anio" value="<?= $anio ?>"><input type="hidden" name="mes" value="<?= $m ?>">
        <input type="text" name="nota" x-model="n" maxlength="255" placeholder="Nota (opcional)" class="w-full mb-2 px-2.5 py-1.5 rounded-lg border border-zinc-300 text-xs">
        <button type="submit" <?= $futuro?'disabled':'' ?> class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg <?= $futuro?'bg-zinc-100 text-zinc-400 cursor-not-allowed':'bg-zinc-800 hover:bg-zinc-900 text-white' ?> text-sm font-semibold"><i data-lucide="lock" class="w-4 h-4"></i> <?= $futuro?'Mes futuro':'Cerrar mes' ?></button>
      </form>
    <?php endif; ?>
  </div>
  <?php endfor; ?>
</div>
<?php require __DIR__ . '/config/footer.php'; ?>
