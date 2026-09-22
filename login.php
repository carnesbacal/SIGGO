<?php
/**
 * ============================================================================
 * login.php - Página de inicio de sesión
 * ============================================================================
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/tema.php';

// Nunca cachear el login: si el navegador muestra una versión guardada (botón
// Atrás, marcador, restaurar pestañas), traería un token viejo que ya no
// coincide con la sesión y saldría "Token de seguridad inválido".
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Si ya está logueado, ir al dashboard
if (esta_logueado()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$error = null;
$usuario_previo = '';
$redir = $_GET['redir'] ?? 'dashboard.php';

if (es_post()) {
    // Acepta el login si el token CSRF es válido O si la petición viene del
    // mismo sitio (respaldo cuando la sesión se pierde entre el GET y el POST).
    if (!csrf_valido(input('_csrf')) && !origen_mismo_sitio()) {
        $error = 'Tu sesión de seguridad expiró. Vuelve a intentar (la página ya se actualizó).';
    } else {
        $usuario_previo = trim((string) input('usuario', ''));
        $password = (string) input('password', '');

        [$ok, $msg, $debe_cambiar] = login($usuario_previo, $password);

        if ($ok) {
            if ($debe_cambiar) {
                header('Location: ' . url('cambiar_password.php'));
            } else {
                // Permitir redirección segura solo dentro del sitio
                $destino = (str_starts_with($redir, '/') || str_contains($redir, '://')) ? 'dashboard.php' : $redir;
                header('Location: ' . url($destino));
            }
            exit;
        }
        $error = $msg;
    }
}
?><!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Iniciar sesión · <?= e(APP_NAME) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="favicon.png?v=2">
    <link rel="shortcut icon" href="favicon.ico?v=2">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png?v=2">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700;12..96,800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="<?= url('assets/js/lucide.min.js') ?>?v=0.511.0"></script>
    <?php tema_tailwind(false); ?>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .font-display { font-family: 'Bricolage Grotesque', sans-serif; letter-spacing: -0.02em; }

        /* Fondo decorativo del panel izquierdo */
        .brand-panel {
            background:
                radial-gradient(circle at 20% 20%, rgba(245,228,24,0.22) 0%, transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(255,255,255,0.10) 0%, transparent 50%),
                linear-gradient(135deg, <?= tono(900) ?> 0%, <?= tono(700) ?> 55%, <?= tono(500) ?> 100%);
        }

        /* Patrón sutil de textura */
        .grain {
            position: absolute;
            inset: 0;
            opacity: 0.08;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            pointer-events: none;
        }

        /* Bordes decorativos */
        .corner-mark {
            position: absolute;
            width: 30px;
            height: 30px;
            border: 2px solid rgba(245,228,24,0.45);
        }
        .corner-mark.tl { top: 30px; left: 30px; border-right: none; border-bottom: none; }
        .corner-mark.tr { top: 30px; right: 30px; border-left: none; border-bottom: none; }
        .corner-mark.bl { bottom: 30px; left: 30px; border-right: none; border-top: none; }
        .corner-mark.br { bottom: 30px; right: 30px; border-left: none; border-top: none; }

        /* Input focus elegante */
        .input-form {
            transition: all 0.15s ease;
        }
        .input-form:focus {
            border-color: <?= tono(600) ?>;
            box-shadow: 0 0 0 3px rgba(124,58,237,0.12);
        }

        .btn-primary {
            background: linear-gradient(135deg, <?= tono(600) ?> 0%, <?= tono(800) ?> 100%);
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, <?= tono(700) ?> 0%, <?= tono(900) ?> 100%);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px -8px rgba(76,29,149,0.6);
        }
        .btn-primary:active { transform: translateY(0); }

        @keyframes shake {
            0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}
        }
        .shake { animation: shake 0.3s; }
    </style>
</head>
<body class="h-full bg-zinc-50">

