<?php
/**
 * ============================================================================
 * config/reportes_export.php
 * ----------------------------------------------------------------------------
 * Utilidades compartidas de IMPRESIÓN y DESCARGA PDF para los reportes.
 *
 * Uso en cada reporte:
 *   1) require_once __DIR__ . '/../config/reportes_export.php';  (tras los helpers)
 *   2) reporte_export_assets();   // una vez, justo después de incluir el header
 *   3) Envolver el contenido imprimible:
 *          <div id="rep-area" data-pdf="mi_reporte.pdf"> ... </div>
 *   4) reporte_doc_header('Título', 'Periodo · Sucursal', $usuario);  // 1er hijo
 *   5) Botones: reporte_print_button();  reporte_pdf_button();
 *
 * El PDF se genera en el cliente con html2pdf (captura #rep-area). Si la
 * librería no carga, cae al diálogo de impresión (que también permite "Guardar
 * como PDF"), así que nunca queda inservible.
 * ============================================================================
 */

if (!function_exists('reporte_export_assets')):

/** Scripts + estilos de impresión/PDF + función descargarPDF(). Llamar una vez. */
function reporte_export_assets(): void { ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
    .solo-print { display: none; }
    body.modo-pdf .no-print { display: none !important; }
    body.modo-pdf .solo-print { display: block !important; }
    body.modo-pdf main,
    body.modo-pdf .overflow-hidden,
    body.modo-pdf .overflow-y-auto,
    body.modo-pdf .overflow-x-auto { overflow: visible !important; height: auto !important; max-height: none !important; }
    @media print {
        @page { size: A4 portrait; margin: 11mm; }
        .no-print { display: none !important; }
        .solo-print { display: block !important; }
        aside, header.h-16 { display: none !important; }
        html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
        main, .overflow-hidden, .overflow-y-auto, .overflow-x-auto { overflow: visible !important; height: auto !important; max-height: none !important; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .rounded-xl, table, tr, thead, tfoot, canvas { break-inside: avoid; }
        .grid { display: block !important; }
        .grid > * { margin-bottom: 10px; }
        a { color: inherit !important; text-decoration: none !important; }
    }
</style>
<script>
function descargarPDF() {
    var el = document.getElementById('rep-area');
    if (typeof html2pdf === 'undefined' || !el) { window.print(); return; }
    document.body.classList.add('modo-pdf');
    var opt = {
        margin:      [8, 6, 10, 6],
        filename:    el.getAttribute('data-pdf') || 'reporte.pdf',
        image:       { type: 'jpeg', quality: 0.95 },
        html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff', scrollY: 0 },
        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak:   { mode: ['css', 'legacy'], avoid: ['tr', 'thead', 'canvas', '.rounded-xl'] }
    };
    html2pdf().set(opt).from(el).save()
        .then(function () { document.body.classList.remove('modo-pdf'); })
        .catch(function () { document.body.classList.remove('modo-pdf'); window.print(); });
}
</script>
<?php }

/** Encabezado que solo aparece en impresión/PDF. Colocar como primer hijo de #rep-area. */
function reporte_doc_header(string $titulo, string $subtitulo = '', ?string $usuario = null): void { ?>
    <div class="solo-print" style="margin-bottom:14px;">
        <table style="width:100%;border-bottom:2px solid #DB2777;padding-bottom:6px;">
            <tr>
                <td style="text-align:left;vertical-align:top;">
                    <div style="font-size:18px;font-weight:800;color:#18181b;"><?= e($titulo) ?></div>
                    <?php if ($subtitulo !== ''): ?>
                    <div style="font-size:12px;color:#52525b;margin-top:2px;"><?= e($subtitulo) ?></div>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;vertical-align:top;font-size:11px;color:#52525b;">
                    <div style="font-size:13px;font-weight:800;color:#DB2777;">SIGAP &middot; <?= EMPRESA_NOMBRE ?></div>
                    <div>Generado: <?= date('d/m/Y H:i') ?></div>
                    <?php if ($usuario): ?><div>Por: <?= e($usuario) ?></div><?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
<?php }

/** Botón de imprimir (agregar dentro de un contenedor .no-print). */
function reporte_print_button(): void { ?>
    <button onclick="window.print()" class="px-3 py-2 rounded-lg border border-zinc-300 bg-white hover:bg-zinc-50 text-sm font-medium text-zinc-700 flex items-center gap-1.5">
        <i data-lucide="printer" class="w-4 h-4"></i> Imprimir
    </button>
<?php }

/** Botón de descargar PDF (agregar dentro de un contenedor .no-print). */
function reporte_pdf_button(): void { ?>
    <button onclick="descargarPDF()" class="px-3 py-2 rounded-lg border border-zinc-300 bg-white hover:bg-zinc-50 text-sm font-medium text-zinc-700 flex items-center gap-1.5">
        <i data-lucide="file-down" class="w-4 h-4"></i> PDF
    </button>
<?php }

endif;
