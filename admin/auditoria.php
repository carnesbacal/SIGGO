<?php
/** admin/auditoria.php - Bitacora de auditoria (solo lectura) */
require __DIR__ . '/../config/admin_helpers.php';
requerir_permiso('administrar');   // pantalla solo para administradores

$fu = (int) input('usuario_id');
$fa = trim((string) input('accion'));
$where = ['1=1']; $p = [];
if ($fu > 0) { $where[] = 'a.usuario_id=:u'; $p['u'] = $fu; }
if ($fa !== '') { $where[] = 'a.accion=:ac'; $p['ac'] = $fa; }
$wsql = implode(' AND ', $where);
$rows = db_all("SELECT a.*, u.nombre_completo AS usuario FROM auditoria_sistema a LEFT JOIN usuarios u ON a.usuario_id=u.id WHERE $wsql ORDER BY a.creado_en DESC, a.id DESC LIMIT 300", $p);
$usuarios = db_all("SELECT id, nombre_completo FROM usuarios ORDER BY nombre_completo");
$acciones = db_all("SELECT DISTINCT accion FROM auditoria_sistema ORDER BY accion");
$titulo_pagina = 'Auditoría';
$pagina_activa = 'admin_auditoria';

function color_accion($a) {
    if (str_starts_with($a,'crear')) return '#16A34A';
    if (str_starts_with($a,'editar')) return '#2563EB';
    if (str_starts_with($a,'desactivar')||str_starts_with($a,'eliminar')) return '#DC2626';
    if (str_starts_with($a,'activar')) return '#0D9488';
    if (str_contains($a,'login')||str_contains($a,'password')) return '#9333EA';
    return '#6B7280';
}
require __DIR__ . '/../config/header.php';
?>
<div class="mb-5"><h2 class="font-display text-2xl font-extrabold text-zinc-900">Auditoría</h2><p class="text-xs text-zinc-500 mt-0.5">Registro de acciones del sistema (últimos 300 eventos).</p></div>
<form method="get" class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4 mb-4">
  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-end">
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Usuario</label>
      <select name="usuario_id" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm"><option value="0">Todos</option>
        <?php foreach ($usuarios as $us): ?><option value="<?= (int)$us['id'] ?>" <?= $fu===(int)$us['id']?'selected':'' ?>><?= e($us['nombre_completo']) ?></option><?php endforeach; ?></select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Acción</label>
      <select name="accion" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm"><option value="">Todas</option>
        <?php foreach ($acciones as $ac): ?><option value="<?= e($ac['accion']) ?>" <?= $fa===$ac['accion']?'selected':'' ?>><?= e($ac['accion']) ?></option><?php endforeach; ?></select></div>
    <div><button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-zinc-800 hover:bg-zinc-900 text-white text-sm font-semibold">Filtrar</button></div>
    <div><a href="<?= url('admin/auditoria.php') ?>" class="block text-center px-3 py-1.5 rounded-lg border border-zinc-200 text-zinc-500 hover:bg-zinc-50 text-sm">Limpiar</a></div>
  </div>
</form>
<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden"><div class="overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
      <th class="px-4 py-3 font-semibold">Fecha</th><th class="px-4 py-3 font-semibold">Usuario</th>
      <th class="px-4 py-3 font-semibold">Acción</th><th class="px-4 py-3 font-semibold">Detalle</th>
    </tr></thead>
    <tbody class="divide-y divide-zinc-100">
    <?php if (!$rows): ?><tr><td colspan="4" class="px-4 py-10 text-center text-zinc-400">Sin registros.</td></tr>
    <?php else: foreach ($rows as $r): $col=color_accion($r['accion']); ?>
      <tr class="hover:bg-zinc-50">
        <td class="px-4 py-3 text-zinc-500 whitespace-nowrap"><?= fmt_fecha($r['creado_en']) ?></td>
        <td class="px-4 py-3 text-zinc-700"><?= e($r['usuario'] ?? 'Sistema') ?></td>
        <td class="px-4 py-3"><span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background:<?= $col ?>18;color:<?= $col ?>"><?= e($r['accion']) ?></span> <?php if ($r['entidad']): ?><span class="text-xs text-zinc-400 font-mono"><?= e($r['entidad']) ?><?= $r['entidad_id']?'#'.$r['entidad_id']:'' ?></span><?php endif; ?></td>
        <td class="px-4 py-3 text-zinc-600"><?= e($r['descripcion'] ?? '—') ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div></div>
<?php require __DIR__ . '/../config/footer.php'; ?>
