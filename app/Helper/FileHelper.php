<?php

namespace Kanboard\Helper;

use Kanboard\Core\Base;

/**
 * File helpers
 *
 * @package helper
 * @author  Frederic Guillot
 */
class FileHelper extends Base
{
    /**
     * Get file icon
     *
     * @access public
     * @param  string   $filename   Filename
     * @return string               Font-Awesome-Icon-Name
     */
    public function icon($filename)
    {
        switch (get_file_extension($filename)) {
            case 'jpeg':
            case 'jpg':
            case 'png':
            case 'gif':
            case 'svg':
            case 'webp':
            case 'avif':
                return 'fa-file-image-o';
            case 'xls':
            case 'xlsx':
            case 'xlsm':
                return 'fa-file-excel-o';
            case 'doc':
            case 'docx':
                return 'fa-file-word-o';
            case 'ppt':
            case 'pptx':
                return 'fa-file-powerpoint-o';
            case 'zip':
            case 'rar':
            case 'tar':
            case 'bz2':
            case 'xz':
            case 'gz':
                return 'fa-file-archive-o';
            case 'mp3':
            case 'amr':
            case 'flac':
            case 'm4a':
            case 'ogg':
            case 'opus':
            case 'wav':
            case 'wma':
            case 'midi':
            case 'mid':
                return 'fa-file-audio-o';
            case 'avi':
            case 'mov':
            case 'mp4':
            case 'mkv':
            case 'webm':
                return 'fa-file-video-o';
            case 'php':
            case 'html':
            case 'css':
            case 'js':
                return 'fa-file-code-o';
            case 'pdf':
                return 'fa-file-pdf-o';
        }

        return 'fa-file-o';
    }

    /**
     * Return the image mimetype based on the file extension
     *
     * Supports modern image formats including WebP and AVIF.
     *
     * @access public
     * @param  string $filename
     * @return string
     */
    public function getImageMimeType($filename)
    {
        switch (get_file_extension($filename)) {
            case 'jpeg':
            case 'jpg':
                return 'image/jpeg';
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            case 'avif':
                return 'image/avif';
            default:
                return 'image/jpeg';
        }
    }

    /**
     * Get the preview type
     *
     * @access public
     * @param  string $filename
     * @return string
     */
    public function getPreviewType($filename)
    {
        switch (get_file_extension($filename)) {
            case 'md':
            case 'markdown':
                return 'markdown';
            case 'txt':
                return 'text';
        }

        return null;
    }

    /**
     * Return the browser view mime-type based on the file extension.
     *
     * Supports modern image formats (WebP, AVIF) and video formats (WebM, MKV).
     *
     * @access public
     * @param  string $filename
     * @return string|null
     */
    public function getBrowserViewType($filename)
    {
        switch (get_file_extension($filename)) {
            // Documents
            case 'pdf':
                return 'application/pdf';
            // Audio
            case 'mp3':
                return 'audio/mpeg';
            case 'ogg':
                return 'audio/ogg';
            case 'flac':
                return 'audio/flac';
            case 'wav':
                return 'audio/wav';
            case 'm4a':
                return 'audio/mp4';
            // Video
            case 'avi':
                return 'video/x-msvideo';
            case 'webm':
                return 'video/webm';
            case 'mov':
                return 'video/quicktime';
            case 'm4v':
                return 'video/x-m4v';
            case 'mp4':
                return 'video/mp4';
            case 'mkv':
                return 'video/x-matroska';
            // Images
            case 'svg':
                return 'image/svg+xml';
            case 'webp':
                return 'image/webp';
            case 'avif':
                return 'image/avif';
        }

        return null;
    }

    /**
     * Check if a filename is a video file
     *
     * @access public
     * @param  string $filename
     * @return bool
     */
    public function isVideo($filename)
    {
        switch (get_file_extension($filename)) {
            case 'mp4':
            case 'webm':
            case 'mov':
            case 'avi':
            case 'mkv':
            case 'm4v':
                return true;
        }

        return false;
    }

    /**
     * Check if a filename is an audio file
     *
     * @access public
     * @param  string $filename
     * @return bool
     */
    public function isAudio($filename)
    {
        switch (get_file_extension($filename)) {
            case 'mp3':
            case 'ogg':
            case 'flac':
            case 'wav':
            case 'm4a':
            case 'opus':
            case 'wma':
                return true;
        }

        return false;
    }

    /**
     * Check if WebP image format is supported by GD
     *
     * @access public
     * @return bool
     */
    public function isWebpSupported()
    {
        if (!function_exists('gd_info')) {
            return false;
        }

        $gdInfo = gd_info();
        return !empty($gdInfo['WebP Support']);
    }

    /**
     * Check if AVIF image format is supported by GD
     *
     * Requires PHP 8.1+ and libavif
     *
     * @access public
     * @return bool
     */
    public function isAvifSupported()
    {
        if (!function_exists('gd_info')) {
            return false;
        }

        $gdInfo = gd_info();
        return !empty($gdInfo['AVIF Support']);
    }
}
