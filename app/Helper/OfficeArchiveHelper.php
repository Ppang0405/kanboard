<?php

namespace Kanboard\Helper;

use Kanboard\Core\Base;
use RuntimeException;
use ZipArchive;

/**
 * Shared helper for OOXML (ZIP based) office file previews
 *
 * Centralizes the security-critical archive handling used before any
 * office file is parsed: temporary file creation, uncompressed size
 * guard and HTML escaping.
 *
 * @package helper
 */
abstract class OfficeArchiveHelper extends Base
{
    /**
     * Maximum number of entries in an OOXML archive.
     */
    const MAX_ARCHIVE_ENTRIES = 2000;

    /**
     * Maximum total uncompressed size of an OOXML archive.
     */
    const MAX_UNCOMPRESSED_SIZE = 15728640;

    /**
     * Write the uploaded content to a temporary file
     *
     * @access protected
     * @param  string $content
     * @return string
     */
    protected function createTempFile($content)
    {
        $filename = tempnam(sys_get_temp_dir(), 'kanboard_office_');

        if ($filename === false) {
            throw new RuntimeException('Unable to create a temporary file');
        }

        if (file_put_contents($filename, $content) === false) {
            @unlink($filename);
            throw new RuntimeException('Unable to write the temporary file');
        }

        return $filename;
    }

    /**
     * Reject suspicious OOXML archives before parsing them
     *
     * Office files are ZIP archives. A tiny file can contain a huge
     * amount of uncompressed XML, so the archive is checked before it is
     * handed over to the format specific parser.
     *
     * @access protected
     * @param  string $filename
     * @param  array  $extensions
     */
    protected function assertArchiveIsSafe($filename, array $extensions)
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('The zip extension is required to preview this file');
        }

        $zip = new ZipArchive();
        $result = $zip->open($filename);

        if ($result !== true) {
            throw new RuntimeException('Invalid spreadsheet archive');
        }

        if ($zip->numFiles > static::MAX_ARCHIVE_ENTRIES) {
            $zip->close();
            throw new RuntimeException('The spreadsheet contains too many files');
        }

        $totalSize = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false) {
                continue;
            }

            if (substr($name, -1) === '/') {
                continue;
            }

            $stream = $zip->getStream($name);

            if ($stream === false) {
                $zip->close();
                throw new RuntimeException('Invalid spreadsheet archive');
            }

            while (! feof($stream)) {
                $chunk = fread($stream, 65536);

                if ($chunk === false) {
                    fclose($stream);
                    $zip->close();
                    throw new RuntimeException('Invalid spreadsheet archive');
                }

                $totalSize += strlen($chunk);

                if ($totalSize > static::MAX_UNCOMPRESSED_SIZE) {
                    fclose($stream);
                    $zip->close();
                    throw new RuntimeException('The spreadsheet is too large to preview');
                }
            }

            fclose($stream);
        }

        $zip->close();
    }

    /**
     * Escape a value for HTML output
     *
     * @access protected
     * @param  mixed $value
     * @return string
     */
    protected function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape a value and preserve line breaks
     *
     * @access protected
     * @param  mixed $value
     * @return string
     */
    protected function escapeMultiline($value)
    {
        $value = $this->escape($value);

        if ($value === '') {
            return '&nbsp;';
        }

        return nl2br($value, false);
    }
}
