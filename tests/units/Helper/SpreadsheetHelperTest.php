<?php

namespace KanboardTests\units\Helper;

use Kanboard\Helper\SpreadsheetHelper;
use KanboardTests\units\Base;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

class SpreadsheetHelperTest extends Base
{
    public function testRenderSpreadsheet()
    {
        $content = $this->createXlsx(function (Spreadsheet $spreadsheet) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Data & More');
            $sheet->setCellValue('A1', 'Hello');
            $sheet->setCellValue('B1', '<script>alert("xss")</script>');
            $sheet->setCellValue('A2', '=1+1');
            $sheet->setCellValue('B2', 1234.5);
        });

        $helper = new SpreadsheetHelper($this->container);
        $html = $helper->render('report.xlsx', $content);

        $this->assertStringContainsString('<h2>Data &amp; More</h2>', $html);
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('alert("xss")', $html);
    }

    public function testRenderSpreadsheetTruncatesLargeSheets()
    {
        $content = $this->createXlsx(function (Spreadsheet $spreadsheet) {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('A1', 'first');
            $sheet->setCellValue('A'.SpreadsheetHelper::MAX_ROWS, 'row-marker');
            $sheet->setCellValue('A'.(SpreadsheetHelper::MAX_ROWS + 1), 'too far');
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex(SpreadsheetHelper::MAX_COLUMNS + 1).'1',
                'too wide'
            );
        });

        $helper = new SpreadsheetHelper($this->container);
        $html = $helper->render('big.xlsx', $content);

        $this->assertStringContainsString('first', $html);
        $this->assertStringContainsString('row-marker', $html);
        $this->assertStringNotContainsString('too far', $html);
        $this->assertStringNotContainsString('too wide', $html);
        $this->assertStringContainsString('Only the first', $html);
        $this->assertStringContainsString(
            'Only the first '.SpreadsheetHelper::MAX_ROWS.' rows and '.SpreadsheetHelper::MAX_COLUMNS.' columns of each sheet are displayed.',
            $html
        );
    }

    public function testRenderSpreadsheetLimitsNumberOfSheets()
    {
        $content = $this->createXlsx(function (Spreadsheet $spreadsheet) {
            $spreadsheet->getActiveSheet()->setTitle('Sheet 1')->setCellValue('A1', 'first');

            for ($index = 2; $index <= SpreadsheetHelper::MAX_SHEETS + 1; $index++) {
                $spreadsheet->createSheet()->setTitle('Sheet '.$index)->setCellValue('A1', 'sheet '.$index);
            }
        });

        $helper = new SpreadsheetHelper($this->container);
        $html = $helper->render('many.xlsx', $content);

        $this->assertStringContainsString('sheet '.SpreadsheetHelper::MAX_SHEETS, $html);
        $this->assertStringNotContainsString('sheet '.(SpreadsheetHelper::MAX_SHEETS + 1), $html);
        $this->assertStringContainsString('Only the first '.SpreadsheetHelper::MAX_SHEETS.' sheets', $html);
    }

    public function testRenderSpreadsheetLimitsRenderedText()
    {
        $longValue = str_repeat('a', SpreadsheetHelper::MAX_CELL_LENGTH);

        $content = $this->createXlsx(function (Spreadsheet $spreadsheet) use ($longValue) {
            $sheet = $spreadsheet->getActiveSheet();

            for ($row = 1; $row <= 500; $row++) {
                $sheet->setCellValue('A'.$row, $longValue);
            }
        });

        $helper = new SpreadsheetHelper($this->container);
        $html = $helper->render('long.xlsx', $content);

        $this->assertStringContainsString('Only the first part of the spreadsheet is displayed.', $html);
    }

    public function testRenderSpreadsheetTruncatesLongCellValue()
    {
        $longValue = str_repeat('a', SpreadsheetHelper::MAX_CELL_LENGTH + 100);
        $expected = str_repeat('a', SpreadsheetHelper::MAX_CELL_LENGTH).'…';

        $content = $this->createXlsx(function (Spreadsheet $spreadsheet) use ($longValue) {
            $spreadsheet->getActiveSheet()->setCellValue('A1', $longValue);
        });

        $helper = new SpreadsheetHelper($this->container);
        $html = $helper->render('cell.xlsx', $content);

        $this->assertStringContainsString($expected, $html);
        $this->assertStringNotContainsString($longValue, $html);
    }

    public function testRenderSpreadsheetEscapesHostileFilename()
    {
        $content = $this->createXlsx(function (Spreadsheet $spreadsheet) {
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'hello');
        });

        $filename = '"><svg onload=alert(1)>.xlsx';
        $helper = new SpreadsheetHelper($this->container);
        $html = $helper->render($filename, $content);

        $this->assertStringContainsString(htmlspecialchars($filename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
        $this->assertStringNotContainsString('<svg onload', $html);
    }

    public function testRenderXlsSpreadsheet()
    {
        $content = $this->createXls(function (Spreadsheet $spreadsheet) {
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'legacy-value');
        });

        $helper = new SpreadsheetHelper($this->container);
        $html = $helper->render('legacy.xls', $content);

        $this->assertStringContainsString('legacy-value', $html);
    }

    public function testRenderRejectsOdsFormat()
    {
        $helper = new SpreadsheetHelper($this->container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported spreadsheet format');
        $helper->render('data.ods', 'dummy');
    }

    public function testRenderRejectsInvalidArchive()
    {
        $helper = new SpreadsheetHelper($this->container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid spreadsheet archive');
        $helper->render('invalid.xlsx', 'not a zip archive');
    }

    public function testRenderRejectsArchiveWithTooManyFiles()
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'kanboard-spreadsheet-test-');

        if ($tempFile === false) {
            $this->fail('Unable to create a temporary file');
        }

        try {
            $zip = new ZipArchive();
            $zip->open($tempFile, ZipArchive::OVERWRITE);

            for ($i = 0; $i <= SpreadsheetHelper::MAX_ARCHIVE_ENTRIES; $i++) {
                $zip->addFromString('file_'.$i.'.txt', 'x');
            }

            $zip->close();

            $content = file_get_contents($tempFile);

            if ($content === false) {
                $this->fail('Unable to read the generated archive');
            }

            $helper = new SpreadsheetHelper($this->container);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The spreadsheet contains too many files');
            $helper->render('many.xlsx', $content);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testRenderRejectsOversizedArchive()
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'kanboard-spreadsheet-test-');

        if ($tempFile === false) {
            $this->fail('Unable to create a temporary file');
        }

        try {
            $zip = new ZipArchive();
            $zip->open($tempFile, ZipArchive::OVERWRITE);
            $zip->addFromString('big.txt', str_repeat('a', SpreadsheetHelper::MAX_UNCOMPRESSED_SIZE + 1024));
            $zip->close();

            $content = file_get_contents($tempFile);

            if ($content === false) {
                $this->fail('Unable to read the generated archive');
            }

            $helper = new SpreadsheetHelper($this->container);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The spreadsheet is too large to preview');
            $helper->render('big.xlsx', $content);
        } finally {
            @unlink($tempFile);
        }
    }

    private function createXlsx(callable $callback)
    {
        return $this->createSpreadsheet($callback, Xlsx::class, 'xlsx');
    }

    private function createXls(callable $callback)
    {
        return $this->createSpreadsheet($callback, Xls::class, 'xls');
    }

    private function createSpreadsheet(callable $callback, $writerClass, $extension)
    {
        $spreadsheet = new Spreadsheet();
        $callback($spreadsheet);

        $tempFile = tempnam(sys_get_temp_dir(), 'kanboard-spreadsheet-test-');

        if ($tempFile === false) {
            $this->fail('Unable to create a temporary file');
        }

        $filename = $tempFile.'.'.$extension;

        try {
            rename($tempFile, $filename);
            (new $writerClass($spreadsheet))->save($filename);
            $content = file_get_contents($filename);

            if ($content === false) {
                $this->fail('Unable to read the generated spreadsheet');
            }

            return $content;
        } finally {
            @unlink($tempFile);
            @unlink($filename);
            $spreadsheet->disconnectWorksheets();
        }
    }
}
