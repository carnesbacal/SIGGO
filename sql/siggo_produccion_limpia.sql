-- =====================================================================
--  SIG-GO · Producción · TUS CATÁLOGOS, SIN MOVIMIENTOS
--  El Grano de Oro · Sucursal Ferias
--
--  CÓMO SE IMPORTA (cPanel)
--    1. phpMyAdmin → selecciona la base de SIG-GO (la que ya creaste, con
--       su prefijo: carnesbacalcom_...). Este archivo NO crea ninguna base.
--    2. Pestaña Importar → este archivo → Continuar.
--    3. Listo. config/db.php no se toca: los datos de conexión son los
--       mismos.
--
--  QUÉ TRAE
--    Los mismos catálogos de tu local: 4 categorías (Operación, Gastos
--    Indirectos, Ventas y Administración) con sus 36 subcategorías,
--    8 áreas, 27 insumos, 16 unidades, 7 formas de pago y 3 usuarios.
--
--  QUÉ NO TRAE
--    Ni un solo gasto, proveedor, gasto fijo ni registro de auditoría.
--    La tienda empieza a capturar en cero.
--
--  QUÉ LE PASA A LO QUE YA ESTABA
--    El archivo borra y vuelve a crear cada tabla (DROP TABLE), así que lo
--    que hubiera en esa base se pierde. Eso es justo lo que se busca aquí,
--    pero si tuvieras algo que salvar, expórtalo antes.
--
--  USUARIOS
--    Van los tres de tu local: admin, lfrodriguez y ksantos, con sus
--    contraseñas de local. A lfrodriguez y ksantos el sistema les va a
--    pedir cambiarla en su primer acceso.
--
--  Generado desde tu respaldo local del 22/09/2026
--  Preparado: 23/09/2026
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: local_limpia
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `areas`
--

DROP TABLE IF EXISTS `areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `responsable_nombre` varchar(150) DEFAULT NULL,
  `responsable_email` varchar(150) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nombre` (`nombre`),
  UNIQUE KEY `uk_codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `areas`
--

/*!40000 ALTER TABLE `areas` DISABLE KEYS */;
INSERT INTO `areas` VALUES
(1,'General','GEN','Gastos que no se atribuyen a un área específica',NULL,NULL,1,'2026-09-15 00:27:40','2026-09-15 00:27:40'),
(2,'Piso de venta','PV','Sala de ventas y exhibición',NULL,NULL,1,'2026-09-15 00:27:40','2026-09-15 00:27:40'),
(3,'Carnicería','CAR','Mostrador de carnes y cámara fría',NULL,NULL,1,'2026-09-15 00:27:40','2026-09-15 00:27:40'),
(4,'Tortillería','TOR','Producción y venta de tortilla',NULL,NULL,1,'2026-09-15 00:27:40','2026-09-15 00:27:40'),
(5,'Abarrotes','ABA','Abarrotes y mercancía seca',NULL,NULL,1,'2026-09-15 00:27:40','2026-09-15 00:27:40'),
(6,'Almacén','ALM','Bodega y recepción de mercancía',NULL,NULL,1,'2026-09-15 00:27:40','2026-09-15 00:27:40'),
(7,'Caja','CAJ','Cajas y punto de venta',NULL,NULL,1,'2026-09-15 00:27:40','2026-09-15 00:27:40'),
(8,'Administración','ADM','Oficina, administración y gerencia',NULL,NULL,1,'2026-09-15 00:27:40','2026-09-15 00:27:40');
/*!40000 ALTER TABLE `areas` ENABLE KEYS */;

--
-- Table structure for table `auditoria_sistema`
--

DROP TABLE IF EXISTS `auditoria_sistema`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auditoria_sistema` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `accion` varchar(100) NOT NULL,
  `entidad` varchar(50) DEFAULT NULL,
  `entidad_id` int(11) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `creado_en` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_accion` (`accion`),
  KEY `idx_fecha` (`creado_en`),
  CONSTRAINT `auditoria_sistema_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auditoria_sistema`
--

/*!40000 ALTER TABLE `auditoria_sistema` DISABLE KEYS */;
/*!40000 ALTER TABLE `auditoria_sistema` ENABLE KEYS */;

--
-- Table structure for table `backups_realizados`
--

DROP TABLE IF EXISTS `backups_realizados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `backups_realizados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_archivo` varchar(255) NOT NULL,
  `tamano_bytes` bigint(20) NOT NULL DEFAULT 0,
  `tipo` enum('manual','automatico') NOT NULL DEFAULT 'manual',
  `realizado_por_id` int(11) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `exitoso` tinyint(1) NOT NULL DEFAULT 1,
  `mensaje_error` text DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_backup_usuario` (`realizado_por_id`),
  CONSTRAINT `fk_backup_usuario` FOREIGN KEY (`realizado_por_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backups_realizados`
--

/*!40000 ALTER TABLE `backups_realizados` DISABLE KEYS */;
/*!40000 ALTER TABLE `backups_realizados` ENABLE KEYS */;

--
-- Table structure for table `categorias_gasto`
--

DROP TABLE IF EXISTS `categorias_gasto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorias_gasto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `codigo` varchar(20) DEFAULT NULL,
  `ambito` enum('gasto','proveedor') NOT NULL DEFAULT 'gasto',
  `descripcion` varchar(255) DEFAULT NULL,
  `color` varchar(20) DEFAULT '#6B7280',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime DEFAULT current_timestamp(),
  `orden` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nombre_ambito` (`nombre`,`ambito`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categorias_gasto`
