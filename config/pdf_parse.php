<?php
/**
 * config/pdf_parse.php
 * Lee una factura en PDF sin depender de nada externo (ni shell, ni librerías).
 *
 * Va en dos pasadas, de la más confiable a la menos:
 *
 *   1. XML INCRUSTADO. Muchos PACs adjuntan el XML dentro del PDF. Si está,
 *      se usa ese: los datos salen exactos, con conceptos y todo, igual que
 *      si el usuario hubiera subido el XML.
 *
 *   2. TEXTO DEL PDF. Si no hay XML, se extrae el texto respetando las
 *      coordenadas (para que una fila de la tabla salga en un solo renglón)
 *      y se buscan los patrones del CFDI: UUID, RFC, total, fecha, folio.
 *      El encabezado sale bien casi siempre; los renglones NO, porque cada
 *      emisor arma la tabla distinto. Por eso el resultado dice qué tan
 *      confiable es, para que la pantalla lo advierta en vez de fingir que
 *      lo leyó todo.
 *
 * Nada de esto adivina en silencio: si algo no se pudo leer se devuelve
 * vacío y la persona lo captura.
 */

// ---------------------------------------------------------------------------
// Decodificadores de flujos
// ---------------------------------------------------------------------------

/** ASCII85 (el /ASCII85Decode del PDF). Devuelve null si trae basura. */
function pdf_ascii85(string $s): ?string {
    $s = preg_replace('/\s+/', '', $s);
    if (strncmp($s, '<~', 2) === 0) $s = substr($s, 2);
    $fin = strpos($s, '~>');
    if ($fin !== false) $s = substr($s, 0, $fin);

    $out = ''; $tupla = 0; $n = 0; $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($ch === 'z' && $n === 0) { $out .= "\0\0\0\0"; continue; }
        $v = ord($ch) - 33;
        if ($v < 0 || $v > 84) return null;
        $tupla = $tupla * 85 + $v;
        if (++$n === 5) { $out .= pack('N', $tupla); $tupla = 0; $n = 0; }
    }
    if ($n > 0) {
        for ($i = $n; $i < 5; $i++) $tupla = $tupla * 85 + 84;
        $out .= substr(pack('N', $tupla), 0, $n - 1);
    }
    return $out;
}

/** ASCIIHex (/ASCIIHexDecode). */
function pdf_asciihex(string $s): ?string {
    $s = preg_replace('/\s+/', '', $s);
    $fin = strpos($s, '>');
    if ($fin !== false) $s = substr($s, 0, $fin);
    if (!preg_match('/^[0-9A-Fa-f]*$/', $s)) return null;
    if (strlen($s) % 2) $s .= '0';
    $bin = @hex2bin($s);
    return $bin === false ? null : $bin;
}

/** RunLength (/RunLengthDecode). */
function pdf_runlength(string $s): ?string {
    $out = ''; $i = 0; $len = strlen($s);
    while ($i < $len) {
        $l = ord($s[$i++]);
        if ($l === 128) break;
        if ($l < 128) { $out .= substr($s, $i, $l + 1); $i += $l + 1; }
        else { if ($i >= $len) break; $out .= str_repeat($s[$i], 257 - $l); $i++; }
    }
    return $out;
}

/** LZW del PDF (con "early change", que es lo normal). */
function pdf_lzw(string $s, int $early = 1): ?string {
    $dic = []; for ($i = 0; $i < 256; $i++) $dic[$i] = chr($i);
    $sig = 258; $ancho = 9; $prev = null; $out = '';
    $bits = 0; $buf = 0; $len = strlen($s);

    for ($i = 0; $i < $len; $i++) {
        $buf = ($buf << 8) | ord($s[$i]); $bits += 8;
        while ($bits >= $ancho) {
            $cod = ($buf >> ($bits - $ancho)) & ((1 << $ancho) - 1);
            $bits -= $ancho;

            if ($cod === 256) { $dic = []; for ($k = 0; $k < 256; $k++) $dic[$k] = chr($k);
                                $sig = 258; $ancho = 9; $prev = null; continue; }
            if ($cod === 257) return $out;

            if (isset($dic[$cod]))      $ent = $dic[$cod];
            elseif ($prev !== null)     $ent = $prev . $prev[0];
            else                        return null;

            $out .= $ent;
            if ($prev !== null) { $dic[$sig++] = $prev . $ent[0]; }
            $prev = $ent;

            if ($sig + $early >= (1 << $ancho) && $ancho < 12) $ancho++;
        }
    }
    return $out;
}

