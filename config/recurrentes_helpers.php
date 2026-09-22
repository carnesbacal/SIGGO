<?php
/**
 * config/recurrentes_helpers.php
 * Gastos fijos recurrentes (plantillas que generan gastos reales) y
 * pronóstico de cierre de año. Depende de las funciones db_*() de app.php,
 * de generar_folio_gasto() (gastos_helpers).
 */
require_once __DIR__ . '/controles_helpers.php';
require_once __DIR__ . '/gastos_helpers.php';


/** Paso en meses entre ocurrencias según la frecuencia. */
function frecuencia_meses(string $frec): int {
    return [
        'mensual'    => 1,
        'bimestral'  => 2,
        'trimestral' => 3,
        'semestral'  => 6,
        'anual'      => 12,
    ][$frec] ?? 1;
}

/** Etiqueta legible de la frecuencia. */
function frecuencia_label(string $frec): string {
    return [
        'mensual'    => 'Mensual',
        'bimestral'  => 'Bimestral',
        'trimestral' => 'Trimestral',
        'semestral'  => 'Semestral',
        'anual'      => 'Anual',
    ][$frec] ?? ucfirst($frec);
}

/** Lista de frecuencias válidas (para selects y validación). */
function frecuencias_validas(): array {
    return ['mensual','bimestral','trimestral','semestral','anual'];
}

/** Índice año-mes comparable (year*12 + (mes-1)). */
function _ym_idx(int $anio, int $mes): int { return $anio * 12 + ($mes - 1); }

/**
 * ¿La plantilla recurrente aplica (debe generar gasto) en el mes indicado?
 * Considera vigencia (fecha_inicio / fecha_fin, con granularidad de mes) y el
 * paso de la frecuencia contado desde el mes de inicio.
 */
function recurrente_aplica_mes(array $rec, int $anio, int $mes): bool {
    if ($mes < 1 || $mes > 12) return false;
    $ini = strtotime((string)($rec['fecha_inicio'] ?? ''));
    if (!$ini) return false;
    $sidx = _ym_idx((int)date('Y', $ini), (int)date('n', $ini));
    $cidx = _ym_idx($anio, $mes);
    if ($cidx < $sidx) return false;
    if (!empty($rec['fecha_fin'])) {
        $fin = strtotime((string)$rec['fecha_fin']);
        if ($fin) {
            $eidx = _ym_idx((int)date('Y', $fin), (int)date('n', $fin));
            if ($cidx > $eidx) return false;
        }
    }
    $step = frecuencia_meses((string)($rec['frecuencia'] ?? 'mensual'));
    return (($cidx - $sidx) % $step) === 0;
}

/** Ajusta el día del mes al máximo real del mes (p. ej. 31 → 28/30). */
function recurrente_dia_valido(int $dia, int $anio, int $mes): int {
    $dim = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));
    return max(1, min($dia, $dim));
}

/** ¿Ya se generó un gasto de esta plantilla para ese año/mes? */
function recurrente_ya_generado(int $rec_id, int $anio, int $mes): bool {
    if ($rec_id <= 0) return false;
    $r = db_one("SELECT id FROM gastos WHERE recurrente_id=:r AND YEAR(fecha)=:a AND MONTH(fecha)=:m LIMIT 1",
                ['r'=>$rec_id, 'a'=>$anio, 'm'=>$mes]);
    return (bool) $r;
}

/**
 * Genera el gasto de UNA plantilla para (año, mes) si aplica y no existe aún.
 * Devuelve el id del gasto creado, o null si no correspondía / ya existía.
 */
