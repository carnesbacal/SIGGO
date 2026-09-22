<?php
/**
 * config/pdf_writer.php
 * Generador de PDF en PHP puro. Sin composer, sin dompdf, sin wkhtmltopdf:
 * el sistema corre en un XAMPP y no hay que instalarle nada.
 *
 * Hace lo que un reporte necesita y nada más: encabezado, tarjetas de totales,
 * tablas con salto de página y encabezado repetido, barras simples y pie con
 * numeración. Usa las fuentes base del PDF (Helvetica), así que el archivo
 * pesa poco y abre en cualquier lector.
 *
 * Dos estilos, porque no todos los reportes son para lo mismo:
 *   'tablas'    — sobrio, blanco y negro, para imprimir y archivar.
 *   'ejecutivo' — con logo, color de marca y gráficas, para dirección.
 *
 * Acentos: el texto se pasa de UTF-8 a CP1252 (WinAnsi), que es lo que
 * entienden las fuentes base. La ñ y los acentos salen bien.
 */

// Anchos de Helvetica y Helvetica-Bold (codigos 32..255, WinAnsi), en milesimas de em
const PDFW_HELV  = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500';
const PDFW_HELVB = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556';

class PdfWriter
{
    // Hoja carta en puntos (72 por pulgada)
    private float $ancho, $alto;
    private float $mIzq = 36, $mDer = 36, $mSup = 36, $mInf = 40;

    private string $estilo;        // 'tablas' | 'ejecutivo'
    private string $titulo;
    private string $subtitulo;
    private string $pie;
    private ?string $logo;

    /** @var string[] contenido de cada página */
    private array $paginas = [];
    private string $buf = '';      // contenido de la página en curso
    private float $y = 0;          // cursor, medido desde arriba
    private array $imgs = [];      // XObjects de imagen

    private array $wHelv = [], $wHelvB = [];

    // Colores (r,g,b de 0 a 1)
    private array $cMarca, $cMarcaClaro, $cTexto, $cSuave, $cLinea, $cZebra;

    public function __construct(array $op = [])
    {
        $this->estilo    = ($op['estilo'] ?? 'tablas') === 'ejecutivo' ? 'ejecutivo' : 'tablas';
        $this->titulo    = (string)($op['titulo'] ?? 'Reporte');
        $this->subtitulo = (string)($op['subtitulo'] ?? '');
        $this->pie       = (string)($op['pie'] ?? '');
        $this->logo      = !empty($op['logo']) && is_file($op['logo']) ? $op['logo'] : null;

        $horizontal = ($op['orientacion'] ?? 'vertical') === 'horizontal';
        $this->ancho = $horizontal ? 792 : 612;
        $this->alto  = $horizontal ? 612 : 792;

        $this->wHelv  = array_map('intval', explode(',', PDFW_HELV));
        $this->wHelvB = array_map('intval', explode(',', PDFW_HELVB));

        if ($this->estilo === 'ejecutivo') {
            $this->cMarca       = [0.486, 0.227, 0.929];   // violeta de marca
            $this->cMarcaClaro  = [0.957, 0.949, 1.0];
            $this->cZebra       = [0.976, 0.973, 1.0];
        } else {
            $this->cMarca       = [0.15, 0.15, 0.17];      // gris muy oscuro
            $this->cMarcaClaro  = [0.94, 0.94, 0.95];
            $this->cZebra       = [0.972, 0.972, 0.975];
        }
        $this->cTexto = [0.13, 0.13, 0.15];
        $this->cSuave = [0.45, 0.45, 0.50];
        $this->cLinea = [0.85, 0.85, 0.87];

        $this->nuevaPagina();
    }

    // -----------------------------------------------------------------
    //  Texto: medidas y codificación
    // -----------------------------------------------------------------

    /**
     * UTF-8 -> CP1252, que es lo que entienden las fuentes base.
     * Los símbolos que no existen en CP1252 se cambian por su equivalente
     * antes de convertir; si no, iconv los deja como "?" y el reporte sale
     * con signos de interrogación sueltos.
     */
    private function cp(string $s): string
    {
        $s = strtr($s, [
            '▲' => '+', '▼' => '-', '↑' => '+', '↓' => '-',
            '—' => '-', '–' => '-', '…' => '...', '•' => '·',
            '“' => '"', '”' => '"', '‘' => "'", '’' => "'", '→' => '->',
        ]);
        return (string) @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
    }

