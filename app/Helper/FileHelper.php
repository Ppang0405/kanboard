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
            case 'webp':
            case 'svg':
                return 'fa-file-image-o';
            case 'xls':
            case 'xlsx':
            case 'xlsm':
            case 'ods':
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
     * @access public
     * @param  $filename
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
            case 'csv':
            case 'tsv':
            case 'log':
            case 'json':
            case 'xml':
            case 'yaml':
            case 'yml':
            case 'toml':
            case 'ini':
            case 'cfg':
            case 'conf':
            case 'sql':
            case 'js':
            case 'css':
            case 'php':
            case 'py':
            case 'rb':
            case 'java':
            case 'c':
            case 'h':
            case 'cpp':
            case 'hpp':
            case 'cs':
            case 'go':
            case 'rs':
            case 'swift':
            case 'kt':
            case 'ts':
            case 'sh':
            case 'bash':
            case 'pl':
            case 'r':
            case 'lua':
            case 'diff':
            case 'patch':
                return 'text';
            case 'html':
            case 'htm':
                return 'html';
            case 'xls':
            case 'xlsx':
            case 'xlsm':
                return 'spreadsheet';
            case 'pdf':
                return 'pdf';
            case 'mp3':
            case 'ogg':
            case 'oga':
            case 'opus':
            case 'flac':
            case 'wav':
            case 'm4a':
                return 'audio';
            case 'mp4':
            case 'm4v':
            case 'webm':
            case 'mov':
            case 'avi':
            case 'mkv':
                return 'video';
        }

        return null;
    }

    /**
     * Return the browser view mime-type based on the file extension.
     *
     * @access public
     * @param  $filename
     * @return string
     */
    public function getBrowserViewType($filename)
    {
        switch (get_file_extension($filename)) {
            case 'pdf':
                return 'application/pdf';
            case 'svg':
                return 'image/svg+xml';
        }

        return null;
    }

    /**
     * Return the audio mime-type based on the file extension.
     *
     * @access public
     * @param  $filename
     * @return string|null
     */
    public function getAudioMimeType($filename)
    {
        switch (get_file_extension($filename)) {
            case 'mp3':
                return 'audio/mpeg';
            case 'ogg':
            case 'oga':
            case 'opus':
                return 'audio/ogg';
            case 'flac':
                return 'audio/flac';
            case 'wav':
                return 'audio/wav';
            case 'm4a':
                return 'audio/mp4';
        }

        return null;
    }

    /**
     * Return the video mime-type based on the file extension.
     *
     * @access public
     * @param  $filename
     * @return string|null
     */
    public function getVideoMimeType($filename)
    {
        switch (get_file_extension($filename)) {
            case 'mp4':
            case 'm4v':
                return 'video/mp4';
            case 'webm':
                return 'video/webm';
            case 'mov':
                return 'video/quicktime';
            case 'avi':
                return 'video/x-msvideo';
            case 'mkv':
                return 'video/x-matroska';
        }

        return null;
    }
}
