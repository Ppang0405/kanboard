# File Upload Analysis & Improvements

This document analyzes Kanboard's current file upload implementation and provides recommendations for making it faster, more reliable, and support multiple simultaneous uploads.

---

## Table of Contents

1. [Current Implementation Analysis](#current-implementation-analysis)
2. [Issue: Upload Stuck at 52%](#issue-upload-stuck-at-52)
3. [Issue: Sequential Upload Only](#issue-sequential-upload-only)
4. [Recommended Improvements](#recommended-improvements)
5. [Implementation Guide](#implementation-guide)

---

## Current Implementation Analysis

### Upload Flow

```
User selects files
        ↓
JavaScript collects files array
        ↓
User clicks "Upload files"
        ↓
FOR EACH file sequentially:
    ├─ Send entire file via XMLHttpRequest + FormData
    ├─ Show progress bar (e.loaded / e.total)
    ├─ Wait for 200 response
    └─ Move to next file
        ↓
All files uploaded → Success message
```

### Key Files

| File | Purpose | Issues |
|------|---------|--------|
| `assets/js/components/file-upload.js` | Client-side upload UI | Sequential uploads only |
| `assets/js/core/http.js` | XMLHttpRequest wrapper | No chunking, no retry |
| `app/Controller/TaskFileController.php` | Handles upload POST | Processes all at once |
| `app/Model/FileModel.php` | Saves files to storage | Generates thumbnail synchronously |

### Current Code Behavior

```javascript
// assets/js/components/file-upload.js, lines 34-39
function onComplete() {
    currentFileIndex++;
    
    if (currentFileIndex < files.length) {
        // Upload NEXT file (sequential)
        KB.http.uploadFile(options.url, files[currentFileIndex], ...);
    } else {
        // All done
        KB.trigger('modal.stop');
    }
}
```

**Problem**: Files upload one at a time in sequence.

```javascript
// assets/js/core/http.js, lines 111-139
KB.http.uploadFile = function (url, file, csrf, onProgress, ...) {
    var fd = new FormData();
    fd.append('files[]', file);  // Single file
    fd.append('csrf_token', csrf);
    
    var xhr = new XMLHttpRequest();
    xhr.upload.addEventListener('progress', onProgress);
    xhr.send(fd);  // Send entire file in one request
};
```

**Problems**:
- No chunking (entire file sent at once)
- No retry on network failure
- No timeout handling
- No simultaneous uploads

---

## Issue: Upload Stuck at 52%

### Possible Causes

| Cause | Likelihood | How to Verify |
|-------|------------|---------------|
| **Network timeout** | ⭐⭐⭐⭐⭐ | Check browser console for "net::ERR_CONNECTION_RESET" |
| **PHP max_execution_time** | ⭐⭐⭐⭐ | Check server logs for "Maximum execution time exceeded" |
| **Memory exhausted** | ⭐⭐⭐⭐ | Check server logs for "Allowed memory size exhausted" |
| **Nginx timeout** | ⭐⭐⭐ | Check if using reverse proxy with timeout |
| **Connection dropped** | ⭐⭐⭐ | Unstable network |
| **Thumbnail generation** | ⭐⭐ | Large image file (>50MB) |

### Why 52% Specifically?

```
File upload process:
0-100%:  Network upload (browser → server)
100%:    Processing on server (PHP)
         ├─ Move file to storage
         ├─ Generate thumbnail (if image)  ← Can take 30+ seconds!
         └─ Save to database

If stuck at 52%:
├─ File still uploading (network slow)
├─ OR upload complete but server timeout
└─ OR server processing but no progress updates
```

### Debug Steps

1. **Check browser console** (F12 → Network tab):
   - Look for the POST request
   - Check if it's still pending or failed
   - See response headers

2. **Check server logs**:
   ```bash
   docker logs kanboard-frankenphp 2>&1 | grep -i "error\|timeout\|memory"
   ```

3. **Check file size**:
   - Files >50MB may timeout
   - Large images (>20MB) take time to generate thumbnails

---

## Issue: Sequential Upload Only

### Current Behavior

```javascript
// File 1 uploads → waits for completion → File 2 uploads → ...
Upload 1: ████████████████████████████ 100% (30 seconds)
Upload 2:                              ████████████████████ 100% (30 seconds)
Upload 3:                                                   ████████████ 100% (30 seconds)
Total: 90 seconds for 3 files
```

### Why Sequential?

Looking at `file-upload.js` line 34-39, the code only starts the next upload in the `onComplete` callback of the previous upload.

This is intentional but inefficient for multiple files.

---

## Recommended Improvements

### 1. Chunked Uploads (Handles Large Files) ⭐⭐⭐⭐⭐

Split large files into chunks and upload piece by piece.

**Benefits:**
- ✅ Resume on failure
- ✅ Progress updates during server processing
- ✅ Works with slow/unstable networks
- ✅ Handles files >100MB

**Implementation:**

```javascript
/**
 * Upload file in chunks for better reliability and progress tracking
 * 
 * @param {string} url - Upload endpoint
 * @param {File} file - File to upload
 * @param {number} chunkSize - Size of each chunk (default 5MB)
 */
KB.http.uploadFileChunked = function(url, file, csrf, chunkSize, onProgress, onComplete, onError) {
    chunkSize = chunkSize || 5 * 1024 * 1024; // 5MB chunks
    
    var totalChunks = Math.ceil(file.size / chunkSize);
    var currentChunk = 0;
    var uploadId = Math.random().toString(36).substring(7);
    
    function uploadChunk() {
        var start = currentChunk * chunkSize;
        var end = Math.min(start + chunkSize, file.size);
        var chunk = file.slice(start, end);
        
        var fd = new FormData();
        fd.append('file_chunk', chunk);
        fd.append('chunk_index', currentChunk);
        fd.append('total_chunks', totalChunks);
        fd.append('upload_id', uploadId);
        fd.append('filename', file.name);
        fd.append('csrf_token', csrf);
        
        var xhr = new XMLHttpRequest();
        
        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                // Calculate total progress across all chunks
                var chunkProgress = (currentChunk / totalChunks) + (e.loaded / e.total / totalChunks);
                onProgress({ loaded: chunkProgress * file.size, total: file.size, lengthComputable: true });
            }
        });
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === XMLHttpRequest.DONE) {
                if (xhr.status === 200) {
                    currentChunk++;
                    
                    if (currentChunk < totalChunks) {
                        // Upload next chunk
                        uploadChunk();
                    } else {
                        // All chunks uploaded
                        onComplete();
                    }
                } else {
                    onError();
                }
            }
        };
        
        xhr.open('POST', url + '/chunk', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.send(fd);
    }
    
    uploadChunk();
};
```

### 2. Parallel Uploads (Faster for Multiple Files) ⭐⭐⭐⭐

Upload multiple files simultaneously instead of sequentially.

**Benefits:**
- ✅ 3-5x faster for multiple files
- ✅ Better network utilization
- ✅ Better user experience

**Implementation:**

```javascript
// Modified file-upload.js
function uploadFiles() {
    if (files.length === 0) return;
    
    // Track completed uploads
    var completedCount = 0;
    var errors = [];
    
    // Upload all files in parallel
    files.forEach(function(file, index) {
        currentFileIndex = index;
        
        KB.http.uploadFile(
            options.url,
            file,
            options.csrf,
            function onProgress(e) {
                if (e.lengthComputable) {
                    var progress = e.loaded / e.total;
                    var percentage = Math.floor(progress * 100);
                    KB.find('#file-progress-' + index).attr('value', progress);
                    KB.find('#file-percentage-' + index).replaceText('(' + percentage + '%)');
                }
            },
            function onComplete() {
                completedCount++;
                
                // Check if all files are done
                if (completedCount === files.length) {
                    if (errors.length === 0) {
                        showSuccessMessage();
                    } else {
                        showPartialSuccessMessage(completedCount, errors.length);
                    }
                }
            },
            function onError() {
                errors.push(index);
                completedCount++;
                
                var errorElement = KB.dom('div').addClass('file-error').text(options.labelUploadError).build();
                KB.find('#file-item-' + index).add(errorElement);
                
                if (completedCount === files.length) {
                    showErrorMessage(errors.length);
                }
            }
        );
    });
}
```

### 3. Async Thumbnail Generation (Prevents Blocking) ⭐⭐⭐⭐

Move thumbnail generation to a background job.

**Current Problem:**

```php
// app/Model/FileModel.php, lines 333-343
public function uploadFile($id, array $file)
{
    if ($file['error'] == UPLOAD_ERR_OK && $file['size'] > 0) {
        $destination_filename = $this->generatePath($id, $file['name']);
        
        if ($this->isImage($file['name'])) {
            // BLOCKS here for large images!
            $this->generateThumbnailFromFile($file['tmp_name'], $destination_filename);
        }
        
        $this->objectStorage->moveUploadedFile($file['tmp_name'], $destination_filename);
        $this->create($id, $file['name'], $destination_filename, $file['size']);
    }
}
```

**Solution:**

```php
public function uploadFile($id, array $file)
{
    if ($file['error'] == UPLOAD_ERR_OK && $file['size'] > 0) {
        $destination_filename = $this->generatePath($id, $file['name']);
        
        // Move file first
        $this->objectStorage->moveUploadedFile($file['tmp_name'], $destination_filename);
        $file_id = $this->create($id, $file['name'], $destination_filename, $file['size']);
        
        // Generate thumbnail asynchronously (non-blocking)
        if ($this->isImage($file['name']) && $file_id) {
            $this->queueManager->push(
                $this->thumbnailGenerationJob->withParams($file_id, $destination_filename)
            );
        }
        
        return $file_id;
    }
}
```

### 4. Client-Side Compression (Reduce Upload Time) ⭐⭐⭐

Compress images on client before uploading.

**Benefits:**
- ✅ Smaller file sizes
- ✅ Faster uploads
- ✅ Less server storage

**Implementation:**

```javascript
function compressImage(file, maxWidth, maxHeight, quality) {
    return new Promise((resolve) => {
        if (!file.type.startsWith('image/')) {
            resolve(file); // Not an image, return as-is
            return;
        }
        
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = new Image();
            img.onload = function() {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                
                // Calculate new dimensions
                var width = img.width;
                var height = img.height;
                
                if (width > maxWidth || height > maxHeight) {
                    var ratio = Math.min(maxWidth / width, maxHeight / height);
                    width *= ratio;
                    height *= ratio;
                }
                
                canvas.width = width;
                canvas.height = height;
                ctx.drawImage(img, 0, 0, width, height);
                
                canvas.toBlob(function(blob) {
                    blob.name = file.name;
                    resolve(blob);
                }, 'image/jpeg', quality || 0.9);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}
```

### 5. Better Error Handling & Retry ⭐⭐⭐⭐

Add retry logic for failed uploads.

**Implementation:**

```javascript
KB.http.uploadFileWithRetry = function(url, file, csrf, maxRetries, onProgress, onComplete, onError) {
    var retryCount = 0;
    maxRetries = maxRetries || 3;
    
    function attemptUpload() {
        KB.http.uploadFile(
            url,
            file,
            csrf,
            onProgress,
            onComplete,
            function onUploadError() {
                retryCount++;
                
                if (retryCount < maxRetries) {
                    // Wait 2 seconds before retry
                    setTimeout(function() {
                        console.log('Retrying upload, attempt ' + (retryCount + 1));
                        attemptUpload();
                    }, 2000);
                } else {
                    // Max retries reached
                    onError();
                }
            }
        );
    }
    
    attemptUpload();
};
```

### 6. Increase PHP Timeouts

**Current Limits:**

```ini
# docker/frankenphp/php.ini
max_execution_time = 300  # 5 minutes
max_input_time = 300
```

**For Large Files:**

```ini
# For files >50MB
max_execution_time = 600  # 10 minutes
max_input_time = 600
memory_limit = 512M       # More memory for large image processing
```

---

## Why Upload Gets Stuck at 52%

### Debugging the 52% Issue

Based on the screenshot showing files stuck at 52%, here are the most likely causes:

#### 1. Network Timeout (Most Likely)

```
Upload progress:
0-52%:  File uploading to server ████████████░░░░░░░░░░░░
52%:    CONNECTION TIMEOUT (no response) ⏱️
```

**Solution:**
- Increase nginx/proxy timeout
- Use chunked uploads
- Check network stability

#### 2. Server Processing Timeout

```
Upload progress:
0-100%: File uploaded successfully ████████████████████████ ✓
100%:   Server processing (thumbnail, DB write) ⏱️
        But progress bar stuck at 52% (last reported value)
```

**Why 52% exactly?**
- That's where the upload was when the connection timed out
- Or the server started processing and stopped sending progress

**Solution:**
- Generate thumbnails asynchronously
- Increase `max_execution_time`
- Add server-side progress updates

#### 3. Reverse Proxy Timeout (Coolify/Nginx)

If behind Coolify or a reverse proxy:

```
Browser → Coolify Proxy → Kanboard Container
                ↓
         60 second timeout (default)
```

**Solution:**
```nginx
# In reverse proxy
proxy_read_timeout 600s;
proxy_send_timeout 600s;
```

---

## Recommended Improvements

### Priority Matrix

| Improvement | Complexity | Impact | Priority |
|-------------|------------|--------|----------|
| **Async thumbnail generation** | Low | High | 🔥 High |
| **Better error handling** | Low | High | 🔥 High |
| **Increase timeouts** | Low | High | 🔥 High |
| **Parallel uploads** | Medium | High | ⭐ Medium |
| **Chunked uploads** | High | Medium | ⭐ Medium |
| **Client compression** | Medium | Medium | 💡 Low |

---

## Implementation Guide

### Quick Fix (10 minutes) - Stop the 52% Issue

#### 1. Increase PHP Timeouts

```ini
# docker/frankenphp/php.ini
max_execution_time = 600
max_input_time = 600
memory_limit = 512M
```

#### 2. Add Timeout to XHR

```javascript
// assets/js/core/http.js, in uploadFile()
var xhr = new XMLHttpRequest();
xhr.timeout = 600000; // 10 minutes
xhr.ontimeout = function() {
    console.error('Upload timeout after 10 minutes');
    onError();
};
```

#### 3. Move Thumbnail to Background

```php
// app/Model/FileModel.php
public function uploadFile($id, array $file)
{
    if ($file['error'] == UPLOAD_ERR_OK && $file['size'] > 0) {
        $destination_filename = $this->generatePath($id, $file['name']);
        
        // Save file FIRST
        $this->objectStorage->moveUploadedFile($file['tmp_name'], $destination_filename);
        $file_id = $this->create($id, $file['name'], $destination_filename, $file['size']);
        
        // Generate thumbnail AFTER response (async)
        if ($this->isImage($file['name'])) {
            // Queue for background processing
            register_shutdown_function(function() use ($file, $destination_filename) {
                try {
                    $this->generateThumbnailFromFile($file['tmp_name'], $destination_filename);
                } catch (Exception $e) {
                    $this->logger->error('Thumbnail generation failed: ' . $e->getMessage());
                }
            });
        }
        
        return $file_id;
    }
}
```

### Medium Improvement (2-3 hours) - Parallel Uploads

Modify `file-upload.js` to upload multiple files simultaneously:

```javascript
function uploadFiles() {
    if (files.length === 0) return;
    
    var maxParallel = 3; // Upload 3 at a time
    var activeUploads = 0;
    var completedCount = 0;
    var queueIndex = 0;
    
    function startNextUpload() {
        if (queueIndex >= files.length) return;
        if (activeUploads >= maxParallel) return;
        
        var index = queueIndex++;
        activeUploads++;
        
        KB.http.uploadFile(
            options.url,
            files[index],
            options.csrf,
            function onProgress(e) {
                updateProgress(index, e);
            },
            function onComplete() {
                activeUploads--;
                completedCount++;
                
                if (completedCount === files.length) {
                    showSuccess();
                } else {
                    startNextUpload();
                }
            },
            function onError() {
                activeUploads--;
                completedCount++;
                showError(index);
                startNextUpload();
            }
        );
    }
    
    // Start initial batch
    for (var i = 0; i < Math.min(maxParallel, files.length); i++) {
        startNextUpload();
    }
}
```

### Advanced (1-2 days) - Full Chunked Upload System

Requires both client and server changes.

#### Client (JavaScript)

See chunked upload example in section #1.

#### Server (PHP)

```php
// app/Controller/TaskFileController.php

public function uploadChunk()
{
    $task = $this->getTask();
    
    $chunkIndex = $this->request->getIntegerParam('chunk_index');
    $totalChunks = $this->request->getIntegerParam('total_chunks');
    $uploadId = $this->request->getStringParam('upload_id');
    $filename = $this->request->getStringParam('filename');
    
    $chunkFile = $this->request->getUploadedFile('file_chunk');
    
    // Save chunk to temp directory
    $tempDir = DATA_DIR . '/tmp/' . $uploadId;
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    $chunkPath = $tempDir . '/chunk_' . $chunkIndex;
    move_uploaded_file($chunkFile['tmp_name'], $chunkPath);
    
    // If this is the last chunk, assemble the file
    if ($chunkIndex == $totalChunks - 1) {
        $finalPath = $this->taskFileModel->generatePath($task['id'], $filename);
        
        // Combine all chunks
        $finalFile = fopen($finalPath, 'wb');
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunk = file_get_contents($tempDir . '/chunk_' . $i);
            fwrite($finalFile, $chunk);
        }
        fclose($finalFile);
        
        // Clean up chunks
        foreach (glob($tempDir . '/chunk_*') as $chunkFile) {
            unlink($chunkFile);
        }
        rmdir($tempDir);
        
        // Create file record
        $this->taskFileModel->create($task['id'], $filename, $finalPath, filesize($finalPath));
        
        $this->response->json(['message' => 'OK', 'complete' => true]);
    } else {
        $this->response->json(['message' => 'OK', 'chunk' => $chunkIndex]);
    }
}
```

---

## Configuration Checklist

### For Large File Uploads (>50MB)

```ini
# PHP settings
upload_max_filesize = 500M
post_max_size = 500M
memory_limit = 512M
max_execution_time = 600
max_input_time = 600

# If using nginx proxy
proxy_read_timeout 600s;
proxy_send_timeout 600s;
client_max_body_size 500M;
```

### For Coolify

In Coolify settings:
- Increase timeout to 10 minutes
- Or use chunked uploads (no timeout needed)

---

## Testing

### Test Large File Upload

1. Create a 100MB file:
   ```bash
   dd if=/dev/zero of=test-100mb.bin bs=1M count=100
   ```

2. Upload via Kanboard
3. Monitor:
   - Browser console for errors
   - Server logs for timeouts
   - Progress bar behavior

### Test Multiple Files

1. Select 10 files (10MB each)
2. Upload
3. Time the process:
   - Sequential: ~50-100 seconds
   - Parallel (3 at a time): ~20-30 seconds

---

## Summary

| Issue | Solution | Difficulty | Impact |
|-------|----------|------------|--------|
| **Stuck at 52%** | Increase timeouts + async thumbnails | Easy | High |
| **Sequential uploads** | Parallel upload implementation | Medium | High |
| **Large file timeout** | Chunked uploads | Hard | High |
| **Poor error handling** | Add retry logic | Easy | Medium |

### Immediate Actions

1. ✅ Update `php.ini`: Increase timeouts to 600s
2. ✅ Modify `FileModel::uploadFile()`: Move thumbnail to background
3. ✅ Add XHR timeout in `http.js`
4. 🔜 Implement parallel uploads (if needed)
5. 🔜 Implement chunked uploads (for very large files)

---

## Files to Modify

| File | Change | Priority |
|------|--------|----------|
| `docker/frankenphp/php.ini` | Increase timeouts | 🔥 High |
| `assets/js/core/http.js` | Add XHR timeout | 🔥 High |
| `app/Model/FileModel.php` | Async thumbnail generation | 🔥 High |
| `assets/js/components/file-upload.js` | Parallel uploads | ⭐ Medium |
| `app/Controller/TaskFileController.php` | Chunked upload endpoint | 💡 Low |
