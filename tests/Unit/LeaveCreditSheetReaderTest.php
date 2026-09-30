<?php

namespace Tests\Unit;

use App\Services\Leave\CreditSheet\LeaveCreditSheetReader;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class LeaveCreditSheetReaderTest extends TestCase
{
    private LeaveCreditSheetReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new LeaveCreditSheetReader;
    }

    public function test_reads_employee_balances_and_skips_group_rows(): void
    {
        $path = $this->workbook(
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <sheetData>
                <row r="1">
                  <c r="A1" t="inlineStr"><is><t>Group Name</t></is></c>
                  <c r="B1" t="inlineStr"><is><t>Employee Name</t></is></c>
                  <c r="C1" t="inlineStr"><is><t>No.</t></is></c>
                  <c r="D1" t="inlineStr"><is><t>VL Running Balance</t></is></c>
                  <c r="E1" t="inlineStr"><is><t>SL Running Balance</t></is></c>
                </row>
                <row r="2">
                  <c r="A2" t="inlineStr"><is><t>Accounting Group</t></is></c>
                </row>
                <row r="3">
                  <c r="A3"><v>1</v></c>
                  <c r="B3" t="inlineStr"><is><t>PEREZ, April</t></is></c>
                  <c r="C3"><v>55</v></c>
                  <c r="D3"><v>20</v></c>
                  <c r="E3"><v>48</v></c>
                </row>
                <row r="4">
                  <c r="B4" t="s"><v>0</v></c>
                  <c r="C4" t="s"><v>1</v></c>
                  <c r="D4"><v>9.5</v></c>
                  <c r="E4"><v>13.75</v></c>
                </row>
              </sheetData>
            </worksheet>
            XML,
            <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <si><t>RAMOS, Sheena</t></si>
              <si><t>243</t></si>
            </sst>
            XML,
        );

        $rows = $this->reader->read($path);

        $this->assertSame([
            ['row' => 3, 'emp_id' => 55, 'name' => 'PEREZ, April', 'vl' => '20.00', 'sl' => '48.00'],
            ['row' => 4, 'emp_id' => 243, 'name' => 'RAMOS, Sheena', 'vl' => '9.50', 'sl' => '13.75'],
        ], $rows);
    }

    public function test_rejects_a_duplicate_employee_number(): void
    {
        $path = $this->workbook($this->sheet(
            '<row r="3"><c r="C3"><v>240</v></c><c r="D3"><v>3.5</v></c><c r="E3"><v>48</v></c></row>'
            .'<row r="4"><c r="C4"><v>240</v></c><c r="D4"><v>4</v></c><c r="E4"><v>48</v></c></row>',
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Employee 240 appears on rows 3 and 4.');

        $this->reader->read($path);
    }

    public function test_rejects_a_negative_balance(): void
    {
        $path = $this->workbook($this->sheet(
            '<row r="3"><c r="C3"><v>21</v></c><c r="D3"><v>-1</v></c><c r="E3"><v>10</v></c></row>',
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Row 3: VL balance cannot be negative.');

        $this->reader->read($path);
    }

    private function sheet(string $body): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <sheetData>
                <row r="1">
                  <c r="C1" t="inlineStr"><is><t>No.</t></is></c>
                  <c r="D1" t="inlineStr"><is><t>VL Running Balance</t></is></c>
                  <c r="E1" t="inlineStr"><is><t>SL Running Balance</t></is></c>
                </row>
                {$body}
              </sheetData>
            </worksheet>
            XML;
    }

    private function workbook(string $sheetXml, ?string $sharedXml = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'credits').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        if ($sharedXml !== null) {
            $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
        }

        $zip->close();

        return $path;
    }
}
