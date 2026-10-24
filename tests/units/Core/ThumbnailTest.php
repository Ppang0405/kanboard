<?php

namespace KanboardTests\units\Core;

use KanboardTests\units\Base;
use Kanboard\Core\Thumbnail;

class ThumbnailTest extends Base
{
    private function createPng($width, $height)
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        return ob_get_clean();
    }

    public function testFitWidthScalesDownWideImages()
    {
        $blob = Thumbnail::createFromString($this->createPng(1200, 600))->fitWidth(600, 1200)->toJpegString();
        $size = getimagesizefromstring($blob);

        $this->assertEquals(array(600, 300), array($size[0], $size[1]));
        $this->assertEquals('image/jpeg', $size['mime']);
    }

    public function testFitWidthKeepsSmallImages()
    {
        $blob = Thumbnail::createFromString($this->createPng(100, 50))->fitWidth(600, 1200)->toJpegString();
        $size = getimagesizefromstring($blob);

        $this->assertEquals(array(100, 50), array($size[0], $size[1]));
    }

    public function testFitWidthCropsTallImages()
    {
        $blob = Thumbnail::createFromString($this->createPng(1000, 5000))->fitWidth(600, 1200)->toJpegString();
        $size = getimagesizefromstring($blob);

        $this->assertEquals(array(600, 1200), array($size[0], $size[1]));
    }
}
