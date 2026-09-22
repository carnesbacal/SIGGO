<?php
/**
 * ============================================================================
 * config/tema.php - Identidad visual de SIG-GO
 * ============================================================================
 * Todo el color del sistema vive aquí. Para cambiar la marca completa no hay
 * que tocar ninguna página: se edita este archivo y se propaga solo.
 *
 * Reglas de la paleta (ver documento de identidad):
 *   · El VIOLETA manda la interfaz: menú activo, botones, encabezados, gráficas.
 *   · El ORO solo acentúa: la flecha del logo, el "GO", la barrita del menú
 *     activo y cifras sobre fondo violeta. NUNCA en una etiqueta de estatus.
 *   · El ROJO es exclusivo de dos cosas: la imagen del logo y las alertas.
 *     En la interfaz, rojo significa una sola cosa: algo va mal.
 *   · El semáforo (verde/naranja/rojo) no se toca y no depende de la marca.
 */

// --- Escala de marca --------------------------------------------------------
const MARCA = [
    50  => '#F5F3FF',
    100 => '#EDE9FE',
    200 => '#DDD6FE',
    300 => '#C4B5FD',
    400 => '#A78BFA',
    500 => '#8B5CF6',
    600 => '#7C3AED',  // interactivo: botones, glifo
    700 => '#6D28D9',  // principal: texto de marca, menú activo
    800 => '#5B21B6',
    900 => '#4C1D95',
];

// --- Oro (del listón del logo) ---------------------------------------------
const ORO      = '#F5E418';  // sobre fondo violeta u oscuro
const ORO_UI   = '#FACC15';  // acentos suaves sobre violeta
const ORO_INK  = '#A16207';  // legible sobre fondo claro
const ORO_DARK = '#453B02';  // texto encima de la pastilla dorada

// --- Colores de la imagen del logo (NO usar en la interfaz) ----------------
const LOGO_ROJO  = '#CC3038';
const LOGO_VERDE = '#007830';

// --- Semáforo de estados (fijo, independiente de la marca) -----------------
const EST_OK   = '#059669';  // pagado
const EST_WARN = '#C2410C';  // parcial / sin comprobante (naranja quemado,
                             // movido del ámbar para no chocar con el oro)
const EST_BAD  = '#DC2626';  // pendiente / cancelado

/** Color de la escala de marca. tono(600) => '#7C3AED' */
function tono(int $n): string {
    return MARCA[$n] ?? MARCA[600];
}

/** Color hexadecimal del estatus de pago de un gasto. */
function color_estatus(string $estatus): string {
    return match ($estatus) {
        'pagado'    => EST_OK,
        'parcial'   => EST_WARN,
        'pendiente' => EST_BAD,
        'cancelado' => EST_BAD,
        default     => '#6B7280',
    };
}

/** Etiqueta legible del estatus de pago. */
function texto_estatus(string $estatus): string {
    return match ($estatus) {
        'pagado'    => 'Pagado',
        'parcial'   => 'Parcial',
        'pendiente' => 'Pendiente',
        'cancelado' => 'Cancelado',
        default     => $estatus,
    };
}

/**
 * Bloque <script> con la configuración de Tailwind.
 * Los alias `rosa` y `bacal` apuntan al violeta a propósito: las páginas que
 * todavía no se han reescrito cambian de color sin tocarles una línea.
 */
function tema_tailwind(bool $con_modo_oscuro = true): void {
    $marca = json_encode(MARCA, JSON_UNESCAPED_SLASHES);
    $oro   = json_encode(['DEFAULT' => ORO, 'ui' => ORO_UI, 'ink' => ORO_INK, 'dark' => ORO_DARK], JSON_UNESCAPED_SLASHES);
    $est   = json_encode(['ok' => EST_OK, 'warn' => EST_WARN, 'bad' => EST_BAD], JSON_UNESCAPED_SLASHES);
    $dark  = $con_modo_oscuro ? "darkMode: 'class'," : '';
    echo <<<HTML
    <script>
        tailwind.config = {
            {$dark}
            theme: { extend: {
                fontFamily: {
                    sans: ['Inter', 'system-ui', 'sans-serif'],
                    display: ['"Bricolage Grotesque"', 'system-ui', 'sans-serif'],
                },
                colors: {
                    marca: {$marca},
                    rosa:  {$marca},
                    bacal: {$marca},
                    oro:   {$oro},
                    est:   {$est}
                }
            } }
        };
    </script>
    HTML;
}

/** Marca "SIG-GO": SIG en violeta + pastilla dorada con GO y flecha. */
function marca_wordmark(string $tam = 'text-xl'): string {
    $sig = tono(800);
    return '<span class="inline-flex items-center gap-1.5 font-display font-extrabold leading-none ' . $tam . '">'
         . '<span style="color:' . $sig . '">SIG</span>'
         . '<span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5" '
         .       'style="background:' . ORO . ';color:' . ORO_DARK . '">GO'
         . marca_flecha(ORO_DARK, '0.8em')
         . '</span></span>';
}

/** Solo la pastilla dorada. Para la barra lateral contraída y el favicon. */
function marca_pastilla(string $tam = 'text-lg'): string {
    return '<span class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-display font-extrabold leading-none ' . $tam . '" '
         .       'style="background:' . ORO . ';color:' . ORO_DARK . '">GO'
         . marca_flecha(ORO_DARK, '0.8em') . '</span>';
}

/** Flecha del "GO". */
function marca_flecha(string $color = ORO, string $tam = '1em'): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="3.2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" '
         . 'style="width:' . $tam . ';height:' . $tam . ';display:block">'
         . '<path d="M4 12h15"/><path d="M13 6l6 6-6 6"/></svg>';
}

/** Logo de la tienda. $variante: 'color' | 'blanco' */
function logo_tienda(string $clases = 'h-8 w-auto', string $variante = 'color'): string {
    $archivo = $variante === 'blanco' ? 'logo-grano-blanco.png' : 'logo-grano.png';
    return '<img src="' . url('assets/img/' . $archivo) . '" alt="' . e(EMPRESA_NOMBRE) . '" '
         . 'onerror="this.style.display=\'none\'" class="' . $clases . '">';
}
