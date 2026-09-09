<?php

namespace KanboardTests\units\Helper;

use Kanboard\Helper\DocumentHelper;
use KanboardTests\units\Base;
use RuntimeException;
use ZipArchive;

class DocumentHelperTest extends Base
{
    public function testRenderDocxParagraph()
    {
        $content = $this->createDocx(
            '<w:p><w:r><w:t>Hello paragraph</w:t></w:r></w:p>'
        );

        $helper = new DocumentHelper($this->container);
        $html = $helper->render('test.docx', $content);

        $this->assertStringContainsString('Hello paragraph', $html);
        $this->assertStringContainsString('<p>', $html);
    }

    public function testRenderDocxHeading()
    {
        $content = $this->createDocx(
            '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Hello Heading</w:t></w:r></w:p>'
        );

        $helper = new DocumentHelper($this->container);
        $html = $helper->render('test.docx', $content);

        $this->assertStringContainsString('<h1>Hello Heading</h1>', $html);
    }

    public function testRenderDocxTable()
    {
        $content = $this->createDocx(
            '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Cell text</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
        );

        $helper = new DocumentHelper($this->container);
        $html = $helper->render('test.docx', $content);

        $this->assertStringContainsString('<td>', $html);
        $this->assertStringContainsString('Cell text', $html);
    }

