<?php
/** gastos.php - Lista de gastos con filtros */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/tema.php';
require_once __DIR__ . '/config/gastos_helpers.php';
require_once __DIR__ . '/config/controles_helpers.php';
requerir_login();
$puede_capturar = tiene_permiso('administrar') || tiene_permiso('crear_solicitud');

$anio = (int) (input('anio') ?: date('Y'));
$mes  = (int) input('mes');
$area = (int) input('area_id');
$cat  = (int) input('categoria_id');
$sub  = (int) input('subcategoria_id');
$tipo = (string) input('tipo');
$est  = (string) input('estatus');
$fp   = (int) input('forma_pago_id');
$q    = trim((string) input('q'));

$where = ['g.anio=:anio']; $pp = ['anio'=>$anio];
if ($mes >= 1 && $mes <= 12)                  { $where[] = 'g.mes=:mes';             $pp['mes']  = $mes; }
if ($area > 0)                                { $where[] = 'g.area_id=:area';        $pp['area'] = $area; }
if ($cat  > 0)                                { $where[] = 'g.categoria_id=:cat';    $pp['cat']  = $cat; }
if ($sub  > 0)                                { $where[] = 'g.subcategoria_id=:sub'; $pp['sub']  = $sub; }
if ($tipo === 'fijo' || $tipo === 'variable') { $where[] = 'g.tipo=:tipo';           $pp['tipo'] = $tipo; }
if (in_array($est, ['pendiente','parcial','pagado','cancelado'], true)) { $where[] = 'g.estatus_pago=:est'; $pp['est'] = $est; }
if ($fp > 0)                                  { $where[] = 'g.forma_pago_id=:fp';    $pp['fp']   = $fp; }
if ($q !== '') {
    // PDO va sin emular preparadas: un placeholder con nombre no se puede
    // repetir, por eso va uno distinto para cada comparacion.
    $where[] = '(g.concepto LIKE :q1 OR g.folio LIKE :q2 OR g.numero_factura LIKE :q3
                 OR EXISTS (SELECT 1 FROM gasto_items i WHERE i.gasto_id=g.id
                            AND (i.descripcion LIKE :q4 OR i.codigo LIKE :q5)))';
    $like = '%' . $q . '%';
    $pp['q1'] = $like; $pp['q2'] = $like; $pp['q3'] = $like;
    $pp['q4'] = $like; $pp['q5'] = $like;
}
$wsql = implode(' AND ', $where);