function recurrente_generar_uno(array $rec, int $anio, int $mes, ?int $usuario_id = null): ?int {
    if (!recurrente_aplica_mes($rec, $anio, $mes)) return null;
    $rec_id = (int) $rec['id'];
    if (recurrente_ya_generado($rec_id, $anio, $mes)) return null;

    $dia   = recurrente_dia_valido((int)($rec['dia_mes'] ?? 1), $anio, $mes);
    $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    $area  = (int) $rec['area_id'];
    $folio = generar_folio_gasto($area, $fecha);
    $nota  = trim('Generado automáticamente desde el gasto fijo recurrente. ' . (string)($rec['notas'] ?? ''));

    $params = [
        'folio' => $folio,
        'f'     => $fecha,
        'a'     => $area,
        'c'     => (int) $rec['categoria_id'],
        'rid'   => $rec_id,
        'co'    => (string) $rec['concepto'],
        'm'     => (float) $rec['monto'],
        'prov'  => !empty($rec['proveedor_id']) ? (int)$rec['proveedor_id'] : null,
        'provt' => ($rec['proveedor_texto'] ?? '') !== '' ? $rec['proveedor_texto'] : null,
        'not'   => $nota !== '' ? $nota : null,
        'sc'    => !empty($rec['subcategoria_id']) ? (int)$rec['subcategoria_id'] : null,
        'fp'    => !empty($rec['forma_pago_id']) ? (int)$rec['forma_pago_id'] : null,
        'u'     => $usuario_id ?: null,
    ];
    // Nace PENDIENTE a proposito: un recurrente se genera antes de pagarse, asi
    // que debe aparecer en la lista de por pagar hasta que alguien lo marque.
    db_exec("INSERT INTO gastos (folio,fecha,area_id,categoria_id,subcategoria_id,recurrente_id,tipo,concepto,monto,proveedor_id,proveedor_texto,forma_pago_id,estatus_pago,notas,registrado_por)
             VALUES (:folio,:f,:a,:c,:sc,:rid,'fijo',:co,:m,:prov,:provt,:fp,'pendiente',:not,:u)", $params);
    $gid = db_last_id();
    // Un gasto fijo es un solo renglon: asi el detalle nunca queda vacio
    if (function_exists('gasto_items_guardar')) {
        gasto_items_guardar($gid, [[
            'descripcion' => (string) $rec['concepto'],
            'cantidad' => 1, 'precio_unitario' => (float) $rec['monto'],
            // Un gasto fijo (renta, luz, internet) no es un consumible: se
            // marca para que no aparezca cada mes como pendiente de catalogar.
            'no_es_insumo' => 1,
        ]]);
    }
    return $gid;
}

/**
 * Genera los gastos de TODAS las plantillas activas para (año, mes).
 * Devuelve ['creados'=>int, 'omitidos'=>int, 'programados'=>int].
 */
function generar_gastos_recurrentes(int $anio, int $mes, ?int $usuario_id = null): array {
    if (function_exists('mes_cerrado') && mes_cerrado($anio, $mes)) {
        return ['creados'=>0, 'omitidos'=>0, 'programados'=>0, 'cerrado'=>true];
    }
    $creados = 0; $omitidos = 0; $programados = 0;
    foreach (db_all("SELECT * FROM gastos_recurrentes WHERE activo=1") as $rec) {
        if (!recurrente_aplica_mes($rec, $anio, $mes)) continue;
        $programados++;
        if (recurrente_ya_generado((int)$rec['id'], $anio, $mes)) { $omitidos++; continue; }
        $nid = recurrente_generar_uno($rec, $anio, $mes, $usuario_id);
        if ($nid) { $creados++; }
    }
    return ['creados'=>$creados, 'omitidos'=>$omitidos, 'programados'=>$programados];
}

/**
 * Monto de gastos recurrentes ya comprometidos para el resto del año
 * (meses >= $desde_mes que aplican y aún NO se han generado).
 */
function recurrentes_comprometido_restante(int $anio, ?int $dep_id, int $desde_mes): float {
    $sql = "SELECT * FROM gastos_recurrentes WHERE activo=1";
    $p = [];
    if ($dep_id) { $sql .= " AND area_id=:d"; $p['d'] = $dep_id; }
    $tot = 0.0;
    foreach (db_all($sql, $p) as $rec) {
        for ($m = max(1, $desde_mes); $m <= 12; $m++) {
            if (!recurrente_aplica_mes($rec, $anio, $m)) continue;
            if (recurrente_ya_generado((int)$rec['id'], $anio, $m)) continue;
            $tot += (float) $rec['monto'];
        }
    }
    return $tot;
}

/**
 * Pronóstico de cierre de año: proyecta cómo terminará el gasto anual.
 *   pronóstico = real a la fecha
 *              + comprometido restante (recurrentes programados aún no generados)
 *              + proyección del gasto variable (promedio mensual variable × meses restantes)
 * Devuelve montos y % contra el presupuesto anual.
 */
function pronostico_cierre(int $anio, ?int $dep_id = null): array {
    // 'presupuesto' conserva el nombre por compatibilidad, pero ahora trae
    // el gasto total del anio anterior: es contra eso que se compara.
    $anioActual = (int) date('Y');
    $mesActual  = (int) date('n');
    if ($anio < $anioActual)      { $transcurridos = 12; $mesCorte = 12; }
    elseif ($anio > $anioActual)  { $transcurridos = 0;  $mesCorte = 0;  }
    else                          { $transcurridos = $mesActual; $mesCorte = $mesActual; }
    $restantes = 12 - $mesCorte;

    // Ya no hay presupuesto: el pronostico se compara contra el anio anterior.
    $presup = (float) (db_one("SELECT COALESCE(SUM(monto),0) v FROM gastos WHERE anio=:a AND estatus_pago<>'cancelado'",
                              ['a'=>$anio-1])['v'] ?? 0);

    // Real a la fecha (hasta el mes de corte)
    $sqlR = "SELECT COALESCE(SUM(monto),0) v FROM vista_gasto_area WHERE YEAR(fecha)=:a AND MONTH(fecha)<=:m";
    $pR = ['a'=>$anio, 'm'=>$mesCorte];
    if ($dep_id) { $sqlR .= " AND area_id=:d"; $pR['d'] = $dep_id; }
    $real = (float) (db_one($sqlR, $pR)['v'] ?? 0);

    // Parte planeada ya generada (para separar la variable)
    $sqlRR = "SELECT COALESCE(SUM(monto),0) v FROM vista_gasto_area WHERE YEAR(fecha)=:a AND MONTH(fecha)<=:m AND recurrente_id IS NOT NULL";
    $pRR = ['a'=>$anio, 'm'=>$mesCorte];
    if ($dep_id) { $sqlRR .= " AND area_id=:d"; $pRR['d'] = $dep_id; }
    try { $realRec = (float) (db_one($sqlRR, $pRR)['v'] ?? 0); }
    catch (Throwable $e) {
        // Respaldo por si la vista aún no existe.
        $sqlRR2 = str_replace('recurrente_id IS NOT NULL', 'recurrente_id IS NOT NULL', $sqlRR);
        try { $realRec = (float) (db_one($sqlRR2, $pRR)['v'] ?? 0); } catch (Throwable $e2) { $realRec = 0.0; }
    }
    $realVar = max(0.0, $real - $realRec);

    $promVar = $transcurridos > 0 ? $realVar / $transcurridos : 0.0;
    $proyVar = $promVar * $restantes;

    $comprometido = recurrentes_comprometido_restante($anio, $dep_id, $mesCorte + 1);

    $pronostico = $real + $comprometido + $proyVar;
    $variacion  = $pronostico - $presup;
    $pct        = $presup > 0 ? ($pronostico / $presup) * 100 : 0.0;

    return [
        'anio'                  => $anio,
        'presupuesto'           => $presup,
        'real'                  => $real,
        'real_recurrente'       => $realRec,
        'real_variable'         => $realVar,
        'comprometido'          => $comprometido,
        'proyeccion_variable'   => $proyVar,
        'prom_variable_mensual' => $promVar,
        'pronostico'            => $pronostico,
        'variacion'             => $variacion,
        'pct'                   => $pct,
        'meses_transcurridos'   => $transcurridos,
        'meses_restantes'       => $restantes,
    ];
}

/** Badge HTML del estado del mes actual para una plantilla recurrente. */
function badge_recurrente_estado(array $rec, int $anio, int $mes): string {
    if (!recurrente_aplica_mes($rec, $anio, $mes)) {
        return '<span class="text-xs font-medium px-2 py-0.5 rounded-full bg-zinc-100 text-zinc-500">No aplica este mes</span>';
    }
    if (recurrente_ya_generado((int)$rec['id'], $anio, $mes)) {
        return '<span class="text-xs font-medium px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">Generado</span>';
    }
    return '<span class="text-xs font-medium px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">Pendiente</span>';
}
