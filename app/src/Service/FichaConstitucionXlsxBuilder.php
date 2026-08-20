<?php

declare(strict_types=1);

namespace EstudioCandame\Service;

use DateTimeImmutable;
use EstudioCandame\Model\Administrador;
use EstudioCandame\Model\ConsultaConstitucionForm;
use EstudioCandame\Model\Persona;
use EstudioCandame\Model\Sociedad;
use EstudioCandame\Model\Socio;
use EstudioCandame\Model\TipoSocietario;
use EstudioCandame\Support\Ars;
use LogicException;
use Normalizer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Arma la ficha de datos de la consulta de constitucion como .xlsx, con la misma
 * estructura de secciones y las mismas etiquetas de fila que la ficha en papel. A
 * diferencia del builder viejo (que tambien armaba el estatuto .docx), este servicio
 * solo genera la planilla -- no hay instrumento que generar.
 *
 * buildRows() devuelve las filas [etiqueta, valor] antes de tocar PhpSpreadsheet: es el
 * metodo que testea GoldenTest, para que un cambio de contenido se vea como un diff de
 * texto legible en vez de tener que abrir un Excel.
 */
final class FichaConstitucionXlsxBuilder
{
    private const COLUMNA_A_ANCHO = 34;
    private const COLUMNA_B_ANCHO = 55;

    /** @return array<int, array{0: string, 1: string}> */
    public function buildRows(ConsultaConstitucionForm $form, CapitalMinimoInfo $capitalMinimo, DateTimeImmutable $enviadoEn): array
    {
        return array_map(
            static fn (array $fila): array => [$fila['etiqueta'], $fila['valor']],
            $this->buildRowsInternas($form, $capitalMinimo, $enviadoEn),
        );
    }

