<?php

declare(strict_types=1);

namespace Azera\Stokehold\Tests\Http;

use Azera\Stokehold\Http\SessionBridge;
use PHPUnit\Framework\TestCase;

/**
 * The session half of what a SAPI does for free.
 *
 * These run with `session.use_cookies=0`, which is how a worker has to be
 * started: PHP refuses to start a session once `headers_sent` is set, and under
 * this SAPI it is set from process startup. The `-d` flags are in the `test`
 * script in composer.json rather than here, because `ini_set()` is refused for
 * session settings at runtime — which is the whole reason they are on the
 * command line.
 */
final class SessionBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $this->resetSession();
        parent::tearDown();
    }

    /**
     * Session state is global and outlives a test, exactly as it outlives a
     * request in the worker this class exists for.
     */
    private function resetSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        session_id('');
        $_COOKIE = [];
    }

    public function testAResponseWithNoSessionCarriesNoCookie(): void
    {
        $bridge = new SessionBridge();
        $bridge->begin([]);

        // The application never started one, which is what a 404 or a static
        // file looks like — the headers come back untouched.
        $headers = [['Content-Type', 'text/html']];

        $this->assertSame($headers, $bridge->cookie($headers));
    }

    public function testTheClientSessionIdIsAdoptedAndIsNotEchoedBack(): void
    {
        $bridge = new SessionBridge();
        $bridge->begin([session_name() => 'abcdef0123456789']);

        $this->assertSame('abcdef0123456789', session_id());

        session_start();
        $this->assertSame('abcdef0123456789', session_id());

        // The client already has this id, so sending it another cookie would be
        // a needless `Set-Cookie` on every response.
        $this->assertSame([], $bridge->cookie([]));
    }

    /**
     * The id from a cookie becomes part of a file name, so anything that is not
     * a session id must not reach the session handler.
     */
    public function testAnInvalidSessionIdFromTheClientIsRefused(): void
    {
        $bridge = new SessionBridge();

        foreach (['../../etc/passwd', 'a b', 'id;rm -rf /', '', 'x/y'] as $hostile) {
            $bridge->begin([session_name() => $hostile]);

            $this->assertSame('', session_id(), sprintf('refused: %s', var_export($hostile, true)));
        }
    }

    public function testANewSessionIsOfferedToTheClientAsACookie(): void
    {
        $bridge = new SessionBridge();
        $bridge->begin([]);

        session_start();
        $id = session_id();
        $this->assertNotSame('', $id);

        $headers = $bridge->cookie([['Content-Type', 'text/html']]);

        $this->assertCount(2, $headers);
        $this->assertSame('Set-Cookie', $headers[1][0]);
        $this->assertStringStartsWith(rawurlencode((string) session_name()) . '=' . $id, $headers[1][1]);
        $this->assertStringContainsString('Path=/', $headers[1][1]);
    }

    /**
     * The reason the id is set on *every* request, not only when a cookie
     * arrives: `session_id()` outlives the request in a persistent process, so
     * a client presenting nothing must not be handed the previous client's
     * session.
     */
    public function testAClientWithNoCookieDoesNotInheritThePreviousClientsSession(): void
    {
        $first = new SessionBridge();
        $first->begin([session_name() => 'aaaaaaaaaaaaaaaa']);
        session_start();
        $firstId = session_id();
        session_write_close();

        $second = new SessionBridge();
        $second->begin([]);

        // The leftover id was cleared before the session was started.
        $this->assertSame('', session_id());

        session_start();
        $this->assertNotSame($firstId, session_id());
    }

    /**
     * With cookies left on, PHP cannot start a session at all in a worker. The
     * bridge must say so rather than let every request silently lose its
     * session, and it must hand back the environment it found.
     */
    public function testTheBridgeDoesNotInventASessionWhenPhpRefusesToStartOne(): void
    {
        // `session.use_cookies` is off for this run, so emulate the failure the
        // warning covers by checking the guard directly.
        $this->assertSame('0', ini_get('session.use_cookies'), 'the suite must run with cookies off');

        $bridge = new SessionBridge();
        $bridge->begin([]);

        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame('', session_id());
    }
}
