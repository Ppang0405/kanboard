<?php

namespace Kanboard\Core;

/**
 * Thumbnail Generator
 *
 * @package core
 * @author  Frederic Guillot
 */
class Thumbnail
{
    protected $compression = -1;
    protected $metadata = array();
    protected $srcImage;
    protected $dstImage;

    /**
     * Create a thumbnail from a local file
     *
     * @static
     * @access public
     * @param  string $filename
     * @return Thumbnail
     */
    public static function createFromFile($filename)
    {
        $self = new static();
        $self->fromFile($filename);
        return $self;
    }

    /**
     * Create a thumbnail from a string
     *
     * @static
     * @access public
     * @param  string $blob
     * @return Thumbnail
     */
    public static function createFromString($blob)
    {
        $self = new static();
        $self->fromString($blob);
        return $self;
    }

    /**
     * Load the local image file in memory with GD
     *
     * @access public
     * @param  string $filename
     * @return Thumbnail
     */
    public function fromFile($filename)
    {
        $this->metadata = getimagesize($filename);
        $this->srcImage = @imagecreatefromstring(file_get_contents($filename));
        return $this;
    }

    /**
     * Load the image blob in memory with GD
     *
     * @access public
     * @param  string $blob
     * @return Thumbnail
     */
    public function fromString($blob)
    {
        if (!function_exists('getimagesizefromstring')) {
            $uri = 'data://application/octet-stream;base64,' . base64_encode($blob);
            $this->metadata = getimagesize($uri);
        } else {
            $this->metadata = getimagesizefromstring($blob);
        }

        // Avoid warning from libpng when loading PNG image with obscure or incorrect iCCP profiles
        $this->srcImage = @imagecreatefromstring($blob);
        return $this;
    }

    /**
     * Resize the image
     *
     * @access public
     * @param  int $width
     * @param  int $height
     * @return Thumbnail
     */
    public function resize($width = 250, $height = 100)
    {
        $srcWidth = $this->metadata[0];
        $srcHeight = $this->metadata[1];
        $dstX = 0;
        $dstY = 0;

        if ($width == 0 && $height == 0) {
            $width = 100;
            $height = 100;
        }

        if ($width > 0 && $height == 0) {
            $dstWidth = $width;
            $dstHeight = floor($srcHeight * ($width / $srcWidth));
            $this->dstImage = imagecreatetruecolor($dstWidth, $dstHeight);
        } elseif ($width == 0 && $height > 0) {
            $dstWidth = floor($srcWidth * ($height / $srcHeight));
            $dstHeight = $height;
            $this->dstImage = imagecreatetruecolor($dstWidth, $dstHeight);
        } else {
            $srcRatio = $srcWidth / $srcHeight;
            $resizeRatio = $width / $height;

            if ($srcRatio <= $resizeRatio) {
                $dstWidth = $width;
                $dstHeight = floor($srcHeight * ($width / $srcWidth));
                $dstY = ($dstHeight - $height) / 2 * (-1);
            } else {
                $dstWidth = floor($srcWidth * ($height / $srcHeight));
                $dstHeight = $height;
                $dstX = ($dstWidth - $width) / 2 * (-1);
            }

            $this->dstImage = imagecreatetruecolor($width, $height);
        }

        imagealphablending($this->dstImage, false);
        imagesavealpha($this->dstImage, true);

        imagecopyresampled($this->dstImage, $this->srcImage, (int) $dstX, (int) $dstY, 0, 0, (int) $dstWidth, (int) $dstHeight, (int) $srcWidth, (int) $srcHeight);

        return $this;
    }

    /**
     * Scale down the image to a maximum width, keeping the aspect ratio
     *
     * Transparent areas are flattened on a white background and very tall
     * images are cropped from the top to keep the output small.
     *
     * @access public
     * @param  integer $maxWidth
     * @param  integer $maxHeight
     * @return $this
     */
    public function fitWidth($maxWidth, $maxHeight)
    {
        $srcWidth = $this->metadata[0];
        $srcHeight = $this->metadata[1];

        $dstWidth = min($maxWidth, $srcWidth);
        $ratio = $dstWidth / $srcWidth;
        $dstHeight = min($maxHeight, max(1, (int) round($srcHeight * $ratio)));
        $srcHeight = min($srcHeight, (int) round($dstHeight / $ratio));

        $this->dstImage = imagecreatetruecolor($dstWidth, $dstHeight);
        imagefill($this->dstImage, 0, 0, imagecolorallocate($this->dstImage, 255, 255, 255));
        imagecopyresampled($this->dstImage, $this->srcImage, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);

        return $this;
    }

    /**
     * Get the JPEG image as string
     *
     * @access public
     * @param  integer $quality
     * @return string
     */
    public function toJpegString($quality = 82)
    {
        ob_start();
        imagejpeg($this->dstImage, null, $quality);
        return ob_get_clean();
    }

    /**
     * Save the thumbnail to a local file
     *
     * @access public
     * @param  string $filename
     * @return Thumbnail
     */
    public function toFile($filename)
    {
        imagepng($this->dstImage, $filename, $this->compression);
        imagedestroy($this->dstImage);
        imagedestroy($this->srcImage);
        return $this;
    }

    /**
     * Return the thumbnail as a string
     *
     * @access public
     * @return string
     */
    public function toString()
    {
        ob_start();
        imagepng($this->dstImage, null, $this->compression);
        imagedestroy($this->dstImage);
        imagedestroy($this->srcImage);
        return ob_get_clean();
    }

    /**
     * Output the thumbnail directly to the browser or stdout
     *
     * @access public
     */
    public function toOutput()
    {
        imagepng($this->dstImage, null, $this->compression);
        imagedestroy($this->dstImage);
        imagedestroy($this->srcImage);
    }
}
