<?php
/**
 * config/header.php - Layout superior de SIG-GO
 * Variables esperadas: $titulo_pagina (string), $pagina_activa (string)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/tema.php';

requerir_login();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$u = usuario_actual();
$titulo_pagina = $titulo_pagina ?? 'Inicio';
$pagina_activa = $pagina_activa ?? '';
$mensajes_flash = flash_get();
$es_admin = function_exists('tiene_permiso') ? tiene_permiso('administrar') : true;

$notif_count = 0;
try {
    $row = db_one("SELECT COUNT(*) c FROM notificaciones WHERE usuario_id = :uid AND leida = 0", ['uid' => $u['id']]);
    $notif_count = (int) ($row['c'] ?? 0);
} catch (Throwable $e) { $notif_count = 0; }

// Renglones capturados como "Otro" que esperan que un admin los clasifique
$insumos_pend = 0;
if ($es_admin) {
    try {
        $row = db_one("SELECT COUNT(*) c FROM gasto_items WHERE insumo_id IS NULL AND no_es_insumo = 0 AND TRIM(descripcion) <> ''");
        $insumos_pend = (int) ($row['c'] ?? 0);
    } catch (Throwable $e) { $insumos_pend = 0; }
}

/** Helper local: imprime un item del menu lateral */
function nav_link(string $ruta, string $icono, string $texto, string $clave, string $activa, int $pendientes = 0): void {
    $on = ($activa === $clave);
    $cls = $on ? 'nav-item-active' : 'text-zinc-700';
    $badge = $pendientes > 0
        ? '<span x-show="sidebarAbierto" x-transition.opacity class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full" '
          . 'style="background:' . EST_WARN . '1f;color:' . EST_WARN . '">' . ($pendientes > 99 ? '99+' : $pendientes) . '</span>'
        : '';
    echo '<a href="' . url($ruta) . '" class="nav-item ' . $cls . ' flex items-center gap-3 px-4 py-2.5 text-sm font-medium">'
       . '<i data-lucide="' . $icono . '" class="w-5 h-5 flex-shrink-0 ' . ($on ? '' : 'text-zinc-500') . '"></i>'
       . '<span x-show="sidebarAbierto" x-transition.opacity>' . e($texto) . '</span>' . $badge . '</a>';
}
?><!DOCTYPE html>
<html lang="es" class="h-full"<?php $__e=(int)($u['escala_interfaz']??100); if(!in_array($__e,[90,100,110,125],true))$__e=100; if($__e!==100) echo ' style="font-size:'.$__e.'%"'; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titulo_pagina) ?> · <?= e(APP_NAME) ?></title>

    <link rel="icon" type="image/png" href="<?= url('favicon.png') ?>?v=2">
    <link rel="apple-touch-icon" href="<?= url('assets/img/apple-touch-icon.png') ?>?v=2">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,500;12..96,600;12..96,700;12..96,800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="<?= url('assets/js/lucide.min.js') ?>?v=0.511.0"></script>

    <script>
        (function() {
            const pref = localStorage.getItem('tema_preferido') || '<?= e($u['tema_preferido'] ?? 'auto') ?>';
            const sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (pref === 'oscuro' || (pref === 'auto' && sysDark)) document.documentElement.classList.add('dark');
        })();
        function layoutSIGGO() {
            const mq = () => window.matchMedia('(max-width:1023px)').matches;
            return {
                esMobile: mq(),
                sidebarAbierto: !mq(),
                menu: false,
                tema: (localStorage.getItem('tema_preferido') || '<?= e($u['tema_preferido'] ?? 'auto') ?>'),
                init() {
                    window.addEventListener('resize', () => {
                        this.esMobile = mq();
                        if (!this.esMobile) this.sidebarAbierto = true;
                    });
                },
                aplicarTema(t) {
                    this.tema = t;
                    try { localStorage.setItem('tema_preferido', t); } catch (e) {}
                    const dark = t === 'oscuro' || (t === 'auto' && window.matchMedia('(prefers-color-scheme:dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                    if (window.lucide) lucide.createIcons();
                }
            };
        }
    </script>
    <?php tema_tailwind(); ?>

    <style>
        body { font-family: 'Inter', sans-serif; }
        .font-display { font-family: 'Bricolage Grotesque', sans-serif; letter-spacing: -0.02em; }
        :root { --texto-tenue: #52525b; }
        .text-zinc-400 { color: var(--texto-tenue) !important; }

        html.dark { color-scheme: dark; }
        html.dark body { background-color: #09090b; color: #e4e4e7; }
        html.dark .bg-white { background-color: #18181b !important; }
        html.dark .bg-zinc-50 { background-color: #1f1f23 !important; }
        html.dark .bg-zinc-100 { background-color: #27272a !important; }
        html.dark .bg-zinc-200 { background-color: #3f3f46 !important; }
        html.dark .hover\:bg-zinc-50:hover { background-color: #27272a !important; }
        html.dark .hover\:bg-zinc-100:hover { background-color: #3f3f46 !important; }
        html.dark .hover\:bg-white:hover { background-color: #27272a !important; }
        html.dark .border-zinc-100 { border-color: #27272a !important; }
        html.dark .border-zinc-200 { border-color: #3f3f46 !important; }
        html.dark .border-zinc-300 { border-color: #52525b !important; }
        html.dark .text-zinc-900 { color: #f4f4f5 !important; }
        html.dark .text-zinc-800 { color: #e4e4e7 !important; }
        html.dark .text-zinc-700 { color: #d4d4d8 !important; }
        html.dark .text-zinc-600 { color: #a1a1aa !important; }
        html.dark .text-zinc-500 { color: #71717a !important; }
        html.dark .text-zinc-400 { color: #52525b !important; }
        html.dark input, html.dark textarea, html.dark select { background-color:#27272a !important; border-color:#52525b !important; color:#f4f4f5 !important; }
        html.dark input::placeholder, html.dark textarea::placeholder { color:#71717a !important; }
        html.dark input:focus, html.dark textarea:focus, html.dark select:focus { border-color:<?= tono(600) ?> !important; }
        html.dark .bg-marca-50, html.dark .bg-bacal-50, html.dark .bg-rosa-50 { background-color: rgba(124,58,237,0.14) !important; }
        html.dark .bg-marca-100, html.dark .bg-bacal-100, html.dark .bg-rosa-100 { background-color: rgba(124,58,237,0.20) !important; }
        html.dark .border-marca-200, html.dark .border-bacal-200, html.dark .border-rosa-200 { border-color: rgba(124,58,237,0.38) !important; }
        html.dark .text-marca-700, html.dark .text-bacal-700, html.dark .text-rosa-700 { color: <?= tono(300) ?> !important; }
        html.dark .text-marca-800, html.dark .text-bacal-800, html.dark .text-rosa-800 { color: <?= tono(200) ?> !important; }
        html.dark .bg-emerald-50 { background-color: rgba(5,150,105,0.12) !important; }
        html.dark .bg-amber-50, html.dark .bg-orange-50 { background-color: rgba(194,65,12,0.12) !important; }
        html.dark .bg-blue-50 { background-color: rgba(59,130,246,0.1) !important; }
        html.dark .shadow-sm { box-shadow: 0 1px 2px 0 rgba(0,0,0,0.3) !important; }
        html.dark .shadow { box-shadow: 0 1px 3px 0 rgba(0,0,0,0.4) !important; }
        html.dark .shadow-md { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.4) !important; }
        html.dark .shadow-lg { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5) !important; }
        html.dark ::-webkit-scrollbar { background:#18181b; }
        html.dark ::-webkit-scrollbar-thumb { background:#3f3f46; border-radius:6px; }

        [x-cloak] { display: none !important; }
        ::-webkit-scrollbar { width:8px; height:8px; }
        ::-webkit-scrollbar-track { background:transparent; }
        ::-webkit-scrollbar-thumb { background:<?= tono(200) ?>; border-radius:4px; }
        ::-webkit-scrollbar-thumb:hover { background:<?= tono(600) ?>; }

        /* El menu activo lleva violeta de fondo y la barrita dorada: el unico
           lugar de la interfaz donde el oro hace de senalador. */
        .nav-item { border-left:3px solid transparent; transition: all .15s ease; }
        .nav-item:hover { background: rgba(124,58,237,0.06); }
        .nav-item-active {
            background: linear-gradient(90deg, rgba(124,58,237,0.11) 0%, rgba(124,58,237,0.02) 100%);
            color:<?= tono(700) ?>; border-left:3px solid <?= ORO ?>;
        }
        .nav-item-active svg { color:<?= tono(600) ?>; }
        html.dark .nav-item-active { color:<?= tono(300) ?>; }

        @media (max-width:1023px) {
            input[type="text"],input[type="email"],input[type="password"],input[type="number"],
            input[type="tel"],input[type="date"],input[type="search"],textarea,select { font-size:16px !important; }
        }
    </style>
</head>
<body class="h-full bg-zinc-50 text-zinc-800">

<div class="flex h-screen overflow-hidden" x-data="layoutSIGGO()">

    <!-- Backdrop movil -->
    <div x-show="sidebarAbierto && esMobile" x-cloak @click="sidebarAbierto=false" x-transition.opacity
         class="fixed inset-0 bg-black/50 z-30 lg:hidden"></div>

    <!-- ===================== SIDEBAR ===================== -->
    <aside class="bg-white border-r border-zinc-200 flex-shrink-0 transition-all duration-300 flex flex-col z-40 lg:relative fixed inset-y-0 left-0"
           :class="esMobile ? (sidebarAbierto ? 'w-64 translate-x-0' : 'w-64 -translate-x-full') : (sidebarAbierto ? 'w-64' : 'w-16')">

        <div class="h-16 flex items-center border-b border-zinc-200 px-3 flex-shrink-0">
            <a href="<?= url('dashboard.php') ?>" class="flex items-center gap-2.5 overflow-hidden w-full" title="<?= e(APP_NAME) ?>">
                <!-- Contraida: solo la pastilla dorada -->
                <span x-show="!sidebarAbierto" x-cloak class="flex-shrink-0"><?= marca_pastilla('text-sm') ?></span>
                <!-- Abierta: el logo de la tienda y luego el wordmark -->
                <span x-show="sidebarAbierto" x-transition.opacity class="flex items-center gap-2.5 overflow-hidden">
                    <?= logo_tienda('h-9 w-auto flex-shrink-0 block dark:hidden') ?>
                    <?= logo_tienda('h-9 w-auto flex-shrink-0 hidden dark:block', 'blanco') ?>
                    <?= marca_wordmark('text-xl') ?>
                </span>
            </a>
        </div>

        <nav class="flex-1 overflow-y-auto py-4">
            <div class="px-3 mb-1" x-show="sidebarAbierto" x-transition.opacity><div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400 px-3">Principal</div></div>
            <?php
                nav_link('dashboard.php', 'layout-dashboard', 'Tablero', 'dashboard', $pagina_activa);
                nav_link('gastos.php', 'receipt-text', 'Gastos', 'gastos', $pagina_activa);
                nav_link('historico.php', 'calendar-range', 'Histórico', 'historico', $pagina_activa);
                nav_link('gastos_recurrentes.php', 'repeat', 'Gastos fijos', 'recurrentes', $pagina_activa);
            ?>
            <div class="px-3 mt-4 mb-1" x-show="sidebarAbierto" x-transition.opacity><div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400 px-3">Catálogos</div></div>
            <?php
                // Áreas, categorías e insumos son catálogos de administrador. Antes
                // se mostraban a todos y el capturista se topaba con un "sin permiso":
                // el menú no debe ofrecer puertas cerradas.
                if ($es_admin) {
                    nav_link('admin/areas.php', 'layout-grid', 'Áreas', 'areas', $pagina_activa);
                    nav_link('admin/categorias.php', 'tags', 'Categorías', 'categorias', $pagina_activa);
                    nav_link('admin/insumos.php', 'package', 'Insumos', 'insumos', $pagina_activa, $insumos_pend);
                }
                nav_link('proveedores.php', 'truck', 'Proveedores', 'proveedores', $pagina_activa);
            ?>
            <div class="px-3 mt-4 mb-1" x-show="sidebarAbierto" x-transition.opacity><div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400 px-3">Análisis</div></div>
            <?php nav_link('reportes/reportes.php', 'bar-chart-3', 'Reportes', 'reportes', $pagina_activa); ?>

            <?php if ($es_admin): ?>
            <div class="px-3 mt-4 mb-1" x-show="sidebarAbierto" x-transition.opacity><div class="text-[10px] uppercase tracking-wider font-bold text-zinc-400 px-3">Administración</div></div>
            <?php
                nav_link('admin/usuarios.php', 'users', 'Usuarios', 'admin_usuarios', $pagina_activa);
                nav_link('admin/catalogos.php', 'sliders-horizontal', 'Formas de pago', 'admin_catalogos', $pagina_activa);
                nav_link('admin/auditoria.php', 'history', 'Auditoría', 'admin_auditoria', $pagina_activa);
                nav_link('cierres.php', 'lock', 'Cierre de mes', 'cierres', $pagina_activa);
                nav_link('admin/backups.php', 'database-backup', 'Backups', 'admin_backups', $pagina_activa);
            ?>
            <?php endif; ?>
        </nav>

        <div class="border-t border-zinc-200 p-3 flex-shrink-0">
            <button @click="sidebarAbierto=!sidebarAbierto" class="w-full flex items-center gap-3 px-3 py-2 text-sm text-zinc-500 hover:bg-zinc-50 rounded-lg">
                <i data-lucide="panel-left" class="w-5 h-5 flex-shrink-0"></i>
                <span x-show="sidebarAbierto" x-transition.opacity>Contraer</span>
            </button>
        </div>
    </aside>

    <!-- ===================== COLUMNA PRINCIPAL ===================== -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

        <!-- TOPBAR -->
        <header class="h-16 bg-white border-b border-zinc-200 flex items-center gap-3 px-4 sm:px-6 flex-shrink-0">
            <button @click="sidebarAbierto=!sidebarAbierto" class="p-2 -ml-2 rounded-lg text-zinc-500 hover:bg-zinc-100 lg:hidden">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div class="flex-1 min-w-0">
                <h1 class="font-display font-bold text-lg text-zinc-800 truncate"><?= e($titulo_pagina) ?></h1>
                <div class="text-[11px] text-zinc-400 truncate hidden sm:block"><?= e(EMPRESA_NOMBRE) ?> · <?= e(SUCURSAL_NOMBRE) ?></div>
            </div>

            <!-- Campana -->
            <a href="<?= url('notificaciones.php') ?>" class="relative p-2 rounded-lg text-zinc-500 hover:bg-zinc-100" title="Notificaciones">
                <i data-lucide="bell" class="w-5 h-5"></i>
                <?php if ($notif_count > 0): ?>
                <span class="absolute top-1 right-1 min-w-[16px] h-4 bg-marca-600 text-white text-[10px] font-bold rounded-full flex items-center justify-center px-1"><?= $notif_count > 9 ? '9+' : $notif_count ?></span>
                <?php endif; ?>
            </a>

            <!-- Menu usuario -->
            <div class="relative" @click.outside="menu=false">
                <button @click="menu=!menu" class="flex items-center gap-2 pl-1 pr-2 py-1 rounded-lg hover:bg-zinc-100">
                    <?= render_avatar($u, 'w-8 h-8') ?>
                    <span class="hidden sm:block text-sm font-medium text-zinc-700 max-w-[140px] truncate"><?= e($u['nombre'] ?? $u['usuario']) ?></span>
                    <i data-lucide="chevron-down" class="w-4 h-4 text-zinc-400"></i>
                </button>
                <div x-show="menu" x-cloak x-transition
                     class="absolute right-0 mt-2 w-60 bg-white rounded-xl shadow-lg border border-zinc-200 py-2 z-50">
                    <div class="px-4 py-2 border-b border-zinc-100">
                        <div class="text-sm font-semibold text-zinc-800 truncate"><?= e($u['nombre'] ?? $u['usuario']) ?></div>
                        <div class="mt-1"><?= badge($u['rol_nombre'] ?? 'Usuario', tono(600)) ?></div>
                    </div>
                    <div class="px-4 py-2 border-b border-zinc-100">
                        <div class="text-[11px] uppercase tracking-wide text-zinc-400 mb-1.5">Tema</div>
                        <div class="flex gap-1">
                            <button @click="aplicarTema('auto')" :class="tema==='auto' ? 'bg-marca-600 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200'" class="flex-1 text-xs py-1.5 rounded-md font-medium">Auto</button>
                            <button @click="aplicarTema('claro')" :class="tema==='claro' ? 'bg-marca-600 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200'" class="flex-1 text-xs py-1.5 rounded-md font-medium">Claro</button>
                            <button @click="aplicarTema('oscuro')" :class="tema==='oscuro' ? 'bg-marca-600 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200'" class="flex-1 text-xs py-1.5 rounded-md font-medium">Oscuro</button>
                        </div>
                    </div>
                    <a href="<?= url('cambiar_password.php') ?>" class="flex items-center gap-2.5 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50"><i data-lucide="key-round" class="w-4 h-4"></i> Cambiar contraseña</a>
                    <a href="<?= url('logout.php') ?>" class="flex items-center gap-2.5 px-4 py-2 text-sm text-marca-700 hover:bg-marca-50 font-semibold"><i data-lucide="log-out" class="w-4 h-4"></i> Cerrar sesión</a>
                </div>
            </div>
        </header>

        <!-- CONTENIDO -->
        <main class="flex-1 overflow-y-auto bg-zinc-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
                <?php if (!empty($mensajes_flash)): foreach ($mensajes_flash as $f):
                    $ftipo = $f['tipo'] ?? 'info';
                    $fmap = ['ok'=>'bg-emerald-50 border-emerald-300 text-emerald-800','exito'=>'bg-emerald-50 border-emerald-300 text-emerald-800','success'=>'bg-emerald-50 border-emerald-300 text-emerald-800','error'=>'bg-red-50 border-red-300 text-red-800','warn'=>'bg-orange-50 border-orange-300 text-orange-800','info'=>'bg-blue-50 border-blue-300 text-blue-800'];
                    $fcls = $fmap[$ftipo] ?? $fmap['info'];
                ?>
                <div class="mb-4 border-l-4 rounded-lg px-4 py-3 text-sm <?= $fcls ?>"><?= e($f['mensaje'] ?? '') ?></div>
                <?php endforeach; endif; ?>
