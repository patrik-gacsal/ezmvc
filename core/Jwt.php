<?php
namespace EzMvc;

/**
 * Minimal JWT implementation supporting HS256 (shared secret) and RS256 (RSA
 * key pair, requires the openssl extension).
 *
 * The verifier never trusts the token's own `alg` header: the caller declares
 * which algorithm and key to use, and a token whose header disagrees is
 * rejected. That is what makes accepting more than one algorithm safe against
 * the RS256↔HS256 confusion attack (where an attacker signs an HS256 token
 * using the public RSA key as the HMAC secret, hoping the verifier reads `alg`
 * from the token and HMACs with the public key).
 */
class Jwt {
    const ALG_HS256 = 'HS256';
    const ALG_RS256 = 'RS256';

    /** Minimum HMAC secret length, in bytes — 128 bits. A length floor, not an entropy measure. */
    private const MIN_SECRET_BYTES = 16;

    /**
     * Verifies the token's signature (using $alg and $key) and, unless
     * $noexpire, its expiry, then returns the decoded payload object — the
     * caller reads whatever claims it needs off it (e.g. $payload->sub for the
     * user id). Returns false if the token is malformed, its header `alg` isn't
     * $alg, the signature is wrong, or it has expired.
     *
     * $aud/$iss (when given) and the automatic `nbf` check apply to RS256 only —
     * consuming another issuer's tokens, where a valid signature alone isn't
     * enough. HS256 is the self-issued case and ignores them. Pass them by name:
     * parse(..., aud: 'my-api'); `aud` in the token may be a value or a list.
     * @param string $token
     * @param string $key HMAC secret (HS256) or PEM public key (RS256)
     * @param bool $noexpire skip the exp check
     * @param string $alg expected algorithm — self::ALG_HS256 or self::ALG_RS256
     * @param ?string $aud RS256 only: required audience — the token's `aud` must contain it
     * @param ?string $iss RS256 only: required issuer — the token's `iss` must equal it
     * @return object|false the decoded payload, or false when invalid
     * @throws \InvalidArgumentException on an HS256 secret weaker than 128 bits,
     *   an unusable RS256 key, or an unsupported $alg
     * @throws \RuntimeException if RS256 is used without the openssl extension
     */
    public static function parse(#[\SensitiveParameter] $token, #[\SensitiveParameter] $key, $noexpire = false, $alg = self::ALG_HS256, ?string $aud = null, ?string $iss = null) {
        if ($alg === self::ALG_HS256) {
            self::assertSecretStrength($key);
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        // Anti-confusion: verify with the caller's algorithm and reject a token
        // that claims a different one — never select the algorithm from the header.
        $header = json_decode(self::b64UrlSafeDecode($parts[0]));
        if (! $header instanceof \stdClass  || ($header->alg ?? null) !== $alg) {
            return false;
        }

        if (! self::verify($parts[0] . '.' . $parts[1], $parts[2], $key, $alg)) {
            return false;
        }

        $payload = json_decode(self::b64UrlSafeDecode($parts[1]));
        if (! $payload instanceof \stdClass) {
            return false;
        }
        if (! $noexpire && ($payload->exp ?? 0) < time()) {
            return false;
        }

        if ($alg === self::ALG_RS256) {
            if ($aud !== null && ! self::audienceMatches($payload->aud ?? null, $aud)) {
                return false;
            }
            if ($iss !== null && ($payload->iss ?? null) !== $iss) {
                return false;
            }
            if (isset($payload->nbf) && $payload->nbf > time()) {
                return false;
            }
        }

        return $payload;
    }

    /**
     * Builds a signed token. Extra claims in $payload are included, but `sub`
     * (set to $uid), `iat` and `exp` are always written by this method and
     * override any same-named claim passed in $payload. $aud/$iss, when given,
     * set the `aud`/`iss` claims — the issuing mirror of parse()'s checks, for
     * when you sign tokens others verify (an identity server); they override any
     * `aud`/`iss` in $payload. Any other claim (nbf, custom) still goes via $payload.
     *
     * $key is the shared secret for HS256, or your PEM private key for RS256.
     * @param mixed $uid the subject — becomes the `sub` claim
     * @param string $key HMAC secret (HS256) or PEM private key (RS256)
     * @param int $ttl lifetime in seconds; `exp` is set to now + $ttl
     * @param array|object|null $payload additional claims to embed
     * @param string $alg signing algorithm — self::ALG_HS256 or self::ALG_RS256
     * @param ?string $aud audience — sets the `aud` claim when given
     * @param ?string $iss issuer — sets the `iss` claim when given
     * @return string the encoded `header.payload.signature` token
     * @throws \InvalidArgumentException on an HS256 secret weaker than 128 bits,
     *   an unusable RS256 key, or an unsupported $alg
     * @throws \RuntimeException if RS256 is used without the openssl extension
     */
    public static function create($uid, #[\SensitiveParameter] $key, $ttl = 600, $payload = null, $alg = self::ALG_HS256, ?string $aud = null, ?string $iss = null) {
        if ($alg === self::ALG_HS256) {
            self::assertSecretStrength($key);
        }

        $header = self::b64UrlSafeEncode(json_encode(['typ' => 'JWT', 'alg' => $alg]));
        $time   = time();
        if ($payload) {
            $payload = (array) $payload;
        } else {
            $payload = [];
        }
        $payload['sub'] = $uid;
        $payload['iat'] = $time;
        $payload['exp'] = $time + $ttl;
        if ($aud !== null) {
            $payload['aud'] = $aud;
        }
        if ($iss !== null) {
            $payload['iss'] = $iss;
        }
        $payloadString  = self::b64UrlSafeEncode(json_encode($payload));
        return $header . '.' . $payloadString . '.' . self::sign($header . '.' . $payloadString, $key, $alg);
    }

    /**
     * Signs $data with $alg and returns the b64url signature.
     * @throws \InvalidArgumentException on an unusable RS256 key or unsupported $alg
     * @throws \RuntimeException if RS256 is used without the openssl extension
     */
    private static function sign(string $data, #[\SensitiveParameter] string $key, string $alg): string {
        switch ($alg) {
            case self::ALG_HS256:
                return self::b64UrlSafeEncode(hash_hmac('sha256', $data, $key, true));
            case self::ALG_RS256:
                if (! function_exists('openssl_sign')) {
                    throw new \RuntimeException('RS256 requires the openssl PHP extension.');
                }
                $privateKey = openssl_pkey_get_private($key);
                if ($privateKey === false) {
                    throw new \InvalidArgumentException('RS256 requires a valid PEM private key');
                }
                openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
                return self::b64UrlSafeEncode($signature);
            default:
                throw new \InvalidArgumentException('Unsupported JWT algorithm: ' . $alg);
        }
    }

    /**
     * Verifies the b64url $signature over $data with $alg. HS256 recomputes and
     * compares in constant time; RS256 checks it against the public key.
     * @throws \InvalidArgumentException on an unusable RS256 key or unsupported $alg
     * @throws \RuntimeException if RS256 is used without the openssl extension
     */
    private static function verify(string $data, string $signature, #[\SensitiveParameter] string $key, string $alg): bool {
        switch ($alg) {
            case self::ALG_HS256:
                return hash_equals(self::b64UrlSafeEncode(hash_hmac('sha256', $data, $key, true)), $signature);
            case self::ALG_RS256:
                if (! function_exists('openssl_verify')) {
                    throw new \RuntimeException('RS256 requires the openssl PHP extension.');
                }
                $publicKey = openssl_pkey_get_public($key);
                if ($publicKey === false) {
                    throw new \InvalidArgumentException('RS256 requires a valid PEM public key');
                }
                return openssl_verify($data, self::b64UrlSafeDecode($signature), $publicKey, OPENSSL_ALGO_SHA256) === 1;
            default:
                throw new \InvalidArgumentException('Unsupported JWT algorithm: ' . $alg);
        }
    }

    /**
     * Rejects an HMAC secret weaker than 128 bits. A misconfiguration (not a
     * client error), so it throws rather than returning false — a weak secret
     * makes every token trivially forgeable, so it must fail loudly.
     * @throws \InvalidArgumentException
     */
    private static function assertSecretStrength(#[\SensitiveParameter] string $secret): void {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException(
                'JWT secret must be at least ' . self::MIN_SECRET_BYTES . ' bytes (128 bits); '
                . strlen($secret) . ' given.'
            );
        }
    }

    /** True if $expected is (or is one of) the token's `aud`, which per RFC 7519 may be a string or a list. */
    private static function audienceMatches($aud, string $expected): bool {
        return is_array($aud) ? in_array($expected, $aud, true) : $aud === $expected;
    }

    private static function b64UrlSafeEncode(string $string): string {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($string));
    }

    private static function b64UrlSafeDecode(string $string): string {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $string));
    }
}
