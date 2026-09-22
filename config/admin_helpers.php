<?php
/**
 * ============================================================================
 * config/admin_helpers.php
 * ============================================================================
 * Utilidades comunes para todas las páginas del panel de administración.
 * Incluye: protección de acceso, componentes UI (tabs, badges, swatches),
 * helpers de CRUD genéricos.
 * ============================================================================
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

requerir_login();
requerir_permiso('administrar');

/**
 * Paleta de colores predefinida para selectores de color en catálogos.
 * Coincide con la estética del sistema (rojo, amarillo, verde, etc).
 */
function colores_disponibles(): array {
    return [
        '#DC2626' => 'Rojo',
        '#EA580C' => 'Naranja',
        '#D97706' => 'Ámbar',
        '#EAB308' => 'Amarillo',
        '#84CC16' => 'Lima',
        '#16A34A' => 'Verde',
        '#10B981' => 'Esmeralda',
        '#0EA5E9' => 'Cielo',
        '#2563EB' => 'Azul',
        '#6366F1' => 'Índigo',
        '#7C3AED' => 'Violeta',
        '#9333EA' => 'Morado',
        '#DB2777' => 'Rosa',
        '#6B7280' => 'Gris',
        '#1F2937' => 'Pizarra',
    ];
}

/**
 * Imprime un selector de color tipo swatches (Alpine).
 * Uso: render_color_picker('color', '#DC2626');
 */
function render_color_picker(string $name, string $valor_actual = '#6B7280'): string {
    $colores = colores_disponibles();
    $valor_actual = e($valor_actual);
    $name_e = e($name);

    $html  = "<div x-data=\"{ valor: '$valor_actual' }\">";
    $html .= "<input type='hidden' name='$name_e' :value='valor'>";
    $html .= "<div class='flex flex-wrap gap-2'>";
    foreach ($colores as $hex => $nombre) {
        $hex_e = e($hex);
        $nombre_e = e($nombre);
        $html .= "<button type='button' @click=\"valor = '$hex_e'\" "
              . "class='w-7 h-7 rounded-md border-2 transition-all' "
              . ":class=\"valor === '$hex_e' ? 'border-zinc-900 scale-110 shadow-md' : 'border-white shadow-sm hover:scale-105'\" "
              . "style='background-color: $hex_e' "
              . "title='$nombre_e'></button>";
    }
    $html .= "</div></div>";
    return $html;
}

/**
 * Devuelve un toggle switch HTML (estilo iOS) para campos booleanos.
 */
function render_toggle(string $name, bool $activo, string $label_on = 'Activo', string $label_off = 'Inactivo'): string {
    $name_e = e($name);
    $checked = $activo ? 'checked' : '';
    return "<label class='inline-flex items-center gap-2 cursor-pointer'>"
        . "<div class='relative'>"
        . "<input type='checkbox' name='$name_e' value='1' $checked class='sr-only peer'>"
        . "<div class='w-9 h-5 bg-zinc-300 rounded-full peer-checked:bg-emerald-500 transition-colors'></div>"
        . "<div class='absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-4 shadow-sm'></div>"
        . "</div>"
        . "<span class='text-xs font-medium text-zinc-600'>" . e($activo ? $label_on : $label_off) . "</span>"
        . "</label>";
}

/**
 * Renderiza un header de página admin con título y opcional botón "+ Nuevo".
 */
function render_admin_header(string $titulo, string $subtitulo, ?string $boton_url = null, string $boton_texto = 'Nuevo'): void {
    echo '<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">';
    echo '  <div>';
    echo '    <h2 class="font-display text-2xl font-extrabold text-zinc-900">' . e($titulo) . '</h2>';
    echo '    <p class="text-xs text-zinc-500 mt-0.5">' . e($subtitulo) . '</p>';
    echo '  </div>';
    if ($boton_url) {
        echo '  <a href="' . e($boton_url) . '" class="flex items-center gap-1.5 px-3 py-2 rounded-lg bg-bacal-700 hover:bg-bacal-800 text-white text-sm font-semibold shadow-sm transition-colors">';
        echo '    <i data-lucide="plus" class="w-4 h-4"></i> ' . e($boton_texto);
        echo '  </a>';
    }
    echo '</div>';
}

/**
 * Activa o desactiva un registro (soft delete) en cualquier tabla con campo "activo".
 * Verifica permisos y registra auditoría.
 */
function admin_toggle_activo(string $tabla, int $id, string $entidad_label): bool {
    // Activar o desactivar un catálogo es cosa de administradores. Sin esto,
    // cualquiera con sesión podía mandar el POST a mano.
    if (!tiene_permiso('administrar')) {
        flash_set('error', 'No tienes permiso para modificar catálogos.');
        return false;
    }

    // Lista blanca: el nombre de la tabla entra directo al SQL, así que no
    // puede venir de cualquier lado. Debe incluir TODO catálogo con botón de
    // activar/desactivar; si falta uno, ese botón responde "Tabla no permitida".
    $tablas_permitidas = [
        'areas', 'categorias_gasto', 'subcategorias_gasto', 'insumos',
        'unidades_medida', 'formas_pago', 'proveedores', 'usuarios', 'roles',
    ];

    if (!in_array($tabla, $tablas_permitidas, true)) {
        flash_set('error', 'Tabla no permitida.');
        return false;
    }

    $row = db_one("SELECT activo FROM $tabla WHERE id = :id", ['id' => $id]);
    if (!$row) {
        flash_set('error', "$entidad_label no encontrado.");
        return false;
    }

    $nuevo = (int) $row['activo'] === 1 ? 0 : 1;
    db_exec("UPDATE $tabla SET activo = :a WHERE id = :id", ['a' => $nuevo, 'id' => $id]);

    registrar_auditoria(
        $nuevo ? 'activar' : 'desactivar',
        $tabla, $id,
        ($nuevo ? 'Activación' : 'Desactivación') . " de $entidad_label"
    );

    flash_set('success', "$entidad_label " . ($nuevo ? 'activado' : 'desactivado') . '.');
    return true;
}
