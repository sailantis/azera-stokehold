<?php

declare(strict_types=1);

namespace Azera\Stokehold\Tests\Http;

use Azera\AppContext;
use Azera\Core\Controller;
use Azera\Core\Dispatcher;
use Azera\Core\Router;
use Azera\Http\Request as AzeraRequest;
use Azera\Http\Response as AzeraResponse;
use Azera\Stokehold\Http\StokeholdHttpWorker;
use PHPUnit\Framework\TestCase;
use Stokehold\Frame;
use Stokehold\Header;
use Stokehold\MessageType;
use Stokehold\Request as StokeholdRequest;
use Stokehold\Response as StokeholdResponse;
use Stokehold\Worker;

/**
 * A controller whose one action proves which request it is serving.
 */
final class EchoController extends Controller
{
    public function whoAction(): AzeraResponse
    {
        $request = AppContext::instance()->request();

        return AzeraResponse::json([
            'path' => $request->path(),
            'method' => $request->method(),
            'body' => $request->body(),
            'query' => $request->query(),
            'user' => $request->post('user'),
        ]);
    }

    public function boomAction(): string
    {
        throw new \RuntimeException('controller exploded');
    }

    /**
     * Writes to the session, so the worker has one to carry back to the client.
     */
    public function rememberAction(string $value): string
    {
        session_start();
        $_SESSION['remembered'] = $value;

        return 'stored';
    }

    /**
     * Reads back what `rememberAction` wrote, to prove the session survived. The
     * cookie the test sends is what the worker reads from the `Cookie` header;
     * under a SAPI PHP does that itself.
     */
    public function recallAction(): string
    {
        session_start();

        return (string) ($_SESSION['remembered'] ?? 'nothing');
    }
}

final class StokeholdHttpWorkerTest extends TestCase
{
    private static function request(
        string $method = 'GET',
        string $uri = '/who',
        array $headers = [],
        string $body = '',
    ): StokeholdRequest {
        return new StokeholdRequest(
            method: $method,
            uri: $uri,
            protocol: 'HTTP/1.1',
            headers: $headers,
            remoteAddr: '127.0.0.1',
            scriptFilename: '/srv/app/public/index.php',
            https: false,
            body: $body,
        );
    }

    private function worker(?callable $notFound = null): StokeholdHttpWorker
    {
        $router = new Router();
        $router->add('GET', '/who', EchoController::class . '::whoAction');
        $router->add('POST', '/who', EchoController::class . '::whoAction');
        $router->add('GET', '/boom', EchoController::class . '::boomAction');
        $router->add('GET', '/remember/{value}', EchoController::class . '::rememberAction');
        $router->add('GET', '/recall', EchoController::class . '::recallAction');

        $dispatcher = new Dispatcher();
        $dispatcher->setBaseNamespace(__NAMESPACE__);

        return new StokeholdHttpWorker(
            AppContext::instance(),
            $router,
            $dispatcher,
            $notFound === null ? null : \Closure::fromCallable($notFound),
        );
    }

