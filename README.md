# SIG-GO · Sistema Integral de Gestión de Gastos de Operación

El Grano de Oro · Sucursal Ferias

Captura y control de los gastos de operación de la tienda: gastos con detalle por
renglón, catálogo cerrado de insumos, lectura automática de facturas (XML y PDF),
comprobante obligatorio, gastos fijos automáticos y siete reportes exportables a
Excel y PDF.

> Mantenimiento, nómina y caja chica **no** se manejan aquí: eso vive en otras apps.

---

## Requisitos del servidor

| | |
|---|---|
| PHP | 8.0 o superior |
| Extensiones | pdo_mysql, mbstring, iconv, zlib, gd (gd solo para el logo en los PDF) |
| MySQL / MariaDB | 5.7 / 10.4 o superior |
| Subidas | `upload_max_filesize` y `post_max_size` en **20M** o más (fotos de celular) |

---

## Instalación en cPanel (primera vez)

### 1. Base de datos

1. cPanel → **MySQL® Databases** → crea la base (por ejemplo `siggo`; cPanel le
   pone el prefijo de la cuenta) y un usuario, y asígnale **todos los privilegios**.
2. cPanel → **phpMyAdmin** → selecciona esa base → pestaña **Importar** →
   sube `sql/siggo_limpia.sql`.
   El archivo **no** crea ninguna base: por eso hay que seleccionarla antes.

La base llega con los catálogos cargados (áreas, categorías, subcategorías,
unidades, formas de pago e insumos) y **sin un solo gasto**.

### 2. Código

1. cPanel → **Git™ Version Control** → *Create*.
   - Clone URL: `https://github.com/carnesbacal/SIGGO.git`
   - Repository Path: `/home/carnesbacalcom/repositories/SIGGO`
2. Entra al repositorio recién creado → pestaña **Pull or Deploy** →
   **Deploy HEAD Commit**. Eso lee `.cpanel.yml` y copia todo a
   `public_html/intranet.carnesbacal.com.mx/SIGGO/`.

### 3. Conexión a la base

En el destino (`.../SIGGO/config/`), copia `db.example.php` como **`db.php`** y
escribe ahí el nombre real de la base, el usuario y la contraseña.
Ese archivo está en `.gitignore`: ningún despliegue lo pisa.

### 4. Permisos de carpetas

Con File Manager, revisa que existan y tengan permiso de escritura (755):

```
assets/uploads/     comprobantes que suben los usuarios
assets/tmp/
assets/avatares/
backups/            respaldos que genera el sistema
```

### 5. Primer acceso

```
Usuario:     admin
Contraseña:  SigGo2026!
```

El sistema **obliga a cambiarla** en el primer acceso. Después:
Usuarios → crea el usuario del gerente con rol **Capturista**.

### 6. Tarea programada (gastos fijos)

cPanel → **Cron Jobs** → una vez al mes, el día 1 a las 6:00 a.m.:

```
0 6 1 * * /usr/local/bin/php /home/carnesbacalcom/public_html/intranet.carnesbacal.com.mx/SIGGO/cron/generar_recurrentes.php
```

Es seguro correrlo varias veces: no duplica lo ya generado y respeta los meses
cerrados.

---

## Actualizaciones (cada vez que haya cambios)

En tu máquina:

```bash
git add .
git commit -m "Descripción corta del cambio"
git push
```

En cPanel → Git™ Version Control → **Pull or Deploy** → *Update from Remote* y
luego **Deploy HEAD Commit**.

---

## Qué NO se sube al repositorio

- `config/db.php` — contraseñas de la base.
- `assets/uploads/` — comprobantes reales (fotos, PDF y XML de facturas).
- `backups/` — respaldos de la base.

Si cambias de servidor, esas tres cosas se copian aparte, a mano.

---

## Estructura

```
admin/       catálogos y administración (áreas, categorías, insumos, usuarios…)
api/         lectura de comprobantes (XML y PDF) por AJAX
assets/      imágenes, JS y los archivos que suben los usuarios
config/      configuración, ayudantes y el generador de PDF
cron/        script de gastos fijos (solo línea de comandos)
reportes/    los siete reportes
sql/         script de instalación de la base
```
