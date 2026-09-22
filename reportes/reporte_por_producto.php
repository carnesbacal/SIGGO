<?php
/** reportes/reporte_por_producto.php - Agrupa los renglones del detalle por producto */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/tema.php';
require_once __DIR__ . '/../config/gastos_helpers.php';
require_once __DIR__ . '/../config/reportes_pdf.php';
requerir_login();

$anio = (int) (input('anio') ?: date('Y'));
$cat  = (int) input('categoria_id');
$area = (int) input('area_id');
$agrupar = input('agrupar') === 'codigo' ? 'codigo' : 'descripcion';
$q    = trim((string) input('q'));

// Agrupa por codigo cuando lo hay; si no, por la descripcion normalizada.
// Cuando el renglon viene del catalogo se agrupa por el insumo: asi dos
// capturas del mismo producto no se separan por una diferencia de tecleo.
// Si el modulo de insumos no esta instalado, esa columna no existe.
$conInsumos = modulo_insumos_instalado();
if ($conInsumos) {
    $clave = $agrupar === 'codigo'
        ? "COALESCE(CONCAT('#', i.insumo_id), NULLIF(TRIM(i.codigo),''), TRIM(i.descripcion))"
        : "COALESCE(CONCAT('#', i.insumo_id), TRIM(i.descripcion))";
} else {
    $clave = $agrupar === 'codigo'
        ? "COALESCE(NULLIF(TRIM(i.codigo),''), TRIM(i.descripcion))"
        : "TRIM(i.descripcion)";
}

$w = ['g.anio = :a', "g.estatus_pago <> 'cancelado'"]; $p = ['a'=>$anio];
if ($cat  > 0) { $w[] = 'g.categoria_id = :c'; $p['c'] = $cat; }
if ($area > 0) { $w[] = 'g.area_id = :ar';     $p['ar'] = $area; }
if ($q !== '') { $w[] = '(i.descripcion LIKE :q1 OR i.codigo LIKE :q2)'; $p['q1'] = "%$q%"; $p['q2'] = "%$q%"; }
$wsql = implode(' AND ', $w);