    /** Ancho del texto en puntos. */
    public function anchoTexto(string $txt, float $tam, bool $negrita = false): float
    {
        $t = $this->cp($txt);
        $w = $negrita ? $this->wHelvB : $this->wHelv;
        $suma = 0;
        $n = strlen($t);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($t[$i]);
            $suma += ($c >= 32 && $c <= 255) ? ($w[$c - 32] ?? 500) : 0;
        }
        return $suma * $tam / 1000;
    }

    /** Corta el texto en líneas que quepan en $ancho. Máximo $maxLineas. */
    private function envolver(string $txt, float $ancho, float $tam, bool $neg, int $maxLineas = 3): array
    {
        $txt = trim(preg_replace('/\s+/u', ' ', $txt));
        if ($txt === '') return [''];
        if ($this->anchoTexto($txt, $tam, $neg) <= $ancho) return [$txt];

        $palabras = explode(' ', $txt);
        $lineas = []; $act = '';
        foreach ($palabras as $p) {
            $prueba = $act === '' ? $p : $act . ' ' . $p;
            if ($this->anchoTexto($prueba, $tam, $neg) <= $ancho) { $act = $prueba; continue; }
            if ($act !== '') $lineas[] = $act;
            // Palabra sola más ancha que la columna: se parte a lo bruto
            while ($this->anchoTexto($p, $tam, $neg) > $ancho && mb_strlen($p) > 1) {
                $corte = mb_strlen($p);
                while ($corte > 1 && $this->anchoTexto(mb_substr($p, 0, $corte), $tam, $neg) > $ancho) $corte--;
                $lineas[] = mb_substr($p, 0, $corte);
                $p = mb_substr($p, $corte);
            }
            $act = $p;
            if (count($lineas) >= $maxLineas) break;
        }
        if ($act !== '' && count($lineas) < $maxLineas) $lineas[] = $act;
        $lineas = array_slice($lineas, 0, $maxLineas);

        // Si se cortó, la última línea lleva puntos suspensivos
        $completo = implode(' ', $lineas);
        if (mb_strlen($completo) < mb_strlen($txt) - 1) {
            $ult = end($lineas);
            while ($ult !== '' && $this->anchoTexto($ult . '...', $tam, $neg) > $ancho) $ult = mb_substr($ult, 0, -1);
            $lineas[count($lineas) - 1] = $ult . '...';
        }
        return $lineas;
    }

    private function esc(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $s);
    }

    // -----------------------------------------------------------------
    //  Primitivas de dibujo
    // -----------------------------------------------------------------

    private function yPdf(float $yArriba): float { return $this->alto - $yArriba; }

    private function color(array $c, bool $trazo = false): string
    {
        return sprintf("%.3f %.3f %.3f %s\n", $c[0], $c[1], $c[2], $trazo ? 'RG' : 'rg');
    }

    public function texto(string $txt, float $x, float $yArriba, float $tam = 9,
                          bool $neg = false, ?array $color = null, string $alinea = 'l', float $ancho = 0): void
    {
        $t = $this->esc($this->cp($txt));
        if ($t === '') return;
        if ($alinea === 'r')      $x = $x + $ancho - $this->anchoTexto($txt, $tam, $neg);
        elseif ($alinea === 'c')  $x = $x + ($ancho - $this->anchoTexto($txt, $tam, $neg)) / 2;

        $this->buf .= $this->color($color ?? $this->cTexto);
        $this->buf .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
                              $neg ? 'F2' : 'F1', $tam, $x, $this->yPdf($yArriba) - $tam * 0.8, $t);
    }

    public function rect(float $x, float $yArriba, float $w, float $h, array $relleno = null, array $borde = null): void
    {
        if ($relleno) {
            $this->buf .= $this->color($relleno);
            $this->buf .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x, $this->yPdf($yArriba + $h), $w, $h);
        }
        if ($borde) {
            $this->buf .= $this->color($borde, true);
            $this->buf .= sprintf("0.6 w %.2f %.2f %.2f %.2f re S\n", $x, $this->yPdf($yArriba + $h), $w, $h);
        }
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, array $color = null, float $grosor = 0.6): void
    {
        $this->buf .= $this->color($color ?? $this->cLinea, true);
        $this->buf .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $grosor, $x1, $this->yPdf($y1), $x2, $this->yPdf($y2));
    }

    // -----------------------------------------------------------------
    //  Páginas
    // -----------------------------------------------------------------

    private function nuevaPagina(): void
    {
        if ($this->buf !== '') $this->paginas[] = $this->buf;
        $this->buf = '';
        $this->y   = $this->mSup;
        $this->encabezado();
    }

    /** Espacio útil de arriba a abajo. */
    private function limiteInferior(): float { return $this->alto - $this->mInf; }

    /** Pide $alto puntos; si no caben, abre página nueva. Devuelve true si saltó. */
    public function asegurar(float $alto): bool
    {
        if ($this->y + $alto <= $this->limiteInferior()) return false;
        $this->nuevaPagina();
        return true;
    }

    private function encabezado(): void
    {
        $x = $this->mIzq;
        $w = $this->ancho - $this->mIzq - $this->mDer;

        if ($this->estilo === 'ejecutivo') {
            // Banda de color con el logo y el título
            $h = 56;
            $this->rect(0, 0, $this->ancho, $h + $this->mSup - 18, $this->cMarca);
            $xt = $x;
            if ($this->logo) {
                $id = $this->imagen($this->logo);
                if ($id) {
                    $prop = $this->imgs[$id]['alto'] / max(1, $this->imgs[$id]['ancho']);
                    $ah = 30; $aw = $ah / max(0.01, $prop);
                    if ($aw > 90) { $aw = 90; $ah = $aw * $prop; }
                    $this->buf .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n",
                        $aw, $ah, $x, $this->yPdf(14 + $ah), $id);
                    $xt = $x + $aw + 12;
                }
            }
            $this->texto($this->titulo, $xt, 16, 15, true, [1, 1, 1]);
            if ($this->subtitulo !== '') $this->texto($this->subtitulo, $xt, 34, 8.5, false, [0.90, 0.86, 1]);
            $this->texto('SIG-GO', $x + $w - 46, 16, 12, true, [1, 1, 1]);
            $this->y = $h + $this->mSup - 4;
        } else {
            $this->texto($this->titulo, $x, $this->mSup, 14, true);
            if ($this->subtitulo !== '') $this->texto($this->subtitulo, $x, $this->mSup + 17, 8.5, false, $this->cSuave);
            $this->y = $this->mSup + ($this->subtitulo !== '' ? 32 : 22);
            $this->linea($x, $this->y, $x + $w, $this->y, $this->cMarca, 1);
            $this->y += 14;
        }
    }

    private function pieDePagina(int $num, int $total): string
    {
        $x = $this->mIzq;
        $w = $this->ancho - $this->mIzq - $this->mDer;
        $yl = $this->alto - $this->mInf + 8;
        $s  = $this->color($this->cLinea, true);
        $s .= sprintf("0.6 w %.2f %.2f m %.2f %.2f l S\n", $x, $this->yPdf($yl), $x + $w, $this->yPdf($yl));

        $izq = $this->esc($this->cp($this->pie));
        $der = $this->esc($this->cp("Página $num de $total"));
        $s .= $this->color($this->cSuave);
        $s .= sprintf("BT /F1 7.5 Tf %.2f %.2f Td (%s) Tj ET\n", $x, $this->yPdf($yl + 13), $izq);
        $anchoDer = $this->anchoTexto("Página $num de $total", 7.5);
        $s .= sprintf("BT /F1 7.5 Tf %.2f %.2f Td (%s) Tj ET\n", $x + $w - $anchoDer, $this->yPdf($yl + 13), $der);
        return $s;
    }

    // -----------------------------------------------------------------
    //  Bloques de contenido
    // -----------------------------------------------------------------

    /** Título de sección. */
    public function seccion(string $txt, float $espacioArriba = 14): void
    {
        $this->asegurar(30 + $espacioArriba);
        $this->y += $espacioArriba;
        $this->texto($txt, $this->mIzq, $this->y, 10.5, true, $this->cMarca);
        $this->y += 16;
    }

    /** Párrafo de texto corrido. */
    public function parrafo(string $txt, float $tam = 8.5, ?array $color = null): void
    {
        $w = $this->ancho - $this->mIzq - $this->mDer;
        foreach ($this->envolver($txt, $w, $tam, false, 12) as $ln) {
            $this->asegurar($tam + 4);
            $this->texto($ln, $this->mIzq, $this->y, $tam, false, $color ?? $this->cSuave);
            $this->y += $tam + 3.5;
        }
        $this->y += 4;
    }

    /**
     * Tarjetas de totales.
     * $tarjetas = [ ['etiqueta', 'valor', 'nota opcional'], ... ]
     */
    public function kpis(array $tarjetas, int $porFila = 4): void
    {
        if (!$tarjetas) return;
        $w = $this->ancho - $this->mIzq - $this->mDer;
        $gap = 8;
        $filas = array_chunk($tarjetas, $porFila);
        foreach ($filas as $fila) {
            $n  = count($fila);
            $cw = ($w - $gap * ($n - 1)) / $n;
            $h  = 46;
            $this->asegurar($h + 10);
            $x = $this->mIzq;
            foreach ($fila as $t) {
                $this->rect($x, $this->y, $cw, $h, $this->cMarcaClaro, $this->cLinea);
                $this->texto(mb_strtoupper((string)$t[0]), $x + 8, $this->y + 8, 6.5, true, $this->cSuave);
                $this->texto((string)$t[1], $x + 8, $this->y + 19, 14, true,
                             $this->estilo === 'ejecutivo' ? $this->cMarca : $this->cTexto);
                if (isset($t[2]) && $t[2] !== '') $this->texto((string)$t[2], $x + 8, $this->y + 36, 7, false, $this->cSuave);
                $x += $cw + $gap;
            }
            $this->y += $h + $gap;
        }
        $this->y += 4;
    }

    /**
     * Tabla con salto de página y encabezado repetido.
     *
     * $cols  = [ ['t'=>'Folio', 'w'=>60, 'a'=>'l|r|c', 'f'=>'money|int|num|null'], ... ]
     *          'w' es proporcional: se reparte el ancho disponible.
     * $filas = [ [v1, v2, ...], ... ]
     * $total = [v1, v2, ...] opcional, se pinta como renglón de totales.
     */
    public function tabla(array $cols, array $filas, ?array $total = null, string $vacio = 'Sin datos.'): void
    {
        $w = $this->ancho - $this->mIzq - $this->mDer;
        $sumaW = 0; foreach ($cols as $c) $sumaW += (float)($c['w'] ?? 1);
        $anchos = [];
        foreach ($cols as $i => $c) $anchos[$i] = $w * ((float)($c['w'] ?? 1) / $sumaW);

        $tamEnc = 7.5; $tam = 8.2; $padX = 5; $padY = 5;

        $pintaEncabezado = function () use ($cols, $anchos, $tamEnc, $padX, $w) {
            $h = 18;
            $this->rect($this->mIzq, $this->y, $w, $h,
                        $this->estilo === 'ejecutivo' ? $this->cMarca : [0.92, 0.92, 0.94]);
            $x = $this->mIzq;
            foreach ($cols as $i => $c) {
                // El título se recorta al ancho de su columna: si no, se
                // encima con el de al lado y el encabezado se vuelve ilegible
                $et = $this->envolver(mb_strtoupper((string)($c['t'] ?? '')),
                                      $anchos[$i] - $padX * 2, $tamEnc, true, 1)[0];
                $this->texto($et, $x + $padX, $this->y + 5.5, $tamEnc, true,
                             $this->estilo === 'ejecutivo' ? [1, 1, 1] : $this->cTexto,
                             ($c['a'] ?? 'l'), $anchos[$i] - $padX * 2);
                $x += $anchos[$i];
            }
            $this->y += $h;
        };

        $this->asegurar(18 + 24);
        $pintaEncabezado();

        if (!$filas) {
            $this->texto($vacio, $this->mIzq + $padX, $this->y + 8, $tam, false, $this->cSuave);
            $this->y += 26;
            return;
        }

        $z = 0;
        foreach ($filas as $fila) {
            // Se calcula el alto del renglón antes de pintarlo
            $lineasPorCol = []; $maxLineas = 1;
            foreach ($cols as $i => $c) {
                $v = $this->formato($fila[$i] ?? '', $c['f'] ?? null);
                $ls = $this->envolver($v, $anchos[$i] - $padX * 2, $tam, false, 3);
                $lineasPorCol[$i] = $ls;
                $maxLineas = max($maxLineas, count($ls));
            }
            $h = $padY * 2 + $maxLineas * ($tam + 2.2);

            if ($this->asegurar($h)) { $pintaEncabezado(); $z = 0; }

            if ($z % 2 === 1) $this->rect($this->mIzq, $this->y, $w, $h, $this->cZebra);
            $x = $this->mIzq;
            foreach ($cols as $i => $c) {
                $yy = $this->y + $padY;
                foreach ($lineasPorCol[$i] as $ln) {
                    $this->texto($ln, $x + $padX, $yy, $tam, !empty($c['b']), null, ($c['a'] ?? 'l'), $anchos[$i] - $padX * 2);
                    $yy += $tam + 2.2;
                }
                $x += $anchos[$i];
            }
            $this->linea($this->mIzq, $this->y + $h, $this->mIzq + $w, $this->y + $h);
            $this->y += $h;
            $z++;
        }

        if ($total !== null) {
            $h = 20;
            if ($this->asegurar($h)) $pintaEncabezado();
            $this->rect($this->mIzq, $this->y, $w, $h, $this->cMarcaClaro);
            $x = $this->mIzq;
            foreach ($cols as $i => $c) {
                $v = $this->formato($total[$i] ?? '', $c['f'] ?? null);
                $this->texto($v, $x + $padX, $this->y + 6, $tam, true, null, ($c['a'] ?? 'l'), $anchos[$i] - $padX * 2);
                $x += $anchos[$i];
            }
            $this->y += $h;
        }
        $this->y += 8;
    }

    private function formato($v, ?string $f): string
    {
        // Celda vacía es vacía: sin esto, un '' con formato de dinero
        // se imprimiría como $0.00 y parecería un dato real
        if ($v === null || $v === '') return '';
        switch ($f) {
            case 'money': return '$' . number_format((float)$v, 2);
            case 'money0':return '$' . number_format((float)$v, 0);
            case 'int':   return number_format((float)$v, 0);
            case 'num':   return rtrim(rtrim(number_format((float)$v, 3), '0'), '.');
            case 'pct':   return number_format((float)$v, 1) . '%';
            default:      return (string)$v;
        }
    }

    /**
     * Barras horizontales: [ ['etiqueta', valor], ... ]. Solo tiene sentido en
     * el estilo ejecutivo; en el sobrio no se dibuja nada.
     */
    public function barras(array $datos, bool $dinero = true): void
    {
        if ($this->estilo !== 'ejecutivo' || !$datos) return;
        $w    = $this->ancho - $this->mIzq - $this->mDer;
        $wEtq = min(170, $w * 0.32);
        $wVal = 78;
        $wBar = $w - $wEtq - $wVal - 12;
        $max  = 0.0; foreach ($datos as $d) $max = max($max, (float)$d[1]);
        if ($max <= 0) $max = 1;

        foreach ($datos as $d) {
            $h = 15;
            $this->asegurar($h + 2);
            $this->texto((string)$d[0], $this->mIzq, $this->y + 3, 8, false, $this->cTexto);
            $largo = $wBar * ((float)$d[1] / $max);
            $this->rect($this->mIzq + $wEtq, $this->y + 3.5, $wBar, 8, [0.93, 0.93, 0.96]);
            if ($largo > 0.5) $this->rect($this->mIzq + $wEtq, $this->y + 3.5, $largo, 8, $this->cMarca);
            $this->texto($dinero ? '$' . number_format((float)$d[1], 2) : (string)$d[1],
                         $this->mIzq + $wEtq + $wBar + 6, $this->y + 3, 8, true, $this->cTexto, 'r', $wVal);
            $this->y += $h;
        }
        $this->y += 8;
    }

    /** Barras verticales por mes: $serie = [1=>valor, ... 12=>valor]. */
    public function barrasMes(array $serie): void
    {
        if ($this->estilo !== 'ejecutivo') return;
        $meses = ['', 'Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        $w = $this->ancho - $this->mIzq - $this->mDer;
        $alto = 90;
        $this->asegurar($alto + 26);
        $max = 0.0; foreach ($serie as $v) $max = max($max, (float)$v);
        if ($max <= 0) $max = 1;

        $gap = 8;
        $bw  = ($w - $gap * 11) / 12;
        $x   = $this->mIzq;
        $base = $this->y + $alto;
        foreach (range(1, 12) as $m) {
            $v = (float)($serie[$m] ?? 0);
            $h = $alto * ($v / $max);
            if ($h > 0.5) $this->rect($x, $base - $h, $bw, $h, $this->cMarca);
            else          $this->rect($x, $base - 1.5, $bw, 1.5, [0.88, 0.88, 0.91]);
            $this->texto($meses[$m], $x, $base + 4, 7, false, $this->cSuave, 'c', $bw);
            if ($v > 0) $this->texto(number_format($v / 1000, 1) . 'k', $x, $base - $h - 9, 6.5, true, $this->cSuave, 'c', $bw);
            $x += $bw + $gap;
        }
        $this->linea($this->mIzq, $base, $this->mIzq + $w, $base);
        $this->y = $base + 18;
    }

    public function conGraficas(): bool { return $this->estilo === 'ejecutivo'; }

    /**
     * Ranking (categorías, áreas, proveedores, productos...).
     * En estilo ejecutivo sale como barras; en el sobrio, como tabla. El dato
     * es el mismo: cambia cómo se ve, no lo que dice.
     */
    public function bloqueRanking(string $titulo, array $datos, bool $dinero = true, string $etiqueta = 'Concepto'): void
    {
        if (!$datos) return;
        $this->seccion($titulo);
        if ($this->conGraficas()) { $this->barras($datos, $dinero); return; }

        $suma = 0.0; foreach ($datos as $d) $suma += (float)$d[1];
        $filas = [];
        foreach ($datos as $d) {
            $filas[] = [(string)$d[0], (float)$d[1], $suma > 0 ? ((float)$d[1] / $suma) * 100 : 0];
        }
        $this->tabla([
            ['t'=>$etiqueta, 'w'=>3],
            ['t'=>'Importe', 'w'=>1.2, 'a'=>'r', 'f'=>$dinero ? 'money' : 'num'],
            ['t'=>'% del bloque', 'w'=>1, 'a'=>'r', 'f'=>'pct'],
        ], $filas);
    }

    /** Gasto por mes: barras en ejecutivo, tabla en sobrio. */
    public function bloqueMeses(string $titulo, array $serie): void
    {
        $this->seccion($titulo);
        if ($this->conGraficas()) { $this->barrasMes($serie); return; }

        $meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio',
                  'Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $suma = 0.0; foreach ($serie as $v) $suma += (float)$v;
        $filas = [];
        foreach (range(1, 12) as $m) {
            $v = (float)($serie[$m] ?? 0);
            $filas[] = [$meses[$m], $v, $suma > 0 ? ($v / $suma) * 100 : 0];
        }
        $this->tabla([
            ['t'=>'Mes', 'w'=>2],
            ['t'=>'Gasto', 'w'=>1.3, 'a'=>'r', 'f'=>'money'],
            ['t'=>'% del año', 'w'=>1, 'a'=>'r', 'f'=>'pct'],
        ], $filas, ['TOTAL', $suma, 100.0]);
    }

    // -----------------------------------------------------------------
    //  Imágenes (solo PNG/JPG, para el logo)
    // -----------------------------------------------------------------

    private function imagen(string $ruta): ?string
    {
        static $cache = [];
        if (isset($cache[$ruta])) return $cache[$ruta];
        if (!function_exists('imagecreatefrompng')) return null;   // sin GD, no hay logo

        $info = @getimagesize($ruta);
        if (!$info) return null;
        $im = null;
        if ($info[2] === IMAGETYPE_PNG)  $im = @imagecreatefrompng($ruta);
        elseif ($info[2] === IMAGETYPE_JPEG) $im = @imagecreatefromjpeg($ruta);
        if (!$im) return null;

        $aw = imagesx($im); $ah = imagesy($im);
        // En el PDF el logo mide 90 puntos: guardar 500 píxeles de ancho solo
        // engorda el archivo. Se reduce a 200 px, que ya es de sobra.
        $maxAncho = 200;
        if ($aw > $maxAncho) {
            $nh = (int) round($ah * $maxAncho / $aw);
            $chico = imagecreatetruecolor($maxAncho, $nh);
            imagefill($chico, 0, 0, imagecolorallocate($chico, 255, 255, 255));
            imagecopyresampled($chico, $im, 0, 0, 0, 0, $maxAncho, $nh, $aw, $ah);
            imagedestroy($im);
            $im = $chico; $aw = $maxAncho; $ah = $nh;
        }
        // Se aplana sobre blanco: el PDF de un reporte no necesita transparencia
        $plano = imagecreatetruecolor($aw, $ah);
        imagefill($plano, 0, 0, imagecolorallocate($plano, 255, 255, 255));
        imagecopy($plano, $im, 0, 0, 0, 0, $aw, $ah);
        imagedestroy($im);

        $rgb = '';
        for ($y = 0; $y < $ah; $y++) {
            for ($x = 0; $x < $aw; $x++) {
                $c = imagecolorat($plano, $x, $y);
                $rgb .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255);
            }
        }
        imagedestroy($plano);

        $id = 'Im' . (count($this->imgs) + 1);
        $this->imgs[$id] = ['ancho' => $aw, 'alto' => $ah, 'datos' => gzcompress($rgb, 6)];
        $cache[$ruta] = $id;
        return $id;
    }

    // -----------------------------------------------------------------
    //  Salida
    // -----------------------------------------------------------------

    private function construir(): string
    {
        if ($this->buf !== '') { $this->paginas[] = $this->buf; $this->buf = ''; }
        if (!$this->paginas) $this->paginas[] = '';
        $nPag = count($this->paginas);

        $objs = [];
        $add = function (string $cuerpo) use (&$objs): int { $objs[] = $cuerpo; return count($objs); };

        $idCat   = $add('');                       // 1 catálogo (se llena después)
        $idPages = $add('');                       // 2 páginas
        $idF1 = $add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
        $idF2 = $add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");

        $idImg = [];
        foreach ($this->imgs as $id => $im) {
            $idImg[$id] = $add("<< /Type /XObject /Subtype /Image /Width {$im['ancho']} /Height {$im['alto']}"
                . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length "
                . strlen($im['datos']) . " >>\nstream\n" . $im['datos'] . "\nendstream");
        }

        $rec = "<< /Font << /F1 $idF1 0 R /F2 $idF2 0 R >>";
        if ($idImg) {
            $rec .= " /XObject << ";
            foreach ($idImg as $nom => $oid) $rec .= "/$nom $oid 0 R ";
            $rec .= ">>";
        }
        $rec .= " >>";

        $idPaginas = [];
        foreach ($this->paginas as $i => $cont) {
            $cont .= $this->pieDePagina($i + 1, $nPag);
            $comp = gzcompress($cont, 6);
            $idCont = $add("<< /Length " . strlen($comp) . " /Filter /FlateDecode >>\nstream\n" . $comp . "\nendstream");
            $idPaginas[] = $add("<< /Type /Page /Parent $idPages 0 R /MediaBox [0 0 {$this->ancho} {$this->alto}]"
                . " /Resources $rec /Contents $idCont 0 R >>");
        }

        $kids = implode(' ', array_map(fn($i) => "$i 0 R", $idPaginas));
        $objs[$idPages - 1] = "<< /Type /Pages /Count $nPag /Kids [ $kids ] >>";
        $objs[$idCat - 1]   = "<< /Type /Catalog /Pages $idPages 0 R >>";

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $i => $cuerpo) {
            $off[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $cuerpo . "\nendobj\n";
        }
        $inicioXref = strlen($pdf);
        $n = count($objs) + 1;
        $pdf .= "xref\n0 $n\n0000000000 65535 f \n";
        for ($i = 1; $i < $n; $i++) $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
        $pdf .= "trailer\n<< /Size $n /Root $idCat 0 R >>\nstartxref\n$inicioXref\n%%EOF";
        return $pdf;
    }

    /** Manda el PDF al navegador como descarga. */
    public function descargar(string $nombre): void
    {
        $pdf = $this->construir();
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $nombre . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
        }
        echo $pdf;
        exit;
    }

    public function contenido(): string { return $this->construir(); }
}