/** Lee los nombres de /Filter del encabezado de un objeto. */
function pdf_filtros(string $cab): array {
    $ini = strrpos($cab, ' obj');
    if ($ini !== false) $cab = substr($cab, $ini);
    if (preg_match('/\/Filter\s*\[([^\]]*)\]/', $cab, $m)) {
        preg_match_all('/\/([A-Za-z0-9]+)/', $m[1], $f);
        return $f[1];
    }
    if (preg_match('/\/Filter\s*\/([A-Za-z0-9]+)/', $cab, $m)) return [$m[1]];
    return [];
}

/**
 * Devuelve todos los flujos del PDF ya descomprimidos.
 * Los de imagen (DCT, JPX, CCITT) se saltan: no traen texto.
 */
function pdf_streams(string $raw): array {
    $salida = [];
    if (!preg_match_all('/stream\r?\n?(.*?)endstream/s', $raw, $m, PREG_OFFSET_CAPTURE)) return $salida;

    foreach ($m[1] as $par) {
        [$datos, $off] = $par;
        $cab = substr($raw, max(0, $off - 1200), min(1200, $off));
        $filtros = pdf_filtros($cab);

        if ($filtros) {
            foreach ($filtros as $f) {
                switch ($f) {
                    case 'FlateDecode': case 'Fl':
                        $d = @gzuncompress($datos);
                        if ($d === false) $d = @gzinflate($datos);
                        if ($d === false) $d = @gzinflate(substr($datos, 1));  // cabecera rara
                        $datos = $d; break;
                    case 'ASCII85Decode': case 'A85': $datos = pdf_ascii85($datos); break;
                    case 'ASCIIHexDecode': case 'AHx': $datos = pdf_asciihex($datos); break;
                    case 'LZWDecode': case 'LZW':      $datos = pdf_lzw($datos); break;
                    case 'RunLengthDecode': case 'RL': $datos = pdf_runlength($datos); break;
                    default: $datos = null;            // imagen u otra cosa
                }
                if (!is_string($datos) || $datos === '') break;
                $datos = ltrim($datos, "\r\n");
            }
        } else {
            // Sin /Filter legible: se intenta a ciegas, por si acaso
            $d = @gzuncompress(ltrim($datos, "\r\n"));
            if ($d === false) $d = @gzinflate(ltrim($datos, "\r\n"));
            if ($d !== false) $datos = $d;
        }

        if (is_string($datos) && $datos !== '') $salida[] = $datos;
    }
    return $salida;
}

// ---------------------------------------------------------------------------
// Extracción de texto respetando renglones
// ---------------------------------------------------------------------------

/** Traduce los escapes de una cadena literal del PDF: \n \( \\ \053 ... */
function pdf_cadena(string $s): string {
    $out = ''; $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c !== '\\') { $out .= $c; continue; }
        $i++; if ($i >= $len) break;
        $n = $s[$i];
        if ($n >= '0' && $n <= '7') {
            $oct = $n;
            for ($k = 0; $k < 2 && $i + 1 < $len && $s[$i+1] >= '0' && $s[$i+1] <= '7'; $k++) $oct .= $s[++$i];
            $out .= chr(octdec($oct));
        } else {
            switch ($n) {
                case 'n': $out .= "\n"; break;  case 'r': $out .= "\r"; break;
                case 't': $out .= "\t"; break;  case 'b': $out .= "\b"; break;
                case 'f': $out .= "\f"; break;  case "\n": break;
                case "\r": if ($i + 1 < $len && $s[$i+1] === "\n") $i++; break;
                default:  $out .= $n;
            }
        }
    }
    return $out;
}

/**
 * Recorre un flujo de contenido y devuelve sus renglones de texto.
 * Agrupa por coordenada Y para que una fila de tabla salga completa en un
 * renglón, aunque el PDF la haya escrito en cinco pedazos.
 */
