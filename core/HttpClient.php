<?php
namespace EzMvc;

/**
 * A small cURL wrapper for outgoing HTTP requests. Each instance carries its own
 * headers, JSON mode and timeout; a few policies — throw-on-error, peer
 * verification, and the onComplete observer — are static and shared by every
 * instance.
 */
class HttpClient {
    private static $throwException   = false;
    private static mixed $onComplete = null;
    private static $verifyPeer       = 1;

    private $headers = [];

    private ?string $lastHeader   = null;
    private ?string $lastResponse = null;
    private ?int $lastCode        = null;
    private mixed $lastInfo       = null;
    private ?string $lastError    = null;
    private bool $json            = false;
    private int $timeout          = 0;
    /**
     * Appends a raw request-header line, e.g. 'Accept: application/json'.
     * @param string $header a full "Name: value" header line
     */
    public function addHeader($header) {
        $this->headers[] = $header;
    }
    /**
     * Adds or replaces a request header by name. If a header with the same name
     * already exists (compared case-insensitively, as HTTP requires) its value is
     * replaced; otherwise the header is appended.
     * @param string $key   header name, e.g. 'Content-Type'
     * @param string $value header value
     */
    public function setHeader($key, $value) {
        $prefix = $key . ':';
        $len    = strlen($prefix);
        foreach ($this->headers as &$h) {
            // header names are case-insensitive, so replace regardless of casing
            if (strncasecmp($h, $prefix, $len) === 0) {
                $h = $key . ': ' . $value;
                return;
            }
        }
        $this->headers[] = $key . ': ' . $value;
    }
    /**
     * Toggles JSON mode. When on, the request body is json_encoded, the response
     * is returned json_decoded, and a 'Content-Type: application/json' header is added.
     * @param bool $json
     */
    public function setJson($json = true) {
        if ($json) {
            $this->addHeader('Content-Type: application/json');
            $this->json = true;
        } else {
            $this->json = false;
        }
    }
    /**
     * Sets the maximum total duration of a request, in seconds. 0 (the default)
     * means no limit — cURL's own default — so existing behavior is unchanged
     * until you set one. Guards against a slow or hung endpoint blocking the request.
     * @param int $seconds
     */
    public function setTimeout(int $seconds) {
        $this->timeout = $seconds;
    }
    /**
     * Returns the last response body, optionally prefixed with the raw response
     * headers. Null before any request has been made.
     * @param bool $withHeader prepend the header block to the body
     * @return ?string
     */
    public function getLastResponse($withHeader = false) {
        return $withHeader
            ? $this->lastHeader . $this->lastResponse
            : $this->lastResponse;
    }
    /**
     * Returns cURL's getinfo() array for the last request (status, timings, sizes,
     * …), or null before any request has been made.
     * @return ?array
     */
    public function getLastInfo() {
        return $this->lastInfo;
    }
    /**
     * Returns the HTTP status code of the last response — 0 if the request failed
     * before any response (see getLastError()), or null before any request.
     * @return ?int
     */
    public function getLastCode() {
        return $this->lastCode;
    }
    /**
     * The cURL transport error from the last request, or null if it reached the
     * server. Set on failures that produce no HTTP response — DNS failure,
     * connection refused, TLS error, timeout — where getLastCode() is 0.
     * @return ?string
     */
    public function getLastError(): ?string {
        return $this->lastError;
    }
    /**
     * Sets whether a non-2xx response throws an \Exception (carrying the status as
     * its code). Off by default. Static — applies to every instance.
     * @param bool $throw
     */
    public static function setThrowException($throw = true) {
        self::$throwException = $throw;
    }
    /**
     * Sets a global hook run after every request completes — the *same* one runs
     * for every HttpClient instance, so it's a single place to observe all
     * outgoing HTTP calls (log, meter, trace, alert) without adding code at each
     * call site. It's a read-only observer: it can't alter the request or response.
     * A callable (function name, Closure, or [object, method]); anything it throws
     * is caught and logged (Core::log), so it can't break the request.
     * @param callable(string $method, string $url, mixed $body, int $code, string $response): void $onComplete
     */
    public static function setOnComplete($onComplete) {
        self::$onComplete = $onComplete;
    }
    /**
     * Sets whether the peer's SSL/TLS certificate is verified. Off by default.
     * Static — applies to every instance.
     * @param bool $verify
     */
    public static function setVerifyPeer($verify = true) {
        self::$verifyPeer = $verify ? 1 : 0;
    }
    /**
     * Sends a POST request and returns the response (the raw body, or the decoded
     * value in JSON mode).
     * @param string $url
     * @param mixed $body request body; JSON-encoded when JSON mode is on
     * @return mixed
     */
    public function post($url, $body = null) {
        return $this->custom('POST', $url, $body);
    }
    /**
     * Sends a PUT request and returns the response (the raw body, or the decoded
     * value in JSON mode).
     * @param string $url
     * @param mixed $body request body; JSON-encoded when JSON mode is on
     * @return mixed
     */
    public function put($url, $body = null) {
        return $this->custom('PUT', $url, $body);
    }
    /**
     * Sends a GET request and returns the response (the raw body, or the decoded
     * value in JSON mode).
     * @param string $url
     * @param mixed $body request body; JSON-encoded when JSON mode is on
     * @return mixed
     */
    public function get($url, $body = null) {
        return $this->custom('GET', $url, $body);
    }
    /**
     * Sends a DELETE request and returns the response (the raw body, or the
     * decoded value in JSON mode).
     * @param string $url
     * @param mixed $body request body; JSON-encoded when JSON mode is on
     * @return mixed
     */
    public function delete($url, $body = null) {
        return $this->custom('DELETE', $url, $body);
    }
    /**
     * Sends a PATCH request and returns the response (the raw body, or the decoded
     * value in JSON mode).
     * @param string $url
     * @param mixed $body request body; JSON-encoded when JSON mode is on
     * @return mixed
     */
    public function patch($url, $body = null) {
        return $this->custom('PATCH', $url, $body);
    }
    /**
     * Sends a request with the given HTTP method and returns the response (the raw
     * body, or the decoded value in JSON mode). Records the status, headers, info
     * and any transport error for the getLast*() accessors, then runs the
     * onComplete hook.
     * @param string $method HTTP method (GET, POST, PUT, …)
     * @param string $url
     * @param mixed $body request body; JSON-encoded when JSON mode is on
     * @param bool $throw throw on a non-2xx response even if the global setting is off
     * @return mixed
     * @throws \Exception on a non-2xx response when throwing is enabled
     */
    public function custom($method, $url, $body = null, $throw = false) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->json ? json_encode($body) : $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, self::$verifyPeer);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        $response           = curl_exec($ch);
        $headerSize         = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->lastCode     = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->lastInfo     = curl_getinfo($ch);
        $this->lastError    = curl_error($ch) ?: null;
        $this->lastHeader   = substr($response, 0, $headerSize);
        $this->lastResponse = substr($response, $headerSize);
        // no curl_close(): since PHP 8 $ch is a CurlHandle object, freed by GC
        // when it goes out of scope at the end of this method
        if (self::$onComplete) {
            try {
                call_user_func(self::$onComplete, $method, $url, $body, $this->lastCode, $this->lastResponse);
            } catch (\Throwable $e) {
                Core::log('onComplete hook failed: ' . $e->getMessage() . ' (' . $method . ' ' . $url . ')', LogLevel::ERROR, 'HttpClient');
            }
        }
        if ((self::$throwException || $throw) && ($this->lastCode < 200 || $this->lastCode > 299)) {
            throw new \Exception('HttpClient exception', $this->lastCode);
        }
        return $this->json
            ? json_decode($this->lastResponse)
            : $this->lastResponse;
    }

    /**
     * Returns a single response-header value by name (case-insensitive), or the
     * whole raw header block when $name is null. Null if the header isn't present.
     * @param ?string $name header name, or null for the entire header block
     * @return ?string
     */
    public function getLastHeader($name = null) {
        if ($name === null) {
            return $this->lastHeader;
        }
        $prefix = $name . ':';
        $len    = strlen($prefix);
        foreach (explode("\n", $this->lastHeader ?? '') as $line) {
            // header names are case-insensitive
            if (strncasecmp($line, $prefix, $len) === 0) {
                return trim(substr($line, $len));
            }
        }
        return null;
    }

}
