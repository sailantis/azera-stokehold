<?php

declare(strict_types=1);

namespace Azera\Stokehold\Http;

/**
 * Bridges PHP's native session storage to the worker protocol.
 *
 * A SAPI does three things for an application for free, and this is the third
 * after the request body and `$_POST`. Under `php-cgi`/FPM the session is
 * started from the request's `Cookie` header, and the cookie naming it is
 * written into the response headers. A worker speaks neither HTTP nor CGI, so
 * both halves have to be done here: the id is taken from the `Cookie` header
 * before the application runs, and the `Set-Cookie` is put on the response by
 * hand afterwards.
 *
 * The reason the cookie cannot simply be left to PHP is subtle and worth
 * stating. PHP refuses to start a session when `session.use_cookies` is on and
 * `headers_sent` is already true (`ext/session/session.c`); under the CLI SAPI
 * `headers_sent` is true from process startup, so **no session can be started
 * in a worker at all** until cookies are turned off. That is done with a
 * `php -d session.use_cookies=0` in the worker command — it cannot be done from
 * inside PHP, because `ini_set()` is refused for session settings once
 * `headers_sent` is set, exactly as `session_name()` is. With cookies off,
 * `header()` and `setcookie()` also become no-ops, which is why the cookie has
 * to be built by hand here.
 *
 * The other half is the id reset. `session_id()` outlives a request in a
 * persistent process, so a client arriving with no cookie would otherwise be
 * handed the id — and the session — left behind by the previous client. The id
 * is therefore set unconditionally before every request, and the cookie is
 * emitted only when the id the request ended up with is not the one it
 * presented.
 */
final class SessionBridge
{
    /**
     * PHP's session id alphabet: the characters `session.sid_bits_per_character`
     * can produce. Anything else from a client is refused rather than passed to
     * the session handler, because the id becomes part of a file name.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9,\-]+$/';

    /** The id the client presented, if any, so it is not sent a cookie it has. */
    private ?string $presented = null;

    /** Whether the missing `session.use_cookies=0` has been complained about. */
    private static bool $warned = false;

    /**
     * Adopt the request's session, if it presented one.
     *
     * The session is deliberately not started here. `SessionMiddleware` starts
     * it, exactly as it does under FPM, so a request that never reaches the
     * middleware does not create one — a 404 and a static file behave as they
     * always have.
     *
     * @param array<string, string> $cookies as the client sent them
     */
    public function begin(array $cookies): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // An application that started a session before dispatching owns it.
            return;
        }

        if (ini_get('session.use_cookies') && !self::$warned) {
            self::$warned = true;
            $this->warn(
                'sessions will not persist: this worker was started with '
                . 'session.use_cookies on, and PHP cannot start a session once '
                . 'headers have been sent, which under this SAPI is already the '
                . 'case. Start the worker with `php -d session.use_cookies=0`.'
            );
        }

        $name = session_name();
        $candidate = ($name !== false && isset($cookies[$name])) ? $cookies[$name] : null;

        $this->presented = ($candidate !== null && preg_match(self::ID_PATTERN, $candidate) === 1)
            ? $candidate
            : null;

        // Unconditional: a leftover id from the previous request is another
        // client's session.
        session_id($this->presented ?? '');
    }

    /**
     * Add the session cookie to a response, if this request earned one.
     *
     * @param list<array{0: string, 1: string}> $headers
     * @return list<array{0: string, 1: string}>
     */
    public function cookie(array $headers): array
    {
        $id = session_id();

        // No session was started, or the client already has this one.
        if ($id === '' || $id === $this->presented) {
            return $headers;
        }

        $headers[] = ['Set-Cookie', $this->header($id)];

        return $headers;
    }

    /**
     * The `Set-Cookie` value PHP would have sent, built from its own settings so
     * `session.cookie_*` keeps working as configuration.
     */
    private function header(string $id): string
    {
        $name = session_name();
        $params = session_get_cookie_params();

        $parts = [rawurlencode($name === false ? 'PHPSESSID' : $name) . '=' . rawurlencode($id)];

        $lifetime = (int) ($params['lifetime'] ?? 0);
        if ($lifetime > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', time() + $lifetime) . ' GMT';
            $parts[] = 'Max-Age=' . $lifetime;
        }

        $parts[] = 'Path=' . (($params['path'] ?? '') !== '' ? $params['path'] : '/');

        if (($params['domain'] ?? '') !== '') {
            $parts[] = 'Domain=' . $params['domain'];
        }

        if (!empty($params['secure'])) {
            $parts[] = 'Secure';
        }

        if (!empty($params['httponly'])) {
            $parts[] = 'HttpOnly';
        }

        if (($params['samesite'] ?? '') !== '') {
            $parts[] = 'SameSite=' . $params['samesite'];
        }

        return implode('; ', $parts);
    }

    /**
     * Say something once, on stderr, which is not the protocol channel.
     */
    private function warn(string $message): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, '[azera-stokehold] ' . $message . PHP_EOL);
        }
    }
}
