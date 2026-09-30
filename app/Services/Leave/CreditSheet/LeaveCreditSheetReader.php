<?php

namespace App\Services\Leave\CreditSheet;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the admin VL/SL remaining-balance workbook.
 *
 * Expected columns: employee number ("No."), "VL Running Balance", "SL Running Balance".
 * Group header rows (no employee number) are skipped. EL and PTB are not in this sheet.
 */
final class LeaveCreditSheetReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return list<array{row: int, emp_id: int, name: string, vl: string, sl: string}>
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Credit sheet not found: {$path}");
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Cannot open credit sheet: {$path}");
        }

        try {
            $shared = $this->sharedStrings($zip);
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        } finally {
            $zip->close();
        }

        if ($sheet === false) {
            throw new RuntimeException('Credit sheet has no worksheet.');
        }

        $xml = simplexml_load_string($sheet);
        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Credit sheet worksheet is not valid XML.');
        }

        $rows = $this->rows($xml, $shared);
        $header = null;
        $headerIndex = null;

        foreach ($rows as $index => $row) {
            $columns = $this->headerColumns($row['cells']);
            if ($columns !== null) {
                $header = $columns;
                $headerIndex = $index;
                break;
            }
        }

        if ($header === null || $headerIndex === null) {
            throw new RuntimeException(
                'Credit sheet is missing columns "No.", "VL Running Balance", and "SL Running Balance".',
            );
        }

        $parsed = [];
        $seen = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $number = trim($row['cells'][$header['emp']] ?? '');
            if ($number === '') {
                continue;
            }

            $empId = $this->employeeId($number, $row['number']);
            if (isset($seen[$empId])) {
                throw new RuntimeException(
                    "Employee {$empId} appears on rows {$seen[$empId]} and {$row['number']}.",
                );
            }

            $seen[$empId] = $row['number'];
            $name = trim($row['cells'][$header['name']] ?? '');

            $parsed[] = [
                'row' => $row['number'],
                'emp_id' => $empId,
                'name' => $name,
                'vl' => $this->amount($row['cells'][$header['vl']] ?? '', $row['number'], 'VL'),
                'sl' => $this->amount($row['cells'][$header['sl']] ?? '', $row['number'], 'SL'),
            ];
        }

        if ($parsed === []) {
            throw new RuntimeException('Credit sheet has no employee rows.');
        }

        return $parsed;
    }

    /**
     * @return list<array{number: int, cells: array<string, string>}>
     */
    private function rows(SimpleXMLElement $xml, array $shared): array
    {
        $rows = [];
        $sheetData = $xml->children(self::NS)->sheetData;

        foreach ($sheetData->children(self::NS)->row as $rowNode) {
            $cells = [];

            foreach ($rowNode->children(self::NS)->c as $cell) {
                $ref = (string) $cell->attributes()['r'];
                if (! preg_match('/^([A-Z]+)/', $ref, $match)) {
                    continue;
                }

                $cells[$match[1]] = $this->cellValue($cell, $shared);
            }

            $rows[] = [
                'number' => (int) $rowNode->attributes()['r'],
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $cells
     * @return array{emp: string, name: string, vl: string, sl: string}|null
     */
    private function headerColumns(array $cells): ?array
    {
        $emp = null;
        $name = null;
        $vl = null;
        $sl = null;

        foreach ($cells as $column => $value) {
            $label = strtolower(trim($value));

            if ($label === 'no.' || $label === 'no') {
                $emp = $column;
            } elseif ($label === 'employee name') {
                $name = $column;
            } elseif (str_starts_with($label, 'vl')) {
                $vl = $column;
            } elseif (str_starts_with($label, 'sl')) {
                $sl = $column;
            }
        }

        if ($emp === null || $vl === null || $sl === null) {
            return null;
        }

        return [
            'emp' => $emp,
            'name' => $name ?? '',
            'vl' => $vl,
            'sl' => $sl,
        ];
    }

    /**
     * @param  list<string>  $shared
     */
    private function cellValue(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell->attributes()['t'];

        if ($type === 'inlineStr') {
            return $this->textNodes($cell);
        }

        $raw = trim((string) $cell->children(self::NS)->v);

        if ($type === 's') {
            return $shared[(int) $raw] ?? '';
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = simplexml_load_string($xml);
        if (! $doc instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];

        foreach ($doc->children(self::NS)->si as $item) {
            $strings[] = $this->textNodes($item);
        }

        return $strings;
    }

    private function textNodes(SimpleXMLElement $node): string
    {
        $text = '';

        foreach ($node->children(self::NS) as $child) {
            $text .= $child->getName() === 't'
                ? (string) $child
                : $this->textNodes($child);
        }

        return $text;
    }

    private function employeeId(string $raw, int $row): int
    {
        if (! is_numeric($raw) || (float) $raw !== (float) (int) $raw) {
            throw new RuntimeException("Row {$row}: employee number [{$raw}] is not a whole number.");
        }

        $empId = (int) $raw;

        if ($empId < 1) {
            throw new RuntimeException("Row {$row}: employee number [{$raw}] is not valid.");
        }

        return $empId;
    }

    private function amount(string $raw, int $row, string $code): string
    {
        $raw = trim($raw);

        if ($raw === '' || ! is_numeric($raw)) {
            throw new RuntimeException("Row {$row}: {$code} balance [{$raw}] is not a number.");
        }

        $amount = number_format((float) $raw, 2, '.', '');

        if (bccomp($amount, '0', 2) < 0) {
            throw new RuntimeException("Row {$row}: {$code} balance cannot be negative.");
        }

        return $amount;
    }
}
