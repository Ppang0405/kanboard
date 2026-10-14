# Kanboard native Linux Swoole experiment

Experimental Linux parity candidate. Not a production migration and not a
speedup proof: one synchronous worker queues slow DB work instead of
parallelizing it, and no pooling, coroutines, or plugins are included.
Existing `Dockerfile`/`app.yaml`/`wasmer.toml` and the live Wasmer
deployment are unchanged.

## Runtime contract (pinned)

- Base image: `php:8.3.30-cli-bookworm` (Debian bookworm native PHP 8.3
  CLI; re-pinned 8.2.28 -> 8.3.26 -> 8.3.28 -> 8.3.30 as supported patches
  were verified on Docker Hub (arm64 manifests) plus php.net
  distribution tarballs).
- Swoole: `6.2.3` via PECL (`SWOOLE_VERSION` build arg), the PECL stable
  at build time (released 2026-09-22), verified against PHP 8.3 at build
  and runtime. These pins are the tested snapshot for this experiment,
  not a newest-or-certified claim: refresh to a then-current security
  patch before any actual deployment.
- Native Linux test target: `docker build -f Dockerfile.swoole --target
  test` adds dev dependencies (same pinned lockfile) for regression runs
  where the macOS host gaps do not apply; the `runtime` target stays lean.
- One synchronous worker (`worker_num: 1`), coroutines OFF
  (`enable_coroutine: false`, `swoole.enable_coroutine = Off`).
- OPcache on, including CLI (`opcache.enable_cli = 1`), timestamps off.
- Non-root service user `kanboard` (uid 10000); image exposes port 8080.
- Timezone baseline is UTC (`TZ=UTC`, `date.timezone = UTC`).
- PHP dependencies install inside the image from the unchanged
  `composer.lock` (`composer install --no-dev`); `vendor/` is excluded
  from the build context and rebuilt reproducibly.

## What the foundation stage wires

- `app/Swoole/Bootstrap.php`: one-time process bootstrap (DB env defines,
  local config files, constants, setup checks). Second calls are no-ops,
  so a long-running worker never re-runs `define()` or setup.
- `app/Swoole/ContainerFactory.php`: fresh Pimple container plus the same
  24 providers per request. `app/common.php` is a minimal hook over both,
  so legacy `index.php`/`jsonrpc.php`/`cli`/`healthcheck.php` behave as before.
- `docker/swoole/server.php` (wiring entrypoint only): one-time bootstrap,
  one fresh container per request, dispatch through `SwooleRouter`, 500 on
  unexpected failure. Worker logs go to STDERR (stdout output would mark
  PHP headers as sent and break sessions).
- `app/Swoole/SwooleRouter.php`: safe routing. `/health` (live `SELECT 1`),
  `/jsonrpc.php` (API), `/` plus pretty URLs (UI flow), allowlisted statics;
  everything else answers 404 without disclosing paths.
- `app/Swoole/SwooleRequestMapper.php`: Swoole request to explicit
  server/get/post/files/cookies/raw-body bags plus Basic-auth decode.
  Empty stays empty (isolated `Request`, no superglobals fallback).
- `app/Swoole/SwooleResponseEmitter.php`: captured status/headers/body to
  the engine, `sendfile()` for validated downloads; a `Location` header on
  an unset status emits 302 like the PHP SAPI does.
- `app/Swoole/SwooleApiHandler.php`: JSON-RPC with injected raw body,
  server vars (no `php://input`, no `$_SERVER` fallback) and capture
  response builder; emits the builder's actual HTTP status/headers after
  `execute()` — 401 with `WWW-Authenticate: Basic realm="JsonRPC"` on
  authentication failure, 403 on access denial, 200 otherwise — mirroring
  `jsonrpc.php`, whose native statuses are never overridden.
- `app/Swoole/SwooleUploadTransfer.php` + `SwooleFileStorage.php`: uploads
  move only this-request registered tmp files (error/size/canonical-tmp
  checks, traversal and symlink guards); downloads record their validated
  path for `sendfile()` instead of buffering. Native `move_uploaded_file()`
  and `output()` paths are unchanged.
- Narrow core hooks: `Response` capture mode plus status/headers/body
  getters; `Request` isolated flag plus body override; `FileStorage::getPath()`
  (read-only); `ApiProvider` lazy `api` service with body/server injection;
  `JsonRPC\Server` null-body keeps `php://input`, any string decodes
  directly. Legacy defaults are intact.