    public function build(ConsultaConstitucionForm $form, CapitalMinimoInfo $capitalMinimo, DateTimeImmutable $enviadoEn): string
    {
        $filas = $this->buildRowsInternas($form, $capitalMinimo, $enviadoEn);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->getColumnDimension('A')->setWidth(self::COLUMNA_A_ANCHO);
        $sheet->getColumnDimension('B')->setWidth(self::COLUMNA_B_ANCHO);

        $rowIndex = 1;
        $this->writeMergedTitle($sheet, $rowIndex, 'ESTUDIO JURÍDICO CANDAME');
        $this->writeMergedTitle($sheet, $rowIndex, 'FICHA DE DATOS - CONSULTA DE CONSTITUCIÓN');

        foreach ($filas as $fila) {
            if ($fila['tipo'] === 'dato') {
                $this->writeRow($sheet, $rowIndex, $fila['etiqueta'], $fila['valor']);
            } else {
                $this->writeHeader($sheet, $rowIndex, $fila['etiqueta']);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $tmpFile = (string) tempnam(sys_get_temp_dir(), 'ficha-');
        $writer->save($tmpFile);
        $bytes = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $bytes !== false ? $bytes : '';
    }

    public function nombreArchivo(ConsultaConstitucionForm $form, DateTimeImmutable $enviadoEn): string
    {
        $tipo = $form->tipoSocietario?->value ?? 'sociedad';
        $slug = $this->slugify($form->sociedad->nombreOpcion1 !== '' ? $form->sociedad->nombreOpcion1 : 'sociedad');

        return sprintf('ficha-%s-%s-%s.xlsx', mb_strtolower($tipo, 'UTF-8'), $slug, $enviadoEn->format('Y-m-d'));
    }

    /** @return array<int, array{tipo: string, etiqueta: string, valor: string}> */
    private function buildRowsInternas(ConsultaConstitucionForm $form, CapitalMinimoInfo $capitalMinimo, DateTimeImmutable $enviadoEn): array
    {
        $tipo = $form->tipoSocietario ?? throw new LogicException('No se puede armar la ficha sin tipo societario validado.');

        $rows = [];
        $rows = [...$rows, ...$this->encabezadoRows($form, $enviadoEn)];
        $rows = [...$rows, ...$this->sociosRows($form->socios)];
        $rows = [...$rows, ...$this->sociedadRows($form->sociedad, $tipo)];
        $rows = [...$rows, ...$this->capitalYPorcentajesRows($form->sociedad, $form->socios, $capitalMinimo)];
        $rows = [...$rows, ...$this->administradoresRows($form->administradores, $tipo)];
        $rows[] = $this->dato('Trámite urgente', $form->sociedad->urgente ? 'Sí' : 'No');

        return $rows;
    }

    /** @return array<int, array{tipo: string, etiqueta: string, valor: string}> */
    private function encabezadoRows(ConsultaConstitucionForm $form, DateTimeImmutable $enviadoEn): array
    {
        return [
            $this->dato('Tipo societario', $form->tipoSocietario?->value ?? ''),
            $this->dato('Fecha de envío', $enviadoEn->format('d/m/Y')),
            $this->dato('Enviado por', $form->contacto->nombre),
            $this->dato('Rol', $form->contacto->rol?->etiqueta() ?? ''),
            $this->dato('Email de contacto', $form->contacto->email),
            $this->dato('Teléfono de contacto', $form->contacto->telefono),
        ];
    }

    /**
     * @param Socio[] $socios
     * @return array<int, array{tipo: string, etiqueta: string, valor: string}>
     */
    private function sociosRows(array $socios): array
    {
        $rows = [$this->header('SOCIOS – DATOS COMPLETOS')];
        foreach ($socios as $index => $socio) {
            $rows[] = $this->subheader('Socio ' . ($index + 1));
            $rows = [...$rows, ...$this->personaRows($socio->persona)];
        }

        return $rows;
    }

    /** @return array<int, array{tipo: string, etiqueta: string, valor: string}> */
    private function sociedadRows(Sociedad $sociedad, TipoSocietario $tipo): array
    {
        return [
            $this->header('SOCIEDAD'),
            $this->dato('Nombre opción 1', $sociedad->nombreOpcion1),
            $this->dato('Nombre opción 2', $sociedad->nombreOpcion2),
            $this->dato('Nombre opción 3', $sociedad->nombreOpcion3),
            $this->dato('Objeto social', $sociedad->objetoSocial),
            $this->dato('Duración', $sociedad->duracionAnios . ' años'),
            $this->dato('Cierre de ejercicio', self::fechaCierreLegible($sociedad->cierreEjercicioDia, $sociedad->cierreEjercicioMes)),
            $this->dato('Sede - Calle y número', trim($sociedad->sede->calle . ' ' . $sociedad->sede->numero)),
            $this->dato('Sede - Piso', $sociedad->sede->piso),
            $this->dato('Sede - Departamento', $sociedad->sede->depto),
            $this->dato('Sede - Jurisdicción', 'Ciudad Autónoma de Buenos Aires'),
            $this->dato('Email de la sociedad', $sociedad->emailSociedad),
            $this->dato('Teléfono de la sociedad', $sociedad->telefonoSociedad),
        ];
    }

    /**
     * @param Socio[] $socios
     * @return array<int, array{tipo: string, etiqueta: string, valor: string}>
     */
    private function capitalYPorcentajesRows(Sociedad $sociedad, array $socios, CapitalMinimoInfo $capitalMinimo): array
    {
        $rows = [
            $this->header('CAPITAL Y PORCENTAJES'),
            $this->dato('Capital social', Ars::formatEntero($sociedad->capitalSocial)),
        ];

        $aviso = $capitalMinimo->aviso((float) $sociedad->capitalSocial);
        if ($aviso !== null) {
            $rows[] = $this->dato('Aviso de capital mínimo', $aviso);
        }

        foreach ($socios as $socio) {
            $rows[] = $this->dato(
                'Porcentaje de participación - ' . $socio->persona->apellidoYNombre,
                self::porcentajeLegible($socio->porcentajeParticipacion),
            );
        }

        return $rows;
    }

    /**
     * @param Administrador[] $administradores
     * @return array<int, array{tipo: string, etiqueta: string, valor: string}>
     */
    private function administradoresRows(array $administradores, TipoSocietario $tipo): array
    {
        $rows = [$this->header($tipo->etiquetaOrgano() . ' – DATOS COMPLETOS')];
        foreach ($administradores as $index => $administrador) {
            $etiquetaCargo = $administrador->cargo?->etiqueta($tipo) ?? $tipo->etiquetaTitular();
            $rows[] = $this->subheader($etiquetaCargo . ' ' . ($index + 1));
            $rows = [...$rows, ...$this->personaRows($administrador->persona)];
        }

        return $rows;
    }

    /** @return array<int, array{tipo: string, etiqueta: string, valor: string}> */
    private function personaRows(Persona $persona): array
    {
        return [
            $this->dato('Apellido y nombre', $persona->apellidoYNombre),
            $this->dato('Nacionalidad', $persona->nacionalidad),
            $this->dato('Fecha de nacimiento', $persona->fechaNacimiento?->format('d/m/Y') ?? ''),
            $this->dato('Tipo de identificación fiscal', $persona->identificacionFiscal->tipo?->value ?? ''),
            $this->dato('Número de identificación fiscal', $persona->identificacionFiscal->numero),
            $this->dato('Estado civil', $persona->estadoCivil?->etiqueta() ?? ''),
            $this->dato('Cónyuge', $persona->conyuge),
            $this->dato('Profesión', $persona->profesion),
            $this->dato('Domicilio real - Calle y número', trim($persona->domicilioReal->calle . ' ' . $persona->domicilioReal->numero)),
            $this->dato('Domicilio real - Piso', $persona->domicilioReal->piso),
            $this->dato('Domicilio real - Departamento', $persona->domicilioReal->depto),
            $this->dato('Domicilio real - Localidad', $persona->domicilioReal->localidad),
            $this->dato('Domicilio real - Provincia', $persona->domicilioReal->provincia),
            $this->dato('Email', $persona->email),
        ];
    }

    /** @return array{tipo: string, etiqueta: string, valor: string} */
    private function dato(string $etiqueta, string $valor): array
    {
        return ['tipo' => 'dato', 'etiqueta' => $etiqueta, 'valor' => $valor];
    }

    /** @return array{tipo: string, etiqueta: string, valor: string} */
    private function header(string $etiqueta): array
    {
        return ['tipo' => 'header', 'etiqueta' => $etiqueta, 'valor' => ''];
    }

    /** @return array{tipo: string, etiqueta: string, valor: string} */
    private function subheader(string $etiqueta): array
    {
        return ['tipo' => 'subheader', 'etiqueta' => $etiqueta, 'valor' => ''];
    }

    private static function fechaCierreLegible(int $dia, int $mes): string
    {
        return sprintf('%02d/%02d', $dia, $mes);
    }

    private static function porcentajeLegible(string $porcentaje): string
    {
        return $porcentaje !== '' ? $porcentaje . '%' : '';
    }

    private function writeMergedTitle(Worksheet $sheet, int &$rowIndex, string $texto): void
    {
        $sheet->setCellValueExplicit("A$rowIndex", $texto, DataType::TYPE_STRING);
        $sheet->mergeCells("A{$rowIndex}:B{$rowIndex}");
        $sheet->getStyle("A$rowIndex")->applyFromArray(['font' => ['bold' => true, 'size' => 14]]);
        $rowIndex++;
    }

    // Sin fill de color a proposito -- el spec pide "nada de colores ni logos" en la
    // ficha, a diferencia del builder viejo que usaba un fill bordo para las secciones.
    private function writeHeader(Worksheet $sheet, int &$rowIndex, string $texto): void
    {
        $sheet->setCellValueExplicit("A$rowIndex", $texto, DataType::TYPE_STRING);
        $sheet->mergeCells("A{$rowIndex}:B{$rowIndex}");
        $sheet->getStyle("A$rowIndex")->applyFromArray(['font' => ['bold' => true]]);
        $rowIndex++;
    }

    private function writeRow(Worksheet $sheet, int &$rowIndex, string $etiqueta, string $valor): void
    {
        $sheet->setCellValueExplicit("A$rowIndex", $etiqueta, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("B$rowIndex", $valor, DataType::TYPE_STRING);
        $sheet->getStyle("A$rowIndex")->applyFromArray(['font' => ['bold' => true]]);
        $sheet->getStyle("B$rowIndex")->applyFromArray(['alignment' => ['wrapText' => true]]);
        $rowIndex++;
    }

    private function slugify(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_D);
        $normalized = preg_replace('/\p{Mn}/u', '', $normalized) ?? $normalized;
        $slug = mb_strtolower($normalized, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'sociedad';
    }
}
