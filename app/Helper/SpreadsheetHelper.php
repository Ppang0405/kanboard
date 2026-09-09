<?php

namespace Kanboard\Helper;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Spreadsheet preview helper
 *
 * Renders a spreadsheet as a self-contained HTML table so that it can be
 * displayed safely in a sandboxed iframe. Only a bounded amount of data is
 * read from the file to keep previews cheap and predictable.
 *
 * @package helper
 */
class SpreadsheetHelper extends OfficeArchiveHelper
{
    /**
     * Maximum number of rows rendered per sheet (one extra row is read to
     * detect that the sheet has been truncated).
     */
    const MAX_ROWS = 500;

    /**
     * Maximum number of columns rendered per sheet (one extra column is read
     * to detect that the sheet has been truncated).
     */
    const MAX_COLUMNS = 30;

    /**
     * Maximum number of sheets rendered.
     */
    const MAX_SHEETS = 5;

    /**
     * Maximum number of characters rendered for a single cell.
     */
    const MAX_CELL_LENGTH = 5000;

    /**
     * Maximum number of characters rendered across all sheets.
     */
    const MAX_RENDERED_SIZE = 2097152;

    /**
     * Maximum total uncompressed size of an OOXML archive.
     */
    const MAX_UNCOMPRESSED_SIZE = 26214400;

    /**
     * Maximum number of entries in an OOXML archive.
     */
    const MAX_ARCHIVE_ENTRIES = 5000;

    /**
     * @var bool
     */
    private $hasMoreSheets = false;

    /**
     * @var bool
     */
    private $hasMoreRows = false;

    /**
     * @var bool
     */
    private $hasMoreColumns = false;

    /**
     * @var int
     */
    private $renderedLength = 0;

    /**
     * @var bool
     */
    private $renderLimitReached = false;