// OJO: los alias NO pueden llamarse igual que las columnas de gasto_items
// (descripcion, codigo). Si se repite el nombre, MariaDB resuelve el GROUP BY
// contra el alias agregado y junta todos los renglones en un solo grupo, sin
// marcar error. Por eso van con otro nombre y el GROUP BY lleva la expresión
// completa en vez del alias.
$rows = db_all("SELECT $clave AS clave,
                       MAX(i.descripcion) AS desc_prod,
                       MAX(NULLIF(TRIM(i.codigo),'')) AS cod_prod,
                       MAX(u.clave) AS unidad,
                       SUM(i.cantidad) AS cantidad,
                       SUM(i.importe)  AS importe,
                       COUNT(DISTINCT i.gasto_id) AS n_gastos,
                       MIN(i.precio_unitario) AS precio_min,
                       MAX(i.precio_unitario) AS precio_max,
                       MAX(g.fecha) AS ultima
                  FROM gasto_items i
                  INNER JOIN gastos g ON g.id = i.gasto_id
                  LEFT JOIN unidades_medida u ON u.id = i.unidad_id
                 WHERE $wsql
                 GROUP BY $clave
                 ORDER BY importe DESC", $p);

$total = 0.0; foreach ($rows as $r) $total += (float)$r['importe'];

$cats  = categorias_lista();
$areas = areas_lista();

if (input('export') === 'xlsx') {
    require_once __DIR__ . '/../config/xlsx_writer.php';
    $x = new XlsxWriter(); $x->addSheet("Por producto $anio"); $x->setPageSetup(1,0,'landscape');
    $x->addHeaderRow(['Código','Descripción','Unidad','Cantidad','Importe','Gastos','P. mínimo','P. máximo','Última compra'], true);
    foreach ($rows as $r) {
        $x->addRow([
            (string)($r['cod_prod'] ?? ''), (string)$r['desc_prod'], (string)($r['unidad'] ?? ''),
            ['v'=>(float)$r['cantidad'],'s'=>0], ['v'=>(float)$r['importe'],'s'=>3],
            (int)$r['n_gastos'],
            ['v'=>(float)$r['precio_min'],'s'=>3], ['v'=>(float)$r['precio_max'],'s'=>3],
            (string)$r['ultima'],
        ]);
    }
    $x->addRow([['v'=>'TOTAL','s'=>1],'','','',['v'=>$total,'s'=>3],'','','','']);
    $x->download("gasto_por_producto_$anio.xlsx");
}

if (input('export') === 'pdf') {
    $per = 'Año ' . $anio;
    if ($cat  > 0) { foreach ($cats  as $c) if ((int)$c['id'] === $cat)  $per .= ' · ' . $c['nombre']; }
    if ($area > 0) { foreach ($areas as $a) if ((int)$a['id'] === $area) $per .= ' · ' . $a['nombre']; }
    if ($q !== '') $per .= ' · buscando "' . $q . '"';

    $pdf = pdf_reporte('Gasto por producto', $per, 'horizontal');
    $pdf->kpis([
        ['Productos distintos', number_format(count($rows), 0)],
        ['Importe total', '$' . number_format($total, 2)],
        ['Producto con más gasto', $rows ? mb_substr((string)$rows[0]['desc_prod'], 0, 28) : '—',
         $rows ? '$' . number_format((float)$rows[0]['importe'], 2) : ''],
    ], 3);

    $pdf->bloqueRanking('Los que más pesan',
        array_map(fn($r) => [(string)$r['desc_prod'], (float)$r['importe']], array_slice($rows, 0, 12)),
        true, 'Producto');

    $pdf->seccion('Detalle por producto');
    $filas = [];
    foreach ($rows as $r) {
        $filas[] = [(string)($r['cod_prod'] ?? '—'), (string)$r['desc_prod'], (string)($r['unidad'] ?? '—'),
                    (float)$r['cantidad'], (float)$r['importe'], (int)$r['n_gastos'],
                    (float)$r['precio_min'], (float)$r['precio_max'],
                    $r['ultima'] ? date('d/m/Y', strtotime((string)$r['ultima'])) : '—'];
    }
    $pdf->tabla([
        ['t'=>'Código','w'=>1], ['t'=>'Producto','w'=>3.2], ['t'=>'Unidad','w'=>0.7],
        ['t'=>'Cantidad','w'=>1,'a'=>'r','f'=>'num'], ['t'=>'Importe','w'=>1.3,'a'=>'r','f'=>'money'],
        ['t'=>'Compras','w'=>0.8,'a'=>'r','f'=>'int'],
        ['t'=>'P. mínimo','w'=>1,'a'=>'r','f'=>'money'], ['t'=>'P. máximo','w'=>1,'a'=>'r','f'=>'money'],
        ['t'=>'Última','w'=>1,'a'=>'r'],
    ], $filas, ['TOTAL','','','', $total,'','','',''], 'Sin renglones capturados con estos filtros.');
    $pdf->parrafo('El precio mínimo y el máximo son del mismo producto en el año: si están muy separados, '
        . 'vale la pena revisar con qué proveedor se compró cada vez.');
    $pdf->descargar(pdf_nombre("gasto_por_producto_$anio"));
}

$anio_actual = (int) date('Y');
$titulo_pagina = 'Gasto por producto';
$pagina_activa = 'reportes';
require __DIR__ . '/../config/header.php';

$qs = ['anio'=>$anio,'agrupar'=>$agrupar];
if ($cat>0) $qs['categoria_id']=$cat; if ($area>0) $qs['area_id']=$area; if ($q!=='') $qs['q']=$q;
?>
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
  <div>
    <a href="<?= url('reportes/reportes.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-1">
      <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Reportes</a>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Gasto por producto · <?= $anio ?></h2>
    <p class="text-xs text-zinc-500 mt-0.5">Sale de los renglones del detalle: qué compras y cuánto llevas gastado en cada cosa.</p>
  </div>
  <?= botones_export('reporte_por_producto.php', http_build_query($qs)) ?>
</div>

<form method="get" class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4 mb-4">
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Año</label>
      <select name="anio" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <?php for($y=$anio_actual+1;$y>=$anio_actual-4;$y--): ?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Categoría</label>
      <select name="categoria_id" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="0">Todas</option>
        <?php foreach($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cat===(int)$c['id']?'selected':'' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Área</label>
      <select name="area_id" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="0">Todas</option>
        <?php foreach($areas as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $area===(int)$a['id']?'selected':'' ?>><?= e($a['nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Agrupar por</label>
      <select name="agrupar" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
        <option value="descripcion" <?= $agrupar==='descripcion'?'selected':'' ?>>Descripción</option>
        <option value="codigo" <?= $agrupar==='codigo'?'selected':'' ?>>Código</option>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-500 mb-1">Buscar</label>
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="bolsa, cloro…" class="w-full h-9 px-2.5 rounded-lg border border-zinc-300 bg-white text-sm"></div>
  </div>
  <div class="flex justify-end gap-2 mt-3 pt-3 border-t border-zinc-100">
    <a href="<?= url('reportes/reporte_por_producto.php') ?>" class="h-9 px-3 inline-flex items-center rounded-lg border border-zinc-200 text-zinc-600 hover:bg-zinc-50 text-sm font-semibold">Limpiar</a>
    <button type="submit" class="h-9 px-4 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm">Filtrar</button>
  </div>
</form>

<div class="flex items-center justify-between mb-3 px-1">
  <span class="text-sm text-zinc-500"><?= count($rows) ?> producto<?= count($rows)===1?'':'s' ?></span>
  <span class="text-sm text-zinc-500">Total: <span class="font-display font-bold text-zinc-800 tabular-nums">$<?= number_format($total,2) ?></span></span>
</div>

<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-3 font-semibold">Código</th>
        <th class="px-4 py-3 font-semibold">Producto</th>
        <th class="px-4 py-3 font-semibold text-right">Cantidad</th>
        <th class="px-4 py-3 font-semibold text-right">Importe</th>
        <th class="px-4 py-3 font-semibold text-right">% del total</th>
        <th class="px-4 py-3 font-semibold text-right">Precio</th>
        <th class="px-4 py-3 font-semibold text-right">Compras</th>
        <th class="px-4 py-3 font-semibold text-right">Última</th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="px-4 py-10 text-center text-zinc-400">No hay renglones con estos filtros.</td></tr>
      <?php else: foreach ($rows as $r):
        $imp = (float)$r['importe']; $pct = $total>0 ? ($imp/$total)*100 : 0;
        $pmin = (float)$r['precio_min']; $pmax = (float)$r['precio_max'];
        $vario = abs($pmax-$pmin) > 0.009;
      ?>
        <tr class="hover:bg-zinc-50">
          <td class="px-4 py-2.5 font-mono text-xs text-zinc-500"><?= e($r['cod_prod'] ?: '—') ?></td>
          <td class="px-4 py-2.5 text-zinc-800"><?= e($r['desc_prod']) ?></td>
          <td class="px-4 py-2.5 text-right text-zinc-600 tabular-nums whitespace-nowrap">
            <?= rtrim(rtrim(number_format((float)$r['cantidad'],3),'0'),'.') ?>
            <span class="text-zinc-400 text-xs"><?= e($r['unidad'] ?: '') ?></span></td>
          <td class="px-4 py-2.5 text-right font-semibold text-zinc-800 tabular-nums whitespace-nowrap">$<?= number_format($imp,2) ?></td>
          <td class="px-4 py-2.5 text-right">
            <div class="flex items-center justify-end gap-2">
              <div class="w-16 h-1.5 bg-zinc-100 rounded-full overflow-hidden">
                <div class="h-full rounded-full" style="width:<?= min(100,$pct) ?>%;background:<?= tono(400) ?>"></div></div>
              <span class="text-xs text-zinc-500 tabular-nums w-10 text-right"><?= number_format($pct,1) ?>%</span></div></td>
          <td class="px-4 py-2.5 text-right tabular-nums whitespace-nowrap text-xs">
            <?php if ($vario): ?>
              <span class="text-zinc-500">$<?= number_format($pmin,2) ?></span>
              <span class="text-zinc-300">–</span>
              <span style="color:<?= EST_WARN ?>">$<?= number_format($pmax,2) ?></span>
            <?php else: ?><span class="text-zinc-500">$<?= number_format($pmin,2) ?></span><?php endif; ?>
          </td>
          <td class="px-4 py-2.5 text-right text-zinc-500 tabular-nums"><?= (int)$r['n_gastos'] ?></td>
          <td class="px-4 py-2.5 text-right text-zinc-400 text-xs whitespace-nowrap"><?= fmt_fecha($r['ultima'], false) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="text-[11px] text-zinc-400 mt-2 px-1">
  Cuando el precio aparece como rango, es que el mismo producto se compró a precios distintos en el año —
  el mayor va en naranja. Sirve para detectar si a un proveedor se le subió el precio sin avisar.</p>

<?php require __DIR__ . '/../config/footer.php'; ?>
