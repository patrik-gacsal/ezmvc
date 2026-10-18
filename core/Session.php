<?php
namespace EzMvc;

/** Static wrapper over the PHP session with hardened cookie settings, an idle timeout and a client fingerprint. */
class Session {

    private static string $name;
    private static int $timeout;
    private static bool $initialized = false;

    /**
     * Starts the session once per request (later calls are no-ops). A session
     * idle longer than $timeout seconds, or from a different client
     * fingerprint, is discarded and replaced with a fresh id.
     */
    public static function init(string $name = 'ezmvc', int $timeout = 900): void {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        self::$name        = $name;
        self::$timeout     = $timeout;

        // Mark the cookie Secure only when the request is actually HTTPS, so a
        // plain-HTTP dev or host still gets a working session cookie.
        $https = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', $https ? 1 : 0);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_trans_sid', 0);
        ini_set('session.hash_function', 'sha256');
        ini_set('session.cookie_lifetime', 0);
        ini_set('session.use_cookies', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cache_limiter', 'nocache');
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_maxlifetime', self::$timeout + 10);

        session_name(self::$name);
        session_start();

        if (self::get('time') < time() || self::get('client') != self::clientHash()) {
            session_unset();
            session_destroy();
            session_start();
            session_regenerate_id(true);
        }
        self::set('client', self::clientHash());
        self::set('time', time() + self::$timeout);
    }

    /** Issues a new session id and deletes the old one; call on login and every privilege change. */
    public static function regenerate(): void {
        session_regenerate_id(true);
    }

    /** Ends the session and deletes its data, e.g. on logout. */
    public static function destroy(): void {
        session_unset();
        session_destroy();
    }

    private static function clientHash(): string {
        return hash('sha256', '!?'
            . ($_SERVER['HTTP_USER_AGENT'] ?? '')
            . self::ipNetwork($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /**
     * A coarse network prefix of $ip, so a client's session survives small
     * address changes (mobile/ISP) yet won't match a client on a different
     * network: the first two octets (/16) for IPv4, the first eight bytes (/64)
     * for IPv6. inet_pton normalizes any address form (incl. `::` compression)
     * to packed bytes first; the prefix is returned as those bytes (it's only
     * ever hashed). Returns $ip unchanged if it isn't a valid IP address.
     */
    private static function ipNetwork(string $ip): string {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        // 4-byte IPv4 → /16 (first 2 bytes); 16-byte IPv6 → /64 (first 8 bytes)
        return substr($packed, 0, strlen($packed) === 4 ? 2 : 8);
    }

    /** Stores a value for later requests; needs init() first. */
    public static function set(string $key, mixed $value): void {
        $_SESSION[$key] = $value;
    }

    /** The stored value, or null when absent. */
    public static function get(string $key): mixed {
        return $_SESSION[$key] ?? null;
    }

}