function pdf_lineas_de_contenido(string $s): array {
    $lineas = [];                    // clave Y => [ ['x'=>, 't'=>], ... ]
    $x = 0.0; $y = 0.0; $leading = 12.0;
    $ops = [];                       // operandos pendientes
    $i = 0; $len = strlen($s);

    $poner = function (string $txt) use (&$lineas, &$x, &$y) {
        if ($txt === '') return;
        $clave = (string) round($y, 1);
        $lineas[$clave][] = ['x' => $x, 't' => $txt];
    };

    while ($i < $len) {
        $c = $s[$i];

        if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\0" || $c === "\f") { $i++; continue; }

        // Comentario
        if ($c === '%') { while ($i < $len && $s[$i] !== "\n") $i++; continue; }

        // Cadena literal ( ... ) con paréntesis anidados
        if ($c === '(') {
            $nivel = 1; $i++; $ini = $i;
            while ($i < $len && $nivel > 0) {
                if ($s[$i] === '\\') { $i += 2; continue; }
                if ($s[$i] === '(') $nivel++;
                elseif ($s[$i] === ')') { $nivel--; if ($nivel === 0) break; }
                $i++;
            }
            $ops[] = ['s', pdf_cadena(substr($s, $ini, $i - $ini))];
            $i++; continue;
        }

        // Cadena hexadecimal < ... >  (y diccionarios << >>)
        if ($c === '<') {
            if ($i + 1 < $len && $s[$i+1] === '<') {           // diccionario: se salta
                $prof = 0;
                while ($i < $len) {
                    if ($s[$i] === '<' && ($i+1 < $len) && $s[$i+1] === '<') { $prof++; $i += 2; continue; }
                    if ($s[$i] === '>' && ($i+1 < $len) && $s[$i+1] === '>') { $prof--; $i += 2; if ($prof <= 0) break; continue; }
                    $i++;
                }
                continue;
            }
            $fin = strpos($s, '>', $i);
            if ($fin === false) break;
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $fin - $i - 1));
            if (strlen($hex) % 2) $hex .= '0';
            $ops[] = ['s', (string) @hex2bin($hex)];
            $i = $fin + 1; continue;
        }

        // Arreglo [ ... ] del operador TJ
        if ($c === '[') {
            $i++; $partes = '';
            while ($i < $len && $s[$i] !== ']') {
                if ($s[$i] === '(') {
                    $nivel = 1; $i++; $ini = $i;
                    while ($i < $len && $nivel > 0) {
                        if ($s[$i] === '\\') { $i += 2; continue; }
                        if ($s[$i] === '(') $nivel++;
                        elseif ($s[$i] === ')') { $nivel--; if ($nivel === 0) break; }
                        $i++;
                    }
                    $partes .= pdf_cadena(substr($s, $ini, $i - $ini));
                    $i++; continue;
                }
                if ($s[$i] === '<') {
                    $fin = strpos($s, '>', $i);
                    if ($fin === false) { $i = $len; break; }
                    $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $fin - $i - 1));
                    if (strlen($hex) % 2) $hex .= '0';
                    $partes .= (string) @hex2bin($hex);
                    $i = $fin + 1; continue;
                }
                // Números de ajuste: un salto grande equivale a un espacio
                if (preg_match('/^-?[\d\.]+/', substr($s, $i, 24), $mm)) {
                    if ((float)$mm[0] <= -120) $partes .= ' ';
                    $i += strlen($mm[0]); continue;
                }
                $i++;
            }
            $i++;
            $ops[] = ['s', $partes];
            continue;
        }

        // Nombre /Algo
        if ($c === '/') {
            $i++;
            while ($i < $len && !preg_match('/[\s\/\[\]\(\)<>]/', $s[$i])) $i++;
            $ops[] = ['n', ''];
            continue;
        }

        // Número
        if (($c >= '0' && $c <= '9') || $c === '-' || $c === '+' || $c === '.') {
            $ini = $i; $i++;
            while ($i < $len && (($s[$i] >= '0' && $s[$i] <= '9') || $s[$i] === '.' || $s[$i] === '-')) $i++;
            $ops[] = ['d', (float) substr($s, $ini, $i - $ini)];
            continue;
        }

        // Operador
        $ini = $i;
        while ($i < $len && !preg_match('/[\s\/\[\]\(\)<>%]/', $s[$i])) $i++;
        $op = substr($s, $ini, $i - $ini);
        if ($op === '') { $i++; continue; }

        $num = function (int $desde) use ($ops) {   // n-ésimo operando desde el final
            $vals = [];
            foreach ($ops as $o) if ($o[0] === 'd') $vals[] = $o[1];
            $c = count($vals);
            return ($c >= $desde) ? $vals[$c - $desde] : 0.0;
        };
        $cadena = function () use ($ops) {
            for ($k = count($ops) - 1; $k >= 0; $k--) if ($ops[$k][0] === 's') return $ops[$k][1];
            return '';
        };

        switch ($op) {
            case 'BT': $x = 0.0; $y = 0.0; break;
            case 'Tm': $x = $num(2); $y = $num(1); break;
            case 'Td': $x += $num(2); $y += $num(1); break;
            case 'TD': $leading = -$num(1); $x += $num(2); $y += $num(1); break;
            case 'TL': $leading = $num(1); break;
            case 'T*': $y -= $leading; break;
            case 'Tj': $poner($cadena()); break;
            case 'TJ': $poner($cadena()); break;
            case "'":  $y -= $leading; $poner($cadena()); break;
            case '"':  $y -= $leading; $poner($cadena()); break;
        }
        $ops = [];
    }

    // De mayor a menor Y (de arriba hacia abajo de la hoja)
    $claves = array_keys($lineas);
    usort($claves, fn($a, $b) => $b <=> $a);

    $salida = [];
    foreach ($claves as $k) {
        $frags = $lineas[$k];
        usort($frags, fn($a, $b) => $a['x'] <=> $b['x']);
        $linea = ''; $ultima = null;
        foreach ($frags as $f) {
            if ($ultima !== null && $f['x'] - $ultima > 1) $linea .= ' ';
            $linea .= $f['t'];
            $ultima = $f['x'] + mb_strlen($f['t']) * 5;   // ancho aproximado
        }
        $linea = trim(preg_replace('/[ \t]+/', ' ', $linea));
        if ($linea !== '') $salida[] = $linea;
    }
    return $salida;
}

