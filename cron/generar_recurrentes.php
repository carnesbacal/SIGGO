<?php
/**
 * cron/generar_recurrentes.php
 * Genera los gastos reales de las plantillas recurrentes del mes en curso.
 *
 * Uso desde el Programador de tareas de Windows o un cron:
 *   php cron/generar_recurrentes.php
 *
 * Es seguro correrlo varias veces: omite los que ya se generaron.
 * No genera nada si el mes ya está cerrado.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/gastos_helpers.php';
require_once __DIR__ . '/../config/recurrentes_helpers.php';

$anio = (int) ($argv[1] ?? date('Y'));
$mes  = (int) ($argv[2] ?? date('n'));

if ($mes < 1 || $mes > 12) {
    fwrite(STDERR, "Mes invalido: $mes\n");
    exit(1);
}

$res = generar_gastos_recurrentes($anio, $mes, null);

if (!empty($res['cerrado'])) {
    echo "El mes $mes/$anio esta cerrado. No se genero nada.\n";
    exit(0);
}

printf(
    "Periodo %02d/%d — programados: %d, creados: %d, omitidos por ya existir: %d\n",
    $mes, $anio, $res['programados'], $res['creados'], $res['omitidos']
);
exit(0);
