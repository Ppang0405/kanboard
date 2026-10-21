<?php

// Native Linux Swoole entrypoint (experimental).
//
// Wiring only: one-time process bootstrap, then one fresh container per
// request dispatched through SwooleRouter (health, API, allowlisted
// statics, UI). Transport, routing, upload, download, session and
// lifecycle details live in app/Swoole modules.

use Kanboard\Swoole\Bootstrap;
use Kanboard\Swoole\ContainerFactory;
use Kanboard\Swoole\SwooleLifecycle;
use Kanboard\Swoole\SwooleRouter;

require __DIR__.'/../../vendor/autoload.php';

Bootstrap::initializeOnce();

$host = getenv('SWOOLE_HOST') ?: '0.0.0.0';
$port = intval(getenv('SWOOLE_PORT') ?: 8080);

$server = new Swoole\Http\Server($host, $port);
$server->set(array(
    'worker_num' => 1,
    'enable_coroutine' => false,
    'max_request' => intval(getenv('SWOOLE_MAX_REQUEST') ?: 0),
    // Swoole defaults to 2 MB per request; match post_max_size (100M) plus multipart/base64 overhead.
    'package_max_length' => intval(getenv('SWOOLE_PACKAGE_MAX_LENGTH') ?: 140 * 1024 * 1024),
));

$server->on('WorkerStart', function ($server, $workerId) {
    // STDERR only: stdout output would mark PHP headers as sent and break
    // session and header handling for every later request on this worker.
    fwrite(STDERR, 'Swoole worker started (id='.$workerId.')'.PHP_EOL);
});

$server->on('Request', function ($request, $response) {
    $container = null;

    // Guard buffer: any stray stdout output stays contained in this request
    // instead of permanently marking PHP headers as sent for the worker.
    ob_start();

    try {
        $container = ContainerFactory::create();
        SwooleRouter::handle($request, $response, $container);
    } catch (Throwable $failure) {
        // Bounded failure signal, no automatic retry at this layer.
        fwrite(STDERR, 'Swoole request failure: '.$failure->getMessage().PHP_EOL);

        try {
            // The response may already be ended (e.g. a handler failure
            // after emit), so never double-end it here.
            if ($response->isWritable()) {
                $response->header('Content-Type', 'application/json');
                $response->status(500);
                $response->end(json_encode(array('status' => 500, 'message' => 'Internal Error')));
            }
        } catch (Throwable $errorResponseFailure) {
            fwrite(STDERR, 'Swoole error response failed: '.$errorResponseFailure->getMessage().PHP_EOL);
        }
    } finally {
        SwooleLifecycle::reset($container);
    }
});

fwrite(STDERR, 'Swoole listening on '.$host.':'.$port.' (1 sync worker, coroutines off)'.PHP_EOL);

$server->start();
