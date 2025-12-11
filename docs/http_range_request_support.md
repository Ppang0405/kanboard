# HTTP Range Request Support for Video Seeking

## Problem Statement

Currently, Kanboard serves video files as complete downloads without support for HTTP Range Requests. This causes two issues:

1. **Video seeking doesn't work** - Users cannot skip to a specific position in the video
2. **Large file streaming is inefficient** - The entire file must be downloaded before playback

## How HTTP Range Requests Work

### Normal Request (Current Behavior)
```
Client:  GET /video.mp4 HTTP/1.1

Server:  HTTP/1.1 200 OK
         Content-Length: 50000000
         Content-Type: video/mp4
         [entire 50MB file]
```

### Range Request (Required for Seeking)
```
Client:  GET /video.mp4 HTTP/1.1
         Range: bytes=10000000-20000000

Server:  HTTP/1.1 206 Partial Content
         Accept-Ranges: bytes
         Content-Range: bytes 10000000-20000000/50000000
         Content-Length: 10000001
         Content-Type: video/mp4
         [10MB partial content]
```

---

## Files to Modify

### 1. `app/Core/Http/Response.php`

Add a new method for serving files with Range support:

```php
/**
 * Serve a file with HTTP Range Request support (for video seeking)
 *
 * @access public
 * @param  string  $filePath  Full path to the file
 * @param  string  $mimeType  MIME type of the file
 * @param  string  $etag      Optional ETag for caching
 */
public function withRangeSupport($filePath, $mimeType, $etag = '')
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        $this->status(404);
        return;
    }

    $fileSize = filesize($filePath);
    $rangeHeader = $this->request->getHeader('Range');

    // Always advertise range support
    $this->withHeader('Accept-Ranges', 'bytes');
    $this->withContentType($mimeType);

    if ($etag) {
        $this->withHeader('ETag', '"' . $etag . '"');
    }

    // Check for If-None-Match (caching)
    if ($etag && $this->request->getHeader('If-None-Match') === '"' . $etag . '"') {
        $this->status(304);
        return;
    }

    // No Range header - serve entire file
    if (empty($rangeHeader)) {
        $this->withHeader('Content-Length', $fileSize);
        $this->send();
        readfile($filePath);
        return;
    }

    // Parse Range header: "bytes=START-END" or "bytes=START-"
    if (!preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
        $this->status(416); // Range Not Satisfiable
        $this->withHeader('Content-Range', 'bytes */' . $fileSize);
        $this->send();
        return;
    }

    $start = $matches[1] === '' ? 0 : (int)$matches[1];
    $end = $matches[2] === '' ? $fileSize - 1 : (int)$matches[2];

    // Validate range
    if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
        $this->status(416); // Range Not Satisfiable
        $this->withHeader('Content-Range', 'bytes */' . $fileSize);
        $this->send();
        return;
    }

    $length = $end - $start + 1;

    // Send 206 Partial Content
    $this->withStatusCode(206);
    $this->withHeader('Content-Range', "bytes $start-$end/$fileSize");
    $this->withHeader('Content-Length', $length);
    $this->send();

    // Output the requested range
    $this->outputFileRange($filePath, $start, $length);
}

/**
 * Output a specific range of a file
 *
 * @access private
 * @param  string  $filePath
 * @param  int     $start
 * @param  int     $length
 */
private function outputFileRange($filePath, $start, $length)
{
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        return;
    }

    fseek($handle, $start);

    $bufferSize = 8192; // 8KB chunks
    $remaining = $length;

    while ($remaining > 0 && !feof($handle)) {
        $readSize = min($bufferSize, $remaining);
        echo fread($handle, $readSize);
        $remaining -= $readSize;
        flush();
    }

    fclose($handle);
}
```

---

### 2. `app/Core/ObjectStorage/ObjectStorageInterface.php`

Add method signature for getting file path:

```php
/**
 * Get the full filesystem path for a key
 *
 * @param  string  $key
 * @return string|null  Returns null if not a local file
 */
public function getFilePath($key);

/**
 * Get file size
 *
 * @param  string  $key
 * @return int
 */
public function getFileSize($key);
```

---

### 3. `app/Core/ObjectStorage/FileStorage.php`

Implement the new methods:

```php
/**
 * Get the full filesystem path for a key
 *
 * @access public
 * @param  string  $key
 * @return string
 */
public function getFilePath($key)
{
    return $this->getRealFilePath($key);
}

/**
 * Get file size
 *
 * @access public
 * @param  string  $key
 * @return int
 */
public function getFileSize($key)
{
    return filesize($this->getRealFilePath($key));
}

/**
 * Output a range of file content (for video seeking)
 *
 * @access public
 * @param  string  $key
 * @param  int     $start
 * @param  int     $length
 */
public function outputRange($key, $start, $length)
{
    $filePath = $this->getRealFilePath($key);
    $handle = fopen($filePath, 'rb');

    if ($handle === false) {
        throw new ObjectStorageException('Unable to open file: ' . $filePath);
    }

    fseek($handle, $start);

    $bufferSize = 8192;
    $remaining = $length;

    while ($remaining > 0 && !feof($handle)) {
        $readSize = min($bufferSize, $remaining);
        echo fread($handle, $readSize);
        $remaining -= $readSize;
        flush();
    }

    fclose($handle);
}
```

