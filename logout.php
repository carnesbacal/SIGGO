<?php
/**
 * ============================================================================
 * logout.php - Cierra la sesión y redirige al login
 * ============================================================================
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/helpers.php';

logout();
header('Location: ' . url('login.php'));
exit;
