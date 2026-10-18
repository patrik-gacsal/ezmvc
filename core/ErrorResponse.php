<?php
namespace EzMvc;

/** The uniform JSON body of every API error response. */
class ErrorResponse {
    public string $requestId, $issuedAt, $method, $path;
    public ?string $message, $exception, $trace, $file;
    public ?int $code, $line;

    /**
     * @param \Throwable|string|null $message a Throwable contributes its message
     *   and code, plus class/trace/file/line in debug mode only
     */
    public function __construct($message = null) {
        $this->requestId = Core::requestId();   // the id on this request's log lines, so a reported error can be traced
        $this->method   = $_SERVER['REQUEST_METHOD'] ?? '';
        $this->path     = $_SERVER['REQUEST_URI'] ?? '';
        $this->issuedAt = date('c');
        if ($message instanceof \Throwable) {
            $this->code    = $message->getCode();
            $this->message = $message->getMessage();
            if (Core::isDebug()) {
                $this->exception = get_class($message);
                $this->trace     = $message->getTraceAsString();
                $this->file      = $message->getFile();
                $this->line      = $message->getLine();
            }
        } else {
            $this->message = $message;
        }
    }
}
