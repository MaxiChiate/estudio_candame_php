-- Referencia propia del estudio en lugar del codigo cargado a mano.
--
-- Hasta aca el unico identificador era `codigo`: se escribia al dar de alta, llevaba el
-- numero de expediente de IGJ y se le mostraba al cliente en el portal. Dos problemas:
-- el cliente no tiene que ver el numero de IGJ, y ese numero recien existe cuando el
-- tramite ya esta presentado, asi que al dar de alta no hay nada que cargar.
--
-- Ahora el identificador es `referencia` (EC-2026-0001), que genera el sistema al crear
-- el tramite y es lo unico que ve el cliente. El numero de IGJ NO se guarda en ningun
-- lado: la doctora lo maneja por fuera del sistema.
--
-- Se aplica A MANO, igual que las anteriores:
--   local: mysql candame_local < database/migrations/003_referencia_propia.sql
--   prod:  pegar en phpMyAdmin (cPanel), sobre la base del portal
--
-- OJO: el DROP de `codigo` borra lo que haya cargado ahi (en local, numeros de IGJ
-- escritos a mano). Es a proposito. Produccion esta vacia.

ALTER TABLE tramite
  ADD COLUMN referencia VARCHAR(20) NOT NULL DEFAULT '' AFTER id;

-- Referencia para las filas que ya existen. Se arma con el id para no depender de
-- funciones de ventana (el esquema se limita a lo que soportan MariaDB y MySQL 8 por
-- igual). No queda correlativa perfecta por año, pero es unica y estable, que es lo que
-- importa; las nuevas si salen correlativas (TramiteRepository::proximaReferencia).
UPDATE tramite
   SET referencia = CONCAT('EC-', YEAR(creado_el), '-', LPAD(id, 4, '0'))
 WHERE referencia = '';

ALTER TABLE tramite
  ADD UNIQUE KEY uq_tramite_referencia (referencia);

ALTER TABLE tramite
  DROP COLUMN codigo;
