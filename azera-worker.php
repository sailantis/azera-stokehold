<?php

declare(strict_types=1);

/**
 * volucer worker entry point for an Azera application.
 *
 * Point a `kind = "worker"` backend at this file:
 *
 *     [backends.app]
 *     kind = "worker"
 *     command = "php vendor/sailantis/azera-volucer/azera-worker.php"
 *
 * The application is booted once and then stays resident, serving every request
 * from the same process. Per-request state is reset by the worker loop after
 * each request, so nothing leaks from one request into the next.
 *
 * An application does not need to change this file. To customise the worker —
 * a friendly 404, or one-time setup that needs the booted container — put a
 * `volucer-worker.php` in the project root that returns a callable:
 *
 *     <?php
 *     return static function (AppContext $ctx): ?Closure {
 *         return static fn (string $path, string $method) => Response::status(404);
 *     };
 *
 * The callable receives the booted AppContext and may return a not-found
 * handler (or null). It runs before the first request, so it is also the right
 * place for one-time setup that needs the container.
 */

use Azera\AppContext;
use Azera\Http\Response as AzeraResponse;
use Azera\Volucer\Http\VolucerHttpWorker;
use Azera\Volucer\WorkerBootstrap;

// The worker is started by the server with the application as its working
// directory, so that is the project root unless it is overridden.
$projectRoot = getenv('AZERA_PROJECT_ROOT') ?: getcwd();
if (!is_string($projectRoot) || $projectRoot === '') {
    throw new RuntimeException('Could not determine the application root.');
}

/** @var string $autoload */
$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException(
        'No vendor/autoload.php in ' . $projectRoot
        . '. Set AZERA_PROJECT_ROOT, or run composer install there.'
    );
}
require $autoload;

// ---------------------------------------------------------------------------
// 1. Boot the application once, for the life of the worker
// ---------------------------------------------------------------------------
$context = WorkerBootstrap::boot($projectRoot);

// dd() would otherwise exit the worker and take every request behind it with
// it; in halt mode it becomes a 500 carrying the dump.
WorkerBootstrap::enableDumpHalt($context);

// ---------------------------------------------------------------------------
// 2. Application-specific worker configuration, if it wants any
// ---------------------------------------------------------------------------
$notFoundHandler = null;
$customise = $projectRoot . '/volucer-worker.php';

if (is_file($customise)) {
    /** @var mixed $callable */
    $callable = require $customise;

    if (!is_callable($callable)) {
        throw new RuntimeException('volucer-worker.php must return a callable.');
    }

    /** @var mixed $handler */
    $handler = $callable($context);

    if ($handler !== null && !$handler instanceof Closure) {
        throw new RuntimeException(
            'volucer-worker.php must return a Closure or null, got ' . get_debug_type($handler) . '.'
        );
    }

    $notFoundHandler = $handler;
}

// ---------------------------------------------------------------------------
// 3. Serve until the server closes the pipe
// ---------------------------------------------------------------------------
$worker = new VolucerHttpWorker(
    $context,
    $context->router(),
    $context->dispatcher(),
    notFoundHandler: $notFoundHandler,
);

$worker->run();
