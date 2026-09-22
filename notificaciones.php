<?php
/**
 * ============================================================================
 * notificaciones.php - Centro de notificaciones del usuario
 * ============================================================================
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/notificaciones_helpers.php';

requerir_login();
$u = usuario_actual();

// Procesar acciones
if (es_post() && csrf_valido(input('_csrf'))) {
    $accion = (string) input('accion', '');
    if ($accion === 'marcar_todas') {
        marcar_todas_leidas((int) $u['id']);
        flash_set('success', 'Todas las notificaciones marcadas como leídas.');
    } elseif ($accion === 'eliminar_leidas') {
        db_exec("DELETE FROM notificaciones WHERE usuario_id = :uid AND leida = 1", ['uid' => $u['id']]);
        flash_set('success', 'Notificaciones leídas eliminadas.');
    }
    header('Location: ' . url('notificaciones.php'));
    exit;
}

$filtro = (string) input('filtro', 'todas');
$solo_no_leidas = ($filtro === 'no_leidas');
$notificaciones = listar_notificaciones((int) $u['id'], 100, $solo_no_leidas);

$no_leidas = contar_no_leidas((int) $u['id']);
$total_notifs = (int) (db_one("SELECT COUNT(*) c FROM notificaciones WHERE usuario_id = :uid", ['uid' => $u['id']])['c'] ?? 0);

$titulo_pagina = 'Notificaciones';
$pagina_activa = 'notificaciones';
require_once __DIR__ . '/config/header.php';
?>

<div class="max-w-3xl mx-auto animate-fade-in">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="font-display text-2xl font-extrabold text-zinc-900">Notificaciones</h2>
            <p class="text-xs text-zinc-500 mt-0.5">
                <?php if ($no_leidas > 0): ?>
                Tienes <strong class="text-bacal-700"><?= $no_leidas ?></strong> sin leer · <?= $total_notifs ?> total
                <?php else: ?>
                Todo al día · <?= $total_notifs ?> notificaciones en total
                <?php endif; ?>
            </p>
        </div>

        <div class="flex items-center gap-2">
            <?php if ($no_leidas > 0): ?>
            <form method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="accion" value="marcar_todas">
                <button type="submit"
                        class="px-3 py-1.5 rounded-lg border border-zinc-300 bg-white text-sm font-medium text-zinc-700 hover:bg-zinc-50 flex items-center gap-1.5">
                    <i data-lucide="check-check" class="w-4 h-4"></i>
                    Marcar todas
                </button>
            </form>
            <?php endif; ?>

            <?php if ($total_notifs > $no_leidas): ?>
            <form method="POST" onsubmit="return confirm('¿Eliminar todas las notificaciones leídas?');">
                <?= csrf_input() ?>
                <input type="hidden" name="accion" value="eliminar_leidas">
                <button type="submit"
                        class="px-3 py-1.5 rounded-lg border border-zinc-300 bg-white text-sm font-medium text-zinc-500 hover:text-bacal-700 hover:bg-zinc-50 flex items-center gap-1.5">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                    Limpiar
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filtros -->
    <div class="flex gap-1 mb-4 border-b border-zinc-200">
        <a href="<?= url('notificaciones.php') ?>"
           class="px-4 py-2 text-sm font-semibold border-b-2 transition-colors <?= $filtro === 'todas' ? 'border-bacal-700 text-bacal-700' : 'border-transparent text-zinc-500 hover:text-zinc-700' ?>">
            Todas
        </a>
        <a href="<?= url('notificaciones.php?filtro=no_leidas') ?>"
           class="px-4 py-2 text-sm font-semibold border-b-2 transition-colors flex items-center gap-1.5 <?= $filtro === 'no_leidas' ? 'border-bacal-700 text-bacal-700' : 'border-transparent text-zinc-500 hover:text-zinc-700' ?>">
            Sin leer
            <?php if ($no_leidas > 0): ?>
            <span class="bg-bacal-700 text-white text-[10px] font-bold rounded-full px-1.5 py-0.5 min-w-[18px] text-center"><?= $no_leidas ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Lista -->
    <?php if (empty($notificaciones)): ?>
    <div class="bg-white rounded-xl border border-zinc-200 shadow-sm p-12 text-center">
        <div class="w-16 h-16 mx-auto rounded-full bg-zinc-100 flex items-center justify-center mb-3">
            <i data-lucide="bell-off" class="w-8 h-8 text-zinc-400"></i>
        </div>
        <p class="text-sm font-medium text-zinc-700">
            <?= $filtro === 'no_leidas' ? 'Sin notificaciones pendientes' : 'Sin notificaciones aún' ?>
        </p>
        <p class="text-xs text-zinc-500 mt-1">
            Aquí aparecerán las alertas sobre las incidencias que te conciernen.
        </p>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-xl border border-zinc-200 shadow-sm divide-y divide-zinc-100 overflow-hidden">
        <?php foreach ($notificaciones as $n):
            $tipo_cfg = NOTIF_TIPOS[$n['tipo']] ?? NOTIF_TIPOS['sistema'];
            $no_leida = (int) $n['leida'] === 0;
        ?>
        <a href="<?= e($n['url'] ?? '#') ?>"
           class="flex items-start gap-3 px-4 py-3 transition-colors <?= $no_leida ? 'bg-bacal-50/30 hover:bg-bacal-50/60' : 'hover:bg-zinc-50' ?>">
            <!-- Ícono -->
            <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
                 style="background-color: <?= e($tipo_cfg['color']) ?>15">
                <i data-lucide="<?= e($tipo_cfg['icono']) ?>" class="w-4 h-4" style="color: <?= e($tipo_cfg['color']) ?>"></i>
            </div>

            <!-- Contenido -->
            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2 mb-0.5">
                    <h4 class="font-semibold text-sm text-zinc-900 leading-tight"><?= e($n['titulo']) ?></h4>
                    <?php if ($no_leida): ?>
                    <span class="w-2 h-2 rounded-full bg-bacal-700 flex-shrink-0 mt-1.5" title="Sin leer"></span>
                    <?php endif; ?>
                </div>
                <p class="text-xs text-zinc-600 leading-relaxed"><?= e($n['mensaje']) ?></p>
                <div class="text-[10px] text-zinc-400 mt-1.5"><?= e(fmt_tiempo_relativo($n['creado_en'])) ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/config/footer.php'; ?>
