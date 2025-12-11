# Media Format Support in Kanboard

This document analyzes Kanboard's current media format support and provides guidance for extending support for modern image and video formats.

---

## Table of Contents

1. [Current State Analysis](#current-state-analysis)
2. [Image Format Support](#image-format-support)
   - [Current Supported Formats](#current-supported-image-formats)
   - [Adding WebP Support](#adding-webp-support)
   - [Adding AVIF Support](#adding-avif-support)
3. [Video Format Support](#video-format-support)
   - [Current Video Handling](#current-video-handling)
   - [Video Player No Sound Issue](#video-player-no-sound-issue)
   - [Adding WebM Support](#adding-webm-support)
4. [Implementation Guide](#implementation-guide)
5. [Browser Compatibility](#browser-compatibility)

---

## Current State Analysis

### File Handling Architecture

Kanboard handles media files through several key components:

| Component | File | Purpose |
|-----------|------|---------|
| `FileModel` | `app/Model/FileModel.php` | Base file operations, determines if file is an image |
| `FileHelper` | `app/Helper/FileHelper.php` | MIME types, icons, preview types |
| `Thumbnail` | `app/Core/Thumbnail.php` | Generates image thumbnails using GD library |
| `FileViewerController` | `app/Controller/FileViewerController.php` | Serves files to browser |

### Key Functions

```php
// FileModel::isImage() - Determines if file gets thumbnail
public function isImage($filename) {
    switch (get_file_extension($filename)) {
        case 'jpeg':
        case 'jpg':
        case 'png':
        case 'gif':
            return true;
    }
    return false;
}

// FileHelper::getImageMimeType() - Returns MIME for images
public function getImageMimeType($filename) {
    switch (get_file_extension($filename)) {
        case 'jpeg':
        case 'jpg':
            return 'image/jpeg';
        case 'png':
            return 'image/png';
        case 'gif':
            return 'image/gif';
        default:
            return 'image/jpeg';
    }
}

// FileHelper::getBrowserViewType() - Returns MIME for browser preview
public function getBrowserViewType($filename) {
    switch (get_file_extension($filename)) {
        case 'mp4':
            return 'video/mp4';
        case 'webm':
            return 'video/webm';
        // ... etc
    }
}
```

---

## Image Format Support

### Current Supported Image Formats

| Format | Extension | Thumbnail | Browser View | MIME Type |
|--------|-----------|-----------|--------------|-----------|
| JPEG | `.jpg`, `.jpeg` | ✅ Yes | ✅ Yes | `image/jpeg` |
| PNG | `.png` | ✅ Yes | ✅ Yes | `image/png` |
| GIF | `.gif` | ✅ Yes | ✅ Yes | `image/gif` |
| SVG | `.svg` | ❌ No | ✅ Yes | `image/svg+xml` |
| **WebP** | `.webp` | ❌ No | ❌ No | - |
| **AVIF** | `.avif` | ❌ No | ❌ No | - |

### Adding WebP Support

#### Requirements

- **PHP GD Extension**: WebP support requires PHP compiled with `--with-webp`
- **PHP Version**: PHP 7.1+ (recommended 8.0+)
- **Check support**: `gd_info()['WebP Support']`

#### Files to Modify

**1. `app/Model/FileModel.php`** - Add WebP to isImage():

```php
public function isImage($filename)
{
    switch (get_file_extension($filename)) {
        case 'jpeg':
        case 'jpg':
        case 'png':
        case 'gif':
        case 'webp':  // ADD THIS
            return true;
    }
    return false;
}
```

**2. `app/Helper/FileHelper.php`** - Add WebP MIME type and icon:

```php
// In icon() method
case 'jpeg':
case 'jpg':
case 'png':
case 'gif':
case 'svg':
case 'webp':  // ADD THIS
    return 'fa-file-image-o';

// In getImageMimeType() method
case 'webp':  // ADD THIS
    return 'image/webp';

// In getBrowserViewType() method (for inline viewing)
case 'webp':  // ADD THIS
    return 'image/webp';
```

**3. `app/Core/Thumbnail.php`** - No changes needed!

The `Thumbnail` class uses `imagecreatefromstring()` which automatically detects and handles WebP if GD supports it.

#### Verify GD WebP Support

```php
<?php
$gd = gd_info();
echo "WebP Support: " . ($gd['WebP Support'] ? 'Yes' : 'No');
```

### Adding AVIF Support

#### Requirements

- **PHP GD Extension**: AVIF support requires PHP 8.1+ compiled with `--with-avif`
- **libavif library**: Must be installed on the system
- **Check support**: `gd_info()['AVIF Support']`

#### Files to Modify

Same as WebP, add `'avif'` case to:
- `FileModel::isImage()`
- `FileHelper::icon()`
- `FileHelper::getImageMimeType()` → return `'image/avif'`
- `FileHelper::getBrowserViewType()` → return `'image/avif'`

#### Important Considerations

| Aspect | WebP | AVIF |
|--------|------|------|
| PHP Version | 7.1+ | 8.1+ |
| GD Support | Common | Requires libavif |
| Docker Alpine | ✅ Available | ⚠️ May need additional packages |
| Browser Support | 97%+ | 92%+ |
| File Size | ~25-35% smaller than JPEG | ~50% smaller than JPEG |

---

## Video Format Support

### Current Video Handling

Kanboard does **NOT** have a built-in video player. Videos are handled by:

1. Browser's native `<video>` element (when clicking "View file")
2. Direct file streaming via `FileViewerController::browser()`

#### Currently Supported Video Formats

| Format | Extension | Browser View | MIME Type |
|--------|-----------|--------------|-----------|
| MP4 | `.mp4` | ✅ Yes | `video/mp4` |
| WebM | `.webm` | ✅ Yes | `video/webm` |
| AVI | `.avi` | ⚠️ Limited | `video/x-msvideo` |
| MOV | `.mov` | ⚠️ Limited | `video/quicktime` |
| M4V | `.m4v` | ✅ Yes | `video/x-m4v` |
| MKV | `.mkv` | ❌ Icon only | - |

### Video Player No Sound Issue

#### Root Cause Analysis

The "no sound" issue when viewing videos in Kanboard is **NOT a bug in Kanboard** - it's caused by browser autoplay policies.

#### Why Videos Play Without Sound

Modern browsers (Chrome 66+, Safari 11+, Firefox 66+) have strict autoplay policies:

```
Browser Autoplay Policy:
┌─────────────────────────────────────────────────────────────┐
│ Videos opened in new tab/window can only autoplay if:       │
│ 1. Video is muted, OR                                       │
│ 2. User has previously interacted with the site, OR         │
│ 3. Site is on user's autoplay allowlist                     │
└─────────────────────────────────────────────────────────────┘
```

When you click "View file" in Kanboard:
1. A new browser tab/window opens
2. Browser streams the video file directly
3. Browser tries to autoplay → **Policy blocks sound**
4. User sees video but hears no audio

#### This is NOT a Kanboard Bug

The video file itself has audio. The browser is intentionally muting it due to autoplay policies.

#### Solutions

**Solution 1: User Action (Easiest)**
- Click the volume/unmute button on the browser's video player
- The audio will play normally

**Solution 2: Download and Play Locally**
- Use "Download" instead of "View file"
- Open the downloaded file with a local media player

**Solution 3: Add Custom Video Player (Development)**

Create a template that embeds video with controls (not autoplay):

```php
<!-- In app/Template/file_viewer/show.php -->
<?php if ($this->file->isVideo($file['name'])): ?>
    <video controls preload="metadata" style="max-width: 100%;">
        <source src="<?= $this->url->href('FileViewerController', 'browser', $params) ?>" 
                type="<?= $this->file->getBrowserViewType($file['name']) ?>">
        Your browser does not support the video tag.
    </video>
<?php endif ?>
```

This requires user to click play, which satisfies browser autoplay policies.

### Adding WebM Support

**Good news: WebM is already supported!**

Looking at `FileHelper.php`:

```php
// In icon() method
case 'webm':
    return 'fa-file-video-o';

// In getBrowserViewType() method
case 'webm':
    return 'video/webm';
```

WebM files can already be:
- ✅ Uploaded
- ✅ Viewed in browser (with native player)
- ✅ Downloaded

No changes needed for WebM support.

---

## Implementation Guide

### Adding Full WebP Support

#### Step 1: Update FileModel.php

```php
// app/Model/FileModel.php, line ~243
public function isImage($filename)
{
    switch (get_file_extension($filename)) {
        case 'jpeg':
        case 'jpg':
        case 'png':
        case 'gif':
        case 'webp':  // New
            return true;
    }
    return false;
}
```

#### Step 2: Update FileHelper.php

```php
// app/Helper/FileHelper.php

// In icon() method, add to image cases:
case 'webp':
    return 'fa-file-image-o';

// In getImageMimeType() method:
case 'webp':
    return 'image/webp';

// In getBrowserViewType() method:
case 'webp':
    return 'image/webp';
```

#### Step 3: Verify Docker Has WebP Support

Check `Dockerfile` or run inside container:
```bash
php -r "print_r(gd_info());" | grep -i webp
```

If not supported, add to Dockerfile:
```dockerfile
RUN apk add --no-cache libwebp-dev
```

### Adding Full AVIF Support

Same pattern as WebP, but requires:
1. PHP 8.1+
2. libavif installed
3. GD compiled with AVIF support

```dockerfile
# In Dockerfile, add:
RUN apk add --no-cache libavif-dev
```

---

## Browser Compatibility

### Image Format Support

| Format | Chrome | Firefox | Safari | Edge |
|--------|--------|---------|--------|------|
| JPEG | ✅ All | ✅ All | ✅ All | ✅ All |
| PNG | ✅ All | ✅ All | ✅ All | ✅ All |
| GIF | ✅ All | ✅ All | ✅ All | ✅ All |
| WebP | ✅ 32+ | ✅ 65+ | ✅ 14+ | ✅ 18+ |
| AVIF | ✅ 85+ | ✅ 93+ | ✅ 16.4+ | ✅ 121+ |

### Video Format Support

| Format | Chrome | Firefox | Safari | Edge |
|--------|--------|---------|--------|------|
| MP4 (H.264) | ✅ All | ✅ 35+ | ✅ All | ✅ All |
| WebM (VP8/VP9) | ✅ All | ✅ All | ✅ 14.1+ | ✅ All |
| AVI | ❌ | ❌ | ❌ | ❌ |
| MKV | ❌ | ❌ | ❌ | ❌ |

---

## Summary

| Feature | Status | Action Required |
|---------|--------|-----------------|
| WebP Images | ❌ Not supported | Add to FileModel + FileHelper |
| AVIF Images | ❌ Not supported | Add to FileModel + FileHelper (PHP 8.1+ required) |
| WebM Videos | ✅ Supported | None |
| Video Sound | ⚠️ Browser policy | Not a bug - add custom player for better UX |

### Priority Recommendations

1. **High Priority**: Add WebP support (widely used, good compression)
2. **Medium Priority**: Add custom video player template (better UX)
3. **Low Priority**: Add AVIF support (requires PHP 8.1+, limited server support)

---

## References

- [PHP GD Image Functions](https://www.php.net/manual/en/ref.image.php)
- [WebP Support in PHP](https://www.php.net/manual/en/function.imagecreatefromwebp.php)
- [AVIF Support in PHP](https://www.php.net/manual/en/function.imagecreatefromavif.php)
- [Chrome Autoplay Policy](https://developer.chrome.com/blog/autoplay/)
- [Can I Use WebP](https://caniuse.com/webp)
- [Can I Use AVIF](https://caniuse.com/avif)
