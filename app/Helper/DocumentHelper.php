<?php

namespace Kanboard\Helper;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Document preview helper
 *
 * Renders docx and pptx attachments as self-contained HTML documents so
 * that they can be displayed safely in a sandboxed iframe. Only text and
 * document structure are extracted, images and other embedded objects
 * are ignored.
 *
 * @package helper
 */
class DocumentHelper extends OfficeArchiveHelper
{
    /**
     * WordprocessingML namespace.
     */
    const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * PresentationML namespace.
     */
    const PRESENTATION_NAMESPACE = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    /**
     * DrawingML namespace.
     */
    const DRAWING_NAMESPACE = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    /**
     * Maximum number of slides rendered.
     */
    const MAX_SLIDES = 50;

    /**
     * Maximum number of paragraphs rendered.
     */
    const MAX_PARAGRAPHS = 2000;

    /**
     * Maximum number of table rows rendered.
     */
    const MAX_TABLE_ROWS = 500;

    /**
     * Maximum number of characters rendered across the whole document.
     */
    const MAX_TEXT_LENGTH = 200000;

    /**
     * Maximum number of characters rendered for a single paragraph.
     */
    const MAX_PARAGRAPH_LENGTH = 5000;

    /**
     * Render a document as a standalone HTML document
     *
     * @access public
     * @param  string $filename Original filename (used to select the parser)
     * @param  string $content  Raw file content
     * @return string
     */
    public function render($filename, $content)
    {
        $extension = get_file_extension($filename);

        if ($extension === 'docx') {
            return $this->renderWord($filename, $content);
        }

        if ($extension === 'pptx') {
            return $this->renderPresentation($filename, $content);
        }

        throw new RuntimeException('Unsupported document format');
    }

