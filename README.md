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
layout is the same as under nginx plus PHP-FPM.

---

## How it works

Stokehold spawns worker processes and speaks its own framed protocol to each one over stdin/stdout. The
worker boots the application once, then serves every request from the same process:

```text
stokehold (Rust)
  └─ http listener ──► framed protocol over stdin/stdout ──► azera-worker.php
                                                              ├─ WorkerBootstrap::boot()    (once)
                                                              └─ StokeholdHttpWorker::run() (per request)
                                                                   ├─ StokeholdToAzeraRequest
                                                                   ├─ Router → Dispatcher → Controller
                                                                   └─ clearRequestScope()      (after each)
```

The per-request pipeline is the same one the framework runs under FPM. What an application server has to add
is the two things a SAPI does for free, and this package does both:

- **The body.** Under a worker `php://input` is not the request body — stdin carries protocol frames — so
  the body is handed to Azera explicitly. This is not an optimisation: letting `body()` fall back to
  `php://input` would read protocol bytes and desynchronise the stream permanently.
- **`$_POST`.** PHP parses a form-urlencoded body into `$_POST` before the application runs. The worker
  reconstructs that from the body and the *effective* content type, so `Request::post()` and `input()`
  behave as they do under FPM.

---

## Customising the worker

An application does not need to change the entry point. For a friendly 404, a session cookie name, or any
one-time setup that needs the booted container, add a `stokehold-worker.php` to the project root that
returns a callable:

```php
<?php

use Azera\AppContext;
use Azera\Http\Response;

return static function (AppContext $ctx): ?Closure {
    session_name('_s');

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
string $method` → `Azera\Http\Response`, or `null` for a plain 404).

---

## Differences from `azera-roadrunner`

Same architecture, different transport — and a few things are deliberately better rather than merely
different:

- **No PSR-7 hop.** RoadRunner's worker hands you PSR-7, so that adapter converts PSR-7 → Azera. Stokehold's
  protocol already speaks CGI, so there is no intermediate message to build and no `nyholm/psr7` dependency.
- **`CONTENT_TYPE` is a CGI variable, not `HTTP_CONTENT_TYPE`.** This matters: Azera reads `CONTENT_TYPE` for
  `contentType()` and `isJson()`. The RoadRunner adapter maps every header to `HTTP_*`, so a JSON body is not
  recognised as JSON there. Here the environment is built the way the CGI specification says.
- **No reflection.** Azera's `Request` now has a `setRawBody()` method instead of the private-property poke
  the RoadRunner adapter previously needed.
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
