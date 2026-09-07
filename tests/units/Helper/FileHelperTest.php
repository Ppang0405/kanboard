<?php

namespace KanboardTests\units\Helper;

use KanboardTests\units\Base;
use Kanboard\Helper\FileHelper;

class FileHelperTest extends Base
{
    public function testIcon()
    {
        $helper = new FileHelper($this->container);
        $this->assertEquals('fa-file-image-o', $helper->icon('test.png'));
        $this->assertEquals('fa-file-o', $helper->icon('test'));
    }

    public function testGetMimeType()
    {
        $helper = new FileHelper($this->container);

        $this->assertEquals('image/jpeg', $helper->getImageMimeType('My File.JPG'));
        $this->assertEquals('image/jpeg', $helper->getImageMimeType('My File.jpeg'));
        $this->assertEquals('image/png', $helper->getImageMimeType('My File.PNG'));
        $this->assertEquals('image/gif', $helper->getImageMimeType('My File.gif'));
        $this->assertEquals('image/jpeg', $helper->getImageMimeType('My File.bmp'));
        $this->assertEquals('image/jpeg', $helper->getImageMimeType('My File'));
    }

    public function testGetPreviewType()
    {
        $helper = new FileHelper($this->container);
        $this->assertEquals('text', $helper->getPreviewType('test.txt'));
        $this->assertEquals('text', $helper->getPreviewType('test.csv'));
        $this->assertEquals('text', $helper->getPreviewType('test.json'));
        $this->assertEquals('text', $helper->getPreviewType('test.log'));
        $this->assertEquals('text', $helper->getPreviewType('test.yaml'));
        $this->assertEquals('text', $helper->getPreviewType('test.py'));
        $this->assertEquals('markdown', $helper->getPreviewType('test.markdown'));
        $this->assertEquals('markdown', $helper->getPreviewType('test.md'));
        $this->assertEquals('html', $helper->getPreviewType('test.html'));
        $this->assertEquals('pdf', $helper->getPreviewType('test.pdf'));
        $this->assertEquals('audio', $helper->getPreviewType('test.mp3'));
        $this->assertEquals('audio', $helper->getPreviewType('test.ogg'));
        $this->assertEquals('video', $helper->getPreviewType('test.mp4'));
        $this->assertEquals('video', $helper->getPreviewType('test.webm'));
        $this->assertEquals(null, $helper->getPreviewType('test.doc'));
    }

    public function testGetBrowserViewType()
    {
        $fileHelper = new FileHelper($this->container);
        $this->assertSame('application/pdf', $fileHelper->getBrowserViewType('SomeFile.PDF'));
        $this->assertSame('image/svg+xml', $fileHelper->getBrowserViewType('SomeFile.svg'));
        $this->assertSame(null, $fileHelper->getBrowserViewType('SomeFile.mp3'));
        $this->assertSame(null, $fileHelper->getBrowserViewType('SomeFile.mp4'));
        $this->assertSame(null, $fileHelper->getBrowserViewType('SomeFile.doc'));
    }

    public function testGetAudioMimeType()
    {
        $fileHelper = new FileHelper($this->container);
        $this->assertSame('audio/mpeg', $fileHelper->getAudioMimeType('test.mp3'));
        $this->assertSame('audio/ogg', $fileHelper->getAudioMimeType('test.ogg'));
        $this->assertSame('audio/flac', $fileHelper->getAudioMimeType('test.flac'));
        $this->assertSame('audio/wav', $fileHelper->getAudioMimeType('test.wav'));
        $this->assertSame('audio/mp4', $fileHelper->getAudioMimeType('test.m4a'));
        $this->assertSame(null, $fileHelper->getAudioMimeType('test.mp4'));
    }

    public function testGetVideoMimeType()
    {
        $fileHelper = new FileHelper($this->container);
        $this->assertSame('video/mp4', $fileHelper->getVideoMimeType('test.mp4'));
        $this->assertSame('video/webm', $fileHelper->getVideoMimeType('test.webm'));
        $this->assertSame('video/quicktime', $fileHelper->getVideoMimeType('test.mov'));
        $this->assertSame(null, $fileHelper->getVideoMimeType('test.mp3'));
    }

    public function testGetImageMimeTypeWebp()
    {
        $fileHelper = new FileHelper($this->container);
        $this->assertSame('image/webp', $fileHelper->getImageMimeType('test.webp'));
        $this->assertSame('image/webp', $fileHelper->getImageMimeType('test.WEBP'));
    }
}
