<?php
/** admin/categorias.php - Catalogo de categorias y sus subcategorias */
require __DIR__ . '/../config/admin_helpers.php';
require_once __DIR__ . '/../config/tema.php';
requerir_permiso('administrar');   // pantalla solo para administradores

$volver = url('admin/categorias.php');

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada, intenta de nuevo.'); header("Location: $volver"); exit; }
    $accion = input('accion');

    // ---- Categorias ----
    if ($accion === 'toggle') {
        admin_toggle_activo('categorias_gasto', (int)input('id'), 'Categoría');
        header("Location: $volver"); exit;
    }
    if ($accion === 'guardar') {
        $id     = (int) input('id');
        $nombre = trim((string) input('nombre'));
        $desc   = trim((string) input('descripcion'));
        $color  = trim((string) input('color')) ?: '#6B7280';
        if ($nombre === '') { flash_set('error','El nombre es obligatorio.'); header("Location: $volver"); exit; }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#6B7280';
        $dup = db_one("SELECT id FROM categorias_gasto WHERE nombre=:n AND ambito='gasto' AND id<>:id", ['n'=>$nombre,'id'=>$id]);
        if ($dup) { flash_set('error','Ya existe una categoría con ese nombre.'); header("Location: $volver"); exit; }
        if ($id > 0) {
            // El código y el orden ya no se capturan: los que tenga se respetan
            db_exec("UPDATE categorias_gasto SET nombre=:n, descripcion=:d, color=:co WHERE id=:id",
                ['n'=>$nombre,'d'=>($desc?:null),'co'=>$color,'id'=>$id]);
            registrar_auditoria('editar','categorias_gasto',$id,"Editó categoría $nombre");
            flash_set('success','Categoría actualizada.');
        } else {
            // La nueva se va al final de la lista, sin que nadie teclee un número
            $orden = (int) (db_one("SELECT COALESCE(MAX(orden),0)+10 v FROM categorias_gasto WHERE ambito='gasto'")['v'] ?? 10);
            db_exec("INSERT INTO categorias_gasto (nombre,ambito,descripcion,color,orden) VALUES (:n,'gasto',:d,:co,:o)",
                ['n'=>$nombre,'d'=>($desc?:null),'co'=>$color,'o'=>$orden]);
            registrar_auditoria('crear','categorias_gasto',db_last_id(),"Creó categoría $nombre");
            flash_set('success','Categoría creada.');
        }
        header("Location: $volver"); exit;
    }

    // ---- Subcategorias ----
    if ($accion === 'sub_toggle') {
        admin_toggle_activo('subcategorias_gasto', (int)input('id'), 'Subcategoría');
        header("Location: $volver"); exit;
    }
    if ($accion === 'sub_guardar') {
        $id     = (int) input('id');
        $catId  = (int) input('categoria_id');
        $nombre = trim((string) input('nombre'));
        if ($nombre === '' || $catId <= 0) { flash_set('error','La subcategoría necesita nombre y categoría.'); header("Location: $volver"); exit; }
        if (!db_one("SELECT id FROM categorias_gasto WHERE id=:c", ['c'=>$catId])) { flash_set('error','Categoría inválida.'); header("Location: $volver"); exit; }
        $dup = db_one("SELECT id FROM subcategorias_gasto WHERE categoria_id=:c AND nombre=:n AND id<>:id", ['c'=>$catId,'n'=>$nombre,'id'=>$id]);
        if ($dup) { flash_set('error','Esa categoría ya tiene una subcategoría con ese nombre.'); header("Location: $volver"); exit; }
        if ($id > 0) {
            // Mismo criterio que en la categoría: sin código ni orden a mano
            db_exec("UPDATE subcategorias_gasto SET categoria_id=:c, nombre=:n WHERE id=:id",
                ['c'=>$catId,'n'=>$nombre,'id'=>$id]);
            registrar_auditoria('editar','subcategorias_gasto',$id,"Editó subcategoría $nombre");
            flash_set('success','Subcategoría actualizada.');
        } else {
            $orden = (int) (db_one("SELECT COALESCE(MAX(orden),0)+10 v FROM subcategorias_gasto WHERE categoria_id=:c", ['c'=>$catId])['v'] ?? 10);
            db_exec("INSERT INTO subcategorias_gasto (categoria_id,nombre,orden) VALUES (:c,:n,:o)",
                ['c'=>$catId,'n'=>$nombre,'o'=>$orden]);
            registrar_auditoria('crear','subcategorias_gasto',db_last_id(),"Creó subcategoría $nombre");
            flash_set('success','Subcategoría creada.');
        }
        header("Location: $volver"); exit;
    }
}