- `app/Swoole/SwooleSession.php`: native session adaptation. Binds only
  this request's `KB_SID` cookie (malformed ids rejected, unknown ids start
  empty under strict mode and rotate at login), emits the final id plus
  remember-me outbox cookies through the engine, writes and closes the
  session before the response is sent, then resets the id. The SAPI
  `setcookie()` path never reaches the client; HttpOnly/Secure/path and
  SameSite=Lax are preserved.
- `app/Swoole/SwooleLifecycle.php`: worker baseline reset after every
  request including failures (server `finally`): leaked transaction
  rollback, session close, `$_SESSION`/buffer/translator reset, UTC
  restore. Stray stdout output is contained per request (buffer guard) and
  `display_errors` stays off so notices can never poison the worker.
- Narrow core hook: `RememberMeCookie` records written/removed cookies in
  a per-request outbox (`getPendingCookies()`); native `setcookie()` calls
  are unchanged. `SessionManager`/`UserSession` need no changes: login ID
  rotation, logout destruction and remember-me encoding flow through
  untouched.
- Pinned real-HTTP contract (`tests/integration/swoole/SwooleHttpApiTest.php`):
  health, login render, failure->success login, rotation/logout, alternating
  users, malformed/fixation cookies, remember-me without session,
  per-user language/timezone isolation, UI board-task mutation plus
  authorized download, API auth, allowlist, denials.

## Startup / stop / restart

```sh
docker compose -f docker-compose.swoole.yml up --build -d
curl -f http://127.0.0.1:8080/health
SWOOLE_HOST_PORT=18080 docker compose -f docker-compose.swoole.yml up -d  # host port taken
docker compose -f docker-compose.swoole.yml stop
docker compose -f docker-compose.swoole.yml restart
docker compose -f docker-compose.swoole.yml down -v  # also drops the isolated volumes
```

The published port binds loopback only (`127.0.0.1`, never all
interfaces); override the host side with `SWOOLE_HOST_PORT`. Ordering:
the `db` service must be healthy before `app` starts (`depends_on` with
`service_healthy`), and migrations run automatically on first container
creation (`DB_RUN_MIGRATIONS`, default true) — verified at schema
version 139/139 against MariaDB 11.4.

Health check inside the image polls `GET /health`; `/health` returns 200
only when a fresh container and a live `SELECT 1` both succeed, otherwise
500 (bounded failure signal, no automatic write retry).

## Environment / secrets / volumes

- `DATABASE_URL` (default `mysql://kanboard:kanboard-secret@db/kanboard`),
  `TZ`, `SWOOLE_HOST`, `SWOOLE_PORT`, `SWOOLE_MAX_REQUEST`.
- Never baked into the image: `config.php`, `wasmer.toml`, `app.yaml`,
  `data/`, `.env*` files, local sqlite files. The Wasmer `config.php`
  (live DB credentials, mail secrets) stays out via
  `Dockerfile.swoole.dockerignore`.
- Unlike the root `.dockerignore`, the dedicated ignore keeps
  `composer.json`/`composer.lock` and the JS/CSS assets in the context.
- Fresh isolated volumes `swoole-data`, `swoole-plugins`, `swoole-db`;
  the stack never mounts the original `./data` directory.

## Migration ordering / scheduler CLI

Database migrations run automatically on container creation
(`DB_RUN_MIGRATIONS`, default true), same as legacy. The scheduler CLI
runs out of band against the same environment and database, e.g.
`docker compose -f docker-compose.swoole.yml exec app php cli cronjob`
(`cli db:version` verified working inside the image); no separate
scheduler service is added by this experiment.

## TLS / trusted proxy

Not terminated in this image (plain HTTP on 8080). Terminate TLS at a
reverse proxy and set `TRUSTED_PROXY_HEADERS`/`TRUSTED_PROXY_NETWORKS`
when proxying. Reverse-proxy auth rules (`REVERSE_PROXY_AUTH` requires
`TRUSTED_PROXY_NETWORKS`) are enforced by `app/check_setup.php` at boot.

## Tested platforms

- Tested: Linux/arm64 via local Docker — full HTTP matrix over MySQL
  (21 tests) plus the unfiltered SQLite unit suite in the native test
  target (1312 tests, 181400 assertions, exit 0, one pre-existing
  fixture warning; exact log is kept with the release evidence).
- Untested: Linux/amd64 (base images are multi-arch in principle; no
  amd64 run is claimed and none is implied).
