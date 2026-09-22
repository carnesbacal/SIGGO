<?php
/**
 * config/xlsx_writer.php
 * Escritor XLSX minimal sin dependencias externas.
 * Usa ZipArchive (incluido en PHP/XAMPP) para generar archivos .xlsx válidos.
 *
 * Uso básico:
 *   $xlsx = new XlsxWriter();
 *   $xlsx->addSheet('Hoja 1');
 *   $xlsx->addRow(['Columna A', 'Columna B']);
 *   $xlsx->addRow(['dato 1', 123.45]);
 *   $xlsx->download('archivo.xlsx');
 */
class XlsxWriter
{
    private array $sheets     = [];
    private int   $sheetIndex = 0;
    private array $sharedStrings = [];
    private array $ssMap       = [];

    // Estilos predefinidos (índices fijos)
    // 0 = normal, 1 = header (negrita, fondo gris), 2 = número, 3 = moneda, 4 = fecha, 5 = header_dark
    private const STYLE_NORMAL      = 0;
    private const STYLE_HEADER      = 1;
    private const STYLE_NUMBER      = 2;  // entero #,##0 (km, conteos)
    private const STYLE_CURRENCY    = 3;  // moneda "$"#,##0.00 (número real, sumable)
    private const STYLE_DATE        = 4;
    private const STYLE_HEADER_DARK = 5;
    private const STYLE_DEC1        = 6;  // 1 decimal #,##0.0 (litros)
    private const STYLE_DEC2        = 7;  // 2 decimales #,##0.00 (km/L, razones)
    private const STYLE_PCT         = 8;  // porcentaje 0.0"%" (número real)

    public function addSheet(string $name): void
    {
        $this->sheets[] = [
            'name'   => $name,
            'rows'   => [],
            'fitW'   => 1,            // ancho: ajustar a 1 página (no corta columnas al imprimir)
            'fitH'   => 0,            // alto: 0 = fluye a las páginas necesarias
            'orient' => 'landscape',  // horizontal por defecto
        ];
        $this->sheetIndex = count($this->sheets) - 1;
    }

    /**
     * Configura la impresión de la hoja activa.
     * $fitW/$fitH = número de páginas a las que ajustar (1 = una página; 0 = sin límite en ese eje).
     * Para "todo en una hoja" usa setPageSetup(1, 1).
     */
    public function setPageSetup(int $fitW = 1, int $fitH = 1, string $orient = 'landscape'): void
    {
        if (empty($this->sheets)) $this->addSheet('Hoja 1');
        $this->sheets[$this->sheetIndex]['fitW']   = max(0, $fitW);
        $this->sheets[$this->sheetIndex]['fitH']   = max(0, $fitH);
        $this->sheets[$this->sheetIndex]['orient'] = in_array($orient, ['portrait', 'landscape'], true) ? $orient : 'landscape';
    }

    /**
     * Agrega una fila a la hoja activa.
     * $row = array de valores: string, int, float, o ['v'=>value,'s'=>style_index]
     * $style = estilo global de la fila (0=normal,1=header,5=header_dark)
     */
    public function addRow(array $row, int $rowStyle = 0): void
    {
        if (empty($this->sheets)) $this->addSheet('Hoja 1');
        $this->sheets[$this->sheetIndex]['rows'][] = ['cells' => $row, 'style' => $rowStyle];
    }

    /** Fila vacía separadora */
    public function addBlankRow(): void
    {
        $this->addRow([]);
    }

    /** Fila de encabezado (negrita, fondo gris) */
    public function addHeaderRow(array $row, bool $dark = false): void
    {
        $this->addRow($row, $dark ? self::STYLE_HEADER_DARK : self::STYLE_HEADER);
    }

    // -------------------------------------------------------------------------
    // Salida
    // -------------------------------------------------------------------------

    public function download(string $filename): void
    {
        $data = $this->build();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: max-age=0');
        echo $data;
        exit;
    }

    public function save(string $path): void
    {
        file_put_contents($path, $this->build());
    }

    // -------------------------------------------------------------------------
    // Construcción interna
    // -------------------------------------------------------------------------

