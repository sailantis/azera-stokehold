<?php

declare(strict_types=1);

namespace Azera\Stokehold\Tests\Http\Adapter;

use Azera\Http\Request as AzeraRequest;
use Azera\Stokehold\Http\Adapter\StokeholdToAzeraRequest;
use PHPUnit\Framework\TestCase;
use Stokehold\Request as StokeholdRequest;

final class StokeholdToAzeraRequestTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private static function request(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        string $body = '',
        string $remoteAddr = '127.0.0.1',
        bool $https = false,
    ): StokeholdRequest {
        return new StokeholdRequest(
            method: $method,
            uri: $uri,
            protocol: 'HTTP/1.1',
            headers: $headers,
            remoteAddr: $remoteAddr,
            scriptFilename: '/srv/app/public/index.php',
            https: $https,
            body: $body,
        );
    }

    public function testCarriesMethodPathAndQuery(): void
    {
        $azera = StokeholdToAzeraRequest::convert(
            self::request('GET', '/users/42?active=1&tag=a&tag=b')
        );

        $this->assertSame('GET', $azera->method());
        $this->assertSame('/users/42', $azera->path());
        // A repeated parameter keeps the last value, which is what PHP's own
        // parse_str() does — the worker must not invent a different shape.
        $this->assertSame(['active' => '1', 'tag' => 'b'], $azera->query());
    }

    public function testInjectsTheBodyRatherThanLettingBodyReadThePipe(): void
    {
        $azera = StokeholdToAzeraRequest::convert(
            self::request('POST', '/x', ['Content-Type' => 'application/json'], '{"a":1}')
        );

        $this->assertSame('{"a":1}', $azera->body());
        $this->assertSame(['a' => 1], $azera->jsonBody());
    }

    /**
     * The reason the body must be injected even when it is empty. `body()` falls
     * back to `php://input` while the value is null — and in a worker stdin is
     * the protocol channel, so a bodyless request is precisely the one that
     * would read protocol bytes. On the CLI `php://input` is empty, so the
     * property is the only place the difference is observable.
     */
    public function testAnEmptyBodyIsInjectedRatherThanLeftUnset(): void
    {
        $azera = StokeholdToAzeraRequest::convert(self::request('GET', '/'));

        $property = new \ReflectionProperty(AzeraRequest::class, 'rawBody');
        $property->setAccessible(true);

        $this->assertSame('', $property->getValue($azera));
    }

    /**
     * Content-Type keeps its CGI name. Azera reads `CONTENT_TYPE` for
     * contentType() and isJson(), and a worker is supposed to see exactly the
     * environment a php-cgi backend would have seen.
     */
    public function testContentTypeKeepsItsCgiName(): void
    {
        $azera = StokeholdToAzeraRequest::convert(
            self::request('POST', '/x', ['Content-Type' => 'application/json'], '{}')
        );

        $this->assertSame('application/json', $azera->contentType());
        $this->assertTrue($azera->isJson());
        $this->assertSame('', $azera->server('HTTP_CONTENT_TYPE', ''));
    }

    public function testParsesAFormUrlencodedBodyIntoPost(): void
    {
        $azera = StokeholdToAzeraRequest::convert(self::request(
            'POST',
            '/login',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'user=ada&pass=secret&tags[]=a&tags[]=b',
        ));

        $this->assertSame(
            ['user' => 'ada', 'pass' => 'secret', 'tags' => ['a', 'b']],
            $azera->post(),
        );
        // The raw body stays available as well, as it does under FPM.
        $this->assertSame('user=ada&pass=secret&tags[]=a&tags[]=b', $azera->body());
    }

    public function testParsesAFormBodyWhoseMediaTypeCarriesParameters(): void
    {
        $azera = StokeholdToAzeraRequest::convert(self::request(
            'POST',
            '/login',
            ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
            'user=ada',
        ));

        $this->assertSame(['user' => 'ada'], $azera->post());
    }

    /**
     * PHP only auto-populates `$_POST` for the form media types. JSON is read
     * through jsonBody(), so pre-filling POST from it would invent behaviour
     * FPM never had.
     */
    public function testDoesNotParseAJsonBodyIntoPost(): void
    {
        $azera = StokeholdToAzeraRequest::convert(
            self::request('POST', '/x', ['Content-Type' => 'application/json'], '{"user":"ada"}')
        );

        $this->assertSame([], $azera->post());
        $this->assertSame(['user' => 'ada'], $azera->jsonBody());
    }

    /**
     * Multipart needs the boundary handling and uploads stokehold does not
     * carry yet. Leaving POST empty is honest; half-parsing it would not be.
     */
    public function testDoesNotHalfParseMultipart(): void
    {
        $azera = StokeholdToAzeraRequest::convert(self::request(
            'POST',
            '/upload',
            ['Content-Type' => 'multipart/form-data; boundary=----x'],
            "------x\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n------x--\r\n",
        ));

        $this->assertSame([], $azera->post());
    }

    /**
     * PHP parses a body with no declared type as form-urlencoded, because that
     * is what it fills into CONTENT_TYPE. The worker has to reach the same
     * conclusion from the effective environment rather than the raw headers.
     */
    public function testABodyWithNoDeclaredTypeGetsTheFormDefault(): void
    {
        $azera = StokeholdToAzeraRequest::convert(self::request('POST', '/x', [], 'a=1'));

        $this->assertSame('application/x-www-form-urlencoded', $azera->contentType());
        $this->assertSame(['a' => '1'], $azera->post());
    }

    /**
     * A bodyless request has no CONTENT_TYPE at all, so there is nothing to
     * parse and POST stays empty.
     */
    public function testABodylessRequestHasNoContentTypeAndNoPost(): void
    {
        $azera = StokeholdToAzeraRequest::convert(self::request('GET', '/x'));

        $this->assertSame('', $azera->contentType());
        $this->assertSame([], $azera->post());
    }

    public function testTrustsProxyHeadersForSchemeHostAndClientIp(): void
    {
        $azera = StokeholdToAzeraRequest::convert(self::request(
            'GET',
            '/',
            [
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'sailantis.dev',
                'X-Forwarded-For' => '203.0.113.7, 10.0.0.1',
            ],
            '',
            '10.0.0.1',
        ));

        $this->assertTrue($azera->isSecure());
        $this->assertSame('https', $azera->scheme());
        $this->assertSame('sailantis.dev', $azera->host());
        $this->assertSame('203.0.113.7', $azera->clientIp(true));
        // REMOTE_ADDR stays the immediate peer (the proxy), not the client.
        $this->assertSame('10.0.0.1', $azera->server('REMOTE_ADDR'));
    }

    public function testAHttpsRequestIsSecureWithoutProxyHeaders(): void
    {
        $azera = StokeholdToAzeraRequest::convert(self::request('GET', '/', [], '', '127.0.0.1', true));

        $this->assertTrue($azera->isSecure());
        $this->assertSame('on', $azera->server('HTTPS'));
    }

    public function testCarriesTheScriptFilenameAndRemoteAddr(): void
    {
        $azera = StokeholdToAzeraRequest::convert(self::request());

        $this->assertSame('/srv/app/public/index.php', $azera->server('SCRIPT_FILENAME'));
        $this->assertSame('127.0.0.1', $azera->server('REMOTE_ADDR'));
    }
}
