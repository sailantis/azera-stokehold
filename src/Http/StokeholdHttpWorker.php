<?php

declare(strict_types=1);

namespace Azera\Stokehold\Http;

use Azera\AppContext;
use Azera\Core\Dispatcher;
use Azera\Core\Router;
use Azera\Http\Request as AzeraRequest;
use Azera\Http\Response as AzeraResponse;
use Azera\Http\WorkerSession;
use Azera\Stokehold\Http\Adapter\StokeholdToAzeraRequest;
use Closure;
use Clarity\Debug\DumpHaltException;
use Stokehold\Request as StokeholdRequest;
use Stokehold\Response as StokeholdResponse;
use Stokehold\Worker;
use Throwable;

/**
 * Persistent HTTP worker loop for stokehold.
 *
 * Reuses the Azera {@see Router} and {@see Dispatcher} pipeline unchanged: each
 * stokehold request is translated to an Azera request, matched against the
 * router, and dispatched to the controller. After the response has been sent,
 * per-request state is reset so nothing leaks into the next request.
 *
 * Nothing here emits output. `Response::send()` would call `header()` and
 * `echo`, which in a worker write into the SDK's capture buffer rather than to a
 * client, so the response is always read through its getters and handed to the
 * SDK as a value.
 */
final class StokeholdHttpWorker
{
    /**
     * The framework's `dd()` dump is answered as a 500 whatever it contains.
     * Nothing about a debug dump is a successful response.
     */
    private const DUMP_STATUS = 500;

    /**
     * Native sessions are a SAPI service too, and a worker has no SAPI; this
     * carries the session id in and the cookie back out. Shared with the
     * RoadRunner adapter, which needs exactly the same two halves.
     */
    private WorkerSession $session;

    /**
     * @param Closure(string $path, string $method): AzeraResponse|null $notFoundHandler
     *        Invoked when no route matches. Return a response to send it, or
     *        null to fall back to a plain 404.
     */
    public function __construct(
        private readonly AppContext $context,
        private readonly Router $router,
        private readonly Dispatcher $dispatcher,
        private readonly ?Closure $notFoundHandler = null,
    ) {
        $this->session = new WorkerSession();
    }

    /**
     * Serve requests until the server closes the pipe.
     */
    public function run(?Worker $worker = null): void
    {
        $worker ??= Worker::create();

        $worker->run(function (StokeholdRequest $request, Worker $worker): StokeholdResponse {
            try {
                return $this->dispatch($request);
            } catch (DumpHaltException $halt) {
                return new StokeholdResponse(
                    self::DUMP_STATUS,
                    [['Content-Type', self::dumpContentType($halt->context)]],
                    $halt->output,
                );
            } catch (Throwable $error) {
                // One bad request must not cost the worker: a long-lived process
                // that dies takes the requests behind it with it. The SDK logs
                // the throwable to stderr, which is not the protocol channel.
                $worker->emitLog('unhandled error: ' . $error->getMessage(), 'error');

                return new StokeholdResponse(
                    500,
                    [['Content-Type', 'text/plain; charset=utf-8']],
                    'Internal Server Error',
                );
            } finally {
                // Reset per-request state before the next request is read.
                // `clearRequestScope()` is idempotent, so the early returns above
                // cannot double-apply it. The session is part of that state: PHP
                // leaves `session_id()` and `$_SESSION` behind in a long-lived
                // process, and the next client must not inherit either.
                $this->session->end();
                $_COOKIE = [];

                $this->context->clearRequestScope();
            }
        });
    }

    /**
     * Run the Router + Dispatcher pipeline against one stokehold request.
     *
     * Public and free of I/O so the pipeline can be tested without a pipe.
     */
    public function dispatch(StokeholdRequest $request): StokeholdResponse
    {
        $azeraRequest = StokeholdToAzeraRequest::convert($request);

        // The framework's request() reads this out of the container. Setting it
        // on every request is not decoration: `clearRequestScope()` only clears
        // the resolved-instance cache, so an object left in the definitions map
        // would otherwise be handed to the next request as well.
        $this->context->set(AzeraRequest::class, $azeraRequest);

        // Adopt the client's session before the application runs, so the
        // session middleware finds `$_COOKIE` the way it would under FPM.
        $_COOKIE = $request->cookies();
        $this->session->begin($_COOKIE);

        $path = $azeraRequest->path();
        $method = $azeraRequest->method();

        $route = $this->router->match($path, $method);

        $response = $route === null
            ? $this->notFound($path, $method)
            : $this->dispatcher->dispatch($route);

        return new StokeholdResponse(
            $response->getStatus(),
            // The session cookie is the one header PHP would have written for
            // free under a SAPI, so it is added last — after the application's
            // own headers, as PHP's would be.
            self::withSessionCookie(self::headers($response->getHeaders()), $this->session->cookie()),
            $response->getBody(),
        );
    }

    /**
     * The session cookie, as an ordered pair like every other header.
     *
     * @param list<array{0: string, 1: string}> $headers
     * @return list<array{0: string, 1: string}>
     */
    private static function withSessionCookie(array $headers, ?string $cookie): array
    {
        if ($cookie !== null) {
            $headers[] = ['Set-Cookie', $cookie];
        }

        return $headers;
    }

    /**
     * The 404 path, with the application's optional override.
     */
    private function notFound(string $path, string $method): AzeraResponse
    {
        if ($this->notFoundHandler === null) {
            return AzeraResponse::status(404);
        }

        $response = ($this->notFoundHandler)($path, $method);

        if ($response === null) {
            return AzeraResponse::status(404);
        }

        if (!$response instanceof AzeraResponse) {
            throw new \RuntimeException(
                'The not-found handler must return an Azera\Http\Response or null.'
            );
        }

        return $response;
    }

    /**
     * Convert Azera's header map into the SDK's ordered list form.
     *
     * Azera holds one value per name, while the wire format carries a list of
     * pairs with order and case preserved, so the map is simply unrolled.
     *
     * @param array<string, string> $headers
     * @return list<array{0: string, 1: string}>
     */
    private static function headers(array $headers): array
    {
        $pairs = [];
        foreach ($headers as $name => $value) {
            $pairs[] = [(string) $name, (string) $value];
        }

        return $pairs;
    }

    /**
     * The Content-Type for a `dd()` dump, which is rendered per escape context.
     */
    private static function dumpContentType(string $context): string
    {
        return match ($context) {
            'js' => 'application/javascript; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            default => 'text/html; charset=UTF-8',
        };
    }
}
