<?php
/** admin/backups.php - Respaldos de la base de datos */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/admin_helpers.php';
require_once __DIR__ . '/../config/backups_helpers.php';
requerir_permiso('administrar');   // pantalla solo para administradores

$u = usuario_actual();

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada, intenta de nuevo.'); header('Location: '.url('admin/backups.php')); exit; }
    $op = (string) input('op');
    if ($op === 'generar') {
        $r = generar_backup('manual', (int)$u['id'], trim((string)input('notas')));
        if (!empty($r['ok'])) { registrar_auditoria('generar_backup', null, null, "Backup manual: {$r['archivo']}"); flash_set('success', "Respaldo generado: {$r['archivo']} (".fmt_bytes($r['tamano'])." · {$r['metodo']})"); }
        else { flash_set('error', $r['mensaje'] ?? 'No se pudo generar el respaldo.'); }
    } elseif ($op === 'eliminar') {
        $bid = (int) input('id');
        if ($bid > 0 && eliminar_backup($bid)) { registrar_auditoria('eliminar_backup','backups_realizados',$bid,'Eliminó backup'); flash_set('success','Respaldo eliminado.'); }
    } elseif ($op === 'limpiar_viejos') {
        $n = limpiar_backups_viejos(); flash_set('success', "Se eliminaron $n respaldos viejos.");
    }
    header('Location: '.url('admin/backups.php')); exit;
}

$descargar = (int) input('descargar');
if ($descargar > 0) {
    $b = db_one("SELECT * FROM backups_realizados WHERE id=:id", ['id'=>$descargar]);
    if ($b && backup_existe_en_disco($b['nombre_archivo'])) {
        $ruta = BACKUPS_DIR . '/' . basename($b['nombre_archivo']);
        registrar_auditoria('descargar_backup','backups_realizados',(int)$b['id'],"Descargó {$b['nombre_archivo']}");
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $b['nombre_archivo'] . '"');
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        readfile($ruta); exit;
    }
    flash_set('error','El archivo no existe en disco.'); header('Location: '.url('admin/backups.php')); exit;
}

$backups = listar_backups(100);
$mysqldump_ok = detectar_mysqldump() !== null;
$titulo_pagina = 'Backups';
$pagina_activa = 'admin_backups';
require __DIR__ . '/../config/header.php';
?>
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
  <div><h2 class="font-display text-2xl font-extrabold text-zinc-900">Respaldos</h2><p class="text-xs text-zinc-500 mt-0.5">Copias de seguridad de la base de datos <code class="text-rosa-700"><?= e(DB_NAME) ?></code>.</p></div>
  <div class="flex gap-2">
    <form method="post"><?= csrf_input() ?><input type="hidden" name="op" value="generar">
      <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-rosa-600 hover:bg-rosa-700 text-white text-sm font-semibold shadow-sm"><i data-lucide="database-backup" class="w-4 h-4"></i> Generar respaldo</button>
    </form>
  </div>
</div>

<div class="mb-4 flex items-center gap-2 text-xs px-3 py-2 rounded-lg <?= $mysqldump_ok ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' ?>">
  <i data-lucide="<?= $mysqldump_ok ? 'check-circle-2' : 'alert-triangle' ?>" class="w-4 h-4"></i>
  <?= $mysqldump_ok ? 'mysqldump disponible: respaldos rápidos y completos.' : 'mysqldump no detectado: se usará el método PHP (más lento, para bases pequeñas).' ?>
</div>

<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden"><div class="overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
      <th class="px-4 py-3 font-semibold">Archivo</th><th class="px-4 py-3 font-semibold">Tipo</th>
      <th class="px-4 py-3 font-semibold text-right">Tamaño</th><th class="px-4 py-3 font-semibold">Fecha</th>
      <th class="px-4 py-3 font-semibold">Por</th><th class="px-4 py-3 font-semibold text-right">Acciones</th>
    </tr></thead>
    <tbody class="divide-y divide-zinc-100">
    <?php if (!$backups): ?><tr><td colspan="6" class="px-4 py-10 text-center text-zinc-400">Aún no hay respaldos. Genera el primero.</td></tr>
    <?php else: foreach ($backups as $b): $por = $b['realizado_por_nombre'] ?? null; ?>
      <tr class="hover:bg-zinc-50">
        <td class="px-4 py-3 font-mono text-xs text-zinc-700"><?= e($b['nombre_archivo']) ?></td>
        <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded-full <?= $b['tipo']==='automatico'?'bg-blue-50 text-blue-700':'bg-zinc-100 text-zinc-600' ?>"><?= e($b['tipo']) ?></span></td>
        <td class="px-4 py-3 text-right tabular-nums text-zinc-600"><?= fmt_bytes((int)$b['tamano_bytes']) ?></td>
        <td class="px-4 py-3 text-zinc-500 whitespace-nowrap"><?= fmt_fecha($b['creado_en']) ?></td>
        <td class="px-4 py-3 text-zinc-500"><?= e($por ?? ($b['tipo']==='automatico'?'Sistema':'—')) ?></td>
        <td class="px-4 py-3"><div class="flex items-center justify-end gap-1">
          <a href="<?= url('admin/backups.php?descargar='.(int)$b['id']) ?>" class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-rosa-700" title="Descargar"><i data-lucide="download" class="w-4 h-4"></i></a>
          <form method="post" class="inline" onsubmit="return confirm('¿Eliminar este respaldo?');"><?= csrf_input() ?><input type="hidden" name="op" value="eliminar"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button type="submit" class="p-1.5 rounded-lg text-zinc-400 hover:bg-zinc-100 hover:text-red-600" title="Eliminar"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
          </form>
        </div></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div></div>
<p class="text-xs text-zinc-400 mt-3">Los respaldos se guardan en la carpeta <code>backups/</code> del servidor (excluida de git). Descárgalos periódicamente a un lugar seguro.</p>
<?php require __DIR__ . '/../config/footer.php'; ?>
