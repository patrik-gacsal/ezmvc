<?php
namespace EzMvc;

/**
 * A deliberate, expected error that maps to an HTTP status. Throw it (or a
 * subclass with the status baked in) anywhere below the controller — services,
 * entities, wherever — with no knowledge of the transport beyond the status
 * itself. index.php's ApiController boundary catches it, returns $status, and
 * renders the message into an ErrorResponse.
 *
 * Its message IS shown to the client (that's the point — "User not found",
 * "Email already taken"), unlike a plain exception, which is treated as
 * unexpected and becomes a generic 500 in production.
 *
 * Subclass it in the project's exceptions/ dir with the status fixed, e.g.
 *   class NotFoundException extends ApiException {
 *       public function __construct(string $message = 'Not found') { parent::__construct(404, $message); }
 *   }
 * or throw it directly: throw new ApiException(422, 'Invalid coupon');
 */
class ApiException extends \Exception {
    public int $status;

    public function __construct(int $status = 400, string $message = '', int $code = 0, ?\Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->status = $status;
    }

    // Factories for the common client errors — `throw ApiException::notFound('…')`.
    // The message defaults to the status's reason phrase. (No 500 factory on
    // purpose: a genuine server error should be a plain exception that bubbles
    // to the boundary and becomes a generic 500, not a deliberately-thrown one.)
    public static function badRequest(string $message = 'Bad request'): self          { return new self(400, $message); }
    public static function unauthorized(string $message = 'Unauthorized'): self        { return new self(401, $message); }
    public static function forbidden(string $message = 'Forbidden'): self              { return new self(403, $message); }
    public static function notFound(string $message = 'Not found'): self               { return new self(404, $message); }
    public static function methodNotAllowed(string $message = 'Method not allowed'): self { return new self(405, $message); }
    public static function conflict(string $message = 'Conflict'): self                { return new self(409, $message); }
    public static function unprocessable(string $message = 'Unprocessable entity'): self { return new self(422, $message); }
}