/** Texto plano del PDF, un renglón por línea visual. */
function pdf_texto_crudo(string $raw): string {
    $texto = '';
    foreach (pdf_streams($raw) as $datos) {
        // Solo los flujos de CONTENIDO traen texto.
        if (strpos($datos, 'BT') === false) continue;
        if (!preg_match('/(?:^|[\s\]\)>])(Tj|TJ)[\s\r\n]/', $datos)) continue;
        $lineas = pdf_lineas_de_contenido($datos);
        if ($lineas) $texto .= implode("\n", $lineas) . "\n";
    }

    // Si el PDF usa fuentes CID sin ToUnicode, lo extraído es basura.
    // Se descarta antes de que ensucie los datos del gasto.
    $limpio = preg_replace('/\s+/', '', $texto);
    if ($limpio !== '') {
        $legibles = preg_match_all('/[A-Za-z0-9ÁÉÍÓÚÑáéíóúñ\.\,\$\-\/]/u', $limpio);
        if ($legibles / max(1, mb_strlen($limpio)) < 0.6) return '';
    }
    return $texto;
}

// ---------------------------------------------------------------------------
// XML incrustado
// ---------------------------------------------------------------------------

/**
 * Busca un XML de CFDI incrustado como adjunto del PDF.
 * Devuelve el XML crudo o null.
 */
function pdf_xml_incrustado(string $raw): ?string {
    foreach (pdf_streams($raw) as $datos) {
        if (stripos($datos, 'Comprobante') === false) continue;
        if (stripos($datos, '<') === false) continue;
        if (stripos($datos, 'cfdi') === false && stripos($datos, 'sat.gob.mx') === false) continue;

        $ini = stripos($datos, '<?xml');
        if ($ini === false) $ini = stripos($datos, '<cfdi:');
        if ($ini === false) continue;

        $xml = substr($datos, $ini);
        $fin = strripos($xml, '</cfdi:Comprobante>');
        if ($fin !== false) $xml = substr($xml, 0, $fin + strlen('</cfdi:Comprobante>'));
        return $xml;
    }
    return null;
}

