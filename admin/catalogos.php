<?php
/** admin/catalogos.php - Formas de pago y unidades de medida */
require __DIR__ . '/../config/admin_helpers.php';
require_once __DIR__ . '/../config/tema.php';
requerir_permiso('administrar');   // pantalla solo para administradores

$volver = url('admin/catalogos.php');

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada, intenta de nuevo.'); header("Location: $volver"); exit; }
    $accion = input('accion');

    if ($accion === 'pago_toggle')   { admin_toggle_activo('formas_pago', (int)input('id'), 'Forma de pago'); header("Location: $volver"); exit; }
    if ($accion === 'unidad_toggle') { admin_toggle_activo('unidades_medida', (int)input('id'), 'Unidad'); header("Location: $volver"); exit; }

    if ($accion === 'pago_guardar') {
        $id     = (int) input('id');
        $nombre = trim((string) input('nombre'));
        $ref    = input('requiere_referencia') === '1' ? 1 : 0;
        $orden  = (int) input('orden');
        if ($nombre === '') { flash_set('error','El nombre es obligatorio.'); header("Location: $volver"); exit; }
        if (db_one("SELECT id FROM formas_pago WHERE nombre=:n AND id<>:id", ['n'=>$nombre,'id'=>$id])) {
            flash_set('error','Ya existe una forma de pago con ese nombre.'); header("Location: $volver"); exit;
        }
        if ($id > 0) {
            db_exec("UPDATE formas_pago SET nombre=:n, requiere_referencia=:r, orden=:o WHERE id=:id", ['n'=>$nombre,'r'=>$ref,'o'=>$orden,'id'=>$id]);
            registrar_auditoria('editar','formas_pago',$id,"Editó forma de pago $nombre");
            flash_set('success','Forma de pago actualizada.');
        } else {
            db_exec("INSERT INTO formas_pago (nombre,requiere_referencia,orden) VALUES (:n,:r,:o)", ['n'=>$nombre,'r'=>$ref,'o'=>$orden]);
            registrar_auditoria('crear','formas_pago',db_last_id(),"Creó forma de pago $nombre");
            flash_set('success','Forma de pago creada.');
        }
        header("Location: $volver"); exit;
    }

    if ($accion === 'unidad_guardar') {
        $id     = (int) input('id');
        $clave  = strtoupper(trim((string) input('clave')));
        $nombre = trim((string) input('nombre'));
        $orden  = (int) input('orden');
        if ($clave === '' || $nombre === '') { flash_set('error','Clave y nombre son obligatorios.'); header("Location: $volver"); exit; }
        if (db_one("SELECT id FROM unidades_medida WHERE clave=:c AND id<>:id", ['c'=>$clave,'id'=>$id])) {
            flash_set('error','Ya existe una unidad con esa clave.'); header("Location: $volver"); exit;
        }
        if ($id > 0) {
            db_exec("UPDATE unidades_medida SET clave=:c, nombre=:n, orden=:o WHERE id=:id", ['c'=>$clave,'n'=>$nombre,'o'=>$orden,'id'=>$id]);
            registrar_auditoria('editar','unidades_medida',$id,"Editó unidad $clave");
            flash_set('success','Unidad actualizada.');
        } else {
            db_exec("INSERT INTO unidades_medida (clave,nombre,orden) VALUES (:c,:n,:o)", ['c'=>$clave,'n'=>$nombre,'o'=>$orden]);
            registrar_auditoria('crear','unidades_medida',db_last_id(),"Creó unidad $clave");
            flash_set('success','Unidad creada.');
        }
        header("Location: $volver"); exit;
    }
}