    /**
     * Render a docx attachment
     *
     * @access protected
     * @param  string $filename
     * @param  string $content
     * @return string
     */
    protected function renderWord($filename, $content)
    {
        $tempFile = $this->createTempFile($content);

        try {
            $this->assertArchiveIsSafe($tempFile, array('docx'));

            $xml = $this->readZipEntry($tempFile, 'word/document.xml');

            return $this->renderWordXml($filename, $xml);
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Render a pptx attachment
     *
     * @access protected
     * @param  string $filename
     * @param  string $content
     * @return string
     */
    protected function renderPresentation($filename, $content)
    {
        $tempFile = $this->createTempFile($content);

        try {
            $this->assertArchiveIsSafe($tempFile, array('pptx'));

            $slides = $this->listSlideEntries($tempFile);
            $truncated = count($slides) > self::MAX_SLIDES;
            $slides = array_slice($slides, 0, self::MAX_SLIDES);

            $body = '';
            $textLength = 0;

            foreach ($slides as $entry) {
                $xml = $this->readZipEntry($tempFile, $entry);

                if ($xml === null) {
                    continue;
                }

                $slide = $this->renderSlide($xml, $textLength, $truncated);

                if ($slide === null) {
                    break;
                }

                $body .= $slide;
            }

            return $this->wrapDocument($filename, $body, $truncated);
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Read a single entry from a ZIP archive
     *
     * Only the requested entry is decompressed, the archive as a whole
     * has already been bounded by assertArchiveIsSafe().
     *
     * @access private
     * @param  string $filename
     * @param  string $entry
     * @return string|null
     */
    private function readZipEntry($filename, $entry)
    {
        $zip = new ZipArchive();

        if ($zip->open($filename) !== true) {
            throw new RuntimeException('Invalid spreadsheet archive');
        }

        $xml = $zip->getFromName($entry);
        $zip->close();

        return is_string($xml) ? $xml : null;
    }

    /**
     * List slide entries ordered by slide number
     *
     * @access private
     * @param  string $filename
     * @return array
     */
    private function listSlideEntries($filename)
    {
        $zip = new ZipArchive();

        if ($zip->open($filename) !== true) {
            throw new RuntimeException('Invalid spreadsheet archive');
        }

        $slides = array();

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (! is_string($name)) {
                continue;
            }

            if (preg_match('#^ppt/slides/slide([0-9]+)\.xml$#', $name, $matches)) {
                $slides[(int) $matches[1]] = $name;
            }
        }

        $zip->close();

        ksort($slides, SORT_NUMERIC);

        return array_values($slides);
    }

    /**
     * Render the main document part of a docx file
     *
     * @access private
     * @param  string      $filename
     * @param  string|null $xml
     * @return string
     */
    private function renderWordXml($filename, $xml)
    {
        $body = '';
        $truncated = false;

        if (is_string($xml) && $xml !== '') {
            $result = $this->parseWordXml($xml, $body, $truncated);
            $body = $result;
        }

        return $this->wrapDocument($filename, $body, $truncated);
    }

    /**
     * Parse word/document.xml with a streaming reader
     *
     * @access private
     * @param  string $xml
     * @param  string $body
     * @param  bool   $truncated
     * @return string
     */
    private function parseWordXml($xml, &$body, &$truncated)
    {
        $body = '';
        $truncated = false;
        $html = '';

        $reader = XMLReader::XML($xml, null, LIBXML_NONET);

        if ($reader === false) {
            return $html;
        }

        $paragraphCount = 0;
        $tableRowCount = 0;
        $textLength = 0;

        $inParagraph = false;
        $paragraphHtml = '';
        $paragraphTextLength = 0;
        $paragraphLevel = null;
        $paragraphIsList = false;
        $paragraphHasBreak = false;

        $inTable = 0;
        $inRow = false;
        $rowOpen = false;
        $inCell = false;
        $cellHtml = '';
        $cellHasContent = false;

        $inText = false;

        while ($reader->read()) {
            if ($truncated && $textLength >= self::MAX_TEXT_LENGTH) {
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT) {
                $localName = $reader->localName;
                $isWord = $this->isWordElement($reader, $localName);

                if ($localName === 'tbl' && $isWord) {
                    $inTable++;
                    $this->appendHtml($html, $cellHtml, $inCell, '<table>', $textLength, $truncated);
                } elseif ($localName === 'tr' && $isWord) {
                    if ($tableRowCount >= self::MAX_TABLE_ROWS) {
                        $truncated = true;
                        break;
                    }

                    $tableRowCount++;
                    $inRow = true;
                    $rowOpen = true;
                    $this->appendHtml($html, $cellHtml, $inCell, '<tr>', $textLength, $truncated);
                } elseif ($localName === 'tc' && $isWord) {
                    $inCell = true;
                    $cellHtml = '';
                    $cellHasContent = false;
                } elseif ($localName === 'p' && $isWord) {
                    if ($paragraphCount >= self::MAX_PARAGRAPHS) {
                        $truncated = true;
                        break;
                    }

                    $inParagraph = true;
                    $paragraphHtml = '';
                    $paragraphTextLength = 0;
                    $paragraphLevel = null;
                    $paragraphIsList = false;
                    $paragraphHasBreak = false;
                } elseif ($localName === 'pStyle' && $isWord && $inParagraph) {
                    $style = $this->getWordAttribute($reader, 'val');

                    if ($style !== null) {
                        if (preg_match('/^Heading([1-6])$/i', $style, $matches)) {
                            $paragraphLevel = (int) $matches[1];
                        } elseif (strcasecmp($style, 'Title') === 0) {
                            $paragraphLevel = 1;
                        }
                    }
                } elseif ($localName === 'numPr' && $isWord && $inParagraph) {
                    $paragraphIsList = true;
                } elseif ($localName === 't' && $isWord && $inParagraph) {
                    $inText = ! $reader->isEmptyElement;
                } elseif ($localName === 'tab' && $isWord && $inParagraph) {
                    $this->appendParagraphText($paragraphHtml, $paragraphTextLength, $textLength, ' ', $truncated);
                } elseif ((($localName === 'br' || $localName === 'cr') && $isWord) && $inParagraph) {
                    $paragraphHtml .= '<br>';
                    $paragraphHasBreak = true;
                }

                if ($reader->isEmptyElement) {
                    if ($localName === 't' && $isWord && $inParagraph) {
                        $inText = false;
                    } elseif ($localName === 'p' && $isWord && $inParagraph) {
                        $paragraphCount = $this->closeParagraph(
                            $html,
                            $cellHtml,
                            $cellHasContent,
                            $inCell,
                            $paragraphHtml,
                            $paragraphTextLength,
                            $paragraphLevel,
                            $paragraphIsList,
                            $paragraphHasBreak,
                            $paragraphCount,
                            $textLength,
                            $truncated
                        );
                        $inParagraph = false;
                        $inText = false;
                    } elseif ($localName === 'tc' && $isWord && $inCell) {
                        $this->closeCell($html, $cellHtml, $cellHasContent);
                        $inCell = false;
                    } elseif ($localName === 'tr' && $isWord && $inRow) {
                        $this->appendHtml($html, $cellHtml, $inCell, '</tr>', $textLength, $truncated);
                        $inRow = false;
                        $rowOpen = false;
                    }
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                $localName = $reader->localName;
                $isWord = $this->isWordElement($reader, $localName);

                if ($localName === 't' && $isWord) {
                    $inText = false;
                } elseif ($localName === 'p' && $isWord && $inParagraph) {
                    $paragraphCount = $this->closeParagraph(
                        $html,
                        $cellHtml,
                        $cellHasContent,
                        $inCell,
                        $paragraphHtml,
                        $paragraphTextLength,
                        $paragraphLevel,
                        $paragraphIsList,
                        $paragraphHasBreak,
                        $paragraphCount,
                        $textLength,
                        $truncated
                    );
                    $inParagraph = false;
                } elseif ($localName === 'tc' && $isWord && $inCell) {
                    $this->closeCell($html, $cellHtml, $cellHasContent);
                    $inCell = false;
                } elseif ($localName === 'tr' && $isWord && $inRow) {
                    if ($rowOpen) {
                        $this->appendHtml($html, $cellHtml, $inCell, '</tr>', $textLength, $truncated);
                    }

                    $inRow = false;
                    $rowOpen = false;
                } elseif ($localName === 'tbl' && $isWord && $inTable > 0) {
                    $inTable--;
                    $this->appendHtml($html, $cellHtml, $inCell, '</table>', $textLength, $truncated);
                }
            } elseif ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                if ($inText && $inParagraph) {
                    $this->appendParagraphText($paragraphHtml, $paragraphTextLength, $textLength, $reader->value, $truncated);
                }
            }
        }

        $reader->close();

        if ($inCell && ($cellHasContent || $cellHtml !== '')) {
            $this->closeCell($html, $cellHtml, $cellHasContent);
        }

        if ($inRow && $rowOpen) {
            $html .= '</tr>';
        }

        $body = $html;

        return $html;
    }

    /**
     * Append escaped text to the current paragraph while enforcing limits
     *
     * @access private
     * @param  string $paragraphHtml
     * @param  int    $paragraphTextLength
     * @param  int    $textLength
     * @param  string $text
     * @param  bool   $truncated
     */
    private function appendParagraphText(&$paragraphHtml, &$paragraphTextLength, &$textLength, $text, &$truncated)
    {
        if ($truncated && ($paragraphTextLength >= self::MAX_PARAGRAPH_LENGTH || $textLength >= self::MAX_TEXT_LENGTH)) {
            return;
        }

        $remainingParagraph = self::MAX_PARAGRAPH_LENGTH - $paragraphTextLength;
        $remainingTotal = self::MAX_TEXT_LENGTH - $textLength;

        if ($remainingParagraph <= 0 || $remainingTotal <= 0) {
            $truncated = true;
            return;
        }

        $length = mb_strlen($text);

        if ($length > $remainingParagraph) {
            $text = mb_substr($text, 0, $remainingParagraph);
            $length = $remainingParagraph;
            $truncated = true;
        }

        if ($length > $remainingTotal) {
            $text = mb_substr($text, 0, $remainingTotal);
            $length = $remainingTotal;
            $truncated = true;
        }

        $paragraphHtml .= $this->escape($text);
        $paragraphTextLength += $length;
        $textLength += $length;
    }

    /**
     * Append a markup fragment either to the open table cell or to the body
     *
     * @access private
     * @param  string $html
     * @param  string $cellHtml
     * @param  bool   $inCell
     * @param  string $fragment
     * @param  int    $textLength
     * @param  bool   $truncated
     */
    private function appendHtml(&$html, &$cellHtml, $inCell, $fragment, &$textLength, &$truncated)
    {
        if ($inCell) {
            $cellHtml .= $fragment;
        } else {
            $html .= $fragment;
        }
    }

    /**
     * Close the current paragraph and emit it
     *
     * @access private
     * @param  string $html
     * @param  string $cellHtml
     * @param  bool   $cellHasContent
     * @param  bool   $inCell
     * @param  string $paragraphHtml
     * @param  int    $paragraphTextLength
     * @param  int    $paragraphLevel
     * @param  bool   $paragraphIsList
     * @param  bool   $paragraphHasBreak
     * @param  int    $paragraphCount
     * @param  int    $textLength
     * @param  bool   $truncated
     * @return int
     */
    private function closeParagraph(&$html, &$cellHtml, &$cellHasContent, $inCell, $paragraphHtml, $paragraphTextLength, $paragraphLevel, $paragraphIsList, $paragraphHasBreak, $paragraphCount, &$textLength, &$truncated)
    {
        $paragraphCount++;

        if ($paragraphCount > self::MAX_PARAGRAPHS) {
            $truncated = true;
            return $paragraphCount;
        }

        if ($paragraphHtml === '' && ! $paragraphHasBreak) {
            return $paragraphCount;
        }

        if ($inCell) {
            if ($cellHasContent) {
                $cellHtml .= '<br>';
            }

            $cellHtml .= $paragraphHtml;
            $cellHasContent = true;
            return $paragraphCount;
        }

        if ($paragraphLevel !== null) {
            $level = max(1, min(6, (int) $paragraphLevel));
            $html .= '<h'.$level.'>'.$paragraphHtml.'</h'.$level.'>';
        } elseif ($paragraphIsList) {
            $html .= '<p>&bull; '.$paragraphHtml.'</p>';
        } else {
            $html .= '<p>'.$paragraphHtml.'</p>';
        }

        return $paragraphCount;
    }

    /**
     * Close the current table cell and emit it
     *
     * @access private
     * @param  string $html
     * @param  string $cellHtml
     * @param  bool   $cellHasContent
     */
    private function closeCell(&$html, &$cellHtml, &$cellHasContent)
    {
        $content = $cellHtml === '' ? '&nbsp;' : $cellHtml;
        $html .= '<td>'.$content.'</td>';
        $cellHtml = '';
        $cellHasContent = false;
    }

    /**
     * Render a single slide as a section
     *
     * Returns null when the global text limit has been reached so the
     * caller stops reading further slides early.
     *
     * @access private
     * @param  string $xml
     * @param  int    $textLength
     * @param  bool   $truncated
     * @return string|null
     */
    private function renderSlide($xml, &$textLength, &$truncated)
    {
        $shapes = $this->parseSlideXml($xml, $textLength, $truncated);

        if ($shapes === null) {
            return null;
        }

        $html = '<section class="slide">';

        $texts = array();

        foreach ($shapes as $shape) {
            $text = trim($shape['text']);

            if ($text === '') {
                continue;
            }

            $texts[] = array('isTitle' => $shape['isTitle'], 'text' => $text);
        }

        if (empty($texts)) {
            $html .= '<p class="muted">'.t('(empty slide)').'</p>';
        } else {
            $firstTitleIndex = null;

            foreach ($texts as $index => $entry) {
                if ($entry['isTitle']) {
                    $firstTitleIndex = $index;
                    break;
                }
            }

            if ($firstTitleIndex !== null) {
                $html .= '<h2>'.$this->escape($texts[$firstTitleIndex]['text']).'</h2>';
            }

            $items = '';

            foreach ($texts as $index => $entry) {
                if ($index === $firstTitleIndex) {
                    continue;
                }

                $items .= '<li>'.$this->escape($entry['text']).'</li>';
            }

            if ($items !== '') {
                $html .= '<ul>'.$items.'</ul>';
            }
        }

        $html .= '</section>';

        if ($textLength >= self::MAX_TEXT_LENGTH) {
            $truncated = true;
        }

        return $html;
    }

    /**
     * Parse a slide XML document and group text runs by shape
     *
     * Returns null when the global text limit has been reached before any
     * shape of this slide could be read.
     *
     * @access private
     * @param  string $xml
     * @param  int    $textLength
     * @param  bool   $truncated
     * @return array|null
     */
    private function parseSlideXml($xml, &$textLength, &$truncated)
    {
        $reader = XMLReader::XML($xml, null, LIBXML_NONET);

        if ($reader === false) {
            return array();
        }

        $shapes = array();
        $currentShape = null;
        $currentParagraph = null;
        $inText = false;

        while ($reader->read()) {
            if ($textLength >= self::MAX_TEXT_LENGTH) {
                $truncated = true;
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT) {
                $localName = $reader->localName;

                if ($localName === 'sp' && $this->isPresentationElement($reader, $localName)) {
                    $shapes[] = array('isTitle' => false, 'paragraphs' => array(), 'text' => '');
                    $currentShape = count($shapes) - 1;
                    $currentParagraph = null;

                    if ($reader->isEmptyElement) {
                        $currentShape = null;
                    }
                } elseif ($localName === 'ph' && $currentShape !== null && $this->isPresentationElement($reader, $localName)) {
                    $type = $this->getUnqualifiedAttribute($reader, 'type');

                    if ($type !== null && (strcasecmp($type, 'title') === 0 || strcasecmp($type, 'ctrTitle') === 0)) {
                        $shapes[$currentShape]['isTitle'] = true;
                    }
                } elseif ($localName === 'p' && $currentShape !== null && $this->isDrawingElement($reader, $localName)) {
                    $currentParagraph = '';

                    if ($reader->isEmptyElement) {
                        $shapes[$currentShape]['paragraphs'][] = '';
                        $currentParagraph = null;
                    }
                } elseif ($localName === 't' && $currentShape !== null && $this->isDrawingElement($reader, $localName)) {
                    $inText = ! $reader->isEmptyElement;

                    if ($currentParagraph === null) {
                        $currentParagraph = '';
                    }

                    if ($reader->isEmptyElement) {
                        $inText = false;
                    }
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                $localName = $reader->localName;

                if ($localName === 't' && $inText) {
                    $inText = false;
                } elseif ($localName === 'p' && $currentShape !== null && $currentParagraph !== null && $this->isDrawingElement($reader, $localName)) {
                    $shapes[$currentShape]['paragraphs'][] = $currentParagraph;
                    $currentParagraph = null;
                } elseif ($localName === 'sp' && $currentShape !== null && $this->isPresentationElement($reader, $localName)) {
                    if ($currentParagraph !== null) {
                        $shapes[$currentShape]['paragraphs'][] = $currentParagraph;
                        $currentParagraph = null;
                    }

                    $text = implode(' ', array_map('trim', $shapes[$currentShape]['paragraphs']));
                    $text = trim(preg_replace('/\s+/', ' ', $text));

                    if (mb_strlen($text) > self::MAX_PARAGRAPH_LENGTH) {
                        $text = mb_substr($text, 0, self::MAX_PARAGRAPH_LENGTH);
                        $truncated = true;
                    }

                    $remaining = self::MAX_TEXT_LENGTH - $textLength;

                    if ($remaining <= 0) {
                        $truncated = true;
                        $shapes[$currentShape]['text'] = '';
                    } elseif (mb_strlen($text) > $remaining) {
                        $shapes[$currentShape]['text'] = mb_substr($text, 0, $remaining);
                        $textLength = self::MAX_TEXT_LENGTH;
                        $truncated = true;
                    } else {
                        $shapes[$currentShape]['text'] = $text;
                        $textLength += mb_strlen($text);
                    }

                    $currentShape = null;
                }
            } elseif ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                if ($inText && $currentShape !== null && $currentParagraph !== null) {
                    $currentParagraph .= $reader->value;
                }
            }
        }

        if ($currentShape !== null && isset($shapes[$currentShape])) {
            if ($currentParagraph !== null) {
                $shapes[$currentShape]['paragraphs'][] = $currentParagraph;
            }

            $text = implode(' ', array_map('trim', $shapes[$currentShape]['paragraphs']));
            $text = trim(preg_replace('/\s+/', ' ', $text));
            $shapes[$currentShape]['text'] = $text;
        }

        $reader->close();

        $result = array();

        foreach ($shapes as $shape) {
            $result[] = array('isTitle' => ! empty($shape['isTitle']), 'text' => isset($shape['text']) ? $shape['text'] : '');
        }

        return $result;
    }

    /**
     * Check that an element belongs to the WordprocessingML namespace
     *
     * The prefix is ignored so documents using a different prefix still
     * parse. Elements without a namespace are accepted as well so that
     * minimal documents keep working.
     *
     * @access private
     * @param  XMLReader $reader
     * @param  string    $localName
     * @return bool
     */
    private function isWordElement(XMLReader $reader, $localName)
    {
        if ($reader->localName !== $localName) {
            return false;
        }

        $namespace = $reader->namespaceURI;

        return $namespace === '' || $namespace === self::WORD_NAMESPACE;
    }

    /**
     * Check that an element belongs to the PresentationML namespace
     *
     * @access private
     * @param  XMLReader $reader
     * @param  string    $localName
     * @return bool
     */
    private function isPresentationElement(XMLReader $reader, $localName)
    {
        if ($reader->localName !== $localName) {
            return false;
        }

        $namespace = $reader->namespaceURI;

        return $namespace === '' || $namespace === self::PRESENTATION_NAMESPACE;
    }

    /**
     * Check that an element belongs to the DrawingML namespace
     *
     * @access private
     * @param  XMLReader $reader
     * @param  string    $localName
     * @return bool
     */
    private function isDrawingElement(XMLReader $reader, $localName)
    {
        if ($reader->localName !== $localName) {
            return false;
        }

        $namespace = $reader->namespaceURI;

        return $namespace === '' || $namespace === self::DRAWING_NAMESPACE;
    }

    /**
     * Get a WordprocessingML attribute value by local name
     *
     * @access private
     * @param  XMLReader $reader
     * @param  string    $localName
     * @return string|null
     */
    private function getWordAttribute(XMLReader $reader, $localName)
    {
        if (! $reader->hasAttributes) {
            return null;
        }

        $value = $reader->getAttributeNs($localName, self::WORD_NAMESPACE);

        if ($value !== null && $value !== '') {
            return $value;
        }

        while ($reader->moveToNextAttribute()) {
            if ($reader->localName === $localName) {
                $value = $reader->value;
                $reader->moveToElement();
                return $value;
            }
        }

        $reader->moveToElement();

        return null;
    }

    /**
     * Get an unqualified attribute value by local name
     *
     * @access private
     * @param  XMLReader $reader
     * @param  string    $localName
     * @return string|null
     */
    private function getUnqualifiedAttribute(XMLReader $reader, $localName)
    {
        if (! $reader->hasAttributes) {
            return null;
        }

        $value = $reader->getAttribute($localName);

        if ($value !== null) {
            return $value;
        }

        while ($reader->moveToNextAttribute()) {
            if ($reader->localName === $localName) {
                $value = $reader->value;
                $reader->moveToElement();
                return $value;
            }
        }

        $reader->moveToElement();

        return null;
    }

    /**
     * Wrap extracted content in a standalone HTML document
     *
     * @access private
     * @param  string $filename
     * @param  string $body
     * @param  bool   $truncated
     * @return string
     */
    private function wrapDocument($filename, $body, $truncated)
    {
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>'.$this->escape($filename).'</title>';
        $html .= '<style>'
            .'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;'
            .'font-size:13px;line-height:1.4;color:#24292f;background:#fff;margin:0;padding:12px;}'
            .'h1{font-size:18px;margin:12px 0 8px;}'
            .'h2{font-size:14px;margin:0 0 8px;padding:6px 8px;background:#f6f8fa;'
            .'border:1px solid #d0d7de;border-radius:4px 4px 0 0;}'
            .'h3,h4,h5,h6{font-size:13px;margin:12px 0 8px;}'
            .'p{margin:0 0 8px;}'
            .'ul{margin:0 0 12px;padding-left:24px;}'
            .'li{margin:0 0 4px;}'
            .'table{border-collapse:collapse;width:100%;margin:0 0 20px;table-layout:auto;}'
            .'td{border:1px solid #d0d7de;padding:4px 8px;vertical-align:top;'
            .'white-space:pre-wrap;overflow-wrap:anywhere;max-width:480px;}'
            .'tr:nth-child(even) td{background:#f6f8fa;}'
            .'.slide{margin:0 0 16px;padding:12px;border:1px solid #d0d7de;border-radius:4px;}'
            .'.muted{color:#57606a;}'
            .'.notice{margin:0 0 16px;padding:8px 10px;background:#fff8c5;'
            .'border:1px solid #d4a72c;border-radius:4px;}'
            .'</style></head><body>';

        $html .= $body;

        if ($truncated) {
            $html .= '<p class="notice">'.t('Only part of the document is displayed.').'</p>';
        }

        $html .= '</body></html>';

        return $html;
    }
}
