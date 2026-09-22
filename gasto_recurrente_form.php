<?php
/** gasto_recurrente_form.php - Alta y edicion de gastos fijos recurrentes */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/tema.php';
require_once __DIR__ . '/config/gastos_helpers.php';
require_once __DIR__ . '/config/recurrentes_helpers.php';
requerir_login();
if (!(tiene_permiso('administrar') || tiene_permiso('crear_solicitud'))) {
    flash_set('error','No tienes permiso para capturar gastos fijos.');
    header('Location: '.url('gastos_recurrentes.php')); exit;
}

$id   = (int) input('id');
$edit = false;
$r    = null;
if ($id > 0) {
    $r = db_one("SELECT * FROM gastos_recurrentes WHERE id=:id", ['id'=>$id]);
    if (!$r) { flash_set('error','Gasto fijo no encontrado.'); header('Location: '.url('gastos_recurrentes.php')); exit; }
    $edit = true;
}

$form = [
    'area_id'=>'', 'categoria_id'=>'', 'subcategoria_id'=>'', 'concepto'=>'', 'monto'=>'',
    'proveedor_id'=>'', 'proveedor_texto'=>'', 'forma_pago_id'=>'',
    'dia_mes'=>'1', 'frecuencia'=>'mensual',
    'fecha_inicio'=>date('Y-m-01'), 'fecha_fin'=>'', 'activo'=>'1', 'notas'=>'',
];
if ($edit) { foreach ($form as $k=>$v) { if (array_key_exists($k, $r)) $form[$k] = (string)$r[$k]; } }

