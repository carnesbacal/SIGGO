<?php
/**
 * ============================================================================
 * cambiar_password.php - Cambio de contraseña del usuario actual
 * ============================================================================
 * Se invoca obligatoriamente al primer login (debe_cambiar_password = 1),
 * y también puede usarse voluntariamente desde el menú de usuario.
 * ============================================================================
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';

requerir_login();

$u = usuario_actual();
$forzado = (bool) $u['debe_cambiar_password'];
$error = null;
$exito = null;

if (es_post()) {
    if (!csrf_valido(input('_csrf'))) {
        $error = 'Token de seguridad inválido. Recarga la página.';
    } else {
        $actual = (string) input('password_actual', '');
        $nuevo  = (string) input('password_nuevo', '');
        $conf   = (string) input('password_conf', '');

        if ($nuevo !== $conf) {
            $error = 'La nueva contraseña y la confirmación no coinciden.';
        } else {
            [$ok, $msg] = cambiar_password($actual, $nuevo);
            if ($ok) {
                flash_set('success', 'Tu contraseña se actualizó correctamente.');
                header('Location: ' . url('dashboard.php'));
                exit;
            }
            $error = $msg;
        }
    }
}
?><!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <title>Cambiar contraseña · <?= e(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = { theme: { extend: {
            fontFamily: { sans: ['Inter','sans-serif'], display: ['"Bricolage Grotesque"','sans-serif'] },
            colors: { bacal: { 50:'#FEF2F2',100:'#FEE2E2',200:'#FECACA',600:'#DC2626',700:'#C8102E',800:'#991B1B' } }
        }}}
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .font-display { font-family: 'Bricolage Grotesque', sans-serif; letter-spacing: -0.02em; }
        .input-form:focus { border-color: #C8102E; box-shadow: 0 0 0 3px rgba(200,16,46,0.10); outline: none; }
        .btn-primary { background: linear-gradient(135deg, #C8102E 0%, #991B1B 100%); transition: all 0.2s; }
        .btn-primary:hover { box-shadow: 0 8px 20px -8px rgba(200,16,46,0.6); transform: translateY(-1px); }
    </style>
</head>
<body class="h-full bg-zinc-100">

<div class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-md">

        <!-- Logo -->
        <div class="flex items-center justify-center gap-3 mb-8">
            <div class="w-11 h-11 rounded-xl bg-bacal-700 flex items-center justify-center text-white font-display font-extrabold text-xl">
                B
            </div>
            <div>
                <div class="font-display font-bold text-lg text-zinc-900"><?= EMPRESA_NOMBRE ?></div>
                <div class="text-[10px] text-bacal-700 uppercase tracking-widest font-semibold">Sistema Interno</div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl border border-zinc-200 p-8">

            <?php if ($forzado): ?>
            <div class="mb-5 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-start gap-2.5">
                <i data-lucide="key-round" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                <div>
                    <div class="font-semibold mb-0.5">Primer inicio de sesión</div>
                    <div class="text-amber-700">Por seguridad debes establecer una nueva contraseña antes de continuar.</div>
                </div>
            </div>
            <?php endif; ?>

            <h1 class="font-display text-2xl font-extrabold text-zinc-900 mb-1">Cambiar contraseña</h1>
            <p class="text-zinc-500 text-sm mb-6">
                Hola, <span class="font-semibold text-zinc-700"><?= e($u['nombre']) ?></span>.
                <?= $forzado ? 'Define tu nueva contraseña personal.' : '' ?>
            </p>

            <?php if ($error): ?>
            <div class="mb-4 px-4 py-3 rounded-lg bg-bacal-50 border border-bacal-200 text-bacal-800 text-sm flex items-start gap-2.5">
                <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                <div><?= e($error) ?></div>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4" autocomplete="off">
                <?= csrf_input() ?>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 mb-1.5 uppercase tracking-wide">Contraseña actual</label>
                    <input type="password" name="password_actual" required
                           class="input-form w-full px-3 py-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 mb-1.5 uppercase tracking-wide">Nueva contraseña</label>
                    <input type="password" name="password_nuevo" required minlength="8"
                           class="input-form w-full px-3 py-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
                    <div class="text-[11px] text-zinc-500 mt-1.5">Mínimo 8 caracteres. Recomendado: mezcla letras, números y símbolos.</div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 mb-1.5 uppercase tracking-wide">Confirmar nueva contraseña</label>
                    <input type="password" name="password_conf" required minlength="8"
                           class="input-form w-full px-3 py-2.5 rounded-lg border border-zinc-300 bg-white text-sm">
                </div>

                <div class="flex gap-2 pt-2">
                    <?php if (!$forzado): ?>
                    <a href="<?= url('dashboard.php') ?>"
                       class="flex-1 text-center py-2.5 rounded-lg border border-zinc-300 text-zinc-700 font-medium text-sm hover:bg-zinc-50">
                        Cancelar
                    </a>
                    <?php endif; ?>
                    <button type="submit"
                            class="btn-primary <?= $forzado ? 'w-full' : 'flex-1' ?> text-white font-semibold text-sm py-2.5 rounded-lg shadow-md">
                        Guardar contraseña
                    </button>
                </div>
            </form>
        </div>

        <?php if (!$forzado): ?>
        <div class="text-center mt-4">
            <a href="<?= url('dashboard.php') ?>" class="text-sm text-zinc-500 hover:text-zinc-700">← Volver al dashboard</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>lucide.createIcons();</script>
</body>
</html>
