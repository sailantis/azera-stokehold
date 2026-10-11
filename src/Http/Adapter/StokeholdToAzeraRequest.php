<?php

declare(strict_types=1);

namespace Azera\Stokehold\Http\Adapter;

use Azera\Http\Request as AzeraRequest;
use Stokehold\Request as StokeholdRequest;

/**
 * Converts a stokehold request into an Azera {@see AzeraRequest}.
 *
 * The two are closer than they look: stokehold speaks the CGI environment
 * natively, and {@see StokeholdRequest::serverVars()} already produces the
 * `$_SERVER`-shaped array Azera's Request is built around — including the CGI
 * naming rules, where `Content-Type` becomes `CONTENT_TYPE` rather than
 * `HTTP_CONTENT_TYPE`, and `CONTENT_LENGTH` is the length of the body actually
 * framed. So this is mostly a handover, plus the two things CGI gets for free
 * from PHP that a worker has to do itself: the parsed form body, and the raw
 * body.
 */
final class StokeholdToAzeraRequest
{
    /**
     * Media types whose bodies PHP parses into `$_POST`.
     *
     * PHP only auto-parses `application/x-www-form-urlencoded`. It deliberately
     * does not parse JSON, so neither does this: an application reads a JSON
     * body through {@see AzeraRequest::jsonBody()}, and pre-filling `$_POST`
     * from JSON would be behaviour FPM never had.
     *
     * `multipart/form-data` is a form type too, but parsing it needs the
     * boundary handling and the uploads stokehold does not carry yet, so it is
     * left unparsed rather than half-parsed.
     */
    private const FORM_MEDIA_TYPE = 'application/x-www-form-urlencoded';

    public static function convert(StokeholdRequest $request): AzeraRequest
    {
        $server = $request->serverVars();

        // `$_POST` is decided from the *effective* content type — the one in the
        // CGI environment, not the raw header. They differ: a body with no
        // declared type has no `Content-Type` header, but the server still puts
        // `application/x-www-form-urlencoded` in `CONTENT_TYPE`, which is what
        // PHP parses. Reading the header instead would leave `$_POST` empty here
        // while it is populated under `php-cgi`.
        $post = self::post($server['CONTENT_TYPE'] ?? '', $request->body);

        // Proxy-header trust, mirroring the RoadRunner adapter: stokehold is
        // expected to sit behind a reverse proxy, so scheme(), host(), port()
        // and clientIp() should honour X-Forwarded-* as they do under FPM.
        $azera = new AzeraRequest($server, $request->query(), $post, [], true);

        // Under a worker, `php://input` is not the body — stdin is the protocol
        // channel, and Azera's `body()` falls back to reading it. Hand the body
        // over explicitly, including when it is empty: the fallback triggers on
        // null, so a bodyless request is exactly the one that must be stopped
        // from reading the pipe.
        $azera->setRawBody($request->body);

        return $azera;
    }

    /**
     * The `$_POST` array a CGI SAPI would have populated.
     *
     * PHP parses the body only for the form media types and leaves `$_POST`
     * empty otherwise. Multipart is deliberately not parsed: it needs the
     * boundary handling — and the uploads — that stokehold does not carry yet,
     * and a half-parsed multipart body would be worse than an empty one.
     *
     * @return array<string, mixed>
     */
    private static function post(string $contentType, string $body): array
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($body === '' || $mediaType !== self::FORM_MEDIA_TYPE) {
            return [];
        }

        $post = [];
        parse_str($body, $post);

        return $post;
    }
}