$pagos = db_all("SELECT f.*, (SELECT COUNT(*) FROM gastos g WHERE g.forma_pago_id=f.id) n_usos
                   FROM formas_pago f ORDER BY f.orden, f.nombre");
$unids = db_all("SELECT u.*, (SELECT COUNT(*) FROM gasto_items i WHERE i.unidad_id=u.id) n_usos
                   FROM unidades_medida u ORDER BY u.orden, u.clave");

$titulo_pagina = 'Formas de pago y unidades';
$pagina_activa = 'admin_catalogos';
require __DIR__ . '/../config/header.php';
?>
<div x-data="cats()">
  <div class="mb-6">
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Formas de pago y unidades</h2>
    <p class="text-xs text-zinc-500 mt-0.5">Los dos catálogos chicos que alimentan la captura de gastos.</p>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <!-- Formas de pago -->
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-zinc-200 flex items-center justify-between">
        <h3 class="font-display font-bold text-sm text-zinc-800">Formas de pago</h3>
        <button @click="nuevoPago()" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-xs font-semibold">
          <i data-lucide="plus" class="w-3.5 h-3.5"></i> Nueva</button>
      </div>
      <table class="w-full text-sm">
        <tbody class="divide-y divide-zinc-100">
        <?php foreach ($pagos as $p): ?>
          <tr class="<?= $p['activo'] ? '' : 'opacity-50' ?>">
            <td class="px-5 py-2.5">
              <span class="text-zinc-800"><?= e($p['nombre']) ?></span>
              <?php if ((int)$p['requiere_referencia']): ?>
                <span class="ml-1.5 text-[10px] text-zinc-400">pide referencia</span><?php endif; ?>
            </td>
            <td class="px-3 py-2.5 text-right text-xs text-zinc-400"><?= (int)$p['n_usos'] > 0 ? (int)$p['n_usos'] : '' ?></td>
            <td class="px-5 py-2.5 w-20">
              <div class="flex items-center justify-end gap-1">
                <button @click='editarPago(<?= json_encode($p, JSON_UNESCAPED_UNICODE) ?>)' class="p-1 rounded text-zinc-400 hover:bg-zinc-100 hover:text-marca-700"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></button>
                <form method="post" class="inline"><?= csrf_input() ?>
                  <input type="hidden" name="accion" value="pago_toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="p-1 rounded text-zinc-400 hover:bg-zinc-100 hover:text-marca-700"><i data-lucide="<?= $p['activo']?'toggle-right':'toggle-left' ?>" class="w-3.5 h-3.5"></i></button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Unidades -->
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-zinc-200 flex items-center justify-between">
        <h3 class="font-display font-bold text-sm text-zinc-800">Unidades de medida</h3>
        <button @click="nuevaUnidad()" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-xs font-semibold">
          <i data-lucide="plus" class="w-3.5 h-3.5"></i> Nueva</button>
      </div>
      <table class="w-full text-sm">
        <tbody class="divide-y divide-zinc-100">
        <?php foreach ($unids as $u): ?>
          <tr class="<?= $u['activo'] ? '' : 'opacity-50' ?>">
            <td class="px-5 py-2.5 w-20"><span class="font-mono text-xs font-semibold text-marca-700"><?= e($u['clave']) ?></span></td>
            <td class="px-3 py-2.5 text-zinc-700"><?= e($u['nombre']) ?></td>
            <td class="px-3 py-2.5 text-right text-xs text-zinc-400"><?= (int)$u['n_usos'] > 0 ? (int)$u['n_usos'] : '' ?></td>
            <td class="px-5 py-2.5 w-20">
              <div class="flex items-center justify-end gap-1">
                <button @click='editarUnidad(<?= json_encode($u, JSON_UNESCAPED_UNICODE) ?>)' class="p-1 rounded text-zinc-400 hover:bg-zinc-100 hover:text-marca-700"><i data-lucide="pencil" class="w-3.5 h-3.5"></i></button>
                <form method="post" class="inline"><?= csrf_input() ?>
                  <input type="hidden" name="accion" value="unidad_toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button type="submit" class="p-1 rounded text-zinc-400 hover:bg-zinc-100 hover:text-marca-700"><i data-lucide="<?= $u['activo']?'toggle-right':'toggle-left' ?>" class="w-3.5 h-3.5"></i></button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <p class="text-[11px] text-zinc-400 mt-2 px-1">El número a la derecha es cuántas veces se ha usado. Nada se borra: se desactiva, para no romper los gastos ya capturados.</p>

  <!-- Modal forma de pago -->
  <div x-show="openPago" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="openPago=false"></div>
    <div class="relative bg-white rounded-2xl shadow-xl border border-zinc-200 w-full max-w-sm p-6" x-transition>
      <h3 class="font-display font-bold text-lg text-zinc-900 mb-4" x-text="pago.id ? 'Editar forma de pago' : 'Nueva forma de pago'"></h3>
      <form method="post" class="space-y-4">
        <?= csrf_input() ?><input type="hidden" name="accion" value="pago_guardar"><input type="hidden" name="id" :value="pago.id">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Nombre *</label>
          <input type="text" name="nombre" x-model="pago.nombre" required maxlength="60" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <label class="flex items-center gap-2 text-sm text-zinc-700">
          <input type="checkbox" name="requiere_referencia" value="1" x-model="pago.requiere_referencia" class="rounded"> Pide número de referencia</label>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Orden</label>
          <input type="number" name="orden" x-model.number="pago.orden" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="openPago=false" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-marca-600 hover:bg-marca-700 shadow-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal unidad -->
  <div x-show="openUnidad" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="openUnidad=false"></div>
    <div class="relative bg-white rounded-2xl shadow-xl border border-zinc-200 w-full max-w-sm p-6" x-transition>
      <h3 class="font-display font-bold text-lg text-zinc-900 mb-4" x-text="unidad.id ? 'Editar unidad' : 'Nueva unidad'"></h3>
      <form method="post" class="space-y-4">
        <?= csrf_input() ?><input type="hidden" name="accion" value="unidad_guardar"><input type="hidden" name="id" :value="unidad.id">
        <div class="grid grid-cols-3 gap-3">
          <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Clave *</label>
            <input type="text" name="clave" x-model="unidad.clave" required maxlength="10" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm uppercase font-mono"></div>
          <div class="col-span-2"><label class="block text-xs font-semibold text-zinc-500 mb-1">Nombre *</label>
            <input type="text" name="nombre" x-model="unidad.nombre" required maxlength="60" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        </div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Orden</label>
          <input type="number" name="orden" x-model.number="unidad.orden" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="openUnidad=false" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-marca-600 hover:bg-marca-700 shadow-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function cats(){return{
  openPago:false, openUnidad:false,
  pago:{id:0,nombre:'',requiere_referencia:false,orden:0},
  unidad:{id:0,clave:'',nombre:'',orden:0},
  nuevoPago(){ this.pago={id:0,nombre:'',requiere_referencia:false,orden:0}; this.openPago=true; },
  editarPago(p){ this.pago={id:p.id,nombre:p.nombre,requiere_referencia:p.requiere_referencia==1,orden:p.orden||0}; this.openPago=true; },
  nuevaUnidad(){ this.unidad={id:0,clave:'',nombre:'',orden:0}; this.openUnidad=true; },
  editarUnidad(u){ this.unidad={id:u.id,clave:u.clave,nombre:u.nombre,orden:u.orden||0}; this.openUnidad=true; }
}}
</script>
<?php require __DIR__ . '/../config/footer.php'; ?>
