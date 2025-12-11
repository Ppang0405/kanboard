# Public File Access with Expiring Tokens

## Overview

This document describes how to implement public access to file attachments on public boards using time-limited tokens that expire after 1 month by default.

## Current Behavior

- Public boards use project tokens: `/?controller=BoardViewController&action=readonly&token=PROJECT_TOKEN`
- File attachments require authentication (no public access)
- `FileViewerController` uses `getFile()` which doesn't check for public access tokens

## Proposed Solution

Create a new token-based public file access system:

1. Generate file access tokens when accessing public boards
2. Tokens expire after 1 month (configurable)
3. New `PublicFileController` handles public file requests
4. Tokens are tied to file ID + project token for security

---

## Files to Create/Modify

### 1. NEW: `app/Model/FileAccessTokenModel.php`

```php
<?php

namespace Kanboard\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Token;

/**
 * File Access Token Model
 *
 * Manages time-limited tokens for public file access
 *
 * @package Kanboard\Model
 */
class FileAccessTokenModel extends Base
{
    const TABLE = 'file_access_tokens';
    
    // Default expiration: 30 days
    const DEFAULT_EXPIRATION = 30 * 24 * 60 * 60;

    /**
     * Get or create a file access token
     *
     * @param  int    $fileId       File ID
     * @param  int    $projectId    Project ID
     * @param  string $projectToken Project's public token
     * @param  int    $expiration   Expiration time in seconds (default: 30 days)
     * @return string|false
     */
    public function getOrCreate($fileId, $projectId, $projectToken, $expiration = null)
    {
        if ($expiration === null) {
            $expiration = self::DEFAULT_EXPIRATION;
        }

        // Check for existing valid token
        $existing = $this->db->table(self::TABLE)
            ->eq('file_id', $fileId)
            ->eq('project_id', $projectId)
            ->gt('expiration_date', time())
            ->findOne();

        if (!empty($existing)) {
            return $existing['token'];
        }

        // Create new token
        $token = Token::getToken();
        $expirationDate = time() + $expiration;

        $result = $this->db->table(self::TABLE)->insert([
            'token'           => $token,
            'file_id'         => $fileId,
            'project_id'      => $projectId,
            'project_token'   => $projectToken,
            'expiration_date' => $expirationDate,
            'date_creation'   => time(),
        ]);

        return $result ? $token : false;
    }

    /**
     * Validate a file access token
     *
     * @param  string $token        File access token
     * @param  int    $fileId       File ID
     * @param  string $projectToken Project's public token
     * @return bool
     */
    public function validate($token, $fileId, $projectToken)
    {
        $record = $this->db->table(self::TABLE)
            ->eq('token', $token)
            ->eq('file_id', $fileId)
            ->eq('project_token', $projectToken)
            ->gt('expiration_date', time())
            ->findOne();

        return !empty($record);
    }

    /**
     * Get file info by access token
     *
     * @param  string $token File access token
     * @return array|false
     */
    public function getByToken($token)
    {
        return $this->db->table(self::TABLE)
            ->eq('token', $token)
            ->gt('expiration_date', time())
            ->findOne();
    }

    /**
     * Clean up expired tokens
     *
     * @return bool
     */
    public function cleanup()
    {
        return $this->db->table(self::TABLE)
            ->lt('expiration_date', time())
            ->remove();
    }

    /**
     * Revoke all tokens for a project
     *
     * @param  int $projectId
     * @return bool
     */
    public function revokeByProject($projectId)
    {
        return $this->db->table(self::TABLE)
            ->eq('project_id', $projectId)
            ->remove();
    }

    /**
     * Revoke all tokens for a file
     *
     * @param  int $fileId
     * @return bool
     */
    public function revokeByFile($fileId)
    {
        return $this->db->table(self::TABLE)
            ->eq('file_id', $fileId)
            ->remove();
    }
}
```

---

### 2. NEW: Database Migration

Add to `app/Schema/Sqlite.php` (and corresponding MySQL/Postgres files):

```php
function version_XXX(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS file_access_tokens (
            id INTEGER PRIMARY KEY,
            token TEXT NOT NULL UNIQUE,
            file_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            project_token TEXT NOT NULL,
            expiration_date INTEGER NOT NULL,
            date_creation INTEGER NOT NULL,
            FOREIGN KEY (file_id) REFERENCES task_has_files(id) ON DELETE CASCADE
        )
    ');
    
    $pdo->exec('CREATE INDEX idx_file_access_tokens_token ON file_access_tokens(token)');
    $pdo->exec('CREATE INDEX idx_file_access_tokens_expiration ON file_access_tokens(expiration_date)');
}
```

---

### 3. NEW: `app/Controller/PublicFileController.php`

