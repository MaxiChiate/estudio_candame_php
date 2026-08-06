<?php

declare(strict_types=1);

namespace EstudioCandame\Service;

use DateTimeImmutable;
use EstudioCandame\Model\ConstitucionSasForm;
use EstudioCandame\Model\ObjetoSocialCategoria;
use EstudioCandame\Model\SasAccionista;
use EstudioCandame\Model\SasAdministrador;
use EstudioCandame\Model\SasDomicilioSociedad;
use EstudioCandame\Model\SasPersona;
use EstudioCandame\Support\Ars;
use EstudioCandame\Support\NumeroALetras;
use IntlDateFormatter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Font;
use ZipArchive;

final class SasDocumentService
{
    // Introduccion y cierre fijos del articulo de objeto, tomados del modelo oficial de
    // instrumento constitutivo SAS (RG IGJ 12/2024). Solo cambia la actividad del medio,
    // sea una de las diez categorias preaprobadas o el texto especifico que redacte el
    // cliente.
    private const OBJETO_INTRO = 'La sociedad tiene por objeto dedicarse, por cuenta propia o ajena, o asociada a '
        . 'terceros, dentro o fuera del país a la creación, producción, intercambio, fabricación, transformación, '
        . 'comercialización, intermediación, representación, importación y exportación de bienes materiales, '
        . 'incluso recursos naturales, e inmateriales y la prestación de servicios, relacionados directa o '
        . 'indirectamente con las siguientes actividades:';

    private const OBJETO_CIERRE = 'La sociedad tiene plena capacidad de derecho para realizar cualquier acto '
        . 'jurídico en el país o en el extranjero, realizar toda actividad lícita, adquirir derechos y contraer '
        . 'obligaciones. Para la ejecución de las actividades enumeradas en su objeto, la sociedad puede realizar '
        . 'inversiones y aportes de capitales a personas humanas y/o jurídicas, actuar como fiduciario y celebrar '
        . 'contratos de colaboración; comprar, vender y/o permutar toda clase de títulos y valores; tomar y '
        . 'otorgar créditos y realizar toda clase de operaciones financieras, excluidas las reguladas por la Ley '
        . 'de Entidades Financieras y toda otra que requiera el concurso y/o ahorro público y todas aquellas '
        . 'actividades a las que no esté habilitada por el tipo social.';

    public function __construct(private readonly SmvmService $smvmService)
    {
    }

    public function buildZip(ConstitucionSasForm $form, string $sociedadSlug): string
    {
        $capital = $this->smvmService->obtenerCapitalMinimo();
        $planilla = $this->buildPlanilla($form, $capital);
        $estatuto = $this->buildEstatuto($form, $capital);

        $tmpFile = tempnam(sys_get_temp_dir(), 'sas-zip-');
        $zip = new ZipArchive();
        $zip->open($tmpFile, ZipArchive::OVERWRITE);
        $zip->addFromString("planilla-datos-$sociedadSlug.xlsx", $planilla);
        $zip->addFromString("estatuto-$sociedadSlug.docx", $estatuto);
        $zip->close();

        $bytes = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $bytes;
    }

    // ------------------------------------------------------------------
    // Planilla con datos (data grid, .xlsx)
    // ------------------------------------------------------------------

    public function buildPlanilla(ConstitucionSasForm $form, CapitalSasInfo $capital): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Planilla de datos');
        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(55);

        $rowIndex = 1;

        $this->writeMergedTitle($sheet, $rowIndex, 'ESTUDIO JURÍDICO CANDAME', $this->titleStyle());
        $this->writeMergedTitle($sheet, $rowIndex, 'FICHA DE DATOS - CONSTITUCIÓN SAS', $this->labelStyle());
        $rowIndex++;