    public function testRenderDocxEscapesText()
    {
        $content = $this->createDocx(
            '<w:p><w:r><w:t>&lt;script&gt;alert(1)&lt;/script&gt;</w:t></w:r></w:p>'
        );

        $helper = new DocumentHelper($this->container);
        $html = $helper->render('test.docx', $content);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testRenderDocxEscapesHostileFilename()
    {
        $content = $this->createDocx(
            '<w:p><w:r><w:t>hello</w:t></w:r></w:p>'
        );

        $filename = '"><svg onload=alert(1)>.docx';
        $helper = new DocumentHelper($this->container);
        $html = $helper->render($filename, $content);

        $this->assertStringContainsString(htmlspecialchars($filename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
        $this->assertStringNotContainsString('<svg onload', $html);
    }

    public function testRenderPptxTitleAndBody()
    {
        $content = $this->createPptx(array(
            $this->buildSlide('Slide Title', 'Body text'),
        ));

        $helper = new DocumentHelper($this->container);
        $html = $helper->render('test.pptx', $content);

        $this->assertStringContainsString('<h2>Slide Title</h2>', $html);
        $this->assertStringContainsString('<li>Body text</li>', $html);
    }

    public function testRenderPptxMultipleSlidesOrdered()
    {
        $content = $this->createPptx(array(
            $this->buildSlide('First Slide', 'First body'),
            $this->buildSlide('Second Slide', 'Second body'),
        ));

        $helper = new DocumentHelper($this->container);
        $html = $helper->render('test.pptx', $content);

        $firstTitlePosition = strpos($html, 'First Slide');
        $secondTitlePosition = strpos($html, 'Second Slide');

        $this->assertNotFalse($firstTitlePosition);
        $this->assertNotFalse($secondTitlePosition);
        $this->assertLessThan($secondTitlePosition, $firstTitlePosition);
    }

    public function testRenderPptxEmptySlide()
    {
        $content = $this->createPptx(array(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<p:cSld><p:spTree></p:spTree></p:cSld></p:sld>',
        ));

        $helper = new DocumentHelper($this->container);
        $html = $helper->render('test.pptx', $content);

        $this->assertStringContainsString('(empty slide)', $html);
    }

    public function testRenderRejectsUnsupportedFormat()
    {
        $helper = new DocumentHelper($this->container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported document format');
        $helper->render('data.doc', 'dummy');
    }

    public function testRenderRejectsInvalidArchive()
    {
        $helper = new DocumentHelper($this->container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid spreadsheet archive');
        $helper->render('invalid.docx', 'not a zip archive');
    }

    public function testRenderRejectsArchiveWithTooManyFiles()
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'kanboard-document-test-');

        if ($tempFile === false) {
            $this->fail('Unable to create a temporary file');
        }

        try {
            $zip = new ZipArchive();
            $zip->open($tempFile, ZipArchive::OVERWRITE);

            for ($i = 0; $i <= DocumentHelper::MAX_ARCHIVE_ENTRIES; $i++) {
                $zip->addFromString('file_'.$i.'.txt', 'x');
            }

            $zip->close();

            $content = file_get_contents($tempFile);

            if ($content === false) {
                $this->fail('Unable to read the generated archive');
            }

            $helper = new DocumentHelper($this->container);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The spreadsheet contains too many files');
            $helper->render('many.docx', $content);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testRenderRejectsOversizedArchive()
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'kanboard-document-test-');

        if ($tempFile === false) {
            $this->fail('Unable to create a temporary file');
        }

        try {
            $zip = new ZipArchive();
            $zip->open($tempFile, ZipArchive::OVERWRITE);
            $zip->addFromString('big.txt', str_repeat('a', DocumentHelper::MAX_UNCOMPRESSED_SIZE + 1024));
            $zip->close();

            $content = file_get_contents($tempFile);

            if ($content === false) {
                $this->fail('Unable to read the generated archive');
            }

            $helper = new DocumentHelper($this->container);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The spreadsheet is too large to preview');
            $helper->render('big.docx', $content);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testRenderTruncatesWhenTooManySlides()
    {
        $slides = array();

        for ($i = 1; $i <= DocumentHelper::MAX_SLIDES + 1; $i++) {
            $slides[] = $this->buildSlide('Slide '.$i, 'Body '.$i);
        }

        $content = $this->createPptx($slides);

        $helper = new DocumentHelper($this->container);
        $html = $helper->render('many.pptx', $content);

        $this->assertStringContainsString('Slide '.DocumentHelper::MAX_SLIDES, $html);
        $this->assertStringNotContainsString('Slide '.(DocumentHelper::MAX_SLIDES + 1), $html);
        $this->assertStringContainsString('Only part of the document is displayed.', $html);
    }

    private function createDocx($body)
    {
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$body.'</w:body></w:document>';

        $tempFile = tempnam(sys_get_temp_dir(), 'kanboard-document-test-');

        if ($tempFile === false) {
            $this->fail('Unable to create a temporary file');
        }

        try {
            $zip = new ZipArchive();
            $zip->open($tempFile, ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types></Types>');
            $zip->addFromString('word/document.xml', $document);
            $zip->close();

            $content = file_get_contents($tempFile);

            if ($content === false) {
                $this->fail('Unable to read the generated document');
            }

            return $content;
        } finally {
            @unlink($tempFile);
        }
    }

    private function createPptx(array $slides)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'kanboard-document-test-');

        if ($tempFile === false) {
            $this->fail('Unable to create a temporary file');
        }

        try {
            $zip = new ZipArchive();
            $zip->open($tempFile, ZipArchive::OVERWRITE);

            foreach ($slides as $index => $slide) {
                $zip->addFromString('ppt/slides/slide'.($index + 1).'.xml', $slide);
            }

            $zip->close();

            $content = file_get_contents($tempFile);

            if ($content === false) {
                $this->fail('Unable to read the generated presentation');
            }

            return $content;
        } finally {
            @unlink($tempFile);
        }
    }

    private function buildSlide($title, $body)
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<p:cSld><p:spTree>'
            .'<p:sp><p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            .'<p:txBody><a:bodyPr/><a:p><a:r><a:t>'.$title.'</a:t></a:r></a:p></p:txBody></p:sp>'
            .'<p:sp><p:nvSpPr><p:nvPr><p:ph type="body"/></p:nvPr></p:nvSpPr>'
            .'<p:txBody><a:bodyPr/><a:p><a:r><a:t>'.$body.'</a:t></a:r></a:p></p:txBody></p:sp>'
            .'</p:spTree></p:cSld></p:sld>';
    }
}
