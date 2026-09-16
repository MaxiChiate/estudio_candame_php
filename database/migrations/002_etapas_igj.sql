-- Etapas reales del pipeline de IGJ.
--
-- Las 6 etapas provisorias (DOCUMENTACION, FIRMA, PRESENTACION, INSCRIPCION, CUIT,
-- LIBROS) se reemplazan por las 12 del pipeline real, generico para todo tipo de
-- tramite. Ver app/src/Seguimiento/Etapa.php y app/config/etapas.php.
--
-- Se aplica A MANO, igual que 001: no hay framework de migraciones ni SSH.
--   local: mysql candame_local < database/migrations/002_etapas_igj.sql
--   prod:  pegar en phpMyAdmin (cPanel), sobre la base del portal
--
-- NO hay mapeo de etapas viejas a nuevas: la base de produccion esta vacia y nunca
-- corrio con datos reales. Los UPDATE de abajo son por si quedo algo cargado a mano en
-- una base local: llevan cualquier valor que no sea una etapa nueva a la inicial, en
-- vez de dejar filas con un valor que el enum ya no conoce (Tramite::desdeFila tira
-- RuntimeException con una etapa desconocida, y eventosPublicos() las descarta en
-- silencio).
--
-- No hace falta tocar el tipo de las columnas: etapa_actual y tramite_evento.etapa son
-- VARCHAR(30) y el value mas largo del enum nuevo es PROCESANDO_DOCUMENTACION, de 24
-- caracteres. Entra sin ALTER.

UPDATE tramite
   SET etapa_actual = 'REUNIENDO_DOCUMENTACION'
 WHERE etapa_actual NOT IN (
         'REUNIENDO_DOCUMENTACION', 'PROCESANDO_DOCUMENTACION', 'ESPERANDO_CONFIRMACION',
         'HABILITADO_ESCRIBANIA', 'ESPERANDO_ESCRIBANIA', 'EDICTO_PUBLICADO',
         'DICTAMENES', 'TRAMITE_INICIADO', 'VISTA', 'VISTA_CONTESTADA',
         'TERMINADO', 'PARA_RETIRAR'
       );

UPDATE tramite_evento
   SET etapa = 'REUNIENDO_DOCUMENTACION'
 WHERE etapa NOT IN (
         'REUNIENDO_DOCUMENTACION', 'PROCESANDO_DOCUMENTACION', 'ESPERANDO_CONFIRMACION',
         'HABILITADO_ESCRIBANIA', 'ESPERANDO_ESCRIBANIA', 'EDICTO_PUBLICADO',
         'DICTAMENES', 'TRAMITE_INICIADO', 'VISTA', 'VISTA_CONTESTADA',
         'TERMINADO', 'PARA_RETIRAR'
       );