```php
<?php

namespace Kanboard\Controller;

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Controller\PageNotFoundException;
use Kanboard\Core\ObjectStorage\ObjectStorageException;

/**
 * Public File Controller
 *
 * Handles public access to files via time-limited tokens
 *
 * @package Kanboard\Controller
 */
class PublicFileController extends BaseController
{
    /**
     * Display image publicly
     */
    public function image()
    {
        $file = $this->validateAndGetFile();
        $this->renderFileWithCache($file, $this->helper->file->getImageMimeType($file['name']));
    }

    /**
     * Display thumbnail publicly
     */
    public function thumbnail()
    {
        $file = $this->validateAndGetFile();
        $model = $file['model'];
        $filename = $this->$model->getThumbnailPath($file['path']);

        $this->response->withCache(5 * 86400, $file['etag']);
        $this->response->withContentType('image/png');

        if ($this->request->getHeader('If-None-Match') === '"'.$file['etag'].'"') {
            $this->response->status(304);
        } else {
            $this->response->send();
            try {
                $this->objectStorage->output($filename);
            } catch (ObjectStorageException $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    /**
     * Download file publicly
     */
    public function download()
    {
        try {
            $file = $this->validateAndGetFile();
            $this->response->withFileDownload($file['name']);
            $this->response->send();
            $this->objectStorage->output($file['path']);
        } catch (ObjectStorageException $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * View file in browser publicly
     */
    public function browser()
    {
        $file = $this->validateAndGetFile();
        $this->renderFileWithCache($file, $this->helper->file->getBrowserViewType($file['name']));
    }

    /**
     * Validate the access token and return file info
     *
     * @return array
     * @throws AccessForbiddenException
     * @throws PageNotFoundException
     */
    protected function validateAndGetFile()
    {
        $fileToken = $this->request->getStringParam('file_token');
        $projectToken = $this->request->getStringParam('token');
        $fileId = $this->request->getIntegerParam('file_id');

        if (empty($fileToken) || empty($projectToken) || empty($fileId)) {
            throw AccessForbiddenException::getInstance()->withoutLayout();
        }

        // Validate the file access token
        if (!$this->fileAccessTokenModel->validate($fileToken, $fileId, $projectToken)) {
            throw AccessForbiddenException::getInstance()->withoutLayout();
        }

        // Get file info
        $file = $this->taskFileModel->getById($fileId);

        if (empty($file)) {
            // Try project files
            $file = $this->projectFileModel->getById($fileId);
            if (empty($file)) {
                throw new PageNotFoundException();
            }
            $file['model'] = 'projectFileModel';
        } else {
            $file['model'] = 'taskFileModel';
        }

        return $file;
    }

    /**
     * Output file with cache headers
     */
    protected function renderFileWithCache(array $file, $mimetype)
    {
        if ($this->request->getHeader('If-None-Match') === '"'.$file['etag'].'"') {
            $this->response->status(304);
        } else {
            try {
                $this->response->withContentType($mimetype);
                $this->response->withCache(5 * 86400, $file['etag']);
                $this->response->send();
                $this->objectStorage->output($file['path']);
            } catch (ObjectStorageException $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }
}
```

---

### 4. MODIFY: `app/ServiceProvider/AuthenticationProvider.php`

Add public access for the new controller:

```php
// Add to getApplicationAccessMap() method, around line 149:
$acl->add('PublicFileController', '*', Role::APP_PUBLIC);
```

---

### 5. MODIFY: `app/ServiceProvider/RouteProvider.php`

Add routes for public file access:

```php
// Add public file routes (around line 50, with other public routes):
$container['route']->addRoute('public/file/:file_id/image', 'PublicFileController', 'image');
$container['route']->addRoute('public/file/:file_id/thumbnail', 'PublicFileController', 'thumbnail');
$container['route']->addRoute('public/file/:file_id/download', 'PublicFileController', 'download');
$container['route']->addRoute('public/file/:file_id/view', 'PublicFileController', 'browser');
```

---

### 6. NEW: `app/Helper/PublicFileHelper.php`

```php
<?php

namespace Kanboard\Helper;

use Kanboard\Core\Base;

/**
 * Public File Helper
 *
 * Generates public URLs for file attachments
 */
class PublicFileHelper extends Base
{
    /**
     * Generate a public URL for a file
     *
     * @param  string $action       Controller action (image, thumbnail, download, browser)
     * @param  int    $fileId       File ID
     * @param  int    $projectId    Project ID
     * @param  string $projectToken Project's public token
     * @param  array  $extra        Extra parameters
     * @return string
     */
    public function url($action, $fileId, $projectId, $projectToken, array $extra = [])
    {
        $fileToken = $this->fileAccessTokenModel->getOrCreate($fileId, $projectId, $projectToken);

        if ($fileToken === false) {
            return '';
        }

        $params = array_merge([
            'file_id'    => $fileId,
            'token'      => $projectToken,
            'file_token' => $fileToken,
        ], $extra);

        return $this->helper->url->to('PublicFileController', $action, $params);
    }
}
```

---

### 7. MODIFY: `app/ServiceProvider/HelperProvider.php`

Register the new helper:

```php
// Add to register() method:
$container['helper']->register('publicFile', '\Kanboard\Helper\PublicFileHelper');
```

---

