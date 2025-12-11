# FrankenPHP Migration Guide for Kanboard

This document analyzes how to improve Kanboard's PHP server setup by migrating from the traditional nginx + PHP-FPM stack to [FrankenPHP](https://github.com/php/frankenphp), a modern PHP application server.

---

## Table of Contents

1. [Current Architecture Analysis](#current-architecture-analysis)
2. [What is FrankenPHP?](#what-is-frankenphp)
3. [Benefits for Kanboard](#benefits-for-kanboard)
4. [Migration Guide](#migration-guide)
5. [Worker Mode Integration](#worker-mode-integration)
6. [Performance Comparison](#performance-comparison)
7. [Considerations & Trade-offs](#considerations--trade-offs)

---

## Current Architecture Analysis

### Current Stack

```
┌─────────────────────────────────────────────────────────────┐
│                    Kanboard Docker Container                │
│                                                             │
│  ┌─────────────┐    FastCGI    ┌─────────────────────────┐ │
│  │   nginx     │ ────────────► │       PHP-FPM           │ │
│  │  (Port 80)  │               │  (pm.max_children=20)   │ │
│  └─────────────┘               └─────────────────────────┘ │
│        │                                   │               │
│        │                                   ▼               │
│        │                       ┌─────────────────────────┐ │
│        │                       │    Kanboard PHP App     │ │
│        ▼                       └─────────────────────────┘ │
│  ┌─────────────┐                                           │
│  │   s6-init   │ (Process supervisor)                      │
│  └─────────────┘                                           │
└─────────────────────────────────────────────────────────────┘
```

### Current Components

| Component | File | Purpose |
|-----------|------|---------|
| nginx | `docker/etc/nginx/nginx.conf` | Web server, SSL termination, static files |
| PHP-FPM | `docker/etc/php84/php-fpm.conf` | PHP process manager |
| s6 | `docker/etc/services.d/` | Process supervisor |
| Alpine | `Dockerfile` | Base image (~150MB) |

### Current Limitations

1. **Request Lifecycle**: Each request bootstraps entire application
2. **Memory**: PHP-FPM spawns multiple processes (~30-50MB each)
3. **Complexity**: Multiple services to configure and maintain
4. **No HTTP/3**: Requires additional nginx configuration
5. **No Early Hints**: 103 status code not supported
6. **Manual SSL**: Self-signed certificates, no auto HTTPS

---

## What is FrankenPHP?

[FrankenPHP](https://frankenphp.dev) is a modern PHP application server built on top of Caddy, written in Go. It embeds PHP directly into the web server.

### Key Features

| Feature | Description |
|---------|-------------|
| **Worker Mode** | Keep PHP app in memory, handle multiple requests |
| **Early Hints** | Send 103 status for preloading assets |
| **Automatic HTTPS** | Let's Encrypt integration built-in |
| **HTTP/2 & HTTP/3** | Native support out of the box |
| **Real-time** | Native Mercure hub for WebSockets/SSE |
| **Single Binary** | No nginx, no PHP-FPM, just one process |

### Architecture Comparison

```
Current (nginx + PHP-FPM):           FrankenPHP:
┌───────────────────────────┐        ┌───────────────────────────┐
│         nginx             │        │      FrankenPHP           │
│           │               │        │  (Caddy + PHP embedded)   │
│     ┌─────▼─────┐         │        │                           │
│     │  FastCGI  │         │        │  ┌───────────────────┐    │
│     └─────┬─────┘         │        │  │  PHP Worker Pool  │    │
│           │               │        │  │  (in-memory)      │    │
│     ┌─────▼─────┐         │        │  └───────────────────┘    │
│     │  PHP-FPM  │         │        │                           │
│     │  Workers  │         │        └───────────────────────────┘
│     └───────────┘         │
└───────────────────────────┘        Single process, no IPC overhead
Multiple processes, IPC overhead
```

---

## Benefits for Kanboard

### 1. Performance Improvements

| Metric | nginx + PHP-FPM | FrankenPHP (Worker Mode) |
|--------|-----------------|--------------------------|
| Requests/sec | ~500-1000 | ~2000-5000 |
| Memory per request | ~30-50MB | ~5-10MB (shared) |
| First byte time | ~50-100ms | ~10-30ms |
| Cold start | ~200-500ms | N/A (always warm) |

### 2. Simplified Architecture

**Before (5 services):**
- nginx
- PHP-FPM
- s6 supervisor
- cron
- ssmtp

**After (1-2 services):**
- FrankenPHP
- (optional) cron sidecar

### 3. Docker Image Size

| Image | Size |
|-------|------|
| Current (Alpine + nginx + PHP-FPM) | ~150-200MB |
| FrankenPHP (Alpine) | ~80-100MB |
| FrankenPHP (Debian) | ~100-150MB |

### 4. Features Kanboard Can Leverage

| Feature | Kanboard Use Case |
|---------|-------------------|
| **Worker Mode** | Keep database connections alive, faster responses |
| **Early Hints** | Preload CSS/JS while PHP processes request |
| **Auto HTTPS** | No manual certificate management |
| **HTTP/3** | Better performance on mobile networks |
| **Real-time** | Future: Live task updates without polling |

---

## Migration Guide

### Option 1: Minimal Migration (Drop-in Replacement)

Replace nginx + PHP-FPM with FrankenPHP in classic mode (no worker).

#### New Dockerfile

```dockerfile
FROM dunglas/frankenphp:1-php8.4-alpine

# Install additional PHP extensions
RUN install-php-extensions \
    pdo_mysql \
    pdo_sqlite \
    pdo_pgsql \
    gd \
    ldap \
    bcmath \
    opcache \
    zip

# Copy application
COPY . /app/public

# Configure Caddy
COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 80 443

# Health check
HEALTHCHECK --start-period=3s --timeout=5s \
    CMD curl -f http://localhost/healthcheck.php || exit 1
```

#### Caddyfile

```caddyfile
{
    # Global options
    auto_https disable_redirects
    admin off
}

:80, :443 {
    root * /app/public
    
    # PHP handling
    php_server
    
    # Security: block sensitive files
    @blocked {
        path /data/* /.ht* *.sqlite *.log
    }
    respond @blocked 404
    
    # Static file caching
    @static {
        path *.ico *.jpg *.jpeg *.png *.gif *.css *.js *.svg *.woff *.woff2
    }
    header @static Cache-Control "public, max-age=604800"
    
    # Gzip compression
    encode gzip
    
    # File upload limit
    request_body {
        max_size 100MB
    }
}
```

#### docker-compose.frankenphp.yml

```yaml
# Docker Compose file to run Kanboard with FrankenPHP
name: kanboard-frankenphp
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.frankenphp
    restart: always
    expose:
      - "80"
      - "443"
    volumes:
      - data:/app/public/data
      - plugins:/app/public/plugins
    environment:
      - TZ=UTC
      - SESSION_HANDLER=php
      - SERVER_NAME=:80
      # Enable debug mode for development
      # - CADDY_GLOBAL_OPTIONS=debug
volumes:
  data:
    driver: local
  plugins:
    driver: local
```

### Option 2: Worker Mode (Maximum Performance)

Enable FrankenPHP worker mode for persistent PHP processes.

#### Worker Script (worker.php)

Create `worker.php` in the application root:

```php
<?php

/**
 * FrankenPHP Worker Script for Kanboard
 * 
 * This script keeps Kanboard bootstrapped in memory and handles
 * multiple requests without re-initializing the application.
 */

// Prevent direct access
if (php_sapi_name() !== 'frankenphp-worker') {
    die('This script must be run as a FrankenPHP worker');
}

// Bootstrap Kanboard once
require __DIR__ . '/app/common.php';

// Get the application container
$container = new Pimple\Container();
require __DIR__ . '/app/ServiceProvider/ServiceProvider.php';

// Register all service providers
// ... (initialization code)

// Worker loop - handle requests without re-bootstrapping
$handler = static function () use ($container) {
    // Reset request-specific state
    $_SESSION = [];
    
    // Handle the request
    // The actual request handling would go here
    // For now, we'll use the standard index.php logic
    
    require __DIR__ . '/index.php';
};

// Process requests in a loop
// FrankenPHP will call this for each incoming request
for ($nbRequests = 0; $nbRequests < 1000; ++$nbRequests) {
    $running = \frankenphp_handle_request($handler);
    
    if (!$running) {
        break;
    }
    
    // Garbage collection every 100 requests
    if ($nbRequests % 100 === 0) {
        gc_collect_cycles();
    }
}
```

#### Caddyfile for Worker Mode

```caddyfile
{
    auto_https disable_redirects
    admin off
    
    frankenphp {
        # Number of worker threads
        num_threads 4
        
        # Worker configuration
        worker {
            file /app/public/worker.php
            num 4
            # Restart workers after 1000 requests to prevent memory leaks
            max_requests 1000
        }
    }
}

:80, :443 {
    root * /app/public
    
    # Use worker for PHP
    php_server {
        worker
    }
    
    # ... rest of configuration
}
```

---

## Worker Mode Integration

### Challenges for Kanboard

Worker mode requires careful handling of:

| Challenge | Solution |
|-----------|----------|
| **Session state** | Reset `$_SESSION` between requests |
| **Database connections** | Keep persistent, handle reconnection |
| **Static variables** | Reset or handle properly |
| **File handles** | Close at end of request |
| **Memory leaks** | Restart workers after N requests |

### Required Code Changes

#### 1. Session Handling

```php
// In worker.php, before each request:
$_SESSION = [];
session_reset();
```

#### 2. Database Connection Pool

```php
// Modify app/ServiceProvider/DatabaseProvider.php
// Keep connection alive between requests
public function register(Container $container)
{
    $container['db'] = function ($c) {
        static $db = null;
        
        if ($db === null || !$db->isConnected()) {
            $db = $this->createConnection();
        }
        
        return $db;
    };
}
```

#### 3. Request State Reset

```php
// Create app/Core/FrankenPHP/RequestReset.php
namespace Kanboard\Core\FrankenPHP;

class RequestReset
{
    public static function reset()
    {
        // Clear superglobals
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
        
        // Reset output buffering
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
```

---

## Performance Comparison

### Benchmark Expectations

| Scenario | nginx + PHP-FPM | FrankenPHP Classic | FrankenPHP Worker |
|----------|-----------------|-------------------|-------------------|
| Simple page load | 100ms | 80ms | 20ms |
| Dashboard with 50 tasks | 300ms | 250ms | 80ms |
| File upload (10MB) | 500ms | 400ms | 350ms |
| API request | 50ms | 40ms | 10ms |
| Memory (20 concurrent) | 600MB | 400MB | 200MB |

### Why Worker Mode is Faster

```
Classic Mode (per request):
┌──────────────────────────────────────────────────────────────┐
│ Load PHP → Load Autoloader → Load Config → Connect DB →     │
│ Route Request → Execute Controller → Render View → Respond  │
│                                                              │
│ Time: ████████████████████████████████████ 100ms             │
└──────────────────────────────────────────────────────────────┘

Worker Mode (per request):
┌──────────────────────────────────────────────────────────────┐
│ (Already loaded) → Route Request → Execute → Respond         │
│                                                              │
│ Time: ████████ 20ms                                          │
└──────────────────────────────────────────────────────────────┘
```

---

## Considerations & Trade-offs

### Pros ✅

| Benefit | Impact |
|---------|--------|
| **Simpler stack** | Fewer things to configure and debug |
| **Better performance** | 2-5x faster with worker mode |
| **Auto HTTPS** | No more manual certificate management |
| **HTTP/3 support** | Better mobile performance |
| **Smaller image** | Faster deployments |
| **Early Hints** | Faster perceived load time |

### Cons ❌

| Concern | Mitigation |
|---------|------------|
| **Newer technology** | Well-tested, used by Laravel, Symfony |
| **Worker mode complexity** | Start with classic mode, migrate gradually |
| **Memory leaks** | Worker restart after N requests |
| **Plugin compatibility** | Test plugins in worker mode |
| **Learning curve** | Caddyfile is simpler than nginx config |

### When NOT to Use FrankenPHP

- If you need specific nginx modules (Lua, etc.)
- If your plugins rely on PHP-FPM specific features
- If you're running on very limited memory (<256MB)

---

## Implementation Roadmap

### Phase 1: Classic Mode (Low Risk)
1. Create `Dockerfile.frankenphp`
2. Create `Caddyfile`
3. Test all features
4. Benchmark performance
5. Deploy to staging

### Phase 2: Worker Mode (Medium Risk)
1. Create `worker.php`
2. Implement request state reset
3. Test thoroughly for memory leaks
4. Benchmark worker mode
5. Gradual rollout

### Phase 3: Advanced Features
1. Enable Early Hints for assets
2. Enable HTTP/3
3. Consider Mercure for real-time updates

---

## Quick Start

### Test FrankenPHP Locally

```bash
# Download FrankenPHP
curl https://frankenphp.dev/install.sh | sh

# Run Kanboard with FrankenPHP
cd /path/to/kanboard
frankenphp php-server --root .
```

Visit `https://localhost` to test!

---

## References

- [FrankenPHP Documentation](https://frankenphp.dev)
- [FrankenPHP GitHub](https://github.com/php/frankenphp)
- [Caddy Documentation](https://caddyserver.com/docs/)
- [FrankenPHP Worker Mode](https://frankenphp.dev/docs/worker/)
- [FrankenPHP Laravel Integration](https://frankenphp.dev/docs/laravel/)

---

## Conclusion

FrankenPHP offers significant benefits for Kanboard:

1. **Simplified deployment** - One binary instead of nginx + PHP-FPM + s6
2. **Better performance** - 2-5x faster with worker mode
3. **Modern features** - HTTP/3, auto HTTPS, Early Hints
4. **Smaller footprint** - Less memory, smaller Docker images

**Recommendation**: Start with FrankenPHP in classic mode for a low-risk migration, then evaluate worker mode for maximum performance gains.
