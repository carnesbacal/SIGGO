<?php
/** reportes/reporte_gastos.php - Detalle de gastos con filtros (+ XLSX) */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/tema.php';
require_once __DIR__ . '/../config/reportes_pdf.php';
requerir_login();

$anio=(int)(input('anio')?:date('Y')); $mes=(int)input('mes'); $dep=(int)input('area_id'); $cat=(int)input('categoria_id'); $tipo=(string)input('tipo');
$where=['g.anio=:anio']; $pp=['anio'=>$anio];
if($mes>=1&&$mes<=12){$where[]='g.mes=:mes';$pp['mes']=$mes;}
if($dep>0){$where[]='g.area_id=:dep';$pp['dep']=$dep;}
if($cat>0){$where[]='g.categoria_id=:cat';$pp['cat']=$cat;}
if($tipo==='fijo'||$tipo==='variable'){$where[]='g.tipo=:tipo';$pp['tipo']=$tipo;}
$wsql=implode(' AND ',$where);
$rows=db_all("SELECT g.folio,g.fecha,g.tipo,g.concepto,g.monto,g.numero_factura,g.proveedor_texto,
    d.nombre dep, c.nombre cat, pr.nombre prov
  FROM gastos g INNER JOIN areas d ON g.area_id=d.id INNER JOIN categorias_gasto c ON g.categoria_id=c.id
  LEFT JOIN proveedores pr ON g.proveedor_id=pr.id WHERE $wsql ORDER BY g.fecha DESC, g.id DESC", $pp);
$total=0.0; foreach($rows as $r)$total+=(float)$r['monto'];

if (input('export') === 'xlsx') {
    require_once __DIR__ . '/../config/xlsx_writer.php';
    $x=new XlsxWriter(); $x->addSheet("Gastos $anio"); $x->setPageSetup(1,0,'landscape');
    $x->addHeaderRow(['Folio','Fecha','Área','Categoría','Tipo','Concepto','Proveedor','Factura','Monto'], true);
    foreach($rows as $r){
        $prov=$r['prov']?:($r['proveedor_texto']?:'');
        $x->addRow([$r['folio'], date('d/m/Y', strtotime($r['fecha'])), $r['dep'], $r['cat'], ucfirst($r['tipo']), $r['concepto'], $prov, (string)($r['numero_factura']??''), ['v'=>(float)$r['monto'],'s'=>3]]);
    }
    $x->addRow([['v'=>'TOTAL','s'=>1],'','','','','','','',['v'=>$total,'s'=>3]]);
    $x->download("detalle_gastos_$anio.xlsx");
}

$deptos=db_all("SELECT id,nombre FROM areas WHERE activo=1 ORDER BY nombre");
$cats=db_all("SELECT id,nombre FROM categorias_gasto WHERE activo=1 ORDER BY nombre");
$anio_actual=(int)date('Y'); $meses_nom=[1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];
$qs='anio='.$anio.'&mes='.$mes.'&area_id='.$dep.'&categoria_id='.$cat.'&tipo='.urlencode($tipo);

if (input('export') === 'pdf') {
    $per = 'Año ' . $anio . ($mes >= 1 && $mes <= 12 ? ' · ' . meses_nombres()[$mes] : '');
    if ($dep > 0)  { foreach ($deptos as $d) if ((int)$d['id'] === $dep) $per .= ' · ' . $d['nombre']; }
    if ($cat > 0)  { foreach ($cats as $c)   if ((int)$c['id'] === $cat) $per .= ' · ' . $c['nombre']; }
    if ($tipo !== '') $per .= ' · Gastos ' . ($tipo === 'fijo' ? 'fijos' : 'variables');

    $pdf = pdf_reporte('Detalle de gastos', $per, 'horizontal');
    $pdf->kpis([
        ['Gastos en el periodo', number_format(count($rows), 0)],
        ['Importe total', '$' . number_format($total, 2)],
        ['Promedio por gasto', '$' . number_format($rows ? $total / count($rows) : 0, 2)],
    ], 3);

    if ($pdf->conGraficas() && $rows) {
        $porCat = []; $porArea = [];
        foreach ($rows as $r) {
            $porCat[$r['cat']]  = ($porCat[$r['cat']]  ?? 0) + (float)$r['monto'];
            $porArea[$r['dep']] = ($porArea[$r['dep']] ?? 0) + (float)$r['monto'];
        }
        arsort($porCat); arsort($porArea);
        $pdf->bloqueRanking('Categorías con más gasto en el periodo',
            array_map(null, array_slice(array_keys($porCat), 0, 8), array_slice(array_values($porCat), 0, 8)));
        $pdf->bloqueRanking('Áreas con más gasto en el periodo',
            array_map(null, array_slice(array_keys($porArea), 0, 8), array_slice(array_values($porArea), 0, 8)));
    }

    $pdf->seccion('Gastos del periodo');
    $filas = [];
    foreach ($rows as $r) {
        $filas[] = [$r['folio'], date('d/m/Y', strtotime($r['fecha'])), $r['concepto'],
                    $r['prov'] ?: ($r['proveedor_texto'] ?: '—'), $r['dep'], $r['cat'],
                    ucfirst((string)$r['tipo']), (float)$r['monto']];
    }
    $pdf->tabla([
        ['t'=>'Folio','w'=>1.3], ['t'=>'Fecha','w'=>0.9], ['t'=>'Concepto','w'=>3],
        ['t'=>'Proveedor','w'=>1.8], ['t'=>'Área','w'=>1.2], ['t'=>'Categoría','w'=>1.3],
        ['t'=>'Tipo','w'=>0.8], ['t'=>'Monto','w'=>1.2,'a'=>'r','f'=>'money'],
    ], $filas, ['TOTAL','','','','','','', $total], 'Sin gastos con estos filtros.');
    $pdf->descargar(pdf_nombre("detalle_gastos_$anio"));
}

$titulo_pagina='Detalle de gastos'; $pagina_activa='reportes';
require __DIR__ . '/../config/header.php';
?>
<div class="flex items-center justify-between gap-3 mb-4">
  <div><a href="<?= url('reportes/reportes.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-1"><i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Reportes</a>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Detalle de gastos</h2></div>
  <?= botones_export('reporte_gastos.php', $qs) ?>
</div>
<form method="get" class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4 mb-4">
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 items-end">
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Año</label><select name="anio" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm"><?php for($y=$anio_actual+1;$y>=$anio_actual-3;$y--):?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endfor;?></select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Mes</label><select name="mes" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm"><option value="0">Todos</option><?php foreach($meses_nom as $mk=>$mv):?><option value="<?= $mk ?>" <?= $mes===$mk?'selected':'' ?>><?= $mv ?></option><?php endforeach;?></select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Área</label><select name="area_id" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm"><option value="0">Todos</option><?php foreach($deptos as $d):?><option value="<?= (int)$d['id'] ?>" <?= $dep===(int)$d['id']?'selected':'' ?>><?= e($d['nombre']) ?></option><?php endforeach;?></select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Categoría</label><select name="categoria_id" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm"><option value="0">Todas</option><?php foreach($cats as $c):?><option value="<?= (int)$c['id'] ?>" <?= $cat===(int)$c['id']?'selected':'' ?>><?= e($c['nombre']) ?></option><?php endforeach;?></select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Tipo</label><select name="tipo" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm"><option value="">Todos</option><option value="fijo" <?= $tipo==='fijo'?'selected':'' ?>>Fijo</option><option value="variable" <?= $tipo==='variable'?'selected':'' ?>>Variable</option></select></div>
    <div><button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold">Filtrar</button></div>
  </div>
</form>
<div class="flex items-center justify-between mb-3 px-1"><span class="text-sm text-zinc-500"><?= count($rows) ?> gasto<?= count($rows)===1?'':'s' ?></span><span class="text-sm text-zinc-500">Total: <span class="font-display font-bold text-zinc-800 tabular-nums">$<?= number_format($total,2) ?></span></span></div>
<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-sm">
  <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
    <th class="px-4 py-3 font-semibold">Folio</th><th class="px-4 py-3 font-semibold">Fecha</th><th class="px-4 py-3 font-semibold">Concepto</th>
    <th class="px-4 py-3 font-semibold">Área</th><th class="px-4 py-3 font-semibold">Categoría</th><th class="px-4 py-3 font-semibold">Proveedor</th>
    <th class="px-4 py-3 font-semibold text-right">Monto</th></tr></thead>
  <tbody class="divide-y divide-zinc-100">
  <?php if(!$rows): ?><tr><td colspan="7" class="px-4 py-10 text-center text-zinc-400">Sin gastos con estos filtros.</td></tr>
  <?php else: foreach($rows as $r): ?>
    <tr class="hover:bg-zinc-50">
      <td class="px-4 py-3 font-mono text-xs text-marca-700"><?= e($r['folio']) ?></td>
      <td class="px-4 py-3 text-zinc-500 whitespace-nowrap"><?= fmt_fecha($r['fecha'],false) ?></td>
      <td class="px-4 py-3 text-zinc-800"><?= e($r['concepto']) ?></td>
      <td class="px-4 py-3 text-zinc-500"><?= e($r['dep']) ?></td>
      <td class="px-4 py-3 text-zinc-500"><?= e($r['cat']) ?></td>
      <td class="px-4 py-3 text-zinc-500"><?= e($r['prov']?:($r['proveedor_texto']?:'—')) ?></td>
      <td class="px-4 py-3 text-right font-semibold text-zinc-800 tabular-nums">$<?= number_format((float)$r['monto'],2) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table></div></div>
<?php require __DIR__ . '/../config/footer.php'; ?>
