<?php
namespace EzMvc;

/**
 * A generic HTTP response: a status code, a body, and optional headers. Not
 * tied to a controller — build one with the factories (ok/created/accepted/
 * noContent) and return it; the caller (the dispatcher) calls send() once.
 * Nothing here dies until send(), so an action that returns one stays a pure,
 * testable function.
 *
 * In the API flow it's the success counterpart to a thrown ApiException: an
 * ApiController action returns a Response (or a bare object/DTO, which the
 * dispatcher wraps as Response::ok()), and send() serializes the body as JSON.
 *
 *   return Response::ok($user->toDto());
 *   return Response::created($dto, "/users/{$id}");
 *   return Response::noContent();
 */
class Response {
    /**
     * Status codes http_response_code() recognises. Anything outside this set
     * (a non-standard/custom code) it silently won't emit, so send() falls back
     * to a hand-written status line via header().
     */
    private const HTTP_CODES = [
        200, 201, 202, 203, 204, 205, 206, 207, 208, 226,
        300, 301, 302, 303, 304, 305, 307, 308,
        400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413, 414, 415, 416, 417, 422, 423, 424, 426, 428, 429, 431,
        500, 501, 502, 503, 504, 505, 506, 507, 508, 510, 511,
    ];

    public function __construct(
        public int $status = 200,
        public mixed $body = null,
        public array $headers = []
    ) {}

    /** 200 with a JSON body. */
    public static function ok($body = null): self {
        return new self(200, $body);
    }

    /** 201, optionally with a Location header pointing at the new resource. */
    public static function created($body = null, ?string $location = null): self {
        return new self(201, $body, $location !== null ? ['Location' => $location] : []);
    }

    /** 202 Accepted (the request was taken but not yet acted on). */
    public static function accepted($body = null): self {
        return new self(202, $body);
    }

    /** 204 No Content — no body. */
    public static function noContent(): self {
        return new self(204);
    }

    /** Emits the status, headers and JSON body, then halts. The dispatcher's job — not an action's. */
    public function send(): void {
        if (in_array($this->status, self::HTTP_CODES, true)) {
            http_response_code($this->status);
        } else {
            // custom code http_response_code() won't emit — write the status line by hand
            header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $this->status . ' Custom Status');
        }
        header('Content-Type: application/json');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->body !== null) {
            $json = json_encode($this->body);
            // the status goes to the RES line Core logs on shutdown; the body only at debug level
            if (Core::isLogged(LogLevel::DEBUG)) {
                Core::log(Core::redactSecrets($json), LogLevel::DEBUG, 'RES-BODY');
            }
            echo $json;
        }
        die();
    }
}
