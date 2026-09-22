<?php
/** admin/areas.php - Catalogo de areas de la tienda (centros de costo) */
require __DIR__ . '/../config/admin_helpers.php';
require_once __DIR__ . '/../config/tema.php';

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada, intenta de nuevo.'); header('Location: '.url('admin/areas.php')); exit; }
    $accion = input('accion');
    if ($accion === 'toggle') {
        admin_toggle_activo('areas', (int)input('id'), 'Área');
        header('Location: '.url('admin/areas.php')); exit;
    }
    if ($accion === 'guardar') {
        $id     = (int) input('id');
        $nombre = trim((string) input('nombre'));
        $codigo = strtoupper(trim((string) input('codigo')));
        $desc   = trim((string) input('descripcion'));
        $resp_nombre = trim((string) input('responsable_nombre')); $resp_nombre = $resp_nombre !== '' ? $resp_nombre : null;
        $resp_email  = trim((string) input('responsable_email'));  $resp_email  = $resp_email !== '' ? $resp_email : null;
        if ($resp_email !== null && !filter_var($resp_email, FILTER_VALIDATE_EMAIL)) { flash_set('error','El correo del responsable no es válido.'); header('Location: '.url('admin/areas.php')); exit; }
        if ($nombre === '' || $codigo === '') { flash_set('error','Nombre y código son obligatorios.'); header('Location: '.url('admin/areas.php')); exit; }
        $dup = db_one("SELECT id FROM areas WHERE (codigo=:c OR nombre=:n) AND id<>:id", ['c'=>$codigo,'n'=>$nombre,'id'=>$id]);
        if ($dup) { flash_set('error','Ya existe un área con ese nombre o código.'); header('Location: '.url('admin/areas.php')); exit; }
        if ($id > 0) {
            db_exec("UPDATE areas SET nombre=:n, codigo=:c, descripcion=:d, responsable_nombre=:rn, responsable_email=:re WHERE id=:id",
                ['n'=>$nombre,'c'=>$codigo,'d'=>($desc?:null),'rn'=>$resp_nombre,'re'=>$resp_email,'id'=>$id]);
            registrar_auditoria('editar','areas',$id,"Editó área $nombre");
            flash_set('success','Área actualizada.');
        } else {
            db_exec("INSERT INTO areas (nombre,codigo,descripcion,responsable_nombre,responsable_email) VALUES (:n,:c,:d,:rn,:re)",
                ['n'=>$nombre,'c'=>$codigo,'d'=>($desc?:null),'rn'=>$resp_nombre,'re'=>$resp_email]);
            registrar_auditoria('crear','areas',db_last_id(),"Creó área $nombre");
            flash_set('success','Área creada.');
        }
        header('Location: '.url('admin/areas.php')); exit;
    }
}