    /**
     * Render a spreadsheet as a standalone HTML document
     *
     * @access public
     * @param  string $filename Original filename (used to select the reader)
     * @param  string $content  Raw file content
     * @return string
     */
    public function render($filename, $content)
    {
        $extension = get_file_extension($filename);
        $readerType = $this->getReaderType($extension);

        if ($readerType === null) {
            throw new RuntimeException('Unsupported spreadsheet format');
        }

        $tempFile = $this->createTempFile($content);

        try {
            if (in_array($extension, array('xlsx', 'xlsm'), true)) {
                $this->assertArchiveIsSafe($tempFile, array('xlsx', 'xlsm'));
            }

            $reader = IOFactory::createReader($readerType);
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(false);
            $reader->setIncludeCharts(false);
            $reader->setAllowExternalImages(false);

            $worksheetNames = $this->getWorksheetNames($reader, $tempFile);
            $this->hasMoreSheets = count($worksheetNames) > self::MAX_SHEETS;
            $worksheetNames = array_slice($worksheetNames, 0, self::MAX_SHEETS);

            if (! empty($worksheetNames)) {
                $reader->setLoadSheetsOnly($worksheetNames);
            }

            $reader->setReadFilter($this->createReadFilter());
            $spreadsheet = $reader->load($tempFile);

            return $this->renderSpreadsheet($spreadsheet, $filename);
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Get the PhpSpreadsheet reader type for a file extension
     *
     * @access private
     * @param  string $extension
     * @return string|null
     */
    private function getReaderType($extension)
    {
        switch ($extension) {
            case 'xlsx':
            case 'xlsm':
                return IOFactory::READER_XLSX;
            case 'xls':
                return IOFactory::READER_XLS;
        }

        return null;
    }

    /**
     * Get worksheet names without loading all worksheet data
     *
     * @access private
     * @param  \PhpOffice\PhpSpreadsheet\Reader\IReader $reader
     * @param  string $filename
     * @return array
     */
    private function getWorksheetNames($reader, $filename)
    {
        $worksheetNames = $reader->listWorksheetNames($filename);

        return is_array($worksheetNames) ? $worksheetNames : array();
    }

    /**
     * Create the read filter used to bound the amount of loaded cells
     *
     * One extra row and column is allowed so the renderer can detect that the
     * sheet has more data than the configured limits.
     *
     * @access private
     * @return IReadFilter
     */
    private function createReadFilter()
    {
        $maxRows = self::MAX_ROWS + 1;
        $maxColumns = self::MAX_COLUMNS + 1;

        return new class($maxRows, $maxColumns) implements IReadFilter {
            /**
             * @var int
             */
            private $maxRows;

            /**
             * @var int
             */
            private $maxColumns;

            public function __construct($maxRows, $maxColumns)
            {
                $this->maxRows = $maxRows;
                $this->maxColumns = $maxColumns;
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row > 0
                    && $row <= $this->maxRows
                    && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
            }
        };
    }

    /**
     * Render all loaded worksheets
     *
     * @access private
     * @param  \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @param  string $filename
     * @return string
     */
    private function renderSpreadsheet($spreadsheet, $filename)
    {
        $this->hasMoreRows = false;
        $this->hasMoreColumns = false;
        $this->renderedLength = 0;
        $this->renderLimitReached = false;

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>'.$this->escape($filename).'</title>';
        $html .= '<style>'
            .'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;'
            .'font-size:13px;line-height:1.4;color:#24292f;background:#fff;margin:0;padding:12px;}'
            .'h2{font-size:14px;margin:0 0 8px;padding:6px 8px;background:#f6f8fa;'
            .'border:1px solid #d0d7de;border-radius:4px 4px 0 0;}'
            .'table{border-collapse:collapse;width:100%;margin:0 0 20px;table-layout:auto;}'
            .'td{border:1px solid #d0d7de;padding:4px 8px;vertical-align:top;'
            .'white-space:pre-wrap;overflow-wrap:anywhere;max-width:480px;}'
            .'tr:nth-child(even) td{background:#f6f8fa;}'
            .'.notice{margin:0 0 16px;padding:8px 10px;background:#fff8c5;'
            .'border:1px solid #d4a72c;border-radius:4px;}'
            .'</style></head><body>';

        $sheets = $spreadsheet->getAllSheets();

        if (empty($sheets)) {
            $html .= '<p class="notice">'.t('There is no data to display.').'</p>';
        }

        foreach ($sheets as $index => $sheet) {
            $html .= $this->renderSheet($sheet, $index);
        }

        if ($this->hasMoreSheets) {
            $html .= '<p class="notice">'.t('Only the first %d sheets are displayed.', self::MAX_SHEETS).'</p>';
        }

        if ($this->hasMoreRows || $this->hasMoreColumns) {
            $html .= '<p class="notice">'
                .t('Only the first %d rows and %d columns of each sheet are displayed.', self::MAX_ROWS, self::MAX_COLUMNS)
                .'</p>';
        }

        if ($this->renderLimitReached) {
            $html .= '<p class="notice">'.t('Only the first part of the spreadsheet is displayed.').'</p>';
        }

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Render one worksheet as an HTML table
     *
     * @access private
     * @param  Worksheet $sheet
     * @param  integer $index
     * @return string
     */
    private function renderSheet(Worksheet $sheet, $index)
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        if ($highestRow > self::MAX_ROWS) {
            $this->hasMoreRows = true;
            $highestRow = self::MAX_ROWS;
        }

        if ($highestColumnIndex > self::MAX_COLUMNS) {
            $this->hasMoreColumns = true;
            $highestColumnIndex = self::MAX_COLUMNS;
        }

        $title = $sheet->getTitle();

        if ($title === '') {
            $title = 'Sheet '.($index + 1);
        }

        $html = '<h2>'.$this->escape($title).'</h2>';
        $html .= '<table><tbody>';

        for ($row = 1; $row <= $highestRow && ! $this->renderLimitReached; $row++) {
            $html .= '<tr>';

            for ($column = 1; $column <= $highestColumnIndex && ! $this->renderLimitReached; $column++) {
                $coordinate = Coordinate::stringFromColumnIndex($column).$row;
                $value = '';

                if ($sheet->cellExists($coordinate)) {
                    $value = $this->formatCellValue($sheet->getCell($coordinate));
                    $this->renderedLength += mb_strlen($value);

                    if ($this->renderedLength > self::MAX_RENDERED_SIZE) {
                        $this->renderLimitReached = true;
                        $value = '…';
                    }
                }

                $html .= '<td>'.$this->escapeMultiline($value).'</td>';
            }

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Format a cell value without calculating formulas
     *
     * PhpSpreadsheet calculates formulas when getFormattedValue() is called.
     * For formula cells the cached value written by the spreadsheet
     * application is used instead.
     *
     * @access private
     * @param  Cell $cell
     * @return string
     */
    private function formatCellValue(Cell $cell)
    {
        try {
            if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                $value = $cell->getOldCalculatedValue();

                if (is_array($value)) {
                    $value = reset($value);
                }

                $formatCode = $cell->getStyle()->getNumberFormat()->getFormatCode(true);
                $value = NumberFormat::toFormattedString($value, $formatCode);
            } else {
                $value = $cell->getFormattedValue();
            }
        } catch (Throwable $e) {
            $value = $cell->getValueString();
        }

        if (mb_strlen($value) > self::MAX_CELL_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_CELL_LENGTH).'…';
        }

        return $value;
    }

}