---

### 4. `app/Controller/FileViewerController.php`

Add a new method for streaming media files:

```php
/**
 * Stream media file with Range support (for video/audio seeking)
 *
 * @access public
 */
public function stream()
{
    try {
        $file = $this->getFile();
        $filePath = $this->objectStorage->getFilePath($file['path']);
        $mimeType = $this->helper->file->getBrowserViewType($file['name']);

        $this->response->withRangeSupport($filePath, $mimeType, $file['etag']);
    } catch (ObjectStorageException $e) {
        $this->logger->error($e->getMessage());
        $this->response->status(404);
    }
}
```

Modify the `browser()` method to use streaming for video files:

```php
/**
 * Display file in browser
 *
 * @access public
 */
public function browser()
{
    $file = $this->getFile();
    $mimeType = $this->helper->file->getBrowserViewType($file['name']);

    // Use streaming with Range support for video/audio files
    if ($this->isStreamableMedia($mimeType)) {
        $this->stream();
        return;
    }

    $this->renderFileWithCache($file, $mimeType);
}

/**
 * Check if MIME type is streamable media
 *
 * @param  string  $mimeType
 * @return bool
 */
private function isStreamableMedia($mimeType)
{
    $streamableTypes = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-matroska',
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
    ];

    return in_array($mimeType, $streamableTypes);
}
```

---

### 5. `app/Core/Http/Request.php`

Ensure the `getHeader()` method can read the `Range` header:

```php
/**
 * Get HTTP header value
 *
 * @access public
 * @param  string  $name  Header name (case-insensitive)
 * @return string
 */
public function getHeader($name)
{
    $name = strtoupper(str_replace('-', '_', $name));

    // Check both HTTP_* and direct header
    if (isset($_SERVER['HTTP_' . $name])) {
        return $_SERVER['HTTP_' . $name];
    }

    // Some headers like Content-Type don't have HTTP_ prefix
    if (isset($_SERVER[$name])) {
        return $_SERVER[$name];
    }

    return '';
}
```

---

## Route Configuration

Add a route for the new stream action in `app/ServiceProvider/RouteProvider.php`:

```php
// In the routes configuration
$container['router']->addRoute('FileViewerController', 'stream', 'file/:file_id/stream');
```

---

## Testing the Implementation

### 1. Test Range Request with cURL

```bash
# Request first 1MB of video
curl -I -H "Range: bytes=0-1048575" "http://localhost:8000/?controller=FileViewerController&action=stream&file_id=123"

# Expected response headers:
# HTTP/1.1 206 Partial Content
# Accept-Ranges: bytes
# Content-Range: bytes 0-1048575/50000000
# Content-Length: 1048576
```

### 2. Test in Browser

Open DevTools → Network tab → Play video and seek → Verify:
- Initial request returns `206 Partial Content`
- Seeking triggers new Range requests
- `Accept-Ranges: bytes` header is present

---

## Compatibility Notes

### PHP Built-in Server
The PHP built-in server (`php -S`) supports Range requests when implemented in application code.

### Apache/Nginx
For static files served directly by the web server, enable Range support:

**Apache (.htaccess):**
```apache
<FilesMatch "\.(mp4|webm|ogg|mp3|wav)$">
    Header set Accept-Ranges bytes
</FilesMatch>
```

**Nginx:**
```nginx
location ~* \.(mp4|webm|ogg|mp3|wav)$ {
    add_header Accept-Ranges bytes;
}
```

### Cloud Storage (S3, etc.)
If using cloud object storage, Range requests should be handled by the storage provider. The implementation above works for local file storage only.

---

## Security Considerations

1. **Path Traversal** - The existing `getRealFilePath()` validation prevents directory traversal attacks
2. **Memory Usage** - Streaming in 8KB chunks prevents memory exhaustion
3. **Range Validation** - Invalid ranges return `416 Range Not Satisfiable`
4. **Authentication** - Existing `getFile()` method handles authorization

---

## Performance Impact

| Scenario | Before | After |
|----------|--------|-------|
| 50MB video seek to middle | Download 50MB, then seek | Download ~5MB, play immediately |
| Memory usage | Entire file in memory | 8KB buffer |
| Network efficiency | Full file always | Only requested bytes |

---

## Related Files

- `app/Core/Http/Response.php` - HTTP response handling
- `app/Core/Http/Request.php` - HTTP request parsing
- `app/Controller/FileViewerController.php` - File viewing endpoints
- `app/Core/ObjectStorage/FileStorage.php` - Local file storage
- `app/Core/ObjectStorage/ObjectStorageInterface.php` - Storage interface

