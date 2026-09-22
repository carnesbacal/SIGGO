<?php
/**
 * reportes/reporte_sin_comprobante.php
 * Gastos que no tienen ni factura ni foto de la nota.
 *
 * Existe porque los gastos fijos nacen sin comprobante a propósito: el cron
 * genera la renta o la luz del mes sin que nadie suba nada. Hasta ahora no
 * había forma de ver cuáles faltaban; se descubrían en la auditoría, tarde.
 *
 * De cada renglón se puede ir directo a subir el comprobante.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/tema.php';
require_once __DIR__ . '/../config/gastos_helpers.php';
require_once __DIR__ . '/../config/reportes_pdf.php';
requerir_login();

$anio  = (int)(input('anio') ?: date('Y'));
$mes   = (int) input('mes');
$area  = (int) input('area_id');
$orig  = (string) input('origen');       // '', 'fijo', 'capturado'

// Sin comprobante = sin archivo adjunto Y sin XML Y sin UUID de CFDI.
$where = ['g.anio = :anio',
          "(g.archivo_url IS NULL OR g.archivo_url = '')",
          "(g.cfdi_xml_url IS NULL OR g.cfdi_xml_url = '')",
          "(g.uuid IS NULL OR g.uuid = '')"];
$pp = ['anio' => $anio];
if ($mes >= 1 && $mes <= 12) { $where[] = 'g.mes = :mes';       $pp['mes']  = $mes; }
if ($area > 0)               { $where[] = 'g.area_id = :area';  $pp['area'] = $area; }
if ($orig === 'fijo')        { $where[] = 'g.recurrente_id IS NOT NULL'; }
if ($orig === 'capturado')   { $where[] = 'g.recurrente_id IS NULL'; }
$wsql = implode(' AND ', $where);

$rows = db_all("SELECT g.id, g.folio, g.fecha, g.concepto, g.monto, g.tipo, g.recurrente_id,
                       g.estatus_pago, a.nombre AS area, c.nombre AS cat,
                       COALESCE(p.nombre, g.proveedor_texto) AS prov,
                       DATEDIFF(CURDATE(), g.fecha) AS dias
                  FROM gastos g
                  INNER JOIN areas a            ON a.id = g.area_id
                  INNER JOIN categorias_gasto c ON c.id = g.categoria_id
                  LEFT  JOIN proveedores p      ON p.id = g.proveedor_id
                 WHERE $wsql
                 ORDER BY g.fecha DESC, g.id DESC", $pp);

$total = 0.0; $nFijos = 0; $mFijos = 0.0; $nCap = 0; $mCap = 0.0; $viejos = 0;
foreach ($rows as $r) {
    $m = (float)$r['monto']; $total += $m;
    if ($r['recurrente_id']) { $nFijos++; $mFijos += $m; } else { $nCap++; $mCap += $m; }
    if ((int)$r['dias'] > 30) $viejos++;
}

// Cuántos gastos hay en total en el periodo, para dar el porcentaje
$wTot = ['g.anio = :anio']; $pTot = ['anio' => $anio];
if ($mes >= 1 && $mes <= 12) { $wTot[] = 'g.mes = :mes';      $pTot['mes']  = $mes; }
if ($area > 0)               { $wTot[] = 'g.area_id = :area'; $pTot['area'] = $area; }
$totGastos = (int)(db_one("SELECT COUNT(*) c FROM gastos g WHERE " . implode(' AND ', $wTot), $pTot)['c'] ?? 0);
$pct = $totGastos > 0 ? (count($rows) / $totGastos) * 100 : 0;

if (input('export') === 'xlsx') {
    require_once __DIR__ . '/../config/xlsx_writer.php';
    $x = new XlsxWriter(); $x->addSheet('Sin comprobante'); $x->setPageSetup(1, 0, 'landscape');
    $x->addHeaderRow(['Folio','Fecha','Días','Concepto','Proveedor','Área','Categoría','Origen','Importe'], true);
    foreach ($rows as $r) {
        $x->addRow([(string)$r['folio'], date('d/m/Y', strtotime($r['fecha'])), (int)$r['dias'],
                    (string)$r['concepto'], (string)($r['prov'] ?? ''), (string)$r['area'], (string)$r['cat'],
                    $r['recurrente_id'] ? 'Gasto fijo' : 'Capturado', ['v'=>(float)$r['monto'],'s'=>3]]);
    }
    $x->addRow([['v'=>'TOTAL','s'=>1],'','','','','','','',['v'=>$total,'s'=>3]]);
    $x->download("gastos_sin_comprobante_$anio.xlsx");
}

if (input('export') === 'pdf') {
    $per = 'Año ' . $anio . ($mes >= 1 && $mes <= 12 ? ' · ' . meses_nombres()[$mes] : '');
    if ($orig === 'fijo')      $per .= ' · Solo gastos fijos';
    if ($orig === 'capturado') $per .= ' · Solo capturados a mano';

    $pdf = pdf_reporte('Gastos sin comprobante', $per);
    $pdf->kpis([
        ['Sin comprobante', number_format(count($rows), 0), number_format($pct, 1) . '% de los gastos'],
        ['Importe involucrado', '$' . number_format($total, 2)],
        ['De gastos fijos', number_format($nFijos, 0), '$' . number_format($mFijos, 2)],
        ['Capturados a mano', number_format($nCap, 0), '$' . number_format($mCap, 2)],
    ]);
    $pdf->parrafo('Un gasto cuenta como sin comprobante cuando no tiene factura XML, ni PDF, ni foto de la nota. '
        . 'Los gastos fijos que genera el sistema nacen así a propósito: la renta domiciliada no trae ticket. '
        . 'Los capturados a mano sin comprobante son los que hay que revisar primero.');

    $pdf->seccion('Detalle');
    $filas = [];
    foreach ($rows as $r) {
        $filas[] = [$r['folio'], date('d/m/Y', strtotime($r['fecha'])), (int)$r['dias'], $r['concepto'],
                    $r['prov'] ?: '—', $r['area'], $r['recurrente_id'] ? 'Gasto fijo' : 'Capturado',
                    (float)$r['monto']];
    }
    $pdf->tabla([
        ['t'=>'Folio','w'=>1.4], ['t'=>'Fecha','w'=>1], ['t'=>'Días','w'=>0.6,'a'=>'r','f'=>'int'],
        ['t'=>'Concepto','w'=>2.6], ['t'=>'Proveedor','w'=>1.6], ['t'=>'Área','w'=>1.2],
        ['t'=>'Origen','w'=>1], ['t'=>'Importe','w'=>1.3,'a'=>'r','f'=>'money'],
    ], $filas, ['TOTAL','','','','','','', $total], 'Todos los gastos del periodo tienen comprobante.');
    $pdf->descargar(pdf_nombre("gastos_sin_comprobante_$anio"));
}

$areas  = db_all("SELECT id,nombre FROM areas WHERE activo=1 ORDER BY nombre");
$anio_a = (int)date('Y');
$meses  = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];
$qs = 'anio='.$anio.'&mes='.$mes.'&area_id='.$area.'&origen='.urlencode($orig);

$titulo_pagina = 'Gastos sin comprobante';
$pagina_activa = 'reportes';
require __DIR__ . '/../config/header.php';
?>
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-4">
  <div>
    <a href="<?= url('reportes/reportes.php') ?>" class="text-xs text-zinc-400 hover:text-marca-700 inline-flex items-center gap-1 mb-1">
      <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Reportes</a>
    <h2 class="font-display text-2xl font-extrabold text-zinc-900">Gastos sin comprobante</h2>
    <p class="text-xs text-zinc-500 mt-0.5">Ni factura XML, ni PDF, ni foto de la nota. Los gastos fijos nacen así: aquí se ve cuáles siguen pendientes.</p>
  </div>
  <?php if ($rows): ?><?= botones_export('reporte_sin_comprobante.php', $qs) ?><?php endif; ?>
</div>

<form method="get" class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4 mb-4">
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 items-end">
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Año</label>
      <select name="anio" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm">
        <?php for ($y=$anio_a+1; $y>=$anio_a-3; $y--): ?><option value="<?= $y ?>" <?= $anio===$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Mes</label>
      <select name="mes" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm">
        <option value="0">Todos</option>
        <?php foreach ($meses as $mk=>$mv): ?><option value="<?= $mk ?>" <?= $mes===$mk?'selected':'' ?>><?= $mv ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Área</label>
      <select name="area_id" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm">
        <option value="0">Todas</option>
        <?php foreach ($areas as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $area===(int)$a['id']?'selected':'' ?>><?= e($a['nombre']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="block text-[11px] font-semibold text-zinc-400 mb-1">Origen</label>
      <select name="origen" class="w-full px-2.5 py-1.5 rounded-lg border border-zinc-300 text-sm">
        <option value="">Todos</option>
        <option value="fijo"      <?= $orig==='fijo'?'selected':'' ?>>Gastos fijos</option>
        <option value="capturado" <?= $orig==='capturado'?'selected':'' ?>>Capturados a mano</option>
      </select></div>
    <div><button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold">Filtrar</button></div>
  </div>
</form>

<?php if ($rows): ?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
  <div class="bg-white rounded-2xl border shadow-sm p-4" style="border-color:<?= EST_WARN ?>55;background:<?= EST_WARN ?>0d">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Sin comprobante</div>
    <div class="font-display text-2xl font-extrabold tabular-nums mt-1" style="color:<?= EST_WARN ?>"><?= count($rows) ?></div>
    <div class="text-[11px] text-zinc-400"><?= number_format($pct,1) ?>% de los gastos del periodo</div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Importe involucrado</div>
    <div class="font-display text-2xl font-extrabold text-zinc-800 tabular-nums mt-1">$<?= number_format($total,2) ?></div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">De gastos fijos</div>
    <div class="font-display text-xl font-extrabold text-zinc-800 tabular-nums mt-1"><?= $nFijos ?></div>
    <div class="text-[11px] text-zinc-400">$<?= number_format($mFijos,2) ?> · el cron los generó</div>
  </div>
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-4">
    <div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400">Capturados a mano</div>
    <div class="font-display text-xl font-extrabold tabular-nums mt-1" style="color:<?= $nCap ? EST_BAD : '#3f3f46' ?>"><?= $nCap ?></div>
    <div class="text-[11px] text-zinc-400">$<?= number_format($mCap,2) ?><?= $nCap ? ' · revisar' : '' ?></div>
  </div>
</div>
<?php if ($viejos): ?>
  <div class="mb-4 border-l-4 rounded-lg px-4 py-3 text-sm flex items-start gap-2"
       style="border-color:<?= EST_WARN ?>;background:<?= EST_WARN ?>0f;color:<?= EST_WARN ?>">
    <i data-lucide="clock" class="w-4 h-4 mt-0.5 flex-shrink-0"></i>
    <div><b><?= $viejos ?></b> <?= $viejos===1?'lleva':'llevan' ?> más de 30 días sin comprobante.
         Entre más tiempo pasa, más difícil es conseguir la nota con el proveedor.</div>
  </div>
<?php endif; ?>
<?php endif; ?>

<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-[11px] uppercase tracking-wide text-zinc-400 border-b border-zinc-200 bg-zinc-50">
        <th class="px-4 py-3 font-semibold">Folio</th>
        <th class="px-4 py-3 font-semibold">Fecha</th>
        <th class="px-4 py-3 font-semibold text-right">Días</th>
        <th class="px-4 py-3 font-semibold">Concepto</th>
        <th class="px-4 py-3 font-semibold">Proveedor</th>
        <th class="px-4 py-3 font-semibold">Área</th>
        <th class="px-4 py-3 font-semibold">Origen</th>
        <th class="px-4 py-3 font-semibold text-right">Importe</th>
        <th class="px-4 py-3 font-semibold"></th>
      </tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="px-4 py-10 text-center text-zinc-400">
          Todos los gastos del periodo tienen comprobante. Así debe verse siempre.</td></tr>
      <?php else: foreach ($rows as $r): $d = (int)$r['dias'];
        $colD = $d > 60 ? EST_BAD : ($d > 30 ? EST_WARN : '#71717a'); ?>
        <tr class="hover:bg-zinc-50">
          <td class="px-4 py-2.5 font-mono text-xs whitespace-nowrap">
            <a href="<?= url('gasto_ver.php?id='.(int)$r['id']) ?>" class="text-marca-700 hover:underline"><?= e($r['folio']) ?></a></td>
          <td class="px-4 py-2.5 text-zinc-500 whitespace-nowrap"><?= fmt_fecha($r['fecha'], false) ?></td>
          <td class="px-4 py-2.5 text-right font-semibold tabular-nums" style="color:<?= $colD ?>"><?= $d ?></td>
          <td class="px-4 py-2.5 text-zinc-800"><?= e($r['concepto']) ?></td>
          <td class="px-4 py-2.5 text-zinc-500"><?= e($r['prov'] ?: '—') ?></td>
          <td class="px-4 py-2.5 text-zinc-500 whitespace-nowrap"><?= e($r['area']) ?></td>
          <td class="px-4 py-2.5">
            <?php if ($r['recurrente_id']): ?>
              <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-marca-50 text-marca-700">Gasto fijo</span>
            <?php else: ?>
              <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background:<?= EST_BAD ?>14;color:<?= EST_BAD ?>">Capturado</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-2.5 text-right font-semibold text-zinc-800 tabular-nums whitespace-nowrap">$<?= number_format((float)$r['monto'],2) ?></td>
          <td class="px-4 py-2.5 text-right whitespace-nowrap">
            <a href="<?= url('gasto_form.php?id='.(int)$r['id']) ?>"
               class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-marca-50 text-marca-700 hover:bg-marca-100 text-xs font-semibold">
              <i data-lucide="camera" class="w-3.5 h-3.5"></i> Subir</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot><tr class="border-t-2 border-zinc-200 bg-zinc-50">
        <td colspan="7" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-zinc-500">Total</td>
        <td class="px-4 py-3 text-right font-display font-extrabold text-marca-700 tabular-nums">$<?= number_format($total,2) ?></td>
        <td></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../config/footer.php'; ?>
