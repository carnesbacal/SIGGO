<?php
/** admin/insumos.php - Catalogo cerrado de insumos + bandeja de "Otro" por clasificar */
require __DIR__ . '/../config/admin_helpers.php';
require_once __DIR__ . '/../config/tema.php';
require_once __DIR__ . '/../config/gastos_helpers.php';

$volver = url('admin/insumos.php');

// Si no se ha corrido siggo_02_insumos.sql, avisar en vez de tronar
if (!modulo_insumos_instalado()) {
    $titulo_pagina = 'Insumos';
    $pagina_activa = 'insumos';
    require __DIR__ . '/../config/header.php';
    ?>
    <div class="max-w-2xl mx-auto">
      <h2 class="font-display text-2xl font-extrabold text-zinc-900 mb-1">Insumos</h2>
      <p class="text-xs text-zinc-500 mb-5">Catálogo de consumibles de la tienda.</p>
      <div class="bg-white rounded-2xl border shadow-sm p-6" style="border-color:<?= EST_WARN ?>55">
        <div class="flex items-start gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
               style="background:<?= EST_WARN ?>1a;color:<?= EST_WARN ?>">
            <i data-lucide="database" class="w-5 h-5"></i></div>
          <div>
            <h3 class="font-display font-bold text-zinc-800 mb-1">Falta preparar la base de datos</h3>
            <p class="text-sm text-zinc-600 mb-4">
              Este módulo necesita la tabla de insumos, que todavía no existe en
              <b><?= e(DB_NAME) ?></b>. Es un solo archivo y no borra nada de lo que ya tienes.</p>
            <ol class="text-sm text-zinc-600 space-y-1.5 list-decimal pl-4">
              <li>Abre <b>phpMyAdmin</b> y selecciona la base <code class="bg-zinc-100 px-1.5 py-0.5 rounded"><?= e(DB_NAME) ?></code></li>
              <li>Ve a la pestaña <b>Importar</b></li>
              <li>Elige el archivo <code class="bg-zinc-100 px-1.5 py-0.5 rounded">siggo_02_insumos.sql</code>, que está en la carpeta del proyecto</li>
              <li>Presiona <b>Continuar</b> y recarga esta página</li>
            </ol>
          </div>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/../config/footer.php';
    exit;
}

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { flash_set('error','Sesión expirada, intenta de nuevo.'); header("Location: $volver"); exit; }
    $accion = input('accion');

    if ($accion === 'toggle') {
        admin_toggle_activo('insumos', (int)input('id'), 'Insumo');
        header("Location: $volver"); exit;
    }

    if ($accion === 'guardar') {
        $id     = (int) input('id');
        $nombre = trim((string) input('nombre'));
        $codigo = strtoupper(trim((string) input('codigo')));
        $cat    = (int) input('categoria_id') ?: null;
        $sub    = (int) input('subcategoria_id') ?: null;
        $uni    = (int) input('unidad_id') ?: null;
        $area   = (int) input('area_sugerida_id') ?: null;
        $orden  = (int) input('orden');
        $notas  = trim((string) input('notas'));
        // Si viene de la bandeja, este es el texto libre que se va a absorber
        $absorbe = trim((string) input('absorbe'));

        if ($nombre === '') { flash_set('error','El nombre del insumo es obligatorio.'); header("Location: $volver"); exit; }
        if (db_one("SELECT id FROM insumos WHERE nombre=:n AND id<>:id", ['n'=>$nombre,'id'=>$id])) {
            flash_set('error','Ya existe un insumo con ese nombre.'); header("Location: $volver"); exit;
        }
        if ($sub && $cat && !db_one("SELECT id FROM subcategorias_gasto WHERE id=:s AND categoria_id=:c", ['s'=>$sub,'c'=>$cat])) {
            flash_set('error','La subcategoría no corresponde a la categoría elegida.'); header("Location: $volver"); exit;
        }

        $p = ['n'=>$nombre,'c'=>($codigo?:null),'cat'=>$cat,'sub'=>$sub,'u'=>$uni,'a'=>$area,'o'=>$orden,'no'=>($notas?:null)];
        if ($id > 0) {
            $p['id'] = $id;
            db_exec("UPDATE insumos SET nombre=:n, codigo=:c, categoria_id=:cat, subcategoria_id=:sub,
                        unidad_id=:u, area_sugerida_id=:a, orden=:o, notas=:no WHERE id=:id", $p);
            registrar_auditoria('editar','insumos',$id,"Editó insumo $nombre");
            flash_set('success','Insumo actualizado.');
        } else {
            $p['cp'] = (int)(usuario_actual()['id'] ?? 0) ?: null;
            db_exec("INSERT INTO insumos (nombre,codigo,categoria_id,subcategoria_id,unidad_id,area_sugerida_id,orden,notas,creado_por)
                     VALUES (:n,:c,:cat,:sub,:u,:a,:o,:no,:cp)", $p);
            $id = db_last_id();
            registrar_auditoria('crear','insumos',$id,"Creó insumo $nombre");
            flash_set('success','Insumo agregado al catálogo.');
        }

        // Absorber los renglones que se habían capturado como "Otro" con ese texto
        if ($absorbe !== '' && $id > 0) {
            $n = db_exec("UPDATE gasto_items
                             SET insumo_id = :i, descripcion = :d, codigo = :c
                           WHERE insumo_id IS NULL AND TRIM(descripcion) = :t",
                         ['i'=>$id, 'd'=>$nombre, 'c'=>($codigo?:null), 't'=>$absorbe]);
            if ($n > 0) {
                registrar_auditoria('clasificar','insumos',$id,"Clasificó $n renglones como «{$nombre}»");
                flash_set('success', "Insumo agregado y $n " . ($n===1?'renglón clasificado':'renglones clasificados') . '.');
            }
        }
        header("Location: $volver"); exit;
    }

    // Descartar: ese texto no es un consumible (renta, luz, un servicio...)
    if ($accion === 'descartar') {
        $texto = trim((string) input('texto'));
        if ($texto === '') { flash_set('error','Falta el texto.'); header("Location: $volver"); exit; }
        $n = db_exec("UPDATE gasto_items SET no_es_insumo = 1
                       WHERE insumo_id IS NULL AND TRIM(descripcion) = :t", ['t'=>$texto]);
        registrar_auditoria('descartar','gasto_items',0,"Marcó «{$texto}» como no insumo ($n renglones)");
        flash_set('success', "«{$texto}» ya no aparecerá en la bandeja ($n " . ($n===1?'renglón':'renglones') . ').');
        header("Location: $volver"); exit;
    }

    // Asignar un texto pendiente a un insumo que YA existe
    if ($accion === 'asignar') {
        $insumoId = (int) input('insumo_id');
        $texto    = trim((string) input('texto'));
        if ($insumoId <= 0 || $texto === '') { flash_set('error','Falta el insumo o el texto.'); header("Location: $volver"); exit; }
        $ins = db_one("SELECT nombre, codigo FROM insumos WHERE id=:i", ['i'=>$insumoId]);
        if (!$ins) { flash_set('error','Ese insumo ya no existe.'); header("Location: $volver"); exit; }
        $n = db_exec("UPDATE gasto_items SET insumo_id=:i, descripcion=:d, codigo=:c
                       WHERE insumo_id IS NULL AND TRIM(descripcion)=:t",
                     ['i'=>$insumoId, 'd'=>$ins['nombre'], 'c'=>$ins['codigo'], 't'=>$texto]);
        registrar_auditoria('clasificar','insumos',$insumoId,"Clasificó $n renglones como «{$ins['nombre']}»");
        flash_set('success', "$n " . ($n===1?'renglón clasificado':'renglones clasificados') . " como «{$ins['nombre']}».");
        header("Location: $volver"); exit;
    }
}

$anio = (int) date('Y');
$rows = db_all("SELECT i.*, c.nombre AS cat, c.color AS catcolor, s.nombre AS subcat,
                       u.clave AS unidad, a.nombre AS area,
                       (SELECT COUNT(*) FROM gasto_items it WHERE it.insumo_id = i.id) AS n_compras,
                       (SELECT COALESCE(SUM(it.importe),0) FROM gasto_items it
                          INNER JOIN gastos g ON g.id = it.gasto_id
                          WHERE it.insumo_id = i.id AND g.anio = :y) AS gasto_anio,
                       (SELECT it.precio_unitario FROM gasto_items it
                          INNER JOIN gastos g ON g.id = it.gasto_id
                          WHERE it.insumo_id = i.id ORDER BY g.fecha DESC, it.id DESC LIMIT 1) AS ultimo_precio
                  FROM insumos i
                  LEFT JOIN categorias_gasto c    ON c.id = i.categoria_id
                  LEFT JOIN subcategorias_gasto s ON s.id = i.subcategoria_id
                  LEFT JOIN unidades_medida u     ON u.id = i.unidad_id
                  LEFT JOIN areas a               ON a.id = i.area_sugerida_id
                 ORDER BY i.activo DESC, s.nombre, i.orden, i.nombre", ['y'=>$anio]);

$pendientes = insumos_pendientes();
$cats       = categorias_lista();
$subsPorCat = subcategorias_por_categoria();
$unidades   = unidades_lista();
$areas      = areas_lista();
$activos    = array_values(array_filter($rows, fn($r) => (int)$r['activo'] === 1));

$titulo_pagina = 'Insumos';
$pagina_activa = 'insumos';
require __DIR__ . '/../config/header.php';
?>
<div x-data="catInsumos()">
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <div>
      <h2 class="font-display text-2xl font-extrabold text-zinc-900">Insumos</h2>
      <p class="text-xs text-zinc-500 mt-0.5">Catálogo cerrado de consumibles. El gerente elige de aquí; solo tú puedes agregar.</p>
    </div>
    <button @click="nuevo()" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm">
      <i data-lucide="plus" class="w-4 h-4"></i> Nuevo insumo
    </button>
  </div>

  <!-- ============ BANDEJA DE PENDIENTES ============ -->
  <?php if ($pendientes): ?>
  <div class="bg-white rounded-2xl border shadow-sm overflow-hidden mb-4" style="border-color:<?= EST_WARN ?>55">
    <div class="px-5 py-3 border-b flex items-center gap-2" style="border-color:<?= EST_WARN ?>33;background:<?= EST_WARN ?>0d">
      <i data-lucide="inbox" class="w-4 h-4" style="color:<?= EST_WARN ?>"></i>
      <h3 class="font-display font-bold text-sm flex-1" style="color:<?= EST_WARN ?>">Por clasificar</h3>
      <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background:<?= EST_WARN ?>1f;color:<?= EST_WARN ?>">
        <?= count($pendientes) ?></span>
    </div>
    <div class="px-5 py-2.5 text-xs text-zinc-500 border-b border-zinc-100">
      Esto lo capturaron los gerentes como «Otro» porque no estaba en el catálogo.
      Conviértelo en insumo, asígnalo a uno que ya exista, o descártalo si no es un consumible
      (la renta y la luz nunca van a ser insumos).
    </div>
    <table class="w-full text-sm">
      <tbody class="divide-y divide-zinc-100">
      <?php foreach ($pendientes as $pd): ?>
        <tr>
          <td class="px-5 py-2.5">
            <div class="text-zinc-800 font-medium"><?= e($pd['texto']) ?></div>
            <div class="text-[11px] text-zinc-400">
              <?= (int)$pd['veces'] ?> <?= (int)$pd['veces']===1?'vez':'veces' ?> ·
              $<?= number_format((float)$pd['importe'],2) ?> ·
              última <?= fmt_fecha($pd['ultima'], false) ?>
            </div>
          </td>
          <td class="px-5 py-2.5 w-96">
            <div class="flex flex-wrap items-center justify-end gap-2">
              <form method="post" class="flex items-center gap-1.5">
                <?= csrf_input() ?><input type="hidden" name="accion" value="asignar">
                <input type="hidden" name="texto" value="<?= e($pd['texto']) ?>">
                <select name="insumo_id" required class="px-2 py-1.5 rounded-lg border border-zinc-300 text-xs max-w-[190px]">
                  <option value="">Asignar a uno existente…</option>
                  <?php foreach ($activos as $a): ?>
                    <option value="<?= (int)$a['id'] ?>"><?= e($a['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="px-2.5 py-1.5 rounded-lg border border-zinc-200 text-zinc-700 hover:bg-zinc-50 text-xs font-semibold">Asignar</button>
              </form>
              <button type="button" @click='desdePendiente(<?= json_encode($pd, JSON_UNESCAPED_UNICODE) ?>)'
                      class="px-2.5 py-1.5 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-xs font-semibold whitespace-nowrap">
                Dar de alta</button>
              <form method="post" class="inline" onsubmit="return confirm('¿Marcar «<?= e($pd['texto']) ?>» como algo que no es un insumo? Dejará de aparecer aquí.')">
                <?= csrf_input() ?><input type="hidden" name="accion" value="descartar">
                <input type="hidden" name="texto" value="<?= e($pd['texto']) ?>">
                <button type="submit" class="px-2.5 py-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 text-xs font-semibold whitespace-nowrap"
                        title="No es un consumible: es un servicio, renta, etc.">No es insumo</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm px-5 py-3 mb-4 flex items-center gap-2 text-sm text-zinc-500">
    <i data-lucide="check-circle-2" class="w-4 h-4" style="color:<?= EST_OK ?>"></i>
    No hay renglones pendientes de clasificar.
  </div>
  <?php endif; ?>

  <!-- ============ CATÁLOGO ============ -->
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
          <th class="px-4 py-3 font-semibold">Código</th>
          <th class="px-4 py-3 font-semibold">Insumo</th>
          <th class="px-4 py-3 font-semibold">Clasificación</th>
          <th class="px-4 py-3 font-semibold">Unidad</th>
          <th class="px-4 py-3 font-semibold text-right">Último precio</th>
          <th class="px-4 py-3 font-semibold text-right">Gasto <?= $anio ?></th>
          <th class="px-4 py-3 font-semibold text-right">Acciones</th>
        </tr></thead>
        <tbody class="divide-y divide-zinc-100">
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="px-4 py-10 text-center text-zinc-400">Aún no hay insumos. Crea el primero.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr class="hover:bg-zinc-50 <?= $r['activo'] ? '' : 'opacity-60' ?>">
            <td class="px-4 py-2.5 font-mono text-xs text-marca-700 whitespace-nowrap"><?= e($r['codigo'] ?: '—') ?></td>
            <td class="px-4 py-2.5">
              <div class="font-medium text-zinc-800"><?= e($r['nombre']) ?></div>
              <?php if ($r['area']): ?><div class="text-[11px] text-zinc-400"><?= e($r['area']) ?></div><?php endif; ?>
            </td>
            <td class="px-4 py-2.5">
              <?php if ($r['cat']): ?>
                <span class="inline-flex items-center gap-1.5 text-zinc-600 whitespace-nowrap">
                  <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:<?= e($r['catcolor']) ?>"></span><?= e($r['cat']) ?></span>
                <?php if ($r['subcat']): ?><div class="text-[11px] text-zinc-400 pl-3.5"><?= e($r['subcat']) ?></div><?php endif; ?>
              <?php else: ?><span class="text-zinc-300">—</span><?php endif; ?>
            </td>
            <td class="px-4 py-2.5 text-xs text-zinc-500"><?= e($r['unidad'] ?: '—') ?></td>
            <td class="px-4 py-2.5 text-right tabular-nums text-zinc-700">
              <?= $r['ultimo_precio'] !== null ? '$'.number_format((float)$r['ultimo_precio'],2) : '<span class="text-zinc-300">—</span>' ?>
              <?php if ((int)$r['n_compras'] > 0): ?>
                <div class="text-[10px] text-zinc-400"><?= (int)$r['n_compras'] ?> compras</div><?php endif; ?>
            </td>
            <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-zinc-800">
              <?= (float)$r['gasto_anio'] > 0 ? '$'.number_format((float)$r['gasto_anio'],2) : '<span class="text-zinc-300 font-normal">—</span>' ?>
            </td>
            <td class="px-4 py-2.5">
              <div class="flex items-center justify-end gap-1">
                <button @click='editar(<?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>)' class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-marca-700" title="Editar">
                  <i data-lucide="pencil" class="w-4 h-4"></i></button>
                <form method="post" class="inline">
                  <?= csrf_input() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="p-1.5 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-marca-700" title="<?= $r['activo'] ? 'Desactivar' : 'Activar' ?>">
                    <i data-lucide="<?= $r['activo'] ? 'toggle-right' : 'toggle-left' ?>" class="w-4 h-4"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <p class="text-[11px] text-zinc-400 mt-2 px-1">Un insumo con compras no se borra, solo se desactiva: así el histórico no pierde a qué correspondía cada renglón.</p>

  <!-- ============ MODAL ============ -->
  <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="open=false"></div>
    <div class="relative bg-white rounded-2xl shadow-xl border border-zinc-200 w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto" x-transition>
      <h3 class="font-display font-bold text-lg text-zinc-900 mb-1" x-text="form.id ? 'Editar insumo' : 'Nuevo insumo'"></h3>
      <p x-show="form.absorbe" x-cloak class="text-xs mb-3" :style="'color:<?= EST_WARN ?>'">
        Al guardarlo, los renglones capturados como «<span x-text="form.absorbe"></span>» se van a reasignar a este insumo.</p>
      <form method="post" class="space-y-4 mt-3">
        <?= csrf_input() ?><input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" :value="form.id"><input type="hidden" name="absorbe" :value="form.absorbe">

        <div class="grid grid-cols-3 gap-3">
          <div class="col-span-2"><label class="block text-xs font-semibold text-zinc-500 mb-1">Nombre *</label>
            <input type="text" name="nombre" x-model="form.nombre" required maxlength="150"
                   class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
          <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Código</label>
            <input type="text" name="codigo" x-model="form.codigo" maxlength="40"
                   class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm uppercase"></div>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Categoría</label>
            <select name="categoria_id" x-model="form.categoria_id" @change="form.subcategoria_id=''"
                    class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
              <option value="">— Sin categoría —</option>
              <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Subcategoría</label>
            <select name="subcategoria_id" x-model="form.subcategoria_id"
                    class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
              <option value="">— Sin subcategoría —</option>
              <template x-for="s in subcategorias" :key="s.id"><option :value="s.id" x-text="s.nombre"></option></template>
            </select></div>
        </div>
        <p class="text-[11px] text-zinc-400 -mt-2">Al elegir este insumo en un gasto, la categoría se llena sola.</p>

        <div class="grid grid-cols-2 gap-3">
          <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Unidad</label>
            <select name="unidad_id" x-model="form.unidad_id" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
              <option value="">— Sin unidad —</option>
              <?php foreach ($unidades as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['clave']) ?> · <?= e($u['nombre']) ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Área que lo usa</label>
            <select name="area_sugerida_id" x-model="form.area_sugerida_id" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
              <option value="">— Cualquiera —</option>
              <?php foreach ($areas as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['nombre']) ?></option><?php endforeach; ?>
            </select></div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Orden</label>
            <input type="number" name="orden" x-model.number="form.orden"
                   class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
          <div class="col-span-2"><label class="block text-xs font-semibold text-zinc-500 mb-1">Notas</label>
            <input type="text" name="notas" x-model="form.notas" maxlength="255"
                   class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button type="button" @click="open=false" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</button>
          <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white bg-marca-600 hover:bg-marca-700 shadow-sm">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
const SUBS_INS = <?= json_encode($subsPorCat, JSON_UNESCAPED_UNICODE) ?>;
function catInsumos(){return{
  open:false,
  vacio:{id:0,nombre:'',codigo:'',categoria_id:'',subcategoria_id:'',unidad_id:'',area_sugerida_id:'',orden:0,notas:'',absorbe:''},
  form:{id:0,nombre:'',codigo:'',categoria_id:'',subcategoria_id:'',unidad_id:'',area_sugerida_id:'',orden:0,notas:'',absorbe:''},
  get subcategorias(){ return SUBS_INS[this.form.categoria_id] || []; },
  nuevo(){ this.form={...this.vacio}; this.open=true; },
  editar(r){
    this.form={id:r.id, nombre:r.nombre, codigo:r.codigo||'',
      categoria_id:r.categoria_id?String(r.categoria_id):'',
      subcategoria_id:r.subcategoria_id?String(r.subcategoria_id):'',
      unidad_id:r.unidad_id?String(r.unidad_id):'',
      area_sugerida_id:r.area_sugerida_id?String(r.area_sugerida_id):'',
      orden:r.orden||0, notas:r.notas||'', absorbe:''};
    this.open=true;
  },
  // Viene de la bandeja: precarga el texto que capturó el gerente
  desdePendiente(p){
    this.form={...this.vacio,
      nombre:p.texto,
      categoria_id:p.categoria_id?String(p.categoria_id):'',
      absorbe:p.texto};
    this.open=true;
  }
}}
</script>
<?php require __DIR__ . '/../config/footer.php'; ?>
