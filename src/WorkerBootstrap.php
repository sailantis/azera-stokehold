<?php

declare(strict_types=1);

namespace Azera\Stokehold;

use Azera\AppContext;
use Azera\Boot\BootstrapDiscovery;
use Azera\Boot\BootstrapResolver;
use Azera\Core\Engines\ClarityEngine;

/**
 * Bootstraps the Azera AppContext for a stokehold worker.
 *
 * A stokehold worker is a long-lived PHP process, so the application is booted
 * exactly once — not per request. This resolves the application's
 * BootstrapProvider through the same chain the framework CLI uses, runs it, and
 * returns the shared {@see AppContext}.
 *
 * The provider (or its AppContext subclass) must therefore be the first to call
 * {@see AppContext::instance()}; this class never constructs a base AppContext,
 * or a subclass would lose the singleton slot.
 */
final class WorkerBootstrap
{
    /**
     * Resolve and run the application bootstrap, then return the shared
     * AppContext.
     *
     * @param string $projectRoot Absolute path to the application root, the
     *                            directory holding composer.json.
     */
    public static function boot(string $projectRoot): AppContext
    {
        $bridge = BootstrapResolver::resolve($projectRoot);

        if ($bridge === null && !is_file($projectRoot . '/vendor/azera-bootstrap.php')) {
            BootstrapDiscovery::scanAndCache($projectRoot);
            $bridge = BootstrapResolver::resolve($projectRoot);
        }

        if ($bridge !== null) {
            $bridge->boot();
        }

        return AppContext::instance();
    }

    /**
     * Let `dd()` halt a request with a dump instead of exiting the worker.
     *
     * `dd()` normally ends the process, which in a persistent worker would take
     * the whole worker down — and every request queued behind it. In halt mode
     * it throws a {@see \Clarity\Debug\DumpHaltException} carrying the rendered
     * dump, which the worker loop answers with a 500 rather than letting the
     * process die.
     *
     * No-op unless the view engine is Clarity and debug mode is on.
     */
    public static function enableDumpHalt(AppContext $context): void
    {
        $view = $context->view();

        if ($view instanceof ClarityEngine) {
            $view->getDebugOptions()?->haltWithException(true);
        }
    }
}