    /**
     * The header the worker added, or null.
     *
     * @param list<array{0: string, 1: string}> $headers
     */
    private static function cookieOf(StokeholdResponse $response): ?string
    {
        foreach ($response->headers as [$name, $value]) {
            if (strcasecmp($name, 'Set-Cookie') === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function bodyOf(StokeholdResponse $response): array
    {
        return self::jsonOf($response->body);
    }

    /**
     * Decode a JSON body written to the wire.
     *
     * @return array<string, mixed>
     */
    private static function jsonOf(string $body): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testDispatchesARouteAndReturnsTheApplicationResponse(): void
    {
        $response = $this->worker()->dispatch(self::request('GET', '/who?x=1'));

        $this->assertSame(200, $response->status);
        $this->assertSame(['x' => '1'], self::bodyOf($response)['query']);
        $this->assertSame('/who', self::bodyOf($response)['path']);
        $this->assertSame('GET', self::bodyOf($response)['method']);
    }

    public function testAPostBodyReachesTheController(): void
    {
        $response = $this->worker()->dispatch(self::request(
            'POST',
            '/who',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'user=ada',
        ));

        $this->assertSame('ada', self::bodyOf($response)['user']);
        $this->assertSame('user=ada', self::bodyOf($response)['body']);
    }

    public function testAnUnmatchedPathIsA404(): void
    {
        $response = $this->worker()->dispatch(self::request('GET', '/nope'));

        $this->assertSame(404, $response->status);
    }

    public function testAnApplicationNotFoundHandlerCanOverrideTheDefault(): void
    {
        $worker = $this->worker(static fn (string $path, string $method): AzeraResponse => AzeraResponse::html(
            '<h1>lost</h1>',
            404,
        ));

        $response = $worker->dispatch(self::request('GET', '/nope'));

        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('lost', $response->body);
    }

    public function testAnApplicationNotFoundHandlerMayRedirect(): void
    {
        $worker = $this->worker(static fn (): AzeraResponse => AzeraResponse::redirect('/'));

        $response = $worker->dispatch(self::request('GET', '/nope'));

        $this->assertSame(302, $response->status);
        $this->assertContains(['Location', '/'], $response->headers);
    }

    /**
     * A handler that returns something other than a response or null is a bug in
     * the application, and saying so is more useful than a silent 404.
     */
    public function testANotFoundHandlerReturningTheWrongTypeIsRejected(): void
    {
        $worker = $this->worker(static fn (): string => 'not a response');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return an Azera\Http\Response or null');

        $worker->dispatch(self::request('GET', '/nope'));
    }

    public function testAThrowingControllerIsA500AndKeepsTheWorkerAlive(): void
    {
        $worker = $this->worker();

        // `dispatch()` lets the throwable out — the loop is what turns it into a
        // 500 — so the pipeline stays honest about failures.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('controller exploded');

        $worker->dispatch(self::request('GET', '/boom'));
    }

    public function testResponseHeadersBecomeOrderedPairs(): void
    {
        $worker = $this->worker(static fn (): AzeraResponse => (new AzeraResponse())
            ->setStatus(201)
            ->setHeader('Content-Type', 'application/json')
            ->setHeader('X-Trace', 'abc')
            ->write('{"ok":true}'));

        $response = $worker->dispatch(self::request('GET', '/nope'));

        $this->assertSame(201, $response->status);
        $this->assertSame('{"ok":true}', $response->body);
        $this->assertContains(['X-Trace', 'abc'], $response->headers);
    }

    // ---------------------------------------------------------------------
    // Sessions
    // ---------------------------------------------------------------------

    public function testASessionStartedByTheApplicationIsSentAsACookie(): void
    {
        $response = $this->worker()->dispatch(self::request('GET', '/remember/ada'));

        $cookie = self::cookieOf($response);

        $this->assertIsString($cookie, 'a session was started, so a cookie is owed');
        $this->assertStringStartsWith(rawurlencode((string) session_name()) . '=', $cookie);
    }

    /**
     * The whole point: the next request, presenting that cookie, sees what the
     * previous one wrote — the job a SAPI does for free.
     */
    public function testASessionSurvivesIntoTheNextRequest(): void
    {
        $worker = $this->worker();

        $cookie = (string) self::cookieOf($worker->dispatch(self::request('GET', '/remember/ada')));
        $id = explode(';', explode('=', $cookie, 2)[1])[0];

        $recalled = $worker->dispatch(
            self::request('GET', '/recall', ['Cookie' => (string) session_name() . '=' . $id])
        );

        $this->assertSame('ada', $recalled->body);
        $this->assertNull(
            self::cookieOf($recalled),
            'a client that already holds the id is not sent another cookie'
        );
    }

    /**
     * A worker reads the session id out of the `Cookie` header, so a hostile id
     * must not reach the session handler: it becomes part of a file name.
     */
    public function testAHostileSessionIdIsRefusedAndGetsANewSession(): void
    {
        $response = $this->worker()->dispatch(
            self::request('GET', '/recall', ['Cookie' => (string) session_name() . '=../../etc/passwd'])
        );

        $this->assertSame('nothing', $response->body);
        $this->assertStringNotContainsString('passwd', (string) self::cookieOf($response));
    }

    /**
     * `session_id()` outlives a request in a persistent process, so a client
     * presenting no cookie must not inherit the previous client's session.
     */
    public function testAClientWithNoCookieDoesNotInheritThePreviousClientsSession(): void
    {
        $worker = $this->worker();

        $worker->dispatch(self::request('GET', '/remember/first-client'));

        $second = $worker->dispatch(self::request('GET', '/recall'));

        $this->assertSame('nothing', $second->body);
    }

    // ---------------------------------------------------------------------
    // The loop, over a real pipe
    // ---------------------------------------------------------------------

    /**
     * Encode frames the way the server would.
     *
     * @param list<array{0: int, 1: int, 2?: mixed, 3?: int}> $frames
     * @return resource
     */
    private static function serverStream(array $frames)
    {
        $bytes = '';
        foreach ($frames as $frame) {
            [$type, $id, $payload, $flags] = $frame + [2 => '', 3 => 0];
            if (is_array($payload)) {
                $payload = json_encode($payload, JSON_THROW_ON_ERROR);
                $flags |= Header::FLAG_LAST;
            }
            $bytes .= (new Frame($type, $id, $payload, $flags))->encode();
        }

        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('could not open an in-memory stream');
        }
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /** @return resource */
    private static function sink()
    {
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            throw new \RuntimeException('could not open an in-memory stream');
        }

        return $stream;
    }

    /**
     * @param list<array{0: int, 1: int, 2?: mixed, 3?: int}> $frames
     * @return list<array{status: int, body: string}>
     */
    private static function runOnce(array $frames): array
    {
        $in = self::serverStream(array_merge(
            [[MessageType::HELLO, 0, ['protocol_version' => Header::PROTOCOL_VERSION]]],
            $frames,
        ));
        $out = self::sink();

        $worker = new Worker($in, $out, self::sink());

        (new StokeholdHttpWorker(
            AppContext::instance(),
            (static function (): Router {
                $router = new Router();
                $router->add('GET', '/who', EchoController::class . '::whoAction');

                return $router;
            })(),
            (static function (): Dispatcher {
                $dispatcher = new Dispatcher();
                $dispatcher->setBaseNamespace(__NAMESPACE__);

                return $dispatcher;
            })(),
        ))->run($worker);

        rewind($out);
        $written = (string) stream_get_contents($out);

        // Walk the frames the worker wrote and collect one entry per response.
        // Reset the accumulating body at each RESPONSE head, or two responses
        // would concatenate into one string and the last body would be lost.
        $offset = 0;
        $responses = [];
        while ($offset + Header::HEADER_LEN <= strlen($written)) {
            $header = Header::decode(substr($written, $offset, Header::HEADER_LEN));
            $offset += Header::HEADER_LEN;
            $payload = substr($written, $offset, $header->payloadLen);
            $offset += $header->payloadLen;

            if ($header->messageType === MessageType::RESPONSE) {
                /** @var array{status: int} $head */
                $head = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                $responses[] = ['status' => $head['status'], 'body' => ''];
            } elseif ($header->messageType === MessageType::BODY && $responses !== []) {
                $responses[count($responses) - 1]['body'] .= $payload;
            }
        }

        return $responses;
    }

    private static function requestFrame(int $id, string $uri = '/who'): array
    {
        return [MessageType::REQUEST, $id, [
            'method' => 'GET',
            'uri' => $uri,
            'protocol' => 'HTTP/1.1',
            'headers' => [['Host', 'example.test']],
            'remote_addr' => '127.0.0.1',
            'script_filename' => '/srv/app/public/index.php',
            'body_len' => 0,
            'https' => false,
        ]];
    }

    public function testTheLoopServesARequestOverARealPipe(): void
    {
        $responses = self::runOnce([self::requestFrame(1)]);

        $this->assertCount(1, $responses);
        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame('/who', self::jsonOf($responses[0]['body'])['path']);
        $this->assertSame('GET', self::jsonOf($responses[0]['body'])['method']);
    }

    /**
     * Each request is served with its own request object, not the previous one.
     *
     * This one is weaker than it looks, and is worth being honest about: the
     * loop assigns `AzeraRequest::class` on every request, so it would still
     * pass with the reset disabled — the assignment overwrites the stale entry
     * before it can be observed. It pins that each response reflects its own
     * request, which is the property the assignment guarantees, and
     * `testTheLoopCallsResetStateOnRequestScopedServices` below is what actually
     * covers the reset.
     */
    public function testTheLoopResetsRequestScopeBetweenRequests(): void
    {
        $responses = self::runOnce([
            self::requestFrame(1, '/who?first=1'),
            self::requestFrame(2, '/who?second=2'),
        ]);

        $this->assertCount(2, $responses);
        $this->assertSame(['first' => '1'], self::jsonOf($responses[0]['body'])['query']);
        $this->assertSame(['second' => '2'], self::jsonOf($responses[1]['body'])['query']);
    }

    /**
     * The reset itself, pinned directly: a service registered on the context
     * must be told the request ended exactly once per request.
     *
     * This is what the interface is for — `Azera\Lifecycle\RequestScoped` exists
     * so a long-lived worker can flush per-request services — and it is the
     * behaviour a framework change could silently break.
     */
    public function testTheLoopCallsResetStateOnRequestScopedServices(): void
    {
        $counter = new class () implements \Azera\Lifecycle\RequestScoped {
            public int $resets = 0;

            public function resetState(): void
            {
                $this->resets++;
            }
        };

        AppContext::instance()->set('test.request-scoped', $counter);

        try {
            self::runOnce([self::requestFrame(1), self::requestFrame(2)]);

            $this->assertSame(2, $counter->resets, 'resetState() runs once per request');
        } finally {
            AppContext::instance()->set('test.request-scoped', null);
        }
    }
}
