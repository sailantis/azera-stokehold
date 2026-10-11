# azera-stokehold

Application-server adapter that lets [Azera](https://github.com/Sailantis/azera-framework) run on top of
[stokehold](https://github.com/sailantis/stokehold). stokehold (a Rust application server) runs persistent
PHP workers, so your app boots **once** and stays resident, reusing database/cache/queue connections across
requests — while Azera's Router, Dispatcher, DI container and middleware pipeline work exactly as they do
under PHP-FPM.

This package serves **HTTP**. It sits on top of
[`sailantis/stokehold-worker`](https://github.com/sailantis/stokehold-php-sdk), the PHP SDK for stokehold's
worker protocol; that package is the transport, this one is the framework glue.

---

## Installation

```bash
composer require sailantis/azera-stokehold
```

> Requires PHP >= 8.2, the `sailantis/azera-framework` package, and a `stokehold` binary on the host.

---

## Quick start

Point a `worker` backend at the entry point in your stokehold configuration:

```toml
default_backend = "app"

[server]
http = "0.0.0.0:8001"
document_root = "/srv/app/public"
front_controller = "index.php"

[backends.app]
kind = "worker"
command = "php vendor/sailantis/azera-stokehold/azera-worker.php"
workers = 4
```

Then start the server. Your existing routes, controllers, middleware and templates run unchanged:

```bash
stokehold serve
```

Stokehold serves static files from `document_root` itself and sends everything else to the worker, so the
layout is the same as under nginx plus PHP-FPM. No `php -d` flags are needed: see [Sessions](#sessions).

---

## How it works

Stokehold spawns worker processes and speaks its own framed protocol to each one over stdin/stdout. The
worker boots the application once, then serves every request from the same process:

```text
stokehold (Rust)
  └─ http listener ──► framed protocol over stdin/stdout ──► azera-worker.php
                                                              ├─ WorkerBootstrap::boot()    (once)
                                                              └─ StokeholdHttpWorker::run() (per request)
                                                                   ├─ WorkerSession::begin()
                                                                   ├─ StokeholdToAzeraRequest
                                                                   ├─ Router → Dispatcher → Controller
                                                                   └─ clearRequestScope()      (after each)
```

The per-request pipeline is the same one the framework runs under FPM. What an application server has to add
is what a SAPI does for free, and this package does all of it:

- **The body.** Under a worker `php://input` is not the request body — stdin carries protocol frames — so
  the body is handed to Azera explicitly. This is not an optimisation: letting `body()` fall back to
  `php://input` would read protocol bytes and desynchronise the stream permanently.
- **`$_POST`.** PHP parses a form-urlencoded body into `$_POST` before the application runs. The worker
  reconstructs that from the body and the *effective* content type, so `Request::post()` and `input()`
  behave as they do under FPM.
- **`$_COOKIE` and the session.** A worker speaks neither HTTP nor CGI, so the session id is read from the
  `Cookie` header and the `Set-Cookie` for a new session is written onto the response. See below.

---

## Sessions

Sessions work with no special configuration, because PHP's own session cookie handling cannot be used in a
worker. `header()`, `setcookie()` and `headers_list()` are all no-ops under a worker's SAPI, so a
`Set-Cookie` PHP writes would never reach the client. `WorkerSession` (in `sailera/azera-framework`; every
adapter needs the same two halves) therefore:

- reads the session id from the request's `Cookie` header before the application runs, so
  `SessionMiddleware` finds it exactly as it would under FPM, and
- builds the `Set-Cookie` for a session started during the request, for the adapter to put on the response.

It also relaxes `session.use_cookies` and `session.cache_limiter` at the start of each request, so PHP does
not waste effort on a cookie it cannot send — and stops it emitting `Cache-Control` headers, and a warning,
through the same dead channel. An id the client *did* send is still adopted, so a returning client keeps its
session.

The id is cleared between requests. `session_id()` outlives the request in a persistent process, so a client
presenting no cookie would otherwise be handed the session left behind by the previous client.

`session.name` and the `session.cookie_*` settings are honoured as usual, so a custom cookie name is set the
way it always was — `session_name('_s')` in the worker's customisation file.

---

## Customising the worker

An application does not need to change the entry point. For a friendly 404, or any one-time setup that
needs the booted container, add a `stokehold-worker.php` to the project root that returns a callable:

```php
<?php

use Azera\AppContext;
use Azera\Http\Response;

return static function (AppContext $ctx): ?Closure {
    if (!DEBUG_MODE) {
        return static fn (string $path, string $method) => Response::redirect('/');
    }

    return static fn (string $path, string $method) => Response::html(
        "<h1>404 Not Found</h1><p>No route {$method} {$path}.</p>",
        404,
    );
};
```

The callable receives the booted `AppContext` and may return a not-found handler (`string $path,
string $method` → `Azera\Http\Response`, or `null` for a plain 404). It runs before the first request, so it
is also the place for session settings — `session_name('_s')` — since PHP only refuses to change those once
a session is active.

---

## Differences from `azera-roadrunner`

Same architecture, different transport — and a few things are deliberately better rather than merely
different:

- **No PSR-7 hop.** RoadRunner's worker hands you PSR-7, so that adapter converts PSR-7 → Azera. Stokehold's
  protocol already speaks CGI, so there is no intermediate message to build and no `nyholm/psr7` dependency.
- **No reflection.** Azera's `Request` now has a `setRawBody()` method instead of the private-property poke
  the RoadRunner adapter previously needed.
- **Sessions work.** Both adapters share `Azera\Http\WorkerSession`, which carries the session id in from
  the `Cookie` header and the `Set-Cookie` back out, since a worker's SAPI has no header channel.
- **`dd()` cannot kill the worker.** In halt mode it becomes a 500 carrying the dump, as on RoadRunner.

---

## Testing

```bash
composer test
```

The suite runs without PHP-FPM, a socket, or a stokehold binary: the worker is driven over in-memory streams
carrying real protocol frames, so the loop, the translation, the response framing and the per-request reset
are all asserted against the actual bytes a server would send and receive.

---

## License

MIT — see [LICENSE](LICENSE).
