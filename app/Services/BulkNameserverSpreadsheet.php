<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

final class BulkNameserverSpreadsheet
{
    /** @return list<array{domain: string, nameservers: list<string>}> */
    public function read(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is not a valid Excel workbook.']);
        }

        try {
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw ValidationException::withMessages(['file' => 'The workbook does not contain a readable first worksheet.']);
            }
            $sharedStrings = $this->sharedStrings($zip);
            $sheet = $this->xml($sheetXml);
            $rows = $sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'The worksheet is empty.']);
            }

            $header = $this->rowValues(array_shift($rows), $sharedStrings);
            $columns = $this->headerColumns($header);
            $records = [];
            $seen = [];
            foreach ($rows as $rowNumber => $row) {
                $values = $this->rowValues($row, $sharedStrings);
                $domainValue = trim($values[$columns['domain']] ?? '');
                $ns1 = trim($values[$columns['nameserver1']] ?? '');
                $ns2 = trim($values[$columns['nameserver2']] ?? '');
                if ($domainValue === '' && $ns1 === '' && $ns2 === '') {
                    continue;
                }

                $excelRow = $rowNumber + 2;
                try {
                    $domain = NameserverSet::domain($domainValue);
                    $nameservers = NameserverSet::normalize([$ns1, $ns2]);
                } catch (\Throwable $exception) {
                    throw ValidationException::withMessages(['file' => "Invalid data on Excel row {$excelRow}: {$exception->getMessage()}"]);
                }
                if (isset($seen[$domain])) {
                    throw ValidationException::withMessages(['file' => "Domain {$domain} appears more than once in the workbook."]);
                }
                $seen[$domain] = true;
                $records[] = ['domain' => $domain, 'nameservers' => $nameservers];
                if (count($records) > 100) {
                    throw ValidationException::withMessages(['file' => 'A bulk change is limited to 100 domains.']);
                }
            }

            if ($records === []) {
                throw ValidationException::withMessages(['file' => 'Add at least one domain row to the workbook.']);
            }

            return $records;
        } finally {
            $zip->close();
        }
    }

    public function template(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nameshift-xlsx-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create the Excel template.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create the Excel template.');
        }

        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => $this->rootRelationships(),
            'docProps/app.xml' => $this->appProperties(),
            'docProps/core.xml' => $this->coreProperties(),
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationships(),
            'xl/styles.xml' => $this->styles(),
            'xl/worksheets/sheet1.xml' => $this->dataSheet(),
            'xl/worksheets/sheet2.xml' => $this->instructionsSheet(),
        ];
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, str_replace('\\"', '"', $contents));
        }
        $zip->close();

        try {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read the Excel template.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/sharedStrings.xml');
        if ($contents === false) {
            return [];
        }
        $xml = $this->xml($contents);

        return array_map(
            fn ($item) => implode('', array_map(fn ($text) => (string) $text, $item->xpath('.//*[local-name()="t"]') ?: [])),
            $xml->xpath('//*[local-name()="si"]') ?: [],
        );
    }

    /** @param array<int, string> $sharedStrings
     * @return array<string, string>
     */
    private function rowValues(SimpleXMLElement $row, array $sharedStrings): array
    {
        $values = [];
        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
            preg_match('/^[A-Z]+/', (string) $cell['r'], $matches);
            $column = $matches[0] ?? '';
            $type = (string) $cell['t'];
            if ($type === 'inlineStr') {
                $value = implode('', array_map(fn ($text) => (string) $text, $cell->xpath('.//*[local-name()="t"]') ?: []));
            } else {
                $valueNode = ($cell->xpath('./*[local-name()="v"]') ?: [null])[0];
                $value = $valueNode === null ? '' : (string) $valueNode;
                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                }
            }
            $values[$column] = $value;
        }

        return $values;
    }

    /** @param array<string, string> $header
     * @return array{domain: string, nameserver1: string, nameserver2: string}
     */
    private function headerColumns(array $header): array
    {
        $aliases = [
            'domain' => ['domain', 'domainname', 'namadomain'],
            'nameserver1' => ['nameserver1', 'ns1'],
            'nameserver2' => ['nameserver2', 'ns2'],
        ];
        $columns = [];
        foreach ($header as $column => $value) {
            $normalized = preg_replace('/[^a-z0-9]/', '', strtolower(trim($value)));
            foreach ($aliases as $name => $valid) {
                if (in_array($normalized, $valid, true)) {
                    $columns[$name] = $column;
                }
            }
        }
        foreach (array_keys($aliases) as $required) {
            if (! isset($columns[$required])) {
                throw ValidationException::withMessages(['file' => 'The first row must contain: domain, nameserver1, nameserver2.']);
            }
        }

        return $columns;
    }

    private function xml(string $contents): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw ValidationException::withMessages(['file' => 'The workbook contains invalid XML.']);
        }

        return $xml;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Bulk Update" sheetId="1" r:id="rId1"/><sheet name="Instructions" sheetId="2" r:id="rId2"/></sheets></workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function dataSheet(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:C101"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="34" customWidth="1"/><col min="2" max="3" width="34" customWidth="1"/></cols><sheetData><row r="1" ht="24" customHeight="1"><c r="A1" s="1" t="inlineStr"><is><t>domain</t></is></c><c r="B1" s="1" t="inlineStr"><is><t>nameserver1</t></is></c><c r="C1" s="1" t="inlineStr"><is><t>nameserver2</t></is></c></row></sheetData><autoFilter ref="A1:C101"/></worksheet>';
    }

    private function instructionsSheet(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="100" customWidth="1"/></cols><sheetData><row r="1"><c r="A1" s="1" t="inlineStr"><is><t>How to use this template</t></is></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>1. Enter one managed domain per row in the Bulk Update sheet.</t></is></c></row><row r="4"><c r="A4" t="inlineStr"><is><t>2. Fill nameserver1 and nameserver2. Do not rename or remove the headers.</t></is></c></row><row r="5"><c r="A5" t="inlineStr"><is><t>3. Only domains listed in the file will be included. Maximum 100 domains.</t></is></c></row><row r="6"><c r="A6" t="inlineStr"><is><t>4. Upload the completed .xlsx file in Nameshift and review before confirming.</t></is></c></row></sheetData></worksheet>';
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Nameshift</Application></Properties>';
    }

    private function coreProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Nameshift Bulk Nameserver Update Template</dc:title><dc:creator>Nameshift</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">2026-08-26T00:00:00Z</dcterms:created></cp:coreProperties>';
    }
}
