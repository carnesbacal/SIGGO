-- =====================================================================
--  SIG-GO · Ajuste 01 · Dejar solo cuatro categorías
--  El Grano de Oro · Sucursal Ferias
--
--  QUÉ HACE
--    Deja únicamente: Operación, Servicios, Inmueble y Equipo.
--    Las demás (Personal, Administrativo, Vehículos, Mermas, Otros) se van,
--    junto con sus subcategorías.
--
--  ES SEGURO
--    Ninguna categoría con gastos capturados se borra: esa se DESACTIVA, para
--    no romper el histórico. Solo desaparecen las que nunca se usaron.
--    Los insumos que apuntaran a una subcategoría borrada quedan sin
--    subcategoría (el insumo NO se borra).
--
--  CÓMO
--    phpMyAdmin → selecciona la base de SIG-GO → pestaña Importar → este
--    archivo. Se puede correr varias veces sin problema.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. Antes: qué hay
-- ---------------------------------------------------------------------
SELECT 'ANTES' AS momento, COUNT(*) AS categorias,
       (SELECT COUNT(*) FROM subcategorias_gasto) AS subcategorias
  FROM categorias_gasto;

-- ---------------------------------------------------------------------
--  2. Las que tienen movimientos NO se borran: se desactivan.
--     Así el gasto viejo conserva su categoría y deja de ofrecerse
--     en las pantallas de captura.
-- ---------------------------------------------------------------------
UPDATE categorias_gasto c
   SET c.activo = 0
 WHERE NOT (COALESCE(c.codigo,'') IN ('OPE','SER','INM','EQU')
         OR c.nombre IN ('Operación','Servicios','Inmueble','Equipo'))
   AND (EXISTS (SELECT 1 FROM gastos g              WHERE g.categoria_id = c.id)
     OR EXISTS (SELECT 1 FROM gastos_recurrentes r  WHERE r.categoria_id = c.id));

-- ---------------------------------------------------------------------
--  3. Las que nunca se usaron sí se borran.
--     Sus subcategorías caen solas (la llave foránea está en cascada).
-- ---------------------------------------------------------------------
DELETE c FROM categorias_gasto c
 WHERE NOT (COALESCE(c.codigo,'') IN ('OPE','SER','INM','EQU')
         OR c.nombre IN ('Operación','Servicios','Inmueble','Equipo'))
   AND NOT EXISTS (SELECT 1 FROM gastos g             WHERE g.categoria_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM gastos_recurrentes r WHERE r.categoria_id = c.id);

-- ---------------------------------------------------------------------
--  4. Orden parejo de las cuatro que quedan
-- ---------------------------------------------------------------------
UPDATE categorias_gasto SET orden = 10 WHERE nombre = 'Operación';
UPDATE categorias_gasto SET orden = 20 WHERE nombre = 'Servicios';
UPDATE categorias_gasto SET orden = 30 WHERE nombre = 'Inmueble';
UPDATE categorias_gasto SET orden = 40 WHERE nombre = 'Equipo';

-- ---------------------------------------------------------------------
--  5. Después: cómo quedó
-- ---------------------------------------------------------------------
SELECT 'DESPUES' AS momento, c.id, c.nombre, c.activo,
       (SELECT COUNT(*) FROM subcategorias_gasto s WHERE s.categoria_id = c.id) AS subcategorias,
       (SELECT COUNT(*) FROM gastos g WHERE g.categoria_id = c.id)              AS gastos
  FROM categorias_gasto c
 ORDER BY c.orden, c.id;

SELECT 'Insumos que quedaron sin subcategoría' AS revisar, COUNT(*) AS n
  FROM insumos WHERE subcategoria_id IS NULL AND activo = 1;