<div class="min-h-screen flex">

    <!-- ===================================================== -->
    <!-- Panel izquierdo: marca -->
    <!-- ===================================================== -->
    <div class="hidden lg:flex lg:w-1/2 brand-panel relative overflow-hidden">
        <div class="grain"></div>
        <div class="corner-mark tl"></div>
        <div class="corner-mark tr"></div>
        <div class="corner-mark bl"></div>
        <div class="corner-mark br"></div>

        <div class="relative z-10 flex flex-col p-12 w-full text-white">

            <!-- Logo arriba, centrado sobre el rotulo -->
            <div class="self-start text-center">
                <img src="assets/img/logo-grano-blanco.png" alt="<?= e(EMPRESA_NOMBRE) ?>" onerror="this.style.display='none'" class="h-16 w-auto mx-auto">
                <div class="text-[11px] uppercase tracking-widest font-semibold mt-2" style="color:<?= ORO ?>"><?= e(SUCURSAL_NOMBRE) ?> · Sistema Interno</div>
            </div>

            <!-- Tagline central -->
            <div class="flex-1 flex flex-col justify-center items-center text-center">
                <div class="w-full max-w-lg space-y-5">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 backdrop-blur-sm border border-white/20 text-xs font-semibold uppercase tracking-wider">
                        <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background:<?= ORO ?>"></span>
                        Gestión de Gastos de Operación
                    </div>
                    <h2 class="font-display text-7xl xl:text-8xl font-extrabold leading-[0.9] tracking-tight flex items-center justify-center gap-3">
                        <span class="text-white">SIG</span>
                        <span class="inline-flex items-center gap-2 rounded-2xl px-4 py-1"
                              style="background:<?= ORO ?>;color:<?= ORO_DARK ?>">GO<?= marca_flecha(ORO_DARK, '0.62em') ?></span>
                    </h2>
                    <p class="font-display text-2xl xl:text-3xl font-bold leading-tight">
                        Todo lo que gasta la tienda, en un solo lugar.
                    </p>
                    <p class="text-white/90 text-base leading-snug font-semibold">
                        <span style="color:<?= ORO ?>">S</span>istema <span style="color:<?= ORO ?>">I</span>ntegral de <span style="color:<?= ORO ?>">G</span>estión de <span style="color:<?= ORO ?>">G</span>astos de <span style="color:<?= ORO ?>">O</span>peración.
                    </p>
                    <p class="text-white/65 text-sm leading-relaxed">
                        Control de gastos de operación por área: captura con detalle por producto,
                        comprobante y forma de pago, más el histórico anual de lo gastado.
                    </p>
                    <div class="flex flex-wrap gap-2 justify-center pt-1">
                        <?php foreach (['Gastos', 'Histórico', 'Áreas', 'Categorías', 'Proveedores', 'Reportes'] as $chip): ?>
                        <span class="px-2.5 py-1 rounded-full bg-white/10 border border-white/15 text-[11px] font-semibold text-white/85"><?= $chip ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Footer marca -->
            <div class="flex items-center justify-between text-xs text-white/50">
                <div>© <?= date('Y') ?> <?= e(EMPRESA_NOMBRE) ?>. Uso interno.</div>
                <div class="flex items-center gap-4">
                    <span>Desarrollado por <span class="font-mono font-semibold text-white/75">&lt;LFRC/&gt;</span></span>
                    <span class="font-mono">v<?= APP_VERSION ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================================================== -->
    <!-- Panel derecho: formulario -->
    <!-- ===================================================== -->
    <div class="flex-1 flex items-center justify-center p-6 lg:p-12">
        <div class="w-full max-w-md">

            <!-- Logo móvil -->
            <div class="lg:hidden flex items-center gap-3 mb-10">
                <img src="assets/img/logo-grano.png" alt="<?= e(EMPRESA_NOMBRE) ?>" onerror="this.style.display='none'" class="h-14 w-auto flex-shrink-0">
                <div>
                    <?= marca_wordmark('text-xl') ?>
                    <div class="text-[11px] text-zinc-500 uppercase tracking-widest font-semibold mt-0.5"><?= e(SUCURSAL_NOMBRE) ?></div>
                </div>
            </div>

            <!-- Encabezado -->
            <div class="mb-8">
                <h1 class="font-display text-3xl font-extrabold text-zinc-900 mb-2">Iniciar sesión</h1>
                <p class="text-zinc-500 text-sm">Ingresa tus credenciales para continuar.</p>
            </div>

            <!-- Mensaje de error -->
            <?php if ($error): ?>
            <div class="mb-5 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800 text-sm flex items-start gap-2.5 shake">
                <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
                <div><?= e($error) ?></div>
            </div>
            <?php endif; ?>

            <!-- Formulario -->
            <form method="POST" class="space-y-4" autocomplete="off">
                <?= csrf_input() ?>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 mb-1.5 uppercase tracking-wide">Usuario</label>
                    <div class="relative">
                        <i data-lucide="user" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400"></i>
                        <input type="text" name="usuario" required autofocus
                               value="<?= e($usuario_previo) ?>"
                               class="input-form w-full pl-10 pr-3 py-2.5 rounded-lg border border-zinc-300 bg-white text-sm focus:outline-none"
                               placeholder="Tu nombre de usuario">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 mb-1.5 uppercase tracking-wide">Contraseña</label>
                    <div class="relative" x-data="{ mostrar: false }">
                        <i data-lucide="lock" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400"></i>
                        <input :type="mostrar ? 'text' : 'password'" name="password" required
                               class="input-form w-full pl-10 pr-10 py-2.5 rounded-lg border border-zinc-300 bg-white text-sm focus:outline-none"
                               placeholder="••••••••">
                        <button type="button" @click="mostrar = !mostrar"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-600">
                            <i data-lucide="eye" class="w-4 h-4" x-show="!mostrar"></i>
                            <i data-lucide="eye-off" class="w-4 h-4" x-show="mostrar" style="display:none"></i>
                        </button>
                    </div>
                </div>

                <button type="submit"
                        class="btn-primary w-full text-white font-semibold text-sm py-3 rounded-lg shadow-md mt-2 flex items-center justify-center gap-2">
                    <span>Iniciar sesión</span>
                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
            </form>

            <!-- Pie -->
            <div class="mt-8 pt-6 border-t border-zinc-200 text-center">
                <div class="text-xs text-zinc-500">
                    ¿Problemas para acceder? Contacta a Sistemas de <?= e(EMPRESA_NOMBRE) ?>: lfrodriguez@granodeoro.com.mx.
                </div>
            </div>
        </div>
    </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