$errores = [];

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) { $errores[] = 'Sesión expirada, vuelve a intentar.'; }
    foreach (array_keys($form) as $k) {
        $form[$k] = is_string(input($k)) ? trim((string)input($k)) : (string)(input($k) ?? '');
    }
    $form['activo'] = input('activo') === '1' ? '1' : '0';

    $area  = (int) $form['area_id'];
    $cat   = (int) $form['categoria_id'];
    $sub   = (int) $form['subcategoria_id'] ?: null;
    $monto = (float) str_replace(',', '', (string)$form['monto']);
    $dia   = (int) $form['dia_mes'];

    if ($area <= 0) $errores[] = 'Selecciona un área.';
    if ($cat  <= 0) $errores[] = 'Selecciona una categoría.';
    if ($form['concepto'] === '') $errores[] = 'El concepto es obligatorio.';
    if ($monto <= 0) $errores[] = 'El monto debe ser mayor a cero.';
    if ($dia < 1 || $dia > 31) $errores[] = 'El día del mes debe estar entre 1 y 31.';
    if (!in_array($form['frecuencia'], ['mensual','bimestral','trimestral','semestral','anual'], true)) {
        $errores[] = 'Frecuencia inválida.';
    }
    if ($form['fecha_inicio'] === '' || !strtotime($form['fecha_inicio'])) {
        $errores[] = 'La fecha de inicio es obligatoria.';
    }
    if ($form['fecha_fin'] !== '') {
        if (!strtotime($form['fecha_fin'])) $errores[] = 'La fecha de fin no es válida.';
        elseif (strtotime($form['fecha_fin']) < strtotime($form['fecha_inicio'])) {
            $errores[] = 'La fecha de fin no puede ser anterior a la de inicio.';
        }
    }
    if ($sub) {
        if (!db_one("SELECT id FROM subcategorias_gasto WHERE id=:s AND categoria_id=:c", ['s'=>$sub,'c'=>$cat])) {
            $errores[] = 'La subcategoría no corresponde a la categoría elegida.';
        }
    }

    if (empty($errores)) {
        $params = [
            'a'    => $area,
            'c'    => $cat,
            's'    => $sub,
            'co'   => mb_substr($form['concepto'], 0, 200),
            'm'    => $monto,
            'prov' => (int)$form['proveedor_id'] ?: null,
            'provt'=> $form['proveedor_texto'] !== '' ? $form['proveedor_texto'] : null,
            'fp'   => (int)$form['forma_pago_id'] ?: null,
            'dia'  => $dia,
            'frec' => $form['frecuencia'],
            'fi'   => $form['fecha_inicio'],
            'ff'   => $form['fecha_fin'] !== '' ? $form['fecha_fin'] : null,
            'act'  => (int)$form['activo'],
            'not'  => $form['notas'] !== '' ? $form['notas'] : null,
        ];
        if ($edit) {
            $params['id'] = $id;
            db_exec("UPDATE gastos_recurrentes SET area_id=:a, categoria_id=:c, subcategoria_id=:s,
                        concepto=:co, monto=:m, proveedor_id=:prov, proveedor_texto=:provt,
                        forma_pago_id=:fp, dia_mes=:dia, frecuencia=:frec, fecha_inicio=:fi,
                        fecha_fin=:ff, activo=:act, notas=:not
                      WHERE id=:id", $params);
            registrar_auditoria('editar','gastos_recurrentes',$id,"Editó gasto fijo {$form['concepto']}");
            flash_set('success','Gasto fijo actualizado.');
        } else {
            $params['u'] = (int)(usuario_actual()['id'] ?? 0) ?: null;
            db_exec("INSERT INTO gastos_recurrentes
                       (area_id,categoria_id,subcategoria_id,concepto,monto,proveedor_id,proveedor_texto,
                        forma_pago_id,dia_mes,frecuencia,fecha_inicio,fecha_fin,activo,notas,creado_por)
                     VALUES (:a,:c,:s,:co,:m,:prov,:provt,:fp,:dia,:frec,:fi,:ff,:act,:not,:u)", $params);
            $id = db_last_id();
            registrar_auditoria('crear','gastos_recurrentes',$id,"Creó gasto fijo {$form['concepto']}");
            flash_set('success','Gasto fijo creado.');
        }
        header('Location: '.url('gastos_recurrentes.php')); exit;
    }
}

$areas      = areas_lista();
$cats       = categorias_lista();
$subsPorCat = subcategorias_por_categoria();
$formasPago = formas_pago_lista();
$provs      = db_all("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre");

$titulo_pagina = $edit ? 'Editar gasto fijo' : 'Nuevo gasto fijo';
$pagina_activa = 'recurrentes';
require __DIR__ . '/config/header.php';
?>
<div class="max-w-3xl mx-auto" x-data="recForm()">
  <a href="<?= url('gastos_recurrentes.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-2">
    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Gastos fijos</a>
  <h2 class="font-display text-2xl font-extrabold text-zinc-900 mb-1"><?= $edit ? 'Editar gasto fijo' : 'Nuevo gasto fijo' ?></h2>
  <p class="text-xs text-zinc-500 mb-5">Una plantilla que se repite. El sistema la convierte en un gasto real cada periodo, con un solo renglón de detalle.</p>

  <?php if ($errores): ?>
    <div class="mb-4 border-l-4 border-red-300 bg-red-50 text-red-800 rounded-lg px-4 py-3 text-sm">
      <ul class="list-disc pl-4 space-y-0.5"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-6 space-y-5">
    <?= csrf_input() ?><?php if ($edit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Área *</label>
        <select name="area_id" required class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
          <option value="">— Selecciona —</option>
          <?php foreach ($areas as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (string)$form['area_id']===(string)$a['id']?'selected':'' ?>><?= e($a['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Monto *</label>
        <div class="relative"><span class="absolute left-3 top-2 text-zinc-400 text-sm">$</span>
          <input type="number" step="0.01" min="0" name="monto" value="<?= e((string)$form['monto']) ?>" required
                 class="w-full pl-7 pr-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm tabular-nums"></div></div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Categoría *</label>
        <select name="categoria_id" x-model="categoria_id" @change="subcategoria_id=''" required
                class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
          <option value="">— Selecciona —</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Subcategoría</label>
        <select name="subcategoria_id" x-model="subcategoria_id"
                class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
          <option value="">— Sin subcategoría —</option>
          <template x-for="s in subcategorias" :key="s.id"><option :value="s.id" x-text="s.nombre"></option></template>
        </select></div>
    </div>

    <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Concepto *</label>
      <input type="text" name="concepto" value="<?= e((string)$form['concepto']) ?>" required maxlength="200"
             placeholder="Ej. Renta del local" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Proveedor</label>
        <select name="proveedor_id" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
          <option value="">— Del catálogo (opcional) —</option>
          <?php foreach ($provs as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= (string)$form['proveedor_id']===(string)$pr['id']?'selected':'' ?>><?= e($pr['nombre']) ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="proveedor_texto" value="<?= e((string)$form['proveedor_texto']) ?>" placeholder="…o escribe otro proveedor"
               class="w-full mt-2 px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
      <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Forma de pago</label>
        <select name="forma_pago_id" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
          <option value="">— Selecciona —</option>
          <?php foreach ($formasPago as $fp): ?><option value="<?= (int)$fp['id'] ?>" <?= (string)$form['forma_pago_id']===(string)$fp['id']?'selected':'' ?>><?= e($fp['nombre']) ?></option><?php endforeach; ?>
        </select></div>
    </div>

    <div class="rounded-xl border border-zinc-200 p-4 space-y-4">
      <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Cuándo se repite</div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Frecuencia *</label>
          <select name="frecuencia" x-model="frecuencia" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
            <?php foreach (['mensual'=>'Cada mes','bimestral'=>'Cada 2 meses','trimestral'=>'Cada 3 meses','semestral'=>'Cada 6 meses','anual'=>'Una vez al año'] as $k=>$v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Día del mes *</label>
          <input type="number" min="1" max="31" name="dia_mes" x-model="dia_mes"
                 class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
          <p class="text-[11px] text-zinc-400 mt-1">Si el mes no tiene ese día, se usa el último.</p></div>
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Inicia *</label>
          <input type="date" name="fecha_inicio" x-model="fecha_inicio" required
                 class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"></div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Termina (opcional)</label>
          <input type="date" name="fecha_fin" value="<?= e((string)$form['fecha_fin']) ?>"
                 class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm">
          <p class="text-[11px] text-zinc-400 mt-1">Déjalo vacío si no tiene fin.</p></div>
        <div class="flex items-end pb-1">
          <label class="inline-flex items-center gap-2 text-sm text-zinc-700">
            <input type="checkbox" name="activo" value="1" <?= $form['activo']==='1'?'checked':'' ?> class="rounded"> Activo
          </label></div>
      </div>
      <p class="text-xs text-zinc-500 bg-zinc-50 rounded-lg px-3 py-2" x-text="resumen"></p>
    </div>

    <div><label class="block text-xs font-semibold text-zinc-500 mb-1">Notas</label>
      <textarea name="notas" rows="2" class="w-full px-3 py-2 rounded-lg border border-zinc-300 focus:border-marca-500 outline-none text-sm"><?= e((string)$form['notas']) ?></textarea></div>

    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-100">
      <a href="<?= url('gastos_recurrentes.php') ?>" class="px-4 py-2 rounded-lg text-sm font-medium text-zinc-600 hover:bg-zinc-100">Cancelar</a>
      <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold text-white bg-marca-600 hover:bg-marca-700 shadow-sm">
        <i data-lucide="save" class="w-4 h-4"></i> <?= $edit ? 'Guardar cambios' : 'Crear gasto fijo' ?></button>
    </div>
  </form>
</div>

<script>
const SUBCATS_REC = <?= json_encode($subsPorCat, JSON_UNESCAPED_UNICODE) ?>;
function recForm(){
  return {
    categoria_id:    <?= json_encode((string)$form['categoria_id']) ?>,
    subcategoria_id: <?= json_encode((string)$form['subcategoria_id']) ?>,
    frecuencia:      <?= json_encode((string)$form['frecuencia']) ?>,
    dia_mes:         <?= json_encode((string)$form['dia_mes']) ?>,
    fecha_inicio:    <?= json_encode((string)$form['fecha_inicio']) ?>,
    get subcategorias(){ return SUBCATS_REC[this.categoria_id] || []; },
    get resumen(){
      const cada = {mensual:'cada mes', bimestral:'cada 2 meses', trimestral:'cada 3 meses',
                    semestral:'cada 6 meses', anual:'una vez al año'}[this.frecuencia] || '';
      const d = parseInt(this.dia_mes) || 1;
      const desde = this.fecha_inicio ? (' a partir del ' + this.fecha_inicio) : '';
      return 'Se generará el día ' + d + ' ' + cada + desde + '.';
    }
  };
}
</script>
<?php require __DIR__ . '/config/footer.php'; ?>
