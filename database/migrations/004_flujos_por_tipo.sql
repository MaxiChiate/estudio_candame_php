-- Flujos por tipo de tramite.
--
-- Hasta aca habia un unico pipeline generico y el operador salteaba a mano las etapas
-- que no aplicaban. Ahora cada tramite tiene un FLUJO, elegido al crearlo, que define
-- que etapas recorre y en que orden (ver app/config/flujos.php).
--
-- Se aplica A MANO, igual que las anteriores: no hay framework de migraciones ni SSH.
--   local: mysql candame_local < database/migrations/004_flujos_por_tipo.sql
--   test:  mysql candame_test  < database/migrations/004_flujos_por_tipo.sql
--   prod:  pegar en phpMyAdmin (cPanel), sobre la base del portal
--
-- La columna `tipo` estaba deprecada (guardaba 'SAS' y nada mas: el portal dejo de
-- mostrar el tipo de sociedad en el commit 3e4d731) y se reutiliza como discriminador
-- de flujo, renombrandola.
--
-- BACKFILL: se inspecciono la base antes de escribir esto.
--   SELECT tipo, COUNT(*) FROM tramite GROUP BY tipo;
--     candame_local  -> 'SAS' x 8   (unico valor)
--     estudicn_bd_0  -> sin filas   (produccion todavia vacia)
-- 'SAS' NO significa que esos tramites sean del flujo SAS: era el DEFAULT de la columna
-- (`tipo VARCHAR(10) NOT NULL DEFAULT 'SAS'`, migracion 001) y el alta del panel nunca
-- lo dejo elegir -- de hecho el campo Estatuto se saco del alta en 41ab26d y el tipo
-- dejo de mostrarse en 3e4d731. Lo que esos tramites efectivamente recorrieron es el
-- pipeline unico y generico de antes de los flujos, que hoy es `constitucion_srl_sa`
-- (el flujo 1 es ese mismo recorrido, sin overrides). Ahi van.
--
-- Mandarlos a 'sas' los pondria en un recorrido que nunca hicieron: la SAS pasa por TAD
-- y ratificacion del gerente, y no usa TRAMITE_INICIADO sino TRAMITE_INICIADO_DIGITALMENTE,
-- asi que un tramite viejo parado en TRAMITE_INICIADO apareceria como etapa fuera de
-- flujo en vez de como la etapa normal que es.
--
-- El paso 4 corta la migracion si aparece un valor que no mapee a ningun flujo, antes
-- que meterlo en uno equivocado en silencio.

-- 1) Las etapas nuevas no entran en VARCHAR(30): 'procesando_documentacion_recibida'
--    son 33 caracteres. Se ensanchan las dos columnas de etapa ANTES de que exista un
--    tramite que las use, o el INSERT truncaria el value y Etapa::tryFrom devolveria
--    null al leerlo.
ALTER TABLE tramite        MODIFY etapa_actual VARCHAR(40) NOT NULL;
ALTER TABLE tramite_evento MODIFY etapa        VARCHAR(40) NOT NULL;

-- 2) Ensanchar `tipo` antes del backfill: los values de Flujo llegan a 31 caracteres
--    ('reforma_srl_sin_cambio_gerencia') y la columna era VARCHAR(10).
ALTER TABLE tramite MODIFY tipo VARCHAR(40) NOT NULL DEFAULT '';

-- 3) Backfill. Todo lo que hay es el 'SAS' del default, que es el pipeline generico.
UPDATE tramite SET tipo = 'constitucion_srl_sa' WHERE tipo = 'SAS';

-- 4) Guarda: si quedo alguna fila con un valor que no es un flujo valido, este UPDATE
--    intenta ponerle NULL en una columna NOT NULL y la migracion se corta ahi, con la
--    columna todavia llamada `tipo` y sin datos perdidos. Si todo mapeo bien no toca
--    ninguna fila. Es la forma portable de hacer esta verificacion: el esquema se
--    limita a lo que soportan MariaDB y MySQL 8 por igual, sin CHECK con expresiones.
--
--    OJO: corta de verdad solo con sql_mode estricto, que es el default de MySQL 8 y de
--    MariaDB 10.2+. Con el modo relajado seria un warning, asi que el SELECT de al lado
--    esta para mirarlo a ojo en phpMyAdmin: TIENE que devolver cero filas antes de
--    seguir.
SELECT id, referencia, tipo AS flujo_invalido FROM tramite
 WHERE tipo NOT IN (
         'constitucion_srl_sa', 'asoc_civil_designacion_reforma', 'art_60',
         'reforma_srl_sin_cambio_gerencia', 'reforma_srl_con_cambio_gerencia',
         'sas', 'constitucion_asoc_civil'
       );

UPDATE tramite SET tipo = NULL
 WHERE tipo NOT IN (
         'constitucion_srl_sa', 'asoc_civil_designacion_reforma', 'art_60',
         'reforma_srl_sin_cambio_gerencia', 'reforma_srl_con_cambio_gerencia',
         'sas', 'constitucion_asoc_civil'
       );

-- 5) Renombrar a `flujo` y sacarle el default: a partir de aca el flujo siempre lo
--    elige quien da de alta el tramite, no hay ninguno implicito.
ALTER TABLE tramite CHANGE tipo flujo VARCHAR(40) NOT NULL;

-- 6) `ruta_estatuto` (MODELO/LIBRE) quedo sin uso cuando se saco el campo Estatuto del
--    alta (commit 41ab26d) y ningun codigo la lee. No se borra en esta migracion: es
--    ortogonal al cambio de flujos y una columna muerta no molesta. Si se limpia algun
--    dia, va en una migracion propia.
