---
paths:
  - "app/src/Controller/ConsultaConstitucionController.php"
  - "app/src/Model/**"
  - "app/src/Service/CapitalMinimo*.php"
  - "app/src/Service/FichaConstitucionXlsxBuilder.php"
  - "app/src/Service/ConsultaConstitucionMailer.php"
  - "app/src/Service/SmvmService.php"
  - "app/src/Support/AntiAbuso/**"
  - "app/templates/tramites/constitucion.html.twig"
  - "public/js/constitucion.js"
  - "app/config/capitales_minimos.php"
  - "tests/**"
---

# Consulta de constitución — qué es y qué NO es

## Por qué existe este archivo

El proyecto Kotlin de referencia (`../estudio_candame_web`) tiene un archivo homónimo
(`.claude/rules/configurador.md`) que describe un pipeline aspiracional mucho más
grande: planilla unificada con presupuesto y numeración `PRES-YYYY-NNNN`, generación de
Estatuto/Edicto/Dictamen, carpeta de Drive automática, portal de seguimiento del
trámite, recordatorios anuales. **Nada de eso se implementó ahí tampoco** — es diseño a
futuro sobre un proyecto que dejó de tener desarrollo activo. Este archivo existe para
que quien lea ese pipeline y después mire este repo no asuma que hay algo para portar o
retomar: acá se construyó otra cosa, más chica, a propósito.

## Qué es esto en este repo

Un formulario único (`/tramites/constitucion`, detrás de `CONFIGURADOR_ENABLED`) donde
el usuario elige tipo societario (**SAS, SRL o SA**) y completa la ficha de datos de
constitución. Al enviar:

1. Se genera un `.xlsx` con los datos, misma estructura que la ficha en papel que ya usa
   el estudio.
2. Se manda un mail a `info@estudiocandame.com.ar` con el resumen en el cuerpo y el
   `.xlsx` adjunto.
3. Se manda un acuse simple al remitente.

**Nada más.** Es una consulta, no un trámite: no se genera ningún instrumento.

## Qué se sacó a propósito (no reintroducir sin instrucción explícita)

Existió una versión anterior de este trámite, solo para SAS, que generaba un ZIP con la
planilla `.xlsx` **y** un borrador del estatuto `.docx` (`SasDocumentService`,
`SasConstitucionController`, PhpWord). Se borró completa — no quedó código muerto ni
comentado. Si en algún momento se retoma la generación de instrumentos, es una
funcionalidad nueva a diseñar con el usuario, no una reactivación de lo viejo.

Fuera de alcance, explícitamente, ahora y hasta nueva instrucción:

- Generación de estatuto, edicto, dictamen, poder especial o cualquier instrumento.
- Presupuesto, numeración de expedientes (`PRES-...`), portal de seguimiento, carpetas
  en Drive.
- Declaración de PEP, beneficiario final, autorizados del poder especial, forma de
  acreditación de la integración del 25% — son datos del instrumento, no de la consulta.
- Designación de presidente del directorio, síndico o prescindencia de sindicatura.
- Integración con IGJ, TAD o Boletín Oficial (no hay APIs públicas, dependen de sesión
  con Clave Fiscal — no inventar clientes HTTP para esto).
- Login, cuentas de usuario, guardado de borradores.
- **Prohibición dura:** el formulario no pide, no valida, no guarda y no escribe en
  ningún archivo la **clave fiscal** ni el **apellido materno** de nadie. Alcanza con el
  checkbox de "requiere trámite urgente" — la doctora junta esos datos por su cuenta
  para los trámites que realmente lo necesitan.

## Dónde vive cada cosa

- `ConsultaConstitucionController` — GET arma el form, POST valida y dispara xlsx + 2
  mails. Nunca devuelve un archivo para descargar.
- `Model/` — `TipoSocietario` (relabela órgano/titular/suplente por tipo),
  `ConsultaConstitucionForm` (agregado raíz), `Contacto`/`Sociedad`/`Socio`/
  `Administrador`/`Persona`/`Domicilio`/`IdentificacionFiscal`. `validate()` no hace
  I/O: la fecha de referencia y el capital mínimo ya resuelto entran como parámetros.
- `Validation/CuitCuilCdiValidator` y `Validation/DenominacionValidator`.
- `Service/CapitalMinimoResolver` + `app/config/capitales_minimos.php` — los tres tipos
  son sugerencias no bloqueantes (nunca traban el envío): SRL sugiere $500.000 y SA
  $1.000.000 desde `capitales_minimos.php`, SAS sugiere 2×SMVM vigente desde
  `SmvmService`.
- `Service/FichaConstitucionXlsxBuilder` — `buildRows()` (testeado por golden files) +
  el writer de PhpSpreadsheet.
- `Service/ConsultaConstitucionMailer` — los dos mails.
- `Support/AntiAbuso/` — CSRF, honeypot, timing, rate limit por IP.

## Si se retoma este trabajo

Es una implementación nueva con su propio diseño, no una continuación automática de lo
descripto en el `configurador.md` del proyecto Kotlin. Discutirlo con el usuario antes
de escribir código — empezando por si sigue siendo cierto que "no genera ningún
instrumento".
