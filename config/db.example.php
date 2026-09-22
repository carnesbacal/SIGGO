<?php
/**
 * config/db.example.php · SIG-GO
 *
 * PLANTILLA. Este archivo sí va al repositorio; el real NO.
 *
 * EN EL SERVIDOR (una sola vez):
 *   1. Copia este archivo como  config/db.php  (File Manager → Copy).
 *   2. Pon abajo el nombre de la base, el usuario y la contraseña que creaste
 *      en cPanel → MySQL® Databases. En cPanel los nombres llevan prefijo:
 *      la base "siggo" se llama en realidad algo como carnesbacalcom_siggo.
 *   3. Guarda. Listo: config/db.php está en .gitignore, así que ningún deploy
 *      lo va a pisar y la contraseña nunca viaja a GitHub.
 *
 * EN TU MÁQUINA (XAMPP) el archivo es el mismo, pero con los datos locales
 * (normalmente usuario "root" y contraseña vacía).
 */

define('DB_HOST',    'localhost');
define('DB_NAME',    'carnesbacalcom_siggo');   // <-- nombre real en cPanel
define('DB_USER',    'carnesbacalcom_siggo');   // <-- usuario de la base
define('DB_PASS',    'PON_AQUI_LA_CONTRASENA'); // <-- contraseña del usuario
define('DB_PORT',    3306);
define('DB_CHARSET', 'utf8mb4');

require_once __DIR__ . '/app.php';
