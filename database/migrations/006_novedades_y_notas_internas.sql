-- Novedades y notas internas, separadas de los cambios de etapa.
--
-- Hasta aca toda nota viajaba pegada a un evento de tramite_evento. Para publicarle algo
-- al cliente sin avanzar, el panel obligaba a "registrar" la etapa en la que el tramite
-- ya estaba, y eso creaba un evento nuevo como si fuera un cambio de etapa: el cliente
-- veia "16/10/2026 -- Tramite con vista" repetido en Novedades, la fecha de "Etapa
-- actual desde el..." se corria a la de la nota, y en VISTA el contador pasaba a decir
-- "2a vista" con una sola vista.
--
-- Ahora:
--   tramite_evento        sigue siendo SOLO cambios de etapa. Su nota_publica es el
--                         mensaje que viajo con ese cambio.
--   tramite_novedad       mensaje al cliente SIN cambio de etapa. `etapa` es la vigente
--                         al publicarla: referencia, no transicion.
--   tramite_nota_interna  nota de la doctora, nunca sale del panel. Tabla aparte (y no
--                         una columna "publica" en tramite_novedad) a proposito: la
--                         consulta del portal publico nunca toca esta tabla, asi que no
--                         hay WHERE que olvidarse.
--
-- Se aplica A MANO, igual que las anteriores: no hay framework de migraciones ni SSH.
--   local: mysql candame_local < database/migrations/006_novedades_y_notas_internas.sql
--   test:  mysql candame_test  < database/migrations/006_novedades_y_notas_internas.sql
--   prod:  pegar en phpMyAdmin (cPanel), sobre la base del portal. ANTES de subir el
--          codigo nuevo: el codigo viejo anda igual con estas tablas, el nuevo no anda
--          sin ellas.
--
-- No es re-ejecutable: si se corre dos veces, el primer CREATE TABLE falla y phpMyAdmin
-- corta ahi, antes de convertir nada de nuevo.