### 8. MODIFY: `app/ServiceProvider/ClassProvider.php`

Register the new model:

```php
// Add to the models array:
'FileAccessTokenModel',
```

---

### 9. NEW: Template for Public Board Images

Create `app/Template/board/public_images.php` or modify existing templates to use public URLs when in public context:

```php
<?php if (isset($is_public) && $is_public && !empty($project['token'])): ?>
    <!-- Public file URLs with tokens -->
    <?php foreach ($images as $file): ?>
        <img src="<?= $this->helper->publicFile->url('thumbnail', $file['id'], $project['id'], $project['token']) ?>" 
             alt="<?= $this->text->e($file['name']) ?>">
    <?php endforeach ?>
<?php else: ?>
    <!-- Regular authenticated URLs -->
    <?php foreach ($images as $file): ?>
        <img src="<?= $this->url->href('FileViewerController', 'thumbnail', ['file_id' => $file['id'], 'task_id' => $task['id']]) ?>" 
             alt="<?= $this->text->e($file['name']) ?>">
    <?php endforeach ?>
<?php endif ?>
```

---

### 10. MODIFY: `config.default.php`

Add configuration option for token expiration:

```php
// Public file access token expiration (in seconds)
// Default: 30 days (30 * 24 * 60 * 60 = 2592000)
define('PUBLIC_FILE_TOKEN_EXPIRATION', 2592000);
```

---

## URL Structure

### Before (Requires Authentication)
```
/?controller=FileViewerController&action=image&file_id=123&task_id=456
```

### After (Public with Token)
```
/?controller=PublicFileController&action=image&file_id=123&token=PROJECT_TOKEN&file_token=FILE_ACCESS_TOKEN
```

Or with URL rewriting:
```
/public/file/123/image?token=PROJECT_TOKEN&file_token=FILE_ACCESS_TOKEN
```

---

## Security Considerations

| Aspect | Implementation |
|--------|----------------|
| **Token Generation** | Uses `Token::getToken()` (32 bytes of `random_bytes`) |
| **Token Binding** | Tied to file ID + project token |
| **Expiration** | Default 30 days, configurable |
| **Revocation** | Tokens revoked when project public access disabled |
| **Project Validation** | Validates both project token AND file token |
| **File Ownership** | Verifies file belongs to the project |

---

## Cleanup Cron Job

Add to `app/Console/CronjobCommand.php`:

```php
// Add to execute() method:
$this->fileAccessTokenModel->cleanup();
```

This removes expired tokens daily.

---

## Flow Diagram

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│   Public Board   │     │   Generate File  │     │   Validate &     │
│   with Token     │────▶│   Access Token   │────▶│   Serve File     │
└──────────────────┘     └──────────────────┘     └──────────────────┘
         │                        │                        │
         │                        │                        │
         ▼                        ▼                        ▼
   Project Token            File Token              Check Expiration
   (never expires)      (expires in 30 days)        Serve if valid
```

---

## Files Summary

| File | Action | Purpose |
|------|--------|---------|
| `app/Model/FileAccessTokenModel.php` | CREATE | Token management |
| `app/Controller/PublicFileController.php` | CREATE | Public file endpoints |
| `app/Helper/PublicFileHelper.php` | CREATE | URL generation helper |
| `app/Schema/Sqlite.php` | MODIFY | Add migration |
| `app/Schema/Mysql.php` | MODIFY | Add migration |
| `app/Schema/Postgres.php` | MODIFY | Add migration |
| `app/ServiceProvider/AuthenticationProvider.php` | MODIFY | Add public ACL |
| `app/ServiceProvider/RouteProvider.php` | MODIFY | Add routes |
| `app/ServiceProvider/HelperProvider.php` | MODIFY | Register helper |
| `app/ServiceProvider/ClassProvider.php` | MODIFY | Register model |
| `config.default.php` | MODIFY | Add config option |
| `app/Console/CronjobCommand.php` | MODIFY | Add cleanup |
| Public board templates | MODIFY | Use public URLs |

---

## Testing

### Manual Test
1. Enable public access on a project
2. Add a file attachment to a task
3. Access the public board URL
4. Verify images/files load without login
5. Wait for token to expire (or manually set short expiration)
6. Verify access is denied after expiration

### Automated Test
```php
public function testFileAccessToken()
{
    $projectId = $this->createProject();
    $fileId = $this->createTaskFile($projectId);
    $projectToken = 'test_project_token';
    
    // Generate token
    $fileToken = $this->fileAccessTokenModel->getOrCreate($fileId, $projectId, $projectToken);
    $this->assertNotFalse($fileToken);
    
    // Validate token
    $this->assertTrue($this->fileAccessTokenModel->validate($fileToken, $fileId, $projectToken));
    
    // Wrong file ID should fail
    $this->assertFalse($this->fileAccessTokenModel->validate($fileToken, 999, $projectToken));
    
    // Wrong project token should fail
    $this->assertFalse($this->fileAccessTokenModel->validate($fileToken, $fileId, 'wrong_token'));
}
```