$rows = db_all("SELECT g.*, a.nombre AS area, c.nombre AS cat, c.color AS catcolor,
                       s.nombre AS subcat, pr.nombre AS prov, f.nombre AS forma_pago,
                       (SELECT COUNT(*) FROM gasto_items i WHERE i.gasto_id=g.id) AS n_items,
                       (SELECT COUNT(*) FROM gasto_distribucion d WHERE d.gasto_id=g.id) AS n_reparto
                  FROM gastos g
                  INNER JOIN areas a            ON g.area_id = a.id
                  INNER JOIN categorias_gasto c ON g.categoria_id = c.id
                  LEFT JOIN subcategorias_gasto s ON g.subcategoria_id = s.id
                  LEFT JOIN proveedores pr      ON g.proveedor_id = pr.id
                  LEFT JOIN formas_pago f       ON g.forma_pago_id = f.id
                 WHERE $wsql ORDER BY g.fecha DESC, g.id DESC", $pp);

$total = 0.0; $pendiente = 0.0;
foreach ($rows as $r) {
    $total += (float)$r['monto'];
    if (in_array($r['estatus_pago'], ['pendiente','parcial'], true)) $pendiente += (float)$r['monto'];
}

$areas      = areas_lista();
$cats       = categorias_lista();
$subsPorCat = subcategorias_por_categoria();
$formasPago = formas_pago_lista();
$subsTodas  = db_all("SELECT id, nombre, categoria_id FROM subcategorias_gasto WHERE activo=1 ORDER BY nombre");
$anio_actual = (int) date('Y');
$meses_nom   = meses_nombres();

// Los años del filtro salen de lo que REALMENTE hay capturado, más el rango
// normal. Si alguien se equivocó de fecha y el gasto quedó en 2018, tiene que
// poder llegar a él desde aquí para corregirlo.
$anios = [];
for ($y = $anio_actual + 1; $y >= $anio_actual - 4; $y--) $anios[$y] = true;
foreach (db_all("SELECT DISTINCT anio FROM gastos WHERE anio IS NOT NULL ORDER BY anio DESC") as $r) {
    $anios[(int)$r['anio']] = true;
}
$anios[$anio] = true;
$anios = array_keys($anios);
rsort($anios);

// Chips de filtros activos
$nom = function(array $lista, int $id, string $campo='nombre'): string {
    foreach ($lista as $x) { if ((int)$x['id'] === $id) return (string)$x[$campo]; } return '';
};
$curParams = ['anio'=>$anio];
if ($mes>=1&&$mes<=12) $curParams['mes']=$mes;
if ($area>0) $curParams['area_id']=$area;
if ($cat>0)  $curParams['categoria_id']=$cat;
if ($sub>0)  $curParams['subcategoria_id']=$sub;
if ($tipo!=='') $curParams['tipo']=$tipo;
if ($est!=='')  $curParams['estatus']=$est;
if ($fp>0)   $curParams['forma_pago_id']=$fp;
if ($q!=='')  $curParams['q']=$q;

$chips=[];
if ($mes>=1&&$mes<=12) $chips[]=['k'=>'mes','t'=>'Mes: '.$meses_nom[$mes]];
if ($area>0) $chips[]=['k'=>'area_id','t'=>'Área: '.$nom($areas,$area)];
if ($cat>0)  $chips[]=['k'=>'categoria_id','t'=>'Categoría: '.$nom($cats,$cat)];
if ($sub>0)  $chips[]=['k'=>'subcategoria_id','t'=>'Subcategoría: '.$nom($subsTodas,$sub)];
if ($tipo==='fijo'||$tipo==='variable') $chips[]=['k'=>'tipo','t'=>'Tipo: '.ucfirst($tipo)];
if ($est!=='') $chips[]=['k'=>'estatus','t'=>'Estatus: '.texto_estatus($est)];
if ($fp>0)   $chips[]=['k'=>'forma_pago_id','t'=>'Pago: '.$nom($formasPago,$fp)];
if ($q!=='')  $chips[]=['k'=>'q','t'=>'Busca: '.$q];
$chipUrl = function(string $quitar) use ($curParams){ $b=$curParams; unset($b[$quitar]); return url('gastos.php').($b?('?'.http_build_query($b)):''); };

$titulo_pagina = 'Gastos';
$pagina_activa = 'gastos';
require __DIR__ . '/config/header.php';
?>
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
  <div>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Gastos de operación</h2>
    <p class="text-xs text-zinc-500 mt-0.5">Captura y consulta los gastos de la sucursal.</p>
  </div>
  <?php if($puede_capturar): ?>
  <a href="<?= url('gasto_form.php') ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm">
    <i data-lucide="plus" class="w-4 h-4"></i> Nuevo gasto</a>
  <?php endif; ?>
</div>

<script>
  // Va aqui y no dentro de x-data: el JSON trae comillas dobles y romperia el atributo.
  const SUBCATS_FILTRO = <?= json_encode($subsPorCat, JSON_UNESCAPED_UNICODE) ?>;
</script>
<form method="get" class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4 mb-4"
      x-data="{ cat: '<?= (int)$cat ?>', subs: SUBCATS_FILTRO }">
  <div class="flex items-center gap-2 mb-3">
    <i data-lucide="sliders-horizontal" class="w-4 h-4 text-zinc-500"></i>
    <span class="text-sm font-semibold text-zinc-700">Filtros</span>
    <?php if($chips): ?><span class="text-[11px] font-semibold text-marca-700 bg-marca-50 rounded-full px-2 py-0.5"><?= count($chips) ?> activo<?= count($chips)===1?'':'s' ?></span><?php endif; ?>
  </div>

  <div class="mb-3">
    <div class="relative">
      <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400"></i>
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por concepto, folio, factura o producto del detalle…"
             class="w-full h-9 pl-9 pr-3 rounded-lg border border-zinc-300 bg-white text-sm">
    </div>
  </div>

  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Año</label>
      <select name="anio" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <?php foreach ($anios as $y): ?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Mes</label>
      <select name="mes" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="0">Todos</option>
        <?php foreach ($meses_nom as $mk=>$mv): ?><option value="<?= $mk ?>" <?= $mes===$mk?'selected':'' ?>><?= $mv ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Área</label>
      <select name="area_id" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="0">Todas</option>
        <?php foreach ($areas as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $area===(int)$a['id']?'selected':'' ?>><?= e($a['nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Categoría</label>
      <select name="categoria_id" x-model="cat" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="0">Todas</option>
        <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cat===(int)$c['id']?'selected':'' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Subcategoría</label>
      <select name="subcategoria_id" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="0">Todas</option>
        <template x-for="s in (subs[cat] || [])" :key="s.id">
          <option :value="s.id" :selected="s.id == <?= $sub ?>" x-text="s.nombre"></option>
        </template>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Tipo</label>
      <select name="tipo" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="">Todos</option>
        <option value="variable" <?= $tipo==='variable'?'selected':'' ?>>Variable</option>
        <option value="fijo" <?= $tipo==='fijo'?'selected':'' ?>>Fijo</option>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Estatus de pago</label>
      <select name="estatus" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="">Todos</option>
        <?php foreach (['pagado','pendiente','parcial','cancelado'] as $es): ?>
          <option value="<?= $es ?>" <?= $est===$es?'selected':'' ?>><?= e(texto_estatus($es)) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Forma de pago</label>
      <select name="forma_pago_id" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="0">Todas</option>
        <?php foreach ($formasPago as $f): ?><option value="<?= (int)$f['id'] ?>" <?= $fp===(int)$f['id']?'selected':'' ?>><?= e($f['nombre']) ?></option><?php endforeach; ?>
      </select></div>
  </div>

  <?php if($chips): ?>
  <div class="flex flex-wrap items-center gap-1.5 mt-3">
    <span class="text-[11px] text-zinc-400">Activos:</span>
    <?php foreach($chips as $ch): ?>
      <a href="<?= e($chipUrl($ch['k'])) ?>" class="group inline-flex items-center gap-1 pl-2.5 pr-1.5 py-1 rounded-full bg-zinc-100 hover:bg-marca-50 text-xs text-zinc-600 hover:text-marca-700 transition"><?= e($ch['t']) ?><i data-lucide="x" class="w-3 h-3 text-zinc-400 group-hover:text-marca-600"></i></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="flex items-center justify-end gap-2 mt-3 pt-3 border-t border-zinc-100">
    <a href="<?= url('gastos.php') ?>" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-lg border border-zinc-200 text-zinc-600 hover:bg-zinc-50 text-sm font-semibold">Limpiar</a>
    <button type="submit" class="inline-flex items-center gap-1.5 h-9 px-4 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm"><i data-lucide="search" class="w-4 h-4"></i> Filtrar</button>
  </div>
</form>

<div class="flex flex-wrap items-center justify-between gap-2 mb-3 px-1">
  <span class="text-sm text-zinc-500"><?= count($rows) ?> gasto<?= count($rows)===1?'':'s' ?></span>
  <div class="flex items-center gap-4">
    <?php if ($pendiente > 0): ?>
      <span class="text-sm text-zinc-500">Por pagar:
        <span class="font-display font-bold tabular-nums" style="color:<?= EST_BAD ?>">$<?= number_format($pendiente,2) ?></span></span>
    <?php endif; ?>
    <span class="text-sm text-zinc-500">Total:
      <span class="font-display font-bold text-zinc-800 tabular-nums">$<?= number_format($total,2) ?></span></span>
  </div>
</div>

<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-3 font-semibold">Folio</th>
        <th class="px-4 py-3 font-semibold">Fecha</th>
        <th class="px-4 py-3 font-semibold">Concepto</th>
        <th class="px-4 py-3 font-semibold">Área</th>
        <th class="px-4 py-3 font-semibold">Categoría</th>
        <th class="px-4 py-3 font-semibold text-right">Importe</th>
        <th class="px-4 py-3 font-semibold">Pago</th>
        <th class="px-4 py-3 font-semibold text-center">Comp.</th>
        <th class="px-4 py-3 font-semibold text-right"></th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="px-4 py-10 text-center text-zinc-400">
          No hay gastos con estos filtros.
          <?php if($puede_capturar): ?><a href="<?= url('gasto_form.php') ?>" class="text-marca-700 font-semibold">Registra el primero</a>.<?php endif; ?>
        </td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr class="hover:bg-zinc-50 cursor-pointer" onclick="location.href='<?= url('gasto_ver.php?id='.(int)$r['id']) ?>'">
          <td class="px-4 py-3 font-mono text-xs text-marca-700 whitespace-nowrap"><?= e($r['folio']) ?></td>
          <td class="px-4 py-3 text-zinc-500 whitespace-nowrap"><?= fmt_fecha($r['fecha'], false) ?></td>
          <td class="px-4 py-3">
            <div class="font-medium text-zinc-800"><?= e($r['concepto']) ?></div>
            <div class="flex flex-wrap items-center gap-x-2 text-xs text-zinc-400">
              <?php if ($r['prov'] || $r['proveedor_texto']): ?><span><?= e($r['prov'] ?: $r['proveedor_texto']) ?></span><?php endif; ?>
              <?php if ((int)$r['n_items'] > 1): ?><span><?= (int)$r['n_items'] ?> renglones</span><?php endif; ?>
              <?php if ((int)$r['n_reparto'] > 0): ?><span class="text-marca-600">repartido en <?= (int)$r['n_reparto'] ?> áreas</span><?php endif; ?>
            </div>
          </td>
          <td class="px-4 py-3 text-zinc-500 whitespace-nowrap"><?= e($r['area']) ?></td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center gap-1.5 text-zinc-600 whitespace-nowrap">
              <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:<?= e($r['catcolor']) ?>"></span><?= e($r['cat']) ?></span>
            <?php if ($r['subcat']): ?><div class="text-xs text-zinc-400 pl-4"><?= e($r['subcat']) ?></div><?php endif; ?>
          </td>
          <td class="px-4 py-3 text-right font-semibold text-zinc-800 tabular-nums whitespace-nowrap">$<?= number_format((float)$r['monto'],2) ?></td>
          <td class="px-4 py-3 whitespace-nowrap">
            <?= badge_estatus_pago($r['estatus_pago']) ?>
            <?php if ($r['forma_pago']): ?><div class="text-xs text-zinc-400 mt-0.5"><?= e($r['forma_pago']) ?></div><?php endif; ?>
          </td>
          <td class="px-4 py-3 text-center">
            <?php if ($r['archivo_url']): ?>
              <a href="<?= url_archivo($r['archivo_url']) ?>" target="_blank" onclick="event.stopPropagation()" class="text-marca-600 hover:text-marca-700" title="Ver comprobante">
                <i data-lucide="paperclip" class="w-4 h-4 inline"></i></a>
            <?php else: ?><span class="text-zinc-300">—</span><?php endif; ?>
          </td>
          <td class="px-4 py-3 text-right"><a href="<?= url('gasto_ver.php?id='.(int)$r['id']) ?>" class="text-marca-700 hover:text-marca-800 text-sm font-semibold">Ver</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/config/footer.php'; ?>