-- 1) Tablas nuevas.
CREATE TABLE tramite_novedad (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tramite_id   INT UNSIGNED NOT NULL,
  etapa        VARCHAR(40)  NOT NULL,              -- vigente al publicar; NO es una transicion
  publicado_el DATETIME     NOT NULL,
  texto        TEXT         NOT NULL,              -- publico, lo ve el cliente
  CONSTRAINT fk_novedad_tramite FOREIGN KEY (tramite_id)
    REFERENCES tramite(id) ON DELETE CASCADE,
  INDEX idx_novedad_tramite (tramite_id, publicado_el)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tramite_nota_interna (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tramite_id  INT UNSIGNED NOT NULL,
  etapa       VARCHAR(40)  NOT NULL,               -- vigente al escribirla
  escrita_el  DATETIME     NOT NULL,
  texto       TEXT         NOT NULL,               -- NUNCA se expone en vistas publicas
  CONSTRAINT fk_nota_interna_tramite FOREIGN KEY (tramite_id)
    REFERENCES tramite(id) ON DELETE CASCADE,
  INDEX idx_nota_interna_tramite (tramite_id, escrita_el)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Eventos "a la misma etapa": los que repiten la etapa del evento inmediatamente
--    anterior del mismo tramite (por fecha, y por id dentro de la misma fecha). Esos no
--    fueron un cambio de etapa sino la unica forma que habia de publicar una nota.
--
--    Se juntan en una tabla auxiliar porque MySQL no deja borrar de tramite_evento con
--    una subconsulta sobre tramite_evento (error 1093), y sin funciones de ventana: el
--    esquema se limita a lo que soportan MariaDB y MySQL 8 por igual.
--
--    Antes de correr todo, se puede mirar que va a convertir con este SELECT (es el
--    mismo criterio del INSERT de abajo):
--
--      SELECT e.id, t.referencia, e.etapa, e.ocurrido_el, e.nota_publica, e.nota_interna
--        FROM tramite_evento e JOIN tramite t ON t.id = e.tramite_id
--       WHERE e.etapa = (SELECT p.etapa FROM tramite_evento p
--                         WHERE p.tramite_id = e.tramite_id
--                           AND (p.ocurrido_el < e.ocurrido_el
--                                OR (p.ocurrido_el = e.ocurrido_el AND p.id < e.id))
--                         ORDER BY p.ocurrido_el DESC, p.id DESC LIMIT 1);
CREATE TABLE migracion_006_repetidos (
  evento_id INT UNSIGNED PRIMARY KEY
) ENGINE=InnoDB;

INSERT INTO migracion_006_repetidos (evento_id)
SELECT e.id
  FROM tramite_evento e
 WHERE e.etapa = (SELECT p.etapa FROM tramite_evento p
                   WHERE p.tramite_id = e.tramite_id
                     AND (p.ocurrido_el < e.ocurrido_el
                          OR (p.ocurrido_el = e.ocurrido_el AND p.id < e.id))
                   ORDER BY p.ocurrido_el DESC, p.id DESC LIMIT 1)
   AND ((e.nota_publica IS NOT NULL AND e.nota_publica <> '')
        OR (e.nota_interna IS NOT NULL AND e.nota_interna <> ''));

-- 3) Las notas de esos eventos pasan a novedad / nota interna, con la misma fecha y el
--    mismo texto. Un evento con las dos notas genera una de cada.
INSERT INTO tramite_novedad (tramite_id, etapa, publicado_el, texto)
SELECT e.tramite_id, e.etapa, e.ocurrido_el, e.nota_publica
  FROM tramite_evento e JOIN migracion_006_repetidos r ON r.evento_id = e.id
 WHERE e.nota_publica IS NOT NULL AND e.nota_publica <> '';

INSERT INTO tramite_nota_interna (tramite_id, etapa, escrita_el, texto)
SELECT e.tramite_id, e.etapa, e.ocurrido_el, e.nota_interna
  FROM tramite_evento e JOIN migracion_006_repetidos r ON r.evento_id = e.id
 WHERE e.nota_interna IS NOT NULL AND e.nota_interna <> '';

-- 4) Y los eventos convertidos se borran: sus notas ya estan en las tablas nuevas, y
--    dejarlos duplicaria el texto en Novedades. etapa_actual no se toca: el evento
--    anterior, de la misma etapa, sigue ahi.
DELETE e FROM tramite_evento e JOIN migracion_006_repetidos r ON r.evento_id = e.id;

DROP TABLE migracion_006_repetidos;

-- 5) OPCIONAL, aparte: eventos repetidos SIN ninguna nota (dobles clicks en "Registrar").
--    La migracion NO los borra. No tienen nada que convertir, pero siguen contando como
--    una vez mas por la etapa (en VISTA, el "2a vista" de mas) y corren la fecha de
--    "Etapa actual desde el...". Para verlos, el SELECT del paso 2 sin el filtro de
--    notas. Para borrarlos, pegar esto en phpMyAdmin DESPUES de la migracion:
--
--      CREATE TABLE migracion_006_vacios (evento_id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
--      INSERT INTO migracion_006_vacios (evento_id)
--      SELECT e.id FROM tramite_evento e
--       WHERE e.etapa = (SELECT p.etapa FROM tramite_evento p
--                         WHERE p.tramite_id = e.tramite_id
--                           AND (p.ocurrido_el < e.ocurrido_el
--                                OR (p.ocurrido_el = e.ocurrido_el AND p.id < e.id))
--                         ORDER BY p.ocurrido_el DESC, p.id DESC LIMIT 1)
--         AND (e.nota_publica IS NULL OR e.nota_publica = '')
--         AND (e.nota_interna IS NULL OR e.nota_interna = '');
--      SELECT COUNT(*) AS a_borrar FROM migracion_006_vacios;
--      DELETE e FROM tramite_evento e JOIN migracion_006_vacios v ON v.evento_id = e.id;
--      DROP TABLE migracion_006_vacios;