// ---------------------------------------------------------------------------
// Lectura de la factura
// ---------------------------------------------------------------------------

/** Convierte "1,699.00" o "$1,699.00" a float. */
function pdf_num(?string $s): float {
    if ($s === null) return 0.0;
    return (float) str_replace([',', '$', ' '], '', trim($s));
}

/**
 * Lee una factura PDF. Devuelve null si el archivo no parece un PDF.
 *
 * El arreglo trae, además de los datos:
 *   origen      'xml_incrustado' | 'texto'
 *   confiable   true solo cuando salió del XML incrustado
 *   faltantes   qué no se pudo leer, para avisarlo en pantalla
 */
function pdf_parse_factura(string $raw): ?array {
    if (strncmp($raw, '%PDF', 4) !== 0) return null;

    // --- Pasada 1: XML incrustado -----------------------------------------
    $xml = pdf_xml_incrustado($raw);
    if ($xml !== null && function_exists('cfdi_parse_raw')) {
        $cfdi = cfdi_parse_raw($xml);
        if ($cfdi) {
            $cfdi['origen']    = 'xml_incrustado';
            $cfdi['confiable'] = true;
            $cfdi['faltantes'] = [];
            $cfdi['xml_crudo'] = $xml;
            return $cfdi;
        }
    }

    // --- Pasada 2: texto del PDF ------------------------------------------
    $texto = pdf_texto_crudo($raw);
    if (trim($texto) === '') {
        return [
            'origen' => 'texto', 'confiable' => false,
            'faltantes' => ['todo'],
            'total'=>0.0, 'subtotal'=>0.0, 'iva'=>0.0, 'fecha'=>'', 'folio'=>'',
            'rfc'=>'', 'nombre'=>'', 'uuid'=>'', 'conceptos'=>[],
            'sin_texto' => true,
        ];
    }
    $plano = preg_replace('/\s+/', ' ', $texto);

    // UUID: patrón fijo, es el dato más seguro de todos
    $uuid = '';
    if (preg_match('/\b([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})\b/', $plano, $m)) {
        $uuid = strtoupper($m[1]);
    }

    // RFC del emisor: se prefiere el que venga etiquetado como emisor
    $rfc = '';
    if (preg_match('/(?:RFC\s*(?:del\s*)?Emisor|Emisor\s*(?:RFC)?)\s*:?\s*([A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3})/i', $plano, $m)) {
        $rfc = strtoupper($m[1]);
    } elseif (preg_match_all('/\b([A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3})\b/', $plano, $m)) {
        $rfc = strtoupper($m[1][0]);   // el primero suele ser el emisor
    }

    // Total: se busca etiquetado; si hay varios, gana el mayor
    $total = 0.0;
    if (preg_match_all('/Total\s*:?\s*\$?\s*([\d,]+\.\d{2})/i', $plano, $m)) {
        foreach ($m[1] as $v) { $n = pdf_num($v); if ($n > $total) $total = $n; }
    }
    $subtotal = 0.0;
    if (preg_match('/Sub\s*-?\s*total\s*:?\s*\$?\s*([\d,]+\.\d{2})/i', $plano, $m)) {
        $subtotal = pdf_num($m[1]);
    }
    $iva = 0.0;
    // Ojo: la etiqueta suele traer el porcentaje ("IVA 16%: $392.00"), así que
    // el número de la tasa se salta a propósito antes de tomar el importe.
    if (preg_match('/(?:IVA|I\.V\.A\.|Impuestos?\s+Trasladados?)\s*(?:\(?\s*\d{1,2}(?:[\.,]\d+)?\s*%\s*\)?)?\s*:?\s*\$?\s*([\d,]+\.\d{2})/i', $plano, $m)) {
        $iva = pdf_num($m[1]);
    }
    if ($total <= 0 && $subtotal > 0)  $total = round($subtotal + $iva, 2);
    if ($iva <= 0 && $subtotal > 0 && $total > $subtotal) $iva = round($total - $subtotal, 2);

    // Fecha ISO del CFDI, o dd/mm/aaaa como respaldo
    $fecha = '';
    if (preg_match('/(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}/', $plano, $m)) {
        $fecha = $m[1];
    } elseif (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $plano, $m)) {
        $fecha = $m[1];
    } elseif (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\b/', $plano, $m)) {
        $fecha = "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    // Serie y folio
    // Cuidado con "Folio Fiscal (UUID)": ese no es el folio de la factura.
    $folio = '';
    if (preg_match_all('/\bFolio\b(?!\s*Fiscal)\s*:?\s*([A-Za-z0-9\-]{1,20})/i', $plano, $m)) {
        foreach ($m[1] as $v) {
            $v = trim($v);
            if (preg_match('/\d/', $v) && !preg_match('/^(fiscal|uuid)$/i', $v)) { $folio = $v; break; }
        }
    }
    if ($folio !== '' && preg_match('/\bSerie\s*:?\s*([A-Z0-9]{1,10})\b/i', $plano, $m2)) {
        $folio = trim($m2[1] . ' ' . $folio);
    }
    $folio = trim($folio);

    // Nombre del emisor: la primera línea con pinta de razón social
    $nombre = '';
    foreach (preg_split('/\n/', $texto) as $linea) {
        $l = trim(preg_replace('/\s+/', ' ', $linea));
        if ($l === '' || mb_strlen($l) < 6) continue;
        if (preg_match('/\b(SA DE CV|S\.A\. DE C\.V\.|SA\b|SC\b|S DE RL|SAPI)/i', $l)) { $nombre = $l; break; }
        if ($nombre === '' && !preg_match('/RFC|Folio|Fecha|Serie|Receptor|Regimen|Régimen|Factura/i', $l)) $nombre = $l;
    }
    $nombre = mb_substr(trim($nombre), 0, 150);

    // --- Renglones --------------------------------------------------------
    // Se intenta el patrón más común: codigo? descripcion cantidad unidad
    // precio importe. Si no cuadra con el total, se devuelven vacíos: un
    // renglón inventado es peor que ninguno.
    $conceptos = [];
    foreach (preg_split('/\n/', $texto) as $linea) {
        $l = trim(preg_replace('/\s+/', ' ', $linea));
        if ($l === '') continue;
        $l = str_replace('$', '', $l);
        if (preg_match('/^(?:([A-Z0-9\-\.]{2,20})\s+)?(.{3,80}?)\s+([\d,]+(?:\.\d+)?)\s+([A-Za-zÁÉÍÓÚÑáéíóúñ\.]{2,12})\s+([\d,]+\.\d{2,4})\s+([\d,]+\.\d{2})$/u', $l, $m)) {
            $desc = trim($m[2]);
            if (preg_match('/^(sub)?total|^iva|^impuesto|^descuento|^cantidad|^concepto|^descripci/i', $desc)) continue;
            $conceptos[] = [
                'codigo'          => trim((string)$m[1]),
                'descripcion'     => $desc,
                'cantidad'        => pdf_num($m[3]),
                'unidad'          => mb_strtoupper(trim($m[4])),
                'precio_unitario' => pdf_num($m[5]),
                'importe'         => pdf_num($m[6]),
            ];
        }
    }
    // Cuadre: si la suma de renglones no da el subtotal, no son de fiar
    if ($conceptos) {
        $suma = 0.0; foreach ($conceptos as $c) $suma += $c['importe'];
        $ref = $subtotal > 0 ? $subtotal : ($total > 0 ? $total : 0);
        if ($ref > 0 && abs($suma - $ref) > max(1.0, $ref * 0.02)) {
            $conceptos = [];   // no cuadran: mejor que los capture la persona
        }
    }

    $faltantes = [];
    if ($uuid  === '')  $faltantes[] = 'UUID';
    if ($rfc   === '')  $faltantes[] = 'RFC';
    if ($total <= 0)    $faltantes[] = 'total';
    if ($fecha === '')  $faltantes[] = 'fecha';
    if (!$conceptos)    $faltantes[] = 'renglones';

    return [
        'origen'    => 'texto',
        'confiable' => false,
        'faltantes' => $faltantes,
        'total'     => $total,
        'subtotal'  => $subtotal,
        'iva'       => $iva,
        'fecha'     => $fecha,
        'folio'     => $folio,
        'rfc'       => $rfc,
        'nombre'    => $nombre,
        'uuid'      => $uuid,
        'conceptos' => $conceptos,
    ];
}
