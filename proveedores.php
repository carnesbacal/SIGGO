<?php
/** proveedores.php - Catalogo de proveedores */
require __DIR__ . '/config/admin_helpers.php';

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada, intenta de nuevo.'); header('Location: '.url('proveedores.php')); exit; }
    $accion = input('accion');
    if ($accion === 'toggle') { admin_toggle_activo('proveedores', (int)input('id'), 'Proveedor'); header('Location: '.url('proveedores.php')); exit; }
    if ($accion === 'guardar') {
        $id = (int) input('id');
        $nombre = trim((string) input('nombre'));
        if ($nombre === '') { flash_set('error','El nombre es obligatorio.'); header('Location: '.url('proveedores.php')); exit; }
        $dup = db_one("SELECT id FROM proveedores WHERE nombre=:n AND id<>:id", ['n'=>$nombre,'id'=>$id]);
        if ($dup) { flash_set('error','Ya existe un proveedor con ese nombre.'); header('Location: '.url('proveedores.php')); exit; }
        $p = [
            'n'=>$nombre,
            'rs'=>trim((string)input('razon_social')) ?: null,
            'rfc'=>trim((string)input('rfc')) ?: null,
            'srv'=>trim((string)input('servicio')) ?: null,
            'tel'=>trim((string)input('telefono')) ?: null,
            'em'=>trim((string)input('email')) ?: null,
            'web'=>trim((string)input('sitio_web')) ?: null,
            'dir'=>trim((string)input('direccion')) ?: null,
            'not'=>trim((string)input('notas')) ?: null,
            'cat'=>(int)input('categoria_id') ?: null,
        ];
        if ($id > 0) {
            $p['id']=$id;
            db_exec("UPDATE proveedores SET nombre=:n,razon_social=:rs,rfc=:rfc,servicio=:srv,categoria_id=:cat,telefono=:tel,email=:em,sitio_web=:web,direccion=:dir,notas=:not WHERE id=:id", $p);
            registrar_auditoria('editar','proveedores',$id,"Editó proveedor $nombre");
            flash_set('success','Proveedor actualizado.');
        } else {
            $p['cre']=(int)(usuario_actual()['id'] ?? 0) ?: null;
            db_exec("INSERT INTO proveedores (nombre,razon_social,rfc,servicio,categoria_id,telefono,email,sitio_web,direccion,notas,creado_por_id) VALUES (:n,:rs,:rfc,:srv,:cat,:tel,:em,:web,:dir,:not,:cre)", $p);
            registrar_auditoria('crear','proveedores',db_last_id(),"Creó proveedor $nombre");
            flash_set('success','Proveedor creado.');
        }
        header('Location: '.url('proveedores.php')); exit;
    }
}
$rows = db_all("SELECT p.*, c.nombre AS cat_nombre, c.color AS cat_color FROM proveedores p LEFT JOIN categorias_gasto c ON p.categoria_id=c.id ORDER BY p.activo DESC, p.nombre");
$catsProv = db_all("SELECT id, nombre FROM categorias_gasto WHERE ambito='proveedor' AND activo=1 ORDER BY nombre");
$titulo_pagina = 'Proveedores';
$pagina_activa = 'proveedores';
require __DIR__ . '/config/header.php';
?>
<div x-data="catProv()">
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
      <h2 class="font-display text-2xl font-extrabold text-zinc-900">Proveedores</h2>
      <p class="text-xs text-zinc-500 mt-0.5">Directorio de proveedores para asociar a cotizaciones y gastos.</p>
    </div>
    <button @click="nuevo()" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-rosa-600 hover:bg-rosa-700 text-white text-sm font-semibold shadow-sm">
      <i data-lucide="plus" class="w-4 h-4"></i> Nuevo proveedor
    </button>
  </div>

  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
          <th class="px-4 py-3 font-semibold">Proveedor</th><th class="px-4 py-3 font-semibold">Categoría</th><th class="px-4 py-3 font-semibold">Servicio</th>
          <th class="px-4 py-3 font-semibold">Contacto</th><th class="px-4 py-3 font-semibold">Estado</th>
          <th class="px-4 py-3 font-semibold text-right">Acciones</th>
        </tr></thead>
        <tbody class="divide-y divide-zinc-100">
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="px-4 py-10 text-center text-zinc-400">Aún no hay proveedores.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr class="hover:bg-zinc-50 <?= $r['activo'] ? '' : 'opacity-60' ?>">
            <td class="px-4 py-3"><div class="font-medium text-zinc-800"><?= e($r['nombre']) ?></div><?php if ($r['rfc']): ?><div class="text-xs text-zinc-400 font-mono"><?= e($r['rfc']) ?></div><?php endif; ?></td>
            <td class="px-4 py-3"><?php if (!empty($r['cat_nombre'])): ?><span class="inline-flex items-center gap-1.5 text-xs text-zinc-600"><span class="w-2.5 h-2.5 rounded-full" style="background:<?= e($r['cat_color'] ?? '#6B7280') ?>"></span><?= e($r['cat_nombre']) ?></span><?php else: ?><span class="text-zinc-300">—</span><?php endif; ?></td>
            <td class="px-4 py-3 text-zinc-500"><?= e($r['servicio'] ?? '—') ?></td>
            <td class="px-4 py-3 text-zinc-500"><?= e($r['telefono'] ?? ($r['email'] ?? '—')) ?></td>
            <td class="px-4 py-3"><?php if ($r['activo']): ?><span class="text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">Activo</span><?php else: ?><span class="text-xs font-medium text-zinc-500 bg-zinc-100 px-2 py-0.5 rounded-full">Inactivo</span><?php endif; ?></td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-1">
                <button @click='editar(<?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>)' class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-rosa-700" title="Editar"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                <form method="post" class="inline"><?= csrf_input() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-rosa-700" title="<?= $r['activo'] ? 'Desactivar' : 'Activar' ?>"><i data-lucide="<?= $r['activo'] ? 'toggle-right' : 'toggle-left' ?>" class="w-4 h-4"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="open=false"></div>
    <div class="relative bg-white rounded-2xl shadow-xl border border-zinc-200 w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
      <h3 class="font-display font-bold text-lg text-zinc-900 mb-4" x-text="form.id ? 'Editar proveedor' : 'Nuevo proveedor'"></h3>
      <form method="post" class="grid grid-cols-2 gap-3">
        <?= csrf_input() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" :value="form.id">
        <div class="col-span-2"><label class="block text-xs font-semibold text-zinc-500 mb-1">Nombre comercial *</label>
          <input type="text" name="nombre" x-model="form.nombre" required maxlength="150" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 focus:ring-2 focus:ring-rosa-200 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Razón social</label>
          <input type="text" name="razon_social" x-model="form.razon_social" maxlength="200" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">RFC</label>
          <input type="text" name="rfc" x-model="form.rfc" maxlength="20" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 outline-none text-sm uppercase"></div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-zinc-500 mb-1">Servicio que ofrece</label>
          <input type="text" name="servicio" x-model="form.servicio" maxlength="255" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 outline-none text-sm"></div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-zinc-500 mb-1">Categoría</label>
          <select name="categoria_id" x-model="form.categoria_id" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 outline-none text-sm">
            <option value="">— Sin categoría —</option>
            <?php foreach ($catsProv as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
          </select>
          <?php if (!$catsProv): ?><p class="text-[11px] text-zinc-400 mt-1">No hay categorías de proveedor. Créalas en <a href="<?= url('admin/categorias.php?ambito=proveedor') ?>" class="text-rosa-700 font-semibold">Categorías → Proveedor</a>.</p><?php endif; ?></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Teléfono</label>
          <input type="text" name="telefono" x-model="form.telefono" maxlength="50" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Email</label>
          <input type="email" name="email" x-model="form.email" maxlength="150" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Sitio web</label>
          <input type="text" name="sitio_web" x-model="form.sitio_web" maxlength="200" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Dirección</label>
          <input type="text" name="direccion" x-model="form.direccion" maxlength="255" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 outline-none text-sm"></div>
        <div class="col-span-2"><label class="block text-xs font-semibold text-zinc-500 mb-1">Notas</label>
          <textarea name="notas" x-model="form.notas" rows="2" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-rosa-500 outline-none text-sm"></textarea></div>
        <div class="col-span-2 flex justify-end gap-2 pt-1">
          <button type="button" @click="open=false" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-rosa-600 hover:bg-rosa-700 shadow-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function catProv(){return{
  open:false,
  form:{id:0,nombre:'',razon_social:'',rfc:'',servicio:'',categoria_id:'',telefono:'',email:'',sitio_web:'',direccion:'',notas:''},
  nuevo(){ this.form={id:0,nombre:'',razon_social:'',rfc:'',servicio:'',categoria_id:'',telefono:'',email:'',sitio_web:'',direccion:'',notas:''}; this.open=true; },
  editar(r){ this.form={id:r.id,nombre:r.nombre,razon_social:r.razon_social||'',rfc:r.rfc||'',servicio:r.servicio||'',categoria_id:r.categoria_id||'',telefono:r.telefono||'',email:r.email||'',sitio_web:r.sitio_web||'',direccion:r.direccion||'',notas:r.notas||''}; this.open=true; }
}}
</script>
<?php require __DIR__ . '/config/footer.php'; ?>