--

/*!40000 ALTER TABLE `categorias_gasto` DISABLE KEYS */;
INSERT INTO `categorias_gasto` VALUES
(1,'Operación','OPE','gasto','Insumos y servicios del día a día de la tienda','#7c3aed',1,'2026-09-15 00:27:40',10),
(2,'Gastos Indirectos','SER','gasto','Luz, agua, gas, comunicaciones','#0ea5e9',1,'2026-09-15 00:27:40',20),
(3,'Ventas','VTA','gasto',NULL,'#0d9488',1,'2026-09-15 00:27:40',30),
(10,'Administración',NULL,'gasto','Gastos administrativos de la sucursal','#6366F1',1,'2026-09-22 17:24:53',40);
/*!40000 ALTER TABLE `categorias_gasto` ENABLE KEYS */;

--
-- Table structure for table `cierres_mensuales`
--

DROP TABLE IF EXISTS `cierres_mensuales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cierres_mensuales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `anio` smallint(6) NOT NULL,
  `mes` tinyint(4) NOT NULL,
  `cerrado_por` int(11) DEFAULT NULL,
  `cerrado_en` datetime DEFAULT current_timestamp(),
  `nota` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cierre_anio_mes` (`anio`,`mes`),
  KEY `fk_cierre_user` (`cerrado_por`),
  CONSTRAINT `fk_cierre_user` FOREIGN KEY (`cerrado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_cierre_mes` CHECK (`mes` between 1 and 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cierres_mensuales`
--

/*!40000 ALTER TABLE `cierres_mensuales` DISABLE KEYS */;
/*!40000 ALTER TABLE `cierres_mensuales` ENABLE KEYS */;

--
-- Table structure for table `configuracion`
--

DROP TABLE IF EXISTS `configuracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion` (
  `clave` varchar(60) NOT NULL,
  `valor` text DEFAULT NULL,
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion`
--

/*!40000 ALTER TABLE `configuracion` DISABLE KEYS */;
INSERT INTO `configuracion` VALUES
('app_descripcion','Sistema Integral de Gestión de Gastos de Operación','2026-09-15 00:27:40'),
('app_nombre','SIG-GO','2026-09-15 00:27:40'),
('empresa_nombre','El Grano de Oro','2026-09-15 00:27:40'),
('iva_default','16','2026-09-15 00:27:40'),
('moneda','MXN','2026-09-15 00:27:40'),
('sucursal_nombre','Sucursal Ferias','2026-09-15 00:27:40');
/*!40000 ALTER TABLE `configuracion` ENABLE KEYS */;

--
-- Table structure for table `configuracion_notificaciones`
--

DROP TABLE IF EXISTS `configuracion_notificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion_notificaciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` smallint(5) unsigned DEFAULT 587,
  `smtp_seguridad` enum('tls','ssl','none') DEFAULT 'tls',
  `smtp_usuario` varchar(255) DEFAULT NULL,
  `smtp_password` varchar(255) DEFAULT NULL,
  `smtp_from_email` varchar(255) DEFAULT NULL,
  `smtp_from_nombre` varchar(150) DEFAULT 'SIGAP',
  `smtp_activo` tinyint(1) NOT NULL DEFAULT 0,
  `telegram_bot_token` varchar(255) DEFAULT NULL,
  `telegram_activo` tinyint(1) NOT NULL DEFAULT 0,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `actualizado_por` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion_notificaciones`
--

/*!40000 ALTER TABLE `configuracion_notificaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `configuracion_notificaciones` ENABLE KEYS */;

--
-- Table structure for table `formas_pago`
--

DROP TABLE IF EXISTS `formas_pago`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `formas_pago` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `requiere_referencia` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forma_nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `formas_pago`
--

/*!40000 ALTER TABLE `formas_pago` DISABLE KEYS */;
INSERT INTO `formas_pago` VALUES
(1,'Efectivo',0,1,10),
(2,'Caja chica',0,0,20),
(3,'Transferencia',1,1,30),
(4,'Tarjeta empresa',1,1,40),
(5,'Cheque',1,1,50),
(6,'Domiciliado',1,1,60),
(7,'Crédito proveedor',0,1,70);
/*!40000 ALTER TABLE `formas_pago` ENABLE KEYS */;

--
-- Table structure for table `gasto_adjuntos`
--

DROP TABLE IF EXISTS `gasto_adjuntos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gasto_adjuntos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gasto_id` int(11) NOT NULL,
  `tipo` enum('factura','foto') NOT NULL DEFAULT 'factura',
  `archivo_url` varchar(255) NOT NULL,
  `nombre` varchar(200) DEFAULT NULL,
  `subido_por` int(11) DEFAULT NULL,
  `creado_en` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_adj_tipo` (`tipo`),
  KEY `fk_adj_gasto` (`gasto_id`),
  KEY `fk_adj_user` (`subido_por`),
  CONSTRAINT `fk_adj_gasto` FOREIGN KEY (`gasto_id`) REFERENCES `gastos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_adj_user` FOREIGN KEY (`subido_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gasto_adjuntos`
--

/*!40000 ALTER TABLE `gasto_adjuntos` DISABLE KEYS */;
/*!40000 ALTER TABLE `gasto_adjuntos` ENABLE KEYS */;

--
-- Table structure for table `gasto_distribucion`
--

DROP TABLE IF EXISTS `gasto_distribucion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gasto_distribucion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gasto_id` int(11) NOT NULL,
  `area_id` int(11) NOT NULL,
  `porcentaje` decimal(5,2) NOT NULL DEFAULT 0.00,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gasto_area` (`gasto_id`,`area_id`),
  KEY `fk_dist_gasto` (`gasto_id`),
  KEY `fk_dist_area` (`area_id`),
  CONSTRAINT `fk_dist_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_dist_gasto` FOREIGN KEY (`gasto_id`) REFERENCES `gastos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gasto_distribucion`
--

/*!40000 ALTER TABLE `gasto_distribucion` DISABLE KEYS */;
/*!40000 ALTER TABLE `gasto_distribucion` ENABLE KEYS */;

--
-- Table structure for table `gasto_items`
--

DROP TABLE IF EXISTS `gasto_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gasto_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `gasto_id` int(11) NOT NULL,
  `orden` smallint(6) NOT NULL DEFAULT 0,
  `subcategoria_id` int(11) DEFAULT NULL,
  `insumo_id` int(11) DEFAULT NULL,
  `no_es_insumo` tinyint(1) NOT NULL DEFAULT 0,
  `codigo` varchar(40) DEFAULT NULL,
  `descripcion` varchar(255) NOT NULL,
  `cantidad` decimal(12,3) NOT NULL DEFAULT 1.000,
  `unidad_id` int(11) DEFAULT NULL,
  `precio_unitario` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `importe` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notas` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_gasto` (`gasto_id`,`orden`),
  KEY `idx_item_codigo` (`codigo`),
  KEY `fk_item_subcat` (`subcategoria_id`),
  KEY `fk_item_unidad` (`unidad_id`),
  KEY `fk_item_insumo` (`insumo_id`),
  CONSTRAINT `fk_item_gasto` FOREIGN KEY (`gasto_id`) REFERENCES `gastos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_insumo` FOREIGN KEY (`insumo_id`) REFERENCES `insumos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_item_subcat` FOREIGN KEY (`subcategoria_id`) REFERENCES `subcategorias_gasto` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_item_unidad` FOREIGN KEY (`unidad_id`) REFERENCES `unidades_medida` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_item_cantidad` CHECK (`cantidad` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gasto_items`
--

/*!40000 ALTER TABLE `gasto_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `gasto_items` ENABLE KEYS */;

--
-- Table structure for table `gastos`
--

DROP TABLE IF EXISTS `gastos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gastos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) DEFAULT NULL,
  `fecha` date NOT NULL,
  `area_id` int(11) NOT NULL,
  `categoria_id` int(11) NOT NULL,
  `subcategoria_id` int(11) DEFAULT NULL,
  `recurrente_id` int(11) DEFAULT NULL,
  `tipo` enum('fijo','variable') NOT NULL DEFAULT 'variable',
  `concepto` varchar(200) NOT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) DEFAULT NULL,
  `iva` decimal(12,2) DEFAULT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `proveedor_texto` varchar(150) DEFAULT NULL,
  `forma_pago_id` int(11) DEFAULT NULL,
  `referencia_pago` varchar(60) DEFAULT NULL,
  `estatus_pago` enum('pendiente','parcial','pagado','cancelado') NOT NULL DEFAULT 'pagado',
  `fecha_pago` date DEFAULT NULL,
  `responsable_id` int(11) DEFAULT NULL,
  `responsable_texto` varchar(150) DEFAULT NULL,
  `numero_factura` varchar(60) DEFAULT NULL,
  `uuid` varchar(40) DEFAULT NULL,
  `rfc_emisor` varchar(20) DEFAULT NULL,
  `archivo_url` varchar(255) DEFAULT NULL,
  `cfdi_xml_url` varchar(255) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `registrado_por` int(11) DEFAULT NULL,
  `creado_en` datetime DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `anio` smallint(6) GENERATED ALWAYS AS (year(`fecha`)) STORED,
  `mes` tinyint(4) GENERATED ALWAYS AS (month(`fecha`)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_folio` (`folio`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  KEY `idx_fecha` (`fecha`),
  KEY `fk_gasto_categoria` (`categoria_id`),
  KEY `fk_gasto_proveedor` (`proveedor_id`),
  KEY `fk_gasto_usuario` (`registrado_por`),
  KEY `fk_gasto_recurrente` (`recurrente_id`),
  KEY `fk_gasto_area` (`area_id`),
  KEY `fk_gasto_subcat` (`subcategoria_id`),
  KEY `fk_gasto_forma` (`forma_pago_id`),
  KEY `fk_gasto_respons` (`responsable_id`),
  KEY `idx_anio_mes` (`anio`,`mes`),
  KEY `idx_cat_subcat` (`categoria_id`,`subcategoria_id`),
  KEY `idx_estatus` (`estatus_pago`),
  CONSTRAINT `fk_gasto_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_gasto_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_gasto` (`id`),
  CONSTRAINT `fk_gasto_forma` FOREIGN KEY (`forma_pago_id`) REFERENCES `formas_pago` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gasto_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gasto_recurrente` FOREIGN KEY (`recurrente_id`) REFERENCES `gastos_recurrentes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gasto_respons` FOREIGN KEY (`responsable_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gasto_subcat` FOREIGN KEY (`subcategoria_id`) REFERENCES `subcategorias_gasto` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gasto_usuario` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gastos`
--

/*!40000 ALTER TABLE `gastos` DISABLE KEYS */;
/*!40000 ALTER TABLE `gastos` ENABLE KEYS */;

--
-- Table structure for table `gastos_recurrentes`
--

DROP TABLE IF EXISTS `gastos_recurrentes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gastos_recurrentes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `area_id` int(11) NOT NULL,
  `categoria_id` int(11) NOT NULL,
  `subcategoria_id` int(11) DEFAULT NULL,
  `concepto` varchar(200) NOT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  `proveedor_id` int(11) DEFAULT NULL,
  `proveedor_texto` varchar(150) DEFAULT NULL,
  `forma_pago_id` int(11) DEFAULT NULL,
  `dia_mes` tinyint(4) NOT NULL DEFAULT 1,
  `frecuencia` enum('mensual','bimestral','trimestral','semestral','anual') NOT NULL DEFAULT 'mensual',
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `notas` text DEFAULT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `creado_en` datetime DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rec_activo` (`activo`),
  KEY `fk_rec_categoria` (`categoria_id`),
  KEY `fk_rec_proveedor` (`proveedor_id`),
  KEY `fk_rec_creador` (`creado_por`),
  KEY `fk_rec_area` (`area_id`),
  KEY `fk_rec_subcat` (`subcategoria_id`),
  KEY `fk_rec_forma` (`forma_pago_id`),
  CONSTRAINT `fk_rec_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`),
  CONSTRAINT `fk_rec_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_gasto` (`id`),
  CONSTRAINT `fk_rec_creador` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rec_forma` FOREIGN KEY (`forma_pago_id`) REFERENCES `formas_pago` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rec_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rec_subcat` FOREIGN KEY (`subcategoria_id`) REFERENCES `subcategorias_gasto` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_rec_dia` CHECK (`dia_mes` between 1 and 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gastos_recurrentes`
--

/*!40000 ALTER TABLE `gastos_recurrentes` DISABLE KEYS */;
/*!40000 ALTER TABLE `gastos_recurrentes` ENABLE KEYS */;

--
-- Table structure for table `importaciones`
--

DROP TABLE IF EXISTS `importaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `importaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('gastos','areas','categorias','subcategorias','proveedores','usuarios') NOT NULL,
  `nombre_archivo` varchar(255) NOT NULL,
  `total_filas` int(11) NOT NULL DEFAULT 0,
  `exitosos` int(11) NOT NULL DEFAULT 0,
  `fallidos` int(11) NOT NULL DEFAULT 0,
  `errores_json` text DEFAULT NULL,
  `realizado_por_id` int(11) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_import_usuario` (`realizado_por_id`),
  CONSTRAINT `fk_import_usuario` FOREIGN KEY (`realizado_por_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `importaciones`
--

/*!40000 ALTER TABLE `importaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `importaciones` ENABLE KEYS */;

--
-- Table structure for table `insumos`
--

DROP TABLE IF EXISTS `insumos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `insumos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(40) DEFAULT NULL,
  `nombre` varchar(150) NOT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `subcategoria_id` int(11) DEFAULT NULL,
  `unidad_id` int(11) DEFAULT NULL,
  `area_sugerida_id` int(11) DEFAULT NULL,
  `precio_referencia` decimal(12,4) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` smallint(6) NOT NULL DEFAULT 0,
  `creado_por` int(11) DEFAULT NULL,
  `creado_en` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_insumo_nombre` (`nombre`),
  KEY `idx_insumo_activo` (`activo`),
  KEY `fk_ins_categoria` (`categoria_id`),
  KEY `fk_ins_subcat` (`subcategoria_id`),
  KEY `fk_ins_unidad` (`unidad_id`),
  KEY `fk_ins_area` (`area_sugerida_id`),
  CONSTRAINT `fk_ins_area` FOREIGN KEY (`area_sugerida_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ins_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_gasto` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ins_subcat` FOREIGN KEY (`subcategoria_id`) REFERENCES `subcategorias_gasto` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ins_unidad` FOREIGN KEY (`unidad_id`) REFERENCES `unidades_medida` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `insumos`
--

/*!40000 ALTER TABLE `insumos` DISABLE KEYS */;
INSERT INTO `insumos` VALUES
(1,'EMP-BOLC','Bolsa camiseta chica',1,4,7,7,NULL,NULL,1,10,NULL,'2026-09-15 09:22:52'),
(2,'EMP-BOLM','Bolsa camiseta mediana',1,4,7,7,NULL,NULL,1,20,NULL,'2026-09-15 09:22:52'),
(3,'EMP-BOLG','Bolsa camiseta grande',1,4,7,7,NULL,NULL,1,30,NULL,'2026-09-15 09:22:52'),
(4,'EMP-PLAYO','Rollo de playo',1,4,9,3,NULL,NULL,1,40,NULL,'2026-09-15 09:22:52'),
(5,'EMP-CHAR','Charola para carnicería',1,4,7,3,NULL,NULL,1,50,NULL,'2026-09-15 09:22:52'),
(6,'EMP-BOLK','Bolsa para kilo (tortilla)',1,4,7,4,NULL,NULL,1,60,NULL,'2026-09-15 09:22:52'),
(7,'EMP-PAPEL','Papel estraza',1,4,2,3,NULL,NULL,1,70,NULL,'2026-09-15 09:22:52'),
(8,'PAP-ROLLO','Rollo térmico para caja',1,5,9,7,NULL,NULL,1,10,NULL,'2026-09-15 09:22:52'),
(9,'PAP-ETIQ','Etiquetas para báscula',1,5,9,3,NULL,NULL,1,20,NULL,'2026-09-15 09:22:52'),
(10,'PAP-PLUMA','Plumas y marcadores',1,5,1,8,NULL,NULL,1,30,NULL,'2026-09-15 09:22:52'),
(11,'PAP-HOJAS','Hojas blancas',1,5,7,8,NULL,NULL,1,40,NULL,'2026-09-15 09:22:52'),
(12,'PAP-CINTA','Cinta adhesiva',1,5,1,6,NULL,NULL,1,50,NULL,'2026-09-15 09:22:52'),
(13,'LIM-CLORO','Cloro',1,2,4,1,NULL,NULL,1,10,NULL,'2026-09-15 09:22:52'),
(14,'LIM-JABON','Jabón líquido / detergente',1,2,4,1,NULL,NULL,1,20,NULL,'2026-09-15 09:22:52'),
(15,'LIM-JERGA','Jerga y franela',1,2,1,1,NULL,NULL,1,30,NULL,'2026-09-15 09:22:52'),
(16,'LIM-ESCOBA','Escobas y trapeadores',1,2,1,1,NULL,NULL,1,40,NULL,'2026-09-15 09:22:52'),
(17,'LIM-BOLBAS','Bolsa para basura',1,2,7,1,NULL,NULL,1,50,NULL,'2026-09-15 09:22:52'),
(18,'LIM-PAPH','Papel higiénico',1,2,7,1,NULL,NULL,1,60,NULL,'2026-09-15 09:22:52'),
(19,'LIM-GUANT','Guantes de limpieza',1,2,7,1,NULL,NULL,1,70,NULL,'2026-09-15 09:22:52'),
(20,'LIM-DESIN','Desinfectante',1,2,4,1,NULL,NULL,1,80,NULL,'2026-09-15 09:22:52'),
(21,'OPE-AGUA','Garrafón de agua',1,NULL,1,1,NULL,NULL,1,90,NULL,'2026-09-15 09:22:52'),
(22,'OPE-HIELO','Hielo',1,NULL,2,3,NULL,NULL,1,100,NULL,'2026-09-15 09:22:52'),
(23,'OPE-GASLP','Gas LP (cilindro)',2,9,1,4,NULL,NULL,1,110,NULL,'2026-09-15 09:22:52'),
(24,'UNI-MANDIL','Mandil',1,6,1,3,NULL,NULL,1,10,NULL,'2026-09-15 09:22:52'),
(25,'UNI-COFIA','Cofia y red para cabello',1,6,7,3,NULL,NULL,1,20,NULL,'2026-09-15 09:22:52'),
(26,'UNI-PLAYERA','Playera de uniforme',1,6,1,2,NULL,NULL,1,30,NULL,'2026-09-15 09:22:52'),
(27,'UNI-GUANTC','Guante de acero (corte)',1,6,1,3,NULL,NULL,1,40,NULL,'2026-09-15 09:22:52');
/*!40000 ALTER TABLE `insumos` ENABLE KEYS */;

--
-- Table structure for table `notificacion_envios`
--

DROP TABLE IF EXISTS `notificacion_envios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notificacion_envios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `notificacion_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) NOT NULL,
  `canal` enum('email','telegram') NOT NULL,
  `tipo` varchar(60) DEFAULT NULL,
  `asunto` varchar(255) DEFAULT NULL,
  `estado` enum('ok','error') NOT NULL DEFAULT 'ok',
  `error_detalle` text DEFAULT NULL,
  `enviado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario_canal` (`usuario_id`,`canal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notificacion_envios`
--

/*!40000 ALTER TABLE `notificacion_envios` DISABLE KEYS */;
/*!40000 ALTER TABLE `notificacion_envios` ENABLE KEYS */;

--
-- Table structure for table `notificacion_preferencias`
--

DROP TABLE IF EXISTS `notificacion_preferencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notificacion_preferencias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `tipo` varchar(60) NOT NULL,
  `canal_inapp` tinyint(1) NOT NULL DEFAULT 1,
  `canal_email` tinyint(1) NOT NULL DEFAULT 0,
  `canal_telegram` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usuario_tipo` (`usuario_id`,`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notificacion_preferencias`
--

/*!40000 ALTER TABLE `notificacion_preferencias` DISABLE KEYS */;
/*!40000 ALTER TABLE `notificacion_preferencias` ENABLE KEYS */;

--
-- Table structure for table `notificaciones`
--

DROP TABLE IF EXISTS `notificaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notificaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `mensaje` text DEFAULT NULL,
  `enlace` varchar(500) DEFAULT NULL,
  `leida` tinyint(1) NOT NULL DEFAULT 0,
  `leida_en` datetime DEFAULT NULL,
  `creada_en` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario_leida` (`usuario_id`,`leida`),
  CONSTRAINT `notificaciones_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notificaciones`
--

/*!40000 ALTER TABLE `notificaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `notificaciones` ENABLE KEYS */;

--
-- Table structure for table `proveedores`
--

DROP TABLE IF EXISTS `proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) NOT NULL,
  `razon_social` varchar(200) DEFAULT NULL,
  `rfc` varchar(20) DEFAULT NULL,
  `servicio` varchar(255) DEFAULT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `sitio_web` varchar(200) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_por_id` int(11) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nombre` (`nombre`),
  KEY `fk_proveedor_creador` (`creado_por_id`),
  KEY `fk_prov_categoria` (`categoria_id`),
  CONSTRAINT `fk_prov_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_gasto` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_proveedor_creador` FOREIGN KEY (`creado_por_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proveedores`
--

/*!40000 ALTER TABLE `proveedores` DISABLE KEYS */;
/*!40000 ALTER TABLE `proveedores` ENABLE KEYS */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `puede_administrar` tinyint(1) NOT NULL DEFAULT 0,
  `puede_ver_todas_sucursales` tinyint(1) NOT NULL DEFAULT 0,
  `puede_resolver` tinyint(1) NOT NULL DEFAULT 0,
  `puede_crear_solicitud` tinyint(1) NOT NULL DEFAULT 1,
  `puede_ver_reportes` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_en` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES
(1,'Administrador','Acceso total al sistema',1,1,1,1,1,1,'2026-09-01 09:14:16'),
(2,'Capturista','Captura y edita gastos, consulta reportes',0,1,0,1,1,1,'2026-09-01 09:14:16'),
(3,'Consulta','Solo lectura de reportes y tableros',0,1,0,0,1,1,'2026-09-01 09:14:16');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;

--
-- Table structure for table `sesiones`
--

DROP TABLE IF EXISTS `sesiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sesiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `dispositivo` varchar(100) DEFAULT NULL,
  `navegador` varchar(50) DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `motivo_cierre` varchar(100) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `ultima_actividad` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cerrada_en` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_id` (`session_id`),
  KEY `idx_usuario_activa` (`usuario_id`,`activa`),
  CONSTRAINT `fk_sesion_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sesiones`
--

/*!40000 ALTER TABLE `sesiones` DISABLE KEYS */;
/*!40000 ALTER TABLE `sesiones` ENABLE KEYS */;

--
-- Table structure for table `subcategorias_gasto`
--

DROP TABLE IF EXISTS `subcategorias_gasto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subcategorias_gasto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `codigo` varchar(20) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` smallint(6) NOT NULL DEFAULT 0,
  `creado_en` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_subcat_nombre` (`categoria_id`,`nombre`),
  KEY `idx_subcat_activo` (`activo`),
  CONSTRAINT `fk_subcat_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_gasto` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subcategorias_gasto`
--

/*!40000 ALTER TABLE `subcategorias_gasto` DISABLE KEYS */;
INSERT INTO `subcategorias_gasto` VALUES
(1,1,'Telefono',NULL,NULL,1,0,'2026-09-15 00:27:40'),
(2,1,'Telefono Telcel',NULL,NULL,1,0,'2026-09-15 00:27:40'),
(3,1,'Internet',NULL,NULL,1,0,'2026-09-15 00:27:40'),
(4,1,'Uniformes y Eq. de Seguridad',NULL,NULL,1,0,'2026-09-15 00:27:40'),
(5,1,'Mtto de Maq y Equipo',NULL,NULL,1,0,'2026-09-15 00:27:40'),
(6,1,'Mtto Local',NULL,NULL,1,0,'2026-09-15 00:27:40'),
(7,2,'Luz (CFE)','SER-LUZ',NULL,1,10,'2026-09-15 00:27:40'),
(8,2,'Agua','SER-AGU',NULL,1,20,'2026-09-15 00:27:40'),
(9,2,'Gas','SER-GAS',NULL,1,30,'2026-09-15 00:27:40'),
(12,3,'Combustible y Lubricantes -Ventas',NULL,NULL,1,0,'2026-09-15 00:27:40'),
(13,3,'Publicidad y Propaganda',NULL,NULL,1,20,'2026-09-15 00:27:40'),
(14,3,'Comisiones Uber Eats / Rappi / Didi',NULL,NULL,1,0,'2026-09-15 00:27:40'),
(40,1,'Renta de Local',NULL,NULL,1,0,'2026-09-17 12:37:09'),
(41,1,'Recolección de Basura',NULL,NULL,1,0,'2026-09-21 15:22:10'),
(42,1,'Servicio de Fumigación',NULL,NULL,1,0,'2026-09-21 15:22:24'),
(43,1,'Gastos No deducibles',NULL,NULL,1,0,'2026-09-21 15:22:34'),
(44,1,'Herramienta menor',NULL,NULL,1,0,'2026-09-21 15:22:44'),
(45,1,'Recolección de Grasas y Residuos',NULL,NULL,1,0,'2026-09-21 15:22:57'),
(46,1,'Art empaque -Operación',NULL,NULL,1,0,'2026-09-21 15:23:57'),
(47,1,'Aseo y Limpieza -Operación',NULL,NULL,1,0,'2026-09-21 15:24:10'),
(48,1,'Diversos gastos de operación',NULL,NULL,1,0,'2026-09-21 15:24:22'),
(49,1,'Depreciación Gtos Operación',NULL,NULL,1,0,'2026-09-21 15:24:29'),
(50,1,'Papeleria y Utiles',NULL,NULL,1,0,'2026-09-21 15:24:35'),
(51,1,'Vigilancia y Seguridad',NULL,NULL,1,0,'2026-09-21 15:24:42'),
(52,1,'Seguros y Fianzas',NULL,NULL,1,0,'2026-09-21 15:24:50'),
(53,1,'Soporte Tecnico Eq Computo',NULL,NULL,1,0,'2026-09-21 15:25:00'),
(54,1,'Limpieza externa',NULL,NULL,1,0,'2026-09-21 15:25:07'),
(60,10,'Tramites y Licencias',NULL,NULL,1,10,'2026-09-22 17:29:15'),
(61,10,'Mtto Equipo de oficina',NULL,NULL,1,20,'2026-09-22 17:29:15'),
(62,10,'Asesoria en sistemas',NULL,NULL,1,30,'2026-09-22 17:29:15'),
(63,10,'Mtto Equipo de Computo',NULL,NULL,1,40,'2026-09-22 17:29:15'),
(64,10,'Diversos gastos administrativos',NULL,NULL,1,50,'2026-09-22 17:29:15'),
(65,10,'Depreciación Gtos Admon',NULL,NULL,1,60,'2026-09-22 17:29:15'),
(66,10,'Multas',NULL,NULL,1,70,'2026-09-22 17:29:15'),
(67,10,'Recargos',NULL,NULL,1,80,'2026-09-22 17:29:15'),
(68,10,'Recolección de Valores',NULL,NULL,1,90,'2026-09-22 17:29:15');
/*!40000 ALTER TABLE `subcategorias_gasto` ENABLE KEYS */;

--
-- Table structure for table `unidades_medida`
--

DROP TABLE IF EXISTS `unidades_medida`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `unidades_medida` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clave` varchar(10) NOT NULL,
  `nombre` varchar(60) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_unidad_clave` (`clave`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `unidades_medida`
--

/*!40000 ALTER TABLE `unidades_medida` DISABLE KEYS */;
INSERT INTO `unidades_medida` VALUES
(1,'PZA','Pieza',1,10),
(2,'KG','Kilogramo',1,20),
(3,'GR','Gramo',1,30),
(4,'LT','Litro',1,40),
(5,'ML','Mililitro',1,50),
(6,'CAJA','Caja',1,60),
(7,'PAQ','Paquete',1,70),
(8,'BULTO','Bulto',1,80),
(9,'ROLLO','Rollo',1,90),
(10,'MTS','Metro',1,100),
(11,'M2','Metro cuadrado',1,110),
(12,'JGO','Juego',1,120),
(13,'HR','Hora',1,130),
(14,'DIA','Día',1,140),
(15,'MES','Mes',1,150),
(16,'SERV','Servicio',1,160);
/*!40000 ALTER TABLE `unidades_medida` ENABLE KEYS */;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nombre_completo` varchar(150) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `pagina_inicio_preferida` varchar(100) DEFAULT 'dashboard.php',
  `telefono` varchar(50) DEFAULT NULL,
  `rol_id` int(11) NOT NULL,
  `area_id` int(11) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_login` datetime DEFAULT NULL,
  `intentos_fallidos` int(11) NOT NULL DEFAULT 0,
  `bloqueado_hasta` datetime DEFAULT NULL,
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 0,
  `tema_preferido` varchar(20) DEFAULT 'auto',
  `escala_interfaz` smallint(6) NOT NULL DEFAULT 100,
  `telegram_chat_id` varchar(50) DEFAULT NULL,
  `preferencias` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferencias`)),
  `creado_en` datetime DEFAULT current_timestamp(),
  `actualizado_en` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario` (`usuario`),
  KEY `rol_id` (`rol_id`),
  KEY `fk_usuario_area` (`area_id`),
  CONSTRAINT `fk_usuario_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES
(1,'admin','$2y$10$ll4es4sUQHoTCqAU2y8AJO1GAI3mTm6c2A3K1sLUfm8naaKQR4kMK','Administrador del Sistema','admin@sigap.local',NULL,'dashboard.php',NULL,1,NULL,1,'2026-09-22 17:10:35',0,NULL,0,'auto',100,NULL,NULL,'2026-09-01 09:14:17','2026-09-22 17:10:35'),
(2,'lfrodriguez','$2y$10$TKng8AjIVBpLQbv8o7FVzewNcGLNLxcbP9d0zUMGRT/KzpIAWZmnK','Luis Fernando Rodriguez Cruz','lfrodriguez@granodeoro.com.mx',NULL,'dashboard.php',NULL,1,NULL,1,NULL,2,NULL,1,'auto',100,NULL,NULL,'2026-09-21 10:21:42','2026-09-21 14:41:18'),
(3,'ksantos','$2y$10$L4TaCmdqNbn57oHyT6px1OlXe/iDdN5y0hDffnfrae7MmOvMkPJsW','Karina Santos',NULL,NULL,'dashboard.php',NULL,2,NULL,1,NULL,0,NULL,1,'auto',100,NULL,NULL,'2026-09-22 17:12:46','2026-09-22 17:12:46');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;

--
-- Temporary table structure for view `vista_gasto_area`
--

DROP TABLE IF EXISTS `vista_gasto_area`;
/*!50001 DROP VIEW IF EXISTS `vista_gasto_area`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `vista_gasto_area` AS SELECT
 1 AS `gasto_id`,
  1 AS `area_id`,
  1 AS `categoria_id`,
  1 AS `subcategoria_id`,
  1 AS `fecha`,
  1 AS `anio`,
  1 AS `mes`,
  1 AS `tipo`,
  1 AS `estatus_pago`,
  1 AS `monto` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vista_items`
--

DROP TABLE IF EXISTS `vista_items`;
/*!50001 DROP VIEW IF EXISTS `vista_items`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `vista_items` AS SELECT
 1 AS `id`,
  1 AS `gasto_id`,
  1 AS `codigo`,
  1 AS `descripcion`,
  1 AS `cantidad`,
  1 AS `unidad`,
  1 AS `precio_unitario`,
  1 AS `importe`,
  1 AS `fecha`,
  1 AS `anio`,
  1 AS `mes`,
  1 AS `area_id`,
  1 AS `subcategoria_id`,
  1 AS `categoria_id`,
  1 AS `proveedor_id` */;
SET character_set_client = @saved_cs_client;

--
-- Dumping events for database 'local_limpia'
--

--
-- Dumping routines for database 'local_limpia'
--

--
-- Final view structure for view `vista_gasto_area`
--

/*!50001 DROP VIEW IF EXISTS `vista_gasto_area`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `vista_gasto_area` AS select `g`.`id` AS `gasto_id`,`g`.`area_id` AS `area_id`,`g`.`categoria_id` AS `categoria_id`,`g`.`subcategoria_id` AS `subcategoria_id`,`g`.`fecha` AS `fecha`,`g`.`anio` AS `anio`,`g`.`mes` AS `mes`,`g`.`tipo` AS `tipo`,`g`.`estatus_pago` AS `estatus_pago`,`g`.`monto` AS `monto` from `gastos` `g` where !exists(select 1 from `gasto_distribucion` `d` where `d`.`gasto_id` = `g`.`id` limit 1) union all select `g`.`id` AS `id`,`d`.`area_id` AS `area_id`,`g`.`categoria_id` AS `categoria_id`,`g`.`subcategoria_id` AS `subcategoria_id`,`g`.`fecha` AS `fecha`,`g`.`anio` AS `anio`,`g`.`mes` AS `mes`,`g`.`tipo` AS `tipo`,`g`.`estatus_pago` AS `estatus_pago`,`d`.`monto` AS `monto` from (`gastos` `g` join `gasto_distribucion` `d` on(`d`.`gasto_id` = `g`.`id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vista_items`
--

/*!50001 DROP VIEW IF EXISTS `vista_items`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `vista_items` AS select `i`.`id` AS `id`,`i`.`gasto_id` AS `gasto_id`,`i`.`codigo` AS `codigo`,`i`.`descripcion` AS `descripcion`,`i`.`cantidad` AS `cantidad`,`u`.`clave` AS `unidad`,`i`.`precio_unitario` AS `precio_unitario`,`i`.`importe` AS `importe`,`g`.`fecha` AS `fecha`,`g`.`anio` AS `anio`,`g`.`mes` AS `mes`,`g`.`area_id` AS `area_id`,coalesce(`i`.`subcategoria_id`,`g`.`subcategoria_id`) AS `subcategoria_id`,`g`.`categoria_id` AS `categoria_id`,`g`.`proveedor_id` AS `proveedor_id` from ((`gasto_items` `i` join `gastos` `g` on(`g`.`id` = `i`.`gasto_id`)) left join `unidades_medida` `u` on(`u`.`id` = `i`.`unidad_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  Comprobación: pega esto en la pestaña SQL de phpMyAdmin.
--
--  SELECT c.nombre AS categoria, COUNT(s.id) AS subcategorias
--    FROM categorias_gasto c
--    LEFT JOIN subcategorias_gasto s ON s.categoria_id = c.id
--   GROUP BY c.id, c.nombre ORDER BY c.orden;
--
--  Deben salir: Operación 21 · Gastos Indirectos 3 · Ventas 3 ·
--  Administración 9.
-- =====================================================================