        foreach ($form->accionistas as $index => $accionista) {
            $this->writeSection($sheet, $rowIndex, 'ACCIONISTA ' . ($index + 1));
            $this->writePersonaRows($sheet, $rowIndex, $accionista->persona);
            $this->writeRow($sheet, $rowIndex, 'Porcentaje de participación', $accionista->porcentajeParticipacion);
            $aporte = $this->calcularAporte($capital->capital, $accionista->porcentajeParticipacion);
            $this->writeRow($sheet, $rowIndex, 'Aporte de capital', Ars::format($aporte));
            $rowIndex++;
        }

        $this->writeSection($sheet, $rowIndex, 'SOCIEDAD');
        $this->writeRow($sheet, $rowIndex, 'Nombre opción 1', $form->nombreOpcion1);
        $this->writeRow($sheet, $rowIndex, 'Nombre opción 2', $form->nombreOpcion2);
        $this->writeRow($sheet, $rowIndex, 'Nombre opción 3', $form->nombreOpcion3);
        $this->writeRow($sheet, $rowIndex, 'Tipo de objeto social', $this->tipoObjetoLabel($form->tipoObjeto));
        $this->writeRow($sheet, $rowIndex, 'Objeto social', $this->objetoTexto($form));
        $fuente = $capital->fuenteEnVivo ? '' : ', valor de respaldo';
        $this->writeRow($sheet, $rowIndex, 'SMVM vigente considerado', Ars::format($capital->smvm) . " ($capital->fechaSmvm$fuente)");
        $this->writeRow($sheet, $rowIndex, "Capital social ($capital->multiplo x SMVM)", Ars::format($capital->capital));
        $this->writeRow($sheet, $rowIndex, 'Cierre del ejercicio', $this->cierreEjercicioTexto($form->cierreEjercicioMes));
        $this->writeDomicilioSociedadRows($sheet, $rowIndex, $form->domicilio);
        $this->writeRow($sheet, $rowIndex, 'Duración de la sociedad (años)', (string) $form->duracionSociedad);
        $this->writeRow($sheet, $rowIndex, 'Email de la sociedad', $form->emailSociedad);
        $this->writeRow($sheet, $rowIndex, 'Teléfono', $form->telefonoSociedad);
        $rowIndex++;

        foreach ($form->administradores as $index => $administrador) {
            $this->writeSection($sheet, $rowIndex, 'ADMINISTRADOR ' . ($index + 1) . " ($administrador->cargo)");
            $this->writePersonaRows($sheet, $rowIndex, $administrador->persona);
            $rowIndex++;
        }