$anio = (int) date('Y');
$cats = db_all("SELECT c.*,
                  (SELECT COUNT(*) FROM gastos g WHERE g.categoria_id=c.id) AS n_gastos,
                  (SELECT COALESCE(SUM(g.monto),0) FROM gastos g
                    WHERE g.categoria_id=c.id AND g.anio=:y AND g.estatus_pago<>'cancelado') AS gasto_anio
                FROM categorias_gasto c
                WHERE c.ambito='gasto' ORDER BY c.orden, c.nombre", ['y'=>$anio]);

$subs = [];
foreach (db_all("SELECT s.*, (SELECT COUNT(*) FROM gastos g WHERE g.subcategoria_id=s.id) AS n_gastos
                   FROM subcategorias_gasto s ORDER BY s.orden, s.nombre") as $s) {
    $subs[(int)$s['categoria_id']][] = $s;
}

$titulo_pagina = 'Categorías';
$pagina_activa = 'categorias';
require __DIR__ . '/../config/header.php';
?>
<div x-data="catCategorias()">
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
      <h2 class="font-display text-2xl font-extrabold text-zinc-900">Categorías</h2>
      <p class="text-xs text-zinc-500 mt-0.5">Cómo se clasifica el gasto. Cada categoría agrupa sus subcategorías.</p>
    </div>
    <button @click="nuevaCat()" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm">
      <i data-lucide="plus" class="w-4 h-4"></i> Nueva categoría
    </button>
  </div>

  <div class="space-y-3">
    <?php if (!$cats): ?>
      <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm px-4 py-10 text-center text-zinc-400">
        Aún no hay categorías. Crea la primera.</div>
    <?php else: foreach ($cats as $c):
      $lista = $subs[(int)$c['id']] ?? [];
      $ga = (float)$c['gasto_anio'];
    ?>
      <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden <?= $c['activo'] ? '' : 'opacity-60' ?>"
           x-data="{abierto:<?= count($lista) ? 'false' : 'true' ?>}">

        <div class="flex items-center gap-3 px-4 py-3">
          <span class="w-3 h-3 rounded-full flex-shrink-0" style="background:<?= e($c['color']) ?>"></span>
          <button type="button" @click="abierto=!abierto" class="flex items-center gap-2 flex-1 min-w-0 text-left">
            <span class="font-semibold text-zinc-800"><?= e($c['nombre']) ?></span>
            <span class="text-xs text-zinc-400"><?= count($lista) ?> <?= count($lista)===1?'subcategoría':'subcategorías' ?></span>
            <i data-lucide="chevron-down" class="w-4 h-4 text-zinc-400 transition-transform" :class="abierto && 'rotate-180'"></i>
          </button>

          <?php if ($ga > 0): ?>
            <span class="text-sm font-semibold text-zinc-700 tabular-nums hidden sm:block">$<?= number_format($ga,2) ?>
              <span class="text-[10px] font-normal text-zinc-400"><?= $anio ?></span></span>
          <?php endif; ?>

          <?php if (!$c['activo']): ?>
            <span class="text-[11px] font-medium text-zinc-500 bg-zinc-100 px-2 py-0.5 rounded-full">Inactiva</span>
          <?php endif; ?>

          <div class="flex items-center gap-1 flex-shrink-0">
            <button @click='nuevaSub(<?= (int)$c['id'] ?>)' class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-marca-700" title="Agregar subcategoría">
              <i data-lucide="plus" class="w-4 h-4"></i></button>
            <button @click='editarCat(<?= json_encode($c, JSON_UNESCAPED_UNICODE) ?>)' class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-marca-700" title="Editar">
              <i data-lucide="pencil" class="w-4 h-4"></i></button>
            <form method="post" class="inline">
              <?= csrf_input() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-marca-700" title="<?= $c['activo'] ? 'Desactivar' : 'Activar' ?>">
                <i data-lucide="<?= $c['activo'] ? 'toggle-right' : 'toggle-left' ?>" class="w-4 h-4"></i></button>
            </form>
          </div>
        </div>

        <div x-show="abierto" x-cloak class="border-t border-zinc-100 bg-zinc-50/60">
          <?php if (!$lista): ?>
            <div class="px-4 py-4 text-center text-xs text-zinc-400">
              Sin subcategorías. <button type="button" @click='nuevaSub(<?= (int)$c['id'] ?>)' class="text-marca-700 font-semibold hover:underline">Agrega la primera</button>.</div>
          <?php else: ?>
            <table class="w-full text-sm">
              <tbody class="divide-y divide-zinc-100">
              <?php foreach ($lista as $s): ?>
                <tr class="<?= $s['activo'] ? '' : 'opacity-50' ?>">
                  <td class="pl-10 pr-4 py-2 text-zinc-700"><?= e($s['nombre']) ?></td>
                  <td class="px-4 py-2 text-xs text-zinc-400 text-right"><?= (int)$s['n_gastos'] > 0 ? (int)$s['n_gastos'].' gastos' : '' ?></td>
                  <td class="px-4 py-2 w-24">
                    <div class="flex items-center justify-end gap-1">
                      <button @click='editarSub(<?= json_encode($s, JSON_UNESCAPED_UNICODE) ?>)' class="p-1 rounded text-zinc-400 hover:bg-zinc-200 hover:text-marca-700" title="Editar">
                        <i data-lucide="pencil" class="w-3.5 h-3.5"></i></button>
                      <form method="post" class="inline">
                        <?= csrf_input() ?><input type="hidden" name="accion" value="sub_toggle"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="p-1 rounded text-zinc-400 hover:bg-zinc-200 hover:text-marca-700" title="<?= $s['activo'] ? 'Desactivar' : 'Activar' ?>">
                          <i data-lucide="<?= $s['activo'] ? 'toggle-right' : 'toggle-left' ?>" class="w-3.5 h-3.5"></i></button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Modal categoria -->
  <div x-show="openCat" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="openCat=false"></div>
    <div class="relative bg-white rounded-2xl shadow-xl border border-zinc-200 w-full max-w-md p-6" x-transition>
      <h3 class="font-display font-bold text-lg text-zinc-900 mb-4" x-text="cat.id ? 'Editar categoría' : 'Nueva categoría'"></h3>
      <form method="post" class="space-y-4">
        <?= csrf_input() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" :value="cat.id">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Nombre *</label>
          <input type="text" name="nombre" x-model="cat.nombre" required maxlength="100" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Descripción</label>
          <input type="text" name="descripcion" x-model="cat.descripcion" maxlength="255" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Color</label>
          <div class="flex items-center gap-2">
            <input type="color" name="color" x-model="cat.color" class="w-10 h-9 rounded border border-zinc-300 p-0.5 cursor-pointer">
            <input type="text" x-model="cat.color" maxlength="7" class="flex-1 px-2 py-2 rounded-lg border border-zinc-300 text-sm font-mono">
          </div>
          <p class="text-[11px] text-zinc-400 mt-1">Se usa en gráficas y listas.</p></div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="openCat=false" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-marca-600 hover:bg-marca-700 shadow-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal subcategoria -->
  <div x-show="openSub" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="openSub=false"></div>
    <div class="relative bg-white rounded-2xl shadow-xl border border-zinc-200 w-full max-w-md p-6" x-transition>
      <h3 class="font-display font-bold text-lg text-zinc-900 mb-4" x-text="sub.id ? 'Editar subcategoría' : 'Nueva subcategoría'"></h3>
      <form method="post" class="space-y-4">
        <?= csrf_input() ?><input type="hidden" name="accion" value="sub_guardar"><input type="hidden" name="id" :value="sub.id">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Categoría *</label>
          <select name="categoria_id" x-model="sub.categoria_id" required class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Nombre *</label>
          <input type="text" name="nombre" x-model="sub.nombre" required maxlength="100" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="openSub=false" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-marca-600 hover:bg-marca-700 shadow-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function catCategorias(){return{
  openCat:false, openSub:false,
  cat:{id:0,nombre:'',descripcion:'',color:'#7C3AED'},
  sub:{id:0,categoria_id:'',nombre:''},
  nuevaCat(){ this.cat={id:0,nombre:'',descripcion:'',color:'#7C3AED'}; this.openCat=true; },
  editarCat(c){ this.cat={id:c.id,nombre:c.nombre,descripcion:c.descripcion||'',
                color:c.color||'#7C3AED'}; this.openCat=true; },
  nuevaSub(catId){ this.sub={id:0,categoria_id:String(catId),nombre:''}; this.openSub=true; },
  editarSub(s){ this.sub={id:s.id,categoria_id:String(s.categoria_id),nombre:s.nombre}; this.openSub=true; }
}}
</script>
<?php require __DIR__ . '/../config/footer.php'; ?>
