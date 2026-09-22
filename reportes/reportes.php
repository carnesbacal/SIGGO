<?php
/** reportes/reportes.php - Indice de reportes */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/tema.php';
requerir_login();
$titulo_pagina = 'Reportes';
$pagina_activa = 'reportes';
require __DIR__ . '/../config/header.php';
$cards = [
  ['reporte_resumen.php','layout-dashboard','Resumen general','Lo esencial de todos los reportes en una hoja: gasto del periodo, categorías, áreas, proveedores, pendientes y comprobantes.'],
  ['reporte_por_categoria.php','tags','Gasto por categoría','Cuánto pesa cada categoría y subcategoría en el año, con comparativo mes a mes.'],
  ['reporte_por_producto.php','package','Gasto por producto','Agrupa los renglones del detalle por código y descripción: cuánto llevas gastado en bolsas, cloro o refacciones.'],
  ['reporte_por_area.php','layout-grid','Gasto por área','Reparte el gasto entre las áreas de la tienda, incluidos los gastos repartidos por porcentaje.'],
  ['reporte_gastos.php','receipt-text','Detalle de gastos','Listado completo con filtros, exportable a Excel para contabilidad.'],
  ['reporte_gastos_fijos.php','repeat','Gastos fijos','Los recurrentes del año: cuánto está comprometido y cuánto se ha generado.'],
  ['reporte_pendientes.php','alert-circle','Pendientes de pago','Lo que se debe, por antigüedad y proveedor.'],
  ['reporte_sin_comprobante.php','camera-off','Gastos sin comprobante','Los que no tienen factura ni foto de la nota. Los gastos fijos nacen así: aquí se ve cuáles siguen pendientes.'],
];
?>
<div class="mb-6">
  <h2 class="font-display text-2xl font-extrabold text-zinc-900">Reportes</h2>
  <p class="text-xs text-zinc-500 mt-0.5">Análisis del gasto de operación. Todos se exportan a Excel y a PDF.</p>
  <p class="text-xs text-zinc-400 mt-1">
    El <b>PDF sobrio</b> es solo tablas, para imprimir y archivar. El <b>PDF ejecutivo</b> lleva logo,
    color y gráficas, para presentar. Los dos traen la misma información.</p>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
  <?php foreach ($cards as $c): ?>
  <a href="<?= url('reportes/'.$c[0]) ?>" class="block bg-white rounded-2xl border border-zinc-200 shadow-sm p-5 hover:border-marca-300 hover:shadow transition">
    <div class="w-10 h-10 rounded-lg bg-marca-50 text-marca-600 flex items-center justify-center mb-3"><i data-lucide="<?= $c[1] ?>" class="w-5 h-5"></i></div>
    <div class="font-display font-bold text-zinc-800"><?= e($c[2]) ?></div>
    <p class="text-xs text-zinc-500 mt-1 leading-relaxed"><?= e($c[3]) ?></p>
    <div class="mt-3 text-marca-700 text-sm font-semibold inline-flex items-center gap-1">Abrir <i data-lucide="arrow-right" class="w-4 h-4"></i></div>
  </a>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../config/footer.php'; ?>
