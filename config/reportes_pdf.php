<?php
/**
 * config/reportes_pdf.php
 * Pegamento entre los reportes y PdfWriter, para que los seis reportes no
 * repitan el mismo encabezado, el mismo pie y los mismos botones.
 *
 * Dos estilos de salida, porque no sirven para lo mismo:
 *   sobrio    — solo tablas, blanco y negro. Para imprimir, archivar y firmar.
 *   ejecutivo — logo, color y gráficas. Para presentar a dirección.
 */
require_once __DIR__ . '/pdf_writer.php';

/** El estilo pedido en la URL, ya validado. */
function pdf_estilo(): string
{
    return ((string) input('estilo')) === 'ejecutivo' ? 'ejecutivo' : 'tablas';
}

/**
 * Arma un PdfWriter con la identidad de la tienda ya puesta.
 * $subtitulo es el periodo o el filtro aplicado: eso es lo que después
 * distingue una impresión de otra sobre el escritorio.
 */
function pdf_reporte(string $titulo, string $subtitulo = '', string $orientacion = 'vertical'): PdfWriter
{
    $usuario = function_exists('usuario_actual') ? (usuario_actual()['nombre_completo'] ?? '') : '';
    $pie = APP_NAME . ' · ' . EMPRESA_NOMBRE . ', ' . SUCURSAL_NOMBRE
         . ' · generado el ' . date('d/m/Y H:i')
         . ($usuario !== '' ? ' por ' . $usuario : '');

    $sub = trim($subtitulo);
    $sub = $sub !== '' ? $sub . ' · ' . EMPRESA_NOMBRE . ', ' . SUCURSAL_NOMBRE
                       : EMPRESA_NOMBRE . ', ' . SUCURSAL_NOMBRE;

    return new PdfWriter([
        'estilo'      => pdf_estilo(),
        'orientacion' => $orientacion,
        'titulo'      => $titulo,
        'subtitulo'   => $sub,
        'pie'         => $pie,
        'logo'        => __DIR__ . '/../assets/img/logo-grano.png',
    ]);
}

/** Nombre de archivo con el estilo y la fecha, para que no se encimen. */
function pdf_nombre(string $base): string
{
    $base = preg_replace('/[^a-z0-9_\-]/i', '_', $base);
    return $base . '_' . (pdf_estilo() === 'ejecutivo' ? 'ejecutivo' : 'tablas') . '_' . date('Ymd') . '.pdf';
}

/**
 * Botones de exportación (Excel + los dos PDF).
 * $archivo es el nombre del reporte, p. ej. 'reporte_gastos.php'.
 * $qs son los filtros actuales ya armados, sin '?' ni 'export='.
 */
function botones_export(string $archivo, string $qs = '', bool $conExcel = true): string
{
    $base = url('reportes/' . $archivo) . '?' . ($qs !== '' ? $qs . '&' : '');
    $h = '<div class="flex flex-wrap items-center gap-2">';
    if ($conExcel) {
        $h .= '<a href="' . e($base) . 'export=xlsx" '
            . 'class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold shadow-sm">'
            . '<i data-lucide="file-spreadsheet" class="w-4 h-4"></i> Excel</a>';
    }
    $h .= '<a href="' . e($base) . 'export=pdf&estilo=tablas" title="Solo tablas, para imprimir y archivar" '
        . 'class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-zinc-300 text-zinc-700 hover:bg-zinc-50 text-sm font-semibold">'
        . '<i data-lucide="file-text" class="w-4 h-4"></i> PDF sobrio</a>';
    $h .= '<a href="' . e($base) . 'export=pdf&estilo=ejecutivo" title="Con logo y gráficas, para dirección" '
        . 'class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-marca-600 hover:bg-marca-700 text-white text-sm font-semibold shadow-sm">'
        . '<i data-lucide="file-bar-chart" class="w-4 h-4"></i> PDF ejecutivo</a>';
    $h .= '</div>';
    return $h;
}
