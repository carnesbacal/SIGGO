<?php
/**
 * config/controles_helpers.php
 * Cierre de mes. Todo lo de presupuesto, topes por partida y traspasos se
 * retiro con la migracion a SIG-GO: este sistema registra gasto, no lo planea.
 * Depende de las funciones db_*() de app.php.
 */

/** ¿El mes (año, mes) está cerrado? */
function mes_cerrado(int $anio, int $mes): bool {
    if ($mes < 1 || $mes > 12) return false;
    return (bool) db_one("SELECT id FROM cierres_mensuales WHERE anio=:a AND mes=:m LIMIT 1", ['a'=>$anio, 'm'=>$mes]);
}

/** ¿La fecha (Y-m-d) cae en un mes cerrado? */
function fecha_en_mes_cerrado(?string $fecha): bool {
    if (!$fecha) return false;
    $ts = strtotime($fecha);
    if (!$ts) return false;
    return mes_cerrado((int)date('Y', $ts), (int)date('n', $ts));
}

/** Lista de meses cerrados de un año => [mes => ['cerrado_en'=>, 'por'=>, 'nota'=>]] */
function meses_cerrados_anio(int $anio): array {
    $out = [];
    foreach (db_all("SELECT c.mes, c.cerrado_en, c.nota, u.nombre_completo AS por
                     FROM cierres_mensuales c LEFT JOIN usuarios u ON c.cerrado_por=u.id
                     WHERE c.anio=:a", ['a'=>$anio]) as $r) {
        $out[(int)$r['mes']] = ['cerrado_en'=>$r['cerrado_en'], 'por'=>$r['por'], 'nota'=>$r['nota']];
    }
    return $out;
}

/** Cierra un mes. Idempotente. */
function cerrar_mes(int $anio, int $mes, ?int $usuario_id = null, ?string $nota = null): bool {
    if ($mes < 1 || $mes > 12 || $anio < 2000) return false;
    if (mes_cerrado($anio, $mes)) return true;
    db_exec("INSERT INTO cierres_mensuales (anio, mes, cerrado_por, nota) VALUES (:a,:m,:u,:n)",
            ['a'=>$anio, 'm'=>$mes, 'u'=>$usuario_id ?: null, 'n'=>$nota ?: null]);
    if (function_exists('registrar_auditoria')) registrar_auditoria('cerrar','cierres_mensuales',$anio*100+$mes,"Cerró $mes/$anio");
    return true;
}

/** Reabre (elimina el cierre de) un mes. */
function reabrir_mes(int $anio, int $mes): bool {
    if (!mes_cerrado($anio, $mes)) return true;
    db_exec("DELETE FROM cierres_mensuales WHERE anio=:a AND mes=:m", ['a'=>$anio, 'm'=>$mes]);
    if (function_exists('registrar_auditoria')) registrar_auditoria('reabrir','cierres_mensuales',$anio*100+$mes,"Reabrió $mes/$anio");
    return true;
}

/** Nombres de meses (1..12). */
function meses_nombres(): array {
    return [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
}

/** Nombres cortos de meses (1..12), para encabezados de tablas. */
function meses_cortos(): array {
    return [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];
}