$anio = (int) date('Y');
$rows = db_all("SELECT a.*,
                       (SELECT COALESCE(SUM(v.monto),0) FROM vista_gasto_area v
                         WHERE v.area_id = a.id AND v.anio = :y AND v.estatus_pago <> 'cancelado') AS gasto_anio
                  FROM areas a ORDER BY a.activo DESC, a.nombre", ['y'=>$anio]);
$maxGasto = 0.0; foreach ($rows as $r) if ((float)$r['gasto_anio'] > $maxGasto) $maxGasto = (float)$r['gasto_anio'];

$titulo_pagina = 'Áreas';
$pagina_activa = 'areas';
require __DIR__ . '/../config/header.php';
?>
<div x-data="catAreas()">
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
      <h2 class="font-display text-2xl font-extrabold text-zinc-900">Áreas</h2>
      <p class="text-xs text-zinc-500 mt-0.5">Centros de costo de la tienda para clasificar y repartir el gasto.</p>
    </div>
    <button @click="nuevo()" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm">
      <i data-lucide="plus" class="w-4 h-4"></i> Nueva área
    </button>
  </div>

  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
          <th class="px-4 py-3 font-semibold">Código</th>
          <th class="px-4 py-3 font-semibold">Nombre</th>
          <th class="px-4 py-3 font-semibold">Responsable</th>
          <th class="px-4 py-3 font-semibold">Gasto <?= $anio ?></th>
          <th class="px-4 py-3 font-semibold">Estado</th>
          <th class="px-4 py-3 font-semibold text-right">Acciones</th>
        </tr></thead>
        <tbody class="divide-y divide-zinc-100">
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="px-4 py-10 text-center text-zinc-400">Aún no hay áreas. Crea la primera.</td></tr>
        <?php else: foreach ($rows as $r): $g=(float)$r['gasto_anio']; $p = $maxGasto>0 ? ($g/$maxGasto)*100 : 0; ?>
          <tr class="hover:bg-zinc-50 <?= $r['activo'] ? '' : 'opacity-60' ?>">
            <td class="px-4 py-3"><span class="font-mono text-xs font-semibold text-marca-700"><?= e($r['codigo']) ?></span></td>
            <td class="px-4 py-3">
              <div class="font-medium text-zinc-800"><?= e($r['nombre']) ?></div>
              <?php if ($r['descripcion']): ?><div class="text-xs text-zinc-400"><?= e($r['descripcion']) ?></div><?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <?php if (!empty($r['responsable_nombre']) || !empty($r['responsable_email'])): ?>
                <div class="text-zinc-700"><?= e($r['responsable_nombre'] ?? '—') ?></div>
                <?php if (!empty($r['responsable_email'])): ?><div class="text-xs text-zinc-400"><?= e($r['responsable_email']) ?></div><?php endif; ?>
              <?php else: ?><span class="text-zinc-400">—</span><?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <?php if ($g > 0): ?>
                <div class="text-zinc-800 font-semibold tabular-nums">$<?= number_format($g,2) ?></div>
                <div class="mt-1 h-1.5 w-28 bg-zinc-100 rounded-full overflow-hidden">
                  <div class="h-full rounded-full" style="width:<?= $p ?>%;background:<?= tono(400) ?>"></div></div>
              <?php else: ?><span class="text-zinc-300">—</span><?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <?php if ($r['activo']): ?><span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">Activa</span>
              <?php else: ?><span class="inline-flex items-center gap-1 text-xs font-medium text-zinc-500 bg-zinc-100 px-2 py-0.5 rounded-full">Inactiva</span><?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-1">
                <button @click='editar(<?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>)' class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-marca-700" title="Editar"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                <form method="post" class="inline">
                  <?= csrf_input() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-marca-700" title="<?= $r['activo'] ? 'Desactivar' : 'Activar' ?>">
                    <i data-lucide="<?= $r['activo'] ? 'toggle-right' : 'toggle-left' ?>" class="w-4 h-4"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <p class="text-[11px] text-zinc-400 mt-2 px-1">Un área con gastos no se puede borrar, solo desactivar: así el histórico no pierde a qué correspondía cada gasto.</p>

  <!-- Modal -->
  <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="open=false"></div>
    <div class="relative bg-white rounded-2xl shadow-xl border border-zinc-200 w-full max-w-md p-6" x-transition>
      <h3 class="font-display font-bold text-lg text-zinc-900 mb-4" x-text="form.id ? 'Editar área' : 'Nueva área'"></h3>
      <form method="post" class="space-y-4">
        <?= csrf_input() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" :value="form.id">
        <div class="grid grid-cols-3 gap-3">
          <div class="col-span-2"><label class="block text-xs font-semibold text-zinc-500 mb-1">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required maxlength="120" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
          <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Código *</label>
            <input type="text" name="codigo" x-model="form.codigo" required maxlength="20" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm uppercase"></div>
        </div>
        <p class="text-[11px] text-zinc-400 -mt-2">El código se usa en el folio del gasto: <span class="font-mono">G-ALM-2026-0001</span></p>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Descripción</label>
          <input type="text" name="descripcion" x-model="form.descripcion" maxlength="255" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Responsable (opcional)</label>
          <input type="text" name="responsable_nombre" x-model="form.responsable_nombre" maxlength="150" placeholder="Nombre del encargado del área" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
          <input type="email" name="responsable_email" x-model="form.responsable_email" maxlength="150" placeholder="correo@granodeoro.com.mx (opcional)" class="w-full mt-2 px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="open=false" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-marca-600 hover:bg-marca-700 shadow-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function catAreas(){return{
  open:false,
  vacio:{id:0,nombre:'',codigo:'',descripcion:'',responsable_nombre:'',responsable_email:''},
  form:{id:0,nombre:'',codigo:'',descripcion:'',responsable_nombre:'',responsable_email:''},
  nuevo(){ this.form={...this.vacio}; this.open=true; },
  editar(r){ this.form={id:r.id,nombre:r.nombre,codigo:r.codigo,descripcion:r.descripcion||'',
             responsable_nombre:r.responsable_nombre||'',responsable_email:r.responsable_email||''}; this.open=true; }
}}
</script>
<?php require __DIR__ . '/../config/footer.php'; ?>
