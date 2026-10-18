<?php
namespace EzMvc;

/**
 * A single email attachment. $content is the raw file content (not
 * base64-encoded) — each backend encodes it however it needs to.
 */
class MailAttachment {
    public function __construct(
        public string $content,
        public string $filename,
        public string $mimeType = 'application/octet-stream'
    ) {}
}