    private function build(): string
    {
        // Reiniciar shared strings
        $this->sharedStrings = [];
        $this->ssMap         = [];

        // Pre-procesar para recolectar shared strings
        foreach ($this->sheets as &$sheet) {
            foreach ($sheet['rows'] as &$row) {
                foreach ($row['cells'] as &$cell) {
                    $val = is_array($cell) ? $cell['v'] : $cell;
                    if (is_string($val) && $val !== '') {
                        $idx = $this->addSharedString($val);
                        if (is_array($cell)) $cell['ss'] = $idx;
                        else $cell = ['v' => $val, 'ss' => $idx];
                    }
                }
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',      $this->contentTypes());
        $zip->addFromString('_rels/.rels',              $this->rootRels());
        $zip->addFromString('xl/workbook.xml',          $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml',            $this->styles());
        $zip->addFromString('xl/sharedStrings.xml',     $this->sharedStringsXml());

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", $this->sheetXml($sheet));
        }

        $zip->close();
        $data = file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    private function addSharedString(string $s): int
    {
        if (isset($this->ssMap[$s])) return $this->ssMap[$s];
        $idx = count($this->sharedStrings);
        $this->sharedStrings[] = $s;
        $this->ssMap[$s] = $idx;
        return $idx;
    }

    private function colLetter(int $col): string
    {
        $letter = '';
        $col++;
        while ($col > 0) {
            $col--;
            $letter = chr(65 + ($col % 26)) . $letter;
            $col    = intdiv($col, 26);
        }
        return $letter;
    }

    private function sheetXml(array $sheet): string
    {
        // Calcular anchos de columna (máx de cada columna)
        $colWidths = [];
        foreach ($sheet['rows'] as $row) {
            foreach ($row['cells'] as $ci => $cell) {
                $val = is_array($cell) ? $cell['v'] : $cell;
                $len = mb_strlen((string)$val);
                $colWidths[$ci] = max($colWidths[$ci] ?? 8, min($len + 2, 60));
            }
        }

        $colsXml = '<cols>';
        foreach ($colWidths as $ci => $w) {
            $n = $ci + 1;
            $colsXml .= "<col min=\"{$n}\" max=\"{$n}\" width=\"{$w}\" customWidth=\"1\"/>";
        }
        $colsXml .= '</cols>';

        $rowsXml = '';
        foreach ($sheet['rows'] as $ri => $row) {
            $rn  = $ri + 1;
            $ht  = ($row['style'] > 0) ? ' ht="18" customHeight="1"' : '';
            $rowsXml .= "<row r=\"{$rn}\"{$ht}>";
            foreach ($row['cells'] as $ci => $cell) {
                $col    = $this->colLetter($ci);
                $ref    = "{$col}{$rn}";
                $val    = is_array($cell) ? ($cell['v'] ?? '') : $cell;
                $style  = $row['style'];
                if (is_array($cell) && isset($cell['s'])) $style = $cell['s'];

                if (is_array($cell) && isset($cell['ss'])) {
                    // Shared string
                    $rowsXml .= "<c r=\"{$ref}\" t=\"s\" s=\"{$style}\"><v>{$cell['ss']}</v></c>";
                } elseif (is_float($val) || (is_numeric($val) && !is_string($val) && $val !== '')) {
                    $rowsXml .= "<c r=\"{$ref}\" s=\"{$style}\"><v>" . (float)$val . "</v></c>";
                } elseif (is_int($val)) {
                    $rowsXml .= "<c r=\"{$ref}\" s=\"{$style}\"><v>{$val}</v></c>";
                } else {
                    // Vacío
                    $rowsXml .= "<c r=\"{$ref}\" s=\"{$style}\"/>";
                }
            }
            $rowsXml .= '</row>';
        }

        $fitW = (int) ($sheet['fitW'] ?? 1);
        $fitH = (int) ($sheet['fitH'] ?? 0);
        $orient = ($sheet['orient'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        $sheetPr = ($fitW || $fitH) ? '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' : '';
        $pageXml = '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup orientation="' . $orient . '" fitToWidth="' . $fitW . '" fitToHeight="' . $fitH . '"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $sheetPr
            . $colsXml
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . $pageXml
            . '</worksheet>';
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $_) {
            $sheets .= "<Override PartName=\"/xl/worksheets/sheet{$i}.xml\" "
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml"  ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . $sheets
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $sheetsXml = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $name = htmlspecialchars($sheet['name'], ENT_XML1);
            $sheetsXml .= "<sheet name=\"{$name}\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets>'
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $_) {
            $n = $i + 1;
            $rels .= "<Relationship Id=\"rId{$n}\" "
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . "Target=\"worksheets/sheet{$i}.xml\"/>";
        }
        $rels .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
            . 'Target="styles.xml"/>';
        $rels .= '<Relationship Id="rId' . (count($this->sheets) + 2) . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" '
            . 'Target="sharedStrings.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private function styles(): string
    {
        // Estilos:
        // xf 0 = normal
        // xf 1 = header (bold, bg gris claro)
        // xf 2 = número entero
        // xf 3 = moneda 2 dec
        // xf 4 = fecha
        // xf 5 = header dark (bold, bg oscuro, texto blanco)
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="5">'
            . '<numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="DD/MM/YYYY"/>'
            . '<numFmt numFmtId="166" formatCode="#,##0.0"/>'
            . '<numFmt numFmtId="167" formatCode="#,##0.00"/>'
            . '<numFmt numFmtId="168" formatCode="0.0&quot;%&quot;"/>'
            . '</numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF374151"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color auto="1"/></left>'
            . '<right style="thin"><color auto="1"/></right>'
            . '<top style="thin"><color auto="1"/></top>'
            . '<bottom style="thin"><color auto="1"/></bottom>'
            . '<diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            // 0: normal
            . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            // 1: header gris
            . '<xf numFmtId="0"   fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            // 2: número entero
            . '<xf numFmtId="3"   fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1"/>'
            // 3: moneda ("$"#,##0.00)
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1"/>'
            // 4: fecha
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1"/>'
            // 5: header dark
            . '<xf numFmtId="0"   fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            // 6: 1 decimal (#,##0.0)
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1"/>'
            // 7: 2 decimales (#,##0.00)
            . '<xf numFmtId="167" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1"/>'
            // 8: porcentaje (0.0"%")
            . '<xf numFmtId="168" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private function sharedStringsXml(): string
    {
        $count = count($this->sharedStrings);
        $xml   = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>"
            . "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"{$count}\" uniqueCount=\"{$count}\">";
        foreach ($this->sharedStrings as $s) {
            $xml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }
}