        $writer = new Xlsx($spreadsheet);
        $tmpFile = tempnam(sys_get_temp_dir(), 'sas-xlsx-');
        $writer->save($tmpFile);
        $bytes = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $bytes;
    }

    private function writeMergedTitle(Worksheet $sheet, int &$rowIndex, string $text, array $style): void
    {
        $sheet->setCellValue("A$rowIndex", $text);
        $sheet->mergeCells("A{$rowIndex}:B{$rowIndex}");
        $sheet->getStyle("A{$rowIndex}:B{$rowIndex}")->applyFromArray($style);
        $rowIndex++;
    }

    private function writeSection(Worksheet $sheet, int &$rowIndex, string $text): void
    {
        $sheet->setCellValue("A$rowIndex", $text);
        $sheet->mergeCells("A{$rowIndex}:B{$rowIndex}");
        $sheet->getStyle("A{$rowIndex}:B{$rowIndex}")->applyFromArray($this->sectionStyle());
        $rowIndex++;
    }

    private function writeRow(Worksheet $sheet, int &$rowIndex, string $label, string $value): void
    {
        $sheet->setCellValueExplicit("A$rowIndex", $label, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("B$rowIndex", $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle("A$rowIndex")->applyFromArray($this->labelStyle());
        $sheet->getStyle("B$rowIndex")->applyFromArray($this->valueStyle());
        $rowIndex++;
    }

    private function writePersonaRows(Worksheet $sheet, int &$rowIndex, SasPersona $persona): void
    {
        $this->writeRow($sheet, $rowIndex, 'Tratamiento', $persona->tratamiento);
        $this->writeRow($sheet, $rowIndex, 'Apellido y nombre', $persona->nombreCompleto);
        $this->writeRow($sheet, $rowIndex, 'Nacionalidad', $persona->nacionalidad);
        $this->writeRow($sheet, $rowIndex, 'Fecha de nacimiento', $persona->fechaNacimiento?->format('d/m/Y') ?? '');
        $this->writeRow($sheet, $rowIndex, 'Tipo de documento', $persona->tipoDocumento);
        $this->writeRow($sheet, $rowIndex, 'Número de documento', $persona->numeroDocumento);
        $this->writeRow($sheet, $rowIndex, 'CUIT / CUIL', $persona->cuitCuil);
        $this->writeRow($sheet, $rowIndex, 'Estado civil', $persona->estadoCivil);
        $this->writeRow($sheet, $rowIndex, 'Cónyuge', $persona->conyuge);
        $this->writeRow($sheet, $rowIndex, 'Profesión', $persona->profesion);
        $this->writeRow($sheet, $rowIndex, 'Domicilio real - Calle', $persona->domicilio->calle);
        $this->writeRow($sheet, $rowIndex, 'Domicilio real - Altura', $persona->domicilio->altura);
        $this->writeRow($sheet, $rowIndex, 'Domicilio real - Piso', $persona->domicilio->piso);
        $this->writeRow($sheet, $rowIndex, 'Domicilio real - Depto', $persona->domicilio->departamento);
        $this->writeRow($sheet, $rowIndex, 'Domicilio real - Cuerpo', $persona->domicilio->cuerpo);
        $this->writeRow($sheet, $rowIndex, 'Domicilio real - Localidad', $persona->domicilio->localidad);
        $this->writeRow($sheet, $rowIndex, 'Domicilio real - Provincia', $persona->domicilio->provincia);
        $this->writeRow($sheet, $rowIndex, 'Correo electrónico', $persona->email);
    }

    private function writeDomicilioSociedadRows(Worksheet $sheet, int &$rowIndex, SasDomicilioSociedad $domicilio): void
    {
        $this->writeRow($sheet, $rowIndex, 'Sede social - Calle', $domicilio->calle);
        $this->writeRow($sheet, $rowIndex, 'Sede social - Altura', $domicilio->altura);
        $this->writeRow($sheet, $rowIndex, 'Sede social - Piso', $domicilio->piso);
        $this->writeRow($sheet, $rowIndex, 'Sede social - Depto', $domicilio->departamento);
        $this->writeRow($sheet, $rowIndex, 'Sede social - Cuerpo', $domicilio->cuerpo);
        $this->writeRow($sheet, $rowIndex, 'Sede social - Jurisdicción', 'Ciudad Autónoma de Buenos Aires');
    }

    private function titleStyle(): array
    {
        return ['font' => ['bold' => true, 'size' => 14]];
    }

    private function sectionStyle(): array
    {
        return [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '8B0000']],
        ];
    }

    private function labelStyle(): array
    {
        return ['font' => ['bold' => true], 'alignment' => ['wrapText' => false]];
    }

    private function valueStyle(): array
    {
        return ['alignment' => ['wrapText' => true]];
    }

    // ------------------------------------------------------------------
    // Estatuto (instrumento constitutivo SAS, .docx)
    // ------------------------------------------------------------------

    public function buildEstatuto(ConstitucionSasForm $form, CapitalSasInfo $capital): string
    {
        $nombreSociedad = mb_strtoupper(trim($form->nombreOpcion1) !== '' ? trim($form->nombreOpcion1) : '[NOMBRE A DEFINIR]', 'UTF-8');
        $razonSocial = "$nombreSociedad SOCIEDAD POR ACCIONES SIMPLIFICADA";

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $this->addTitle($section, $razonSocial);
        $this->addSubtitle($section, 'CONSTITUCION');

        $hoy = new DateTimeImmutable('now');
        $mesFormatter = new IntlDateFormatter('es_AR', IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'America/Argentina/Buenos_Aires', IntlDateFormatter::GREGORIAN, 'MMMM');
        $diaMes = $mesFormatter->format($hoy);

        $this->addBodyParagraph(
            $section,
            'En la Ciudad Autónoma de Buenos Aires, a los ' . (int) $hoy->format('j') . ' días del mes de '
            . "$diaMes de " . $hoy->format('Y') . ', se reúnen ' . $this->describeAccionistas($form->accionistas) . ', y dicen:',
        );
        $this->addBodyParagraph(
            $section,
            'Que los presentes resuelven constituir una SOCIEDAD POR ACCIONES SIMPLIFICADA, que se '
            . 'regirá por las cláusulas que se indican a continuación y, en lo pertinente, por la Ley '
            . 'N° 27.349 y sus normas reglamentarias:',
        );

        $this->addClause(
            $section,
            'PRIMERA',
            'Denominación y domicilio',
            "La sociedad se denomina \"$razonSocial\" y tiene su domicilio legal en jurisdicción de la "
            . 'Ciudad Autónoma de Buenos Aires, sin perjuicio de las sucursales, agencias y '
            . 'representaciones que pudieran establecerse dentro o fuera del país.',
        );
        $this->addClause(
            $section,
            'SEGUNDA',
            'Plazo de duración',
            "Su plazo de duración es de $form->duracionSociedad años, contados a partir de la fecha "
            . 'de su inscripción ante la Inspección General de Justicia.',
        );
        $this->addClause($section, 'TERCERA', 'Objeto social', $this->objetoTexto($form));
        $this->addClause(
            $section,
            'CUARTA',
            'Capital social',
            'El capital social se fija en la suma de ' . NumeroALetras::formatMonedaConLetras($capital->capital) . ', '
            . "equivalente a $capital->multiplo (" . $this->numeroEnLetrasCorto($capital->multiplo) . ') veces el Salario '
            . 'Mínimo, Vital y Móvil vigente a la fecha de este instrumento (art. 40, Ley N° 27.349), '
            . 'representado por acciones ordinarias, nominativas, no endosables, de un voto por acción, '
            . 'las que se encuentran totalmente suscriptas por los socios según el siguiente detalle: '
            . $this->describeCapital($form->accionistas, $capital->capital) . '. Las acciones se integran en un 25% '
            . 'en efectivo en este acto, obligándose los socios a integrar el saldo restante dentro del '
            . 'plazo máximo de dos años, contados desde la fecha de inscripción de la sociedad.',
        );
        $this->addClause(
            $section,
            'QUINTA',
            'Mora en la integración',
            'La mora en la integración de las acciones suscriptas se producirá al solo vencimiento del '
            . 'plazo. La sociedad podrá optar por cualquiera de las alternativas previstas en el '
            . 'artículo 193 de la Ley General de Sociedades N° 19.550.',
        );
        $this->addClause(
            $section,
            'SEXTA',
            'Transferencia de las acciones',
            'La transferencia de las acciones es libre, debiendo comunicarse la misma a la sociedad.',
        );
        $this->addClause(
            $section,
            'SÉPTIMA',
            'Administración y representación',
            'La administración y representación legal de la sociedad estará a cargo de una o más '
            . 'personas humanas, socias o no, quienes actuarán en forma individual e indistinta por '
            . 'el plazo que dure la sociedad, siendo reelegibles. Se designa como administrador/es de '
            . 'la sociedad a: ' . $this->describeAdministradores($form->administradores) . '.',
        );
        $this->addClause(
            $section,
            'OCTAVA',
            'Órgano de gobierno',
            'Las resoluciones de los socios que no importen modificación del instrumento constitutivo '
            . 'podrán adoptarse por el procedimiento de consulta simultánea o por declaración escrita '
            . 'en la que todos los socios expresen el sentido de su voto, conforme lo previsto en el '
            . 'artículo 53 de la Ley N° 27.349. Las resoluciones que impliquen reforma del instrumento '
            . 'constitutivo se adoptarán por mayoría absoluta de capital.',
        );
        $this->addClause(
            $section,
            'NOVENA',
            'Fiscalización',
            'La sociedad prescinde del órgano de fiscalización, conforme lo dispuesto por el artículo '
            . '51 de la Ley N° 27.349, por lo que los socios poseen las facultades de contralor '
            . 'previstas en el artículo 55 de la Ley General de Sociedades N° 19.550.',
        );
        $this->addClause(
            $section,
            'DÉCIMA',
            'Ejercicio social',
            'El ejercicio social cierra el ' . $this->cierreEjercicioTexto($form->cierreEjercicioMes) . ' de cada año. '
            . 'A dicha fecha se confeccionarán los estados contables conforme las normas vigentes.',
        );
        $this->addClause(
            $section,
            'DÉCIMO PRIMERA',
            'Utilidades, reservas y distribución',
            'De las utilidades líquidas y realizadas se destinarán: (a) el cinco por ciento (5%) a la '
            . 'reserva legal, hasta alcanzar el veinte por ciento (20%) del capital social; (b) el '
            . 'importe que se establezca para retribución de los administradores y síndicos, en su '
            . 'caso; (c) al pago de dividendos a las acciones preferidas en su caso; y (d) el '
            . 'remanente, previa deducción de cualquier otra reserva que los socios dispusieran '
            . 'constituir, se distribuirá entre los mismos en proporción a su participación en el '
            . 'capital social, respetando, en su caso, los derechos de las acciones preferidas.',
        );
        $this->addClause(
            $section,
            'DÉCIMO SEGUNDA',
            'Disolución y liquidación',
            'La sociedad se disuelve por las causales previstas en el artículo 94 de la Ley General de '
            . 'Sociedades N° 19.550 y en la Ley N° 27.349. La liquidación estará a cargo del órgano de '
            . 'administración o de la persona que a tal efecto designen los socios.',
        );
        $this->addClause(
            $section,
            'DÉCIMO TERCERA',
            'Solución de controversias',
            'Cualquier reclamo, diferencia, conflicto o controversia que se suscite entre la sociedad, '
            . 'los socios, sus administradores y, en su caso, los miembros del órgano de fiscalización, '
            . 'cualquiera sea su naturaleza, quedará sometido a la jurisdicción de los tribunales '
            . 'ordinarios con competencia en materia comercial con sede en la Ciudad Autónoma de '
            . 'Buenos Aires.',
        );
        $this->addClause(
            $section,
            'DÉCIMO CUARTA',
            'Sede social',
            'Se establece la sede social en ' . $form->domicilio->direccionCompleta() . '. El representante legal '
            . 'declara bajo juramento que en la sede social indicada funcionará efectivamente el centro '
            . 'principal de la dirección y administración de las actividades de la entidad.',
        );
        $this->addClause(
            $section,
            'DÉCIMO QUINTA',
            'Autorizaciones',
            'Se autoriza a la Dra. María Alejandra Candame y/o a quien ésta autorice, para que, en '
            . 'forma indistinta, realice todos los trámites destinados a la inscripción del presente '
            . 'instrumento ante la Inspección General de Justicia, con facultades para aceptar '
            . 'modificaciones a su texto, contestar vistas y otorgar los instrumentos que fueran '
            . 'necesarios a los fines de la inscripción.',
        );

        $this->addBodyParagraph(
            $section,
            'En prueba de conformidad, se firma el presente instrumento en la Ciudad Autónoma de '
            . 'Buenos Aires, en la fecha de su otorgamiento.',
        );
        $this->addSignatureBlock($section, array_map(
            static fn (SasAccionista $accionista): string => $accionista->persona->nombreCompleto,
            $form->accionistas,
        ));

        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
        $tmpFile = tempnam(sys_get_temp_dir(), 'sas-docx-');
        $writer->save($tmpFile);
        $bytes = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $bytes;
    }

    private function tipoObjetoLabel(string $tipoObjeto): string
    {
        if ($tipoObjeto === 'ESPECIFICO') {
            return 'Específico (redactado a medida)';
        }
        $categoria = ObjetoSocialCategoria::fromName($tipoObjeto);

        return $categoria !== null ? "Preaprobado IGJ: {$categoria->texto()}" : $tipoObjeto;
    }

    private function actividadElegida(ConstitucionSasForm $form): string
    {
        if ($form->tipoObjeto === 'ESPECIFICO') {
            return trim($form->objetoSocial);
        }
        $categoria = ObjetoSocialCategoria::fromName($form->tipoObjeto);

        return $categoria?->texto() ?? trim($form->objetoSocial);
    }

    private function objetoTexto(ConstitucionSasForm $form): string
    {
        return self::OBJETO_INTRO . ' ' . $this->actividadElegida($form) . '. ' . self::OBJETO_CIERRE;
    }

    private function cierreEjercicioTexto(string $mes): string
    {
        return $mes === 'JUNIO' ? '30 de junio' : '31 de diciembre';
    }

    private function numeroEnLetrasCorto(int $valor): string
    {
        return match ($valor) {
            1 => 'uno',
            2 => 'dos',
            3 => 'tres',
            4 => 'cuatro',
            5 => 'cinco',
            default => (string) $valor,
        };
    }

    /** @param SasAccionista[] $accionistas */
    private function describeAccionistas(array $accionistas): string
    {
        $descripciones = array_map(
            fn (SasAccionista $accionista): string => $this->articuloYTratamiento($accionista->persona) . ' ' . $this->describePersona($accionista->persona),
            $accionistas,
        );

        return $this->joinWithY($descripciones);
    }

    private function articuloYTratamiento(SasPersona $persona): string
    {
        return mb_strtolower($persona->tratamiento, 'UTF-8') === 'señora' ? 'la señora' : 'el señor';
    }

    /** @param SasAccionista[] $accionistas */
    private function describeCapital(array $accionistas, float $capitalTotal): string
    {
        // Cada accion escritural vale $1 nominal, asi que la cantidad de acciones
        // suscriptas coincide numericamente con el aporte en pesos (redondeado al peso).
        $detalles = array_map(function (SasAccionista $accionista) use ($capitalTotal): string {
            $aporte = $this->calcularAporte($capitalTotal, $accionista->porcentajeParticipacion);
            $cantidadAcciones = Ars::formatEntero(round($aporte, 0, PHP_ROUND_HALF_UP));

            return $accionista->persona->nombreCompleto . " ($accionista->porcentajeParticipacion%) suscribe la cantidad de "
                . "$cantidadAcciones acciones ordinarias escriturales, "
                . 'de un peso valor nominal cada una (aporte $ ' . Ars::format($aporte) . ')';
        }, $accionistas);

        return implode('; ', $detalles);
    }

    private function parsePorcentaje(string $valor): float
    {
        $normalizado = str_replace(',', '.', trim($valor));

        return is_numeric($normalizado) ? (float) $normalizado : 0.0;
    }

    private function calcularAporte(float $capitalTotal, string $porcentajeParticipacion): float
    {
        $porcentaje = $this->parsePorcentaje($porcentajeParticipacion);

        return round($capitalTotal * $porcentaje / 100, 2, PHP_ROUND_HALF_UP);
    }

    /** @param SasAdministrador[] $administradores */
    private function describeAdministradores(array $administradores): string
    {
        $detalles = array_map(
            fn (SasAdministrador $administrador): string => $administrador->persona->nombreCompleto . ', '
                . $this->describeDocumento($administrador->persona) . " ($administrador->cargo)",
            $administradores,
        );

        return $this->joinWithY($detalles);
    }

    private function describeDocumento(SasPersona $persona): string
    {
        return "$persona->tipoDocumento N° $persona->numeroDocumento";
    }

    // Los adjetivos que usa el estatuto (nacido/a, estado civil) se guardan en el
    // formulario en su forma masculina y se generizan aca segun el tratamiento
    // elegido, para no tener que dejar formas dobles tipo "nacido/a" en el texto final.
    private function generizar(string $base, SasPersona $persona): string
    {
        if (mb_strtolower($persona->tratamiento, 'UTF-8') === 'señora' && str_ends_with($base, 'o')) {
            return mb_substr($base, 0, -1, 'UTF-8') . 'a';
        }

        return $base;
    }

    private function describePersona(SasPersona $persona): string
    {
        $fecha = $persona->fechaNacimiento?->format('d/m/Y') ?? 'sin dato';
        $conyugeClause = $persona->conyuge !== '' ? ", cónyuge $persona->conyuge" : '';

        return "$persona->nombreCompleto, " . $this->describeDocumento($persona) . ", CUIT/CUIL $persona->cuitCuil, "
            . $this->generizar('nacido', $persona) . " el $fecha, de nacionalidad $persona->nacionalidad, estado civil "
            . $this->generizar($persona->estadoCivil, $persona) . "$conyugeClause, de profesión $persona->profesion, "
            . 'con domicilio real en ' . $persona->domicilio->direccionCompleta();
    }

    /** @param string[] $items */
    private function joinWithY(array $items): string
    {
        if ($items === []) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $ultimo = array_pop($items);

        return implode(', ', $items) . ' y ' . $ultimo;
    }

    private function addTitle(Section $section, string $text): void
    {
        $paragraph = $section->addTextRun(['alignment' => Jc::CENTER]);
        $paragraph->addText($text, ['bold' => true, 'size' => 14]);
    }

    private function addSubtitle(Section $section, string $text): void
    {
        $paragraph = $section->addTextRun(['alignment' => Jc::CENTER]);
        $paragraph->addText($text, ['bold' => true, 'size' => 12]);
    }

    private function addBodyParagraph(Section $section, string $text): void
    {
        $paragraph = $section->addTextRun(['alignment' => Jc::BOTH, 'spaceAfter' => 200]);
        $paragraph->addText($text, ['size' => 11]);
    }

    private function addClause(Section $section, string $ordinal, string $title, string $text): void
    {
        $paragraph = $section->addTextRun(['alignment' => Jc::BOTH, 'spaceAfter' => 200]);
        $paragraph->addText("$ordinal ($title): ", ['bold' => true, 'underline' => Font::UNDERLINE_SINGLE, 'size' => 11]);
        $paragraph->addText($text, ['size' => 11]);
    }

    /** @param string[] $nombres */
    private function addSignatureBlock(Section $section, array $nombres): void
    {
        $section->addTextBreak(2);
        if ($nombres === []) {
            return;
        }

        // Cada firma va en su propia celda de tabla (sin bordes) para que nunca se
        // superpongan entre si, en vez de simularlas con tabs sobre un unico parrafo.
        $columnas = min(count($nombres), 3);
        $filas = array_chunk($nombres, $columnas);

        $table = $section->addTable([
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'cellMargin' => 80,
        ]);
        $anchoColumna = (int) (9000 / $columnas);

        foreach ($filas as $nombresFila) {
            $table->addRow();
            for ($columna = 0; $columna < $columnas; $columna++) {
                $cell = $table->addCell($anchoColumna);
                $nombre = $nombresFila[$columna] ?? null;
                if ($nombre === null) {
                    continue;
                }
                $textRun = $cell->addTextRun(['alignment' => Jc::CENTER]);
                $textRun->addText('_______________________', ['size' => 11]);
                $textRun->addTextBreak();
                $textRun->addText($nombre, ['size' => 11]);
            }
        }
    }
}
