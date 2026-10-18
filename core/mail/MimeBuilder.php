<?php
namespace EzMvc;

/**
 * Builds the MIME body and header values shared by Mail's built-in
 * mail()-based sending and Smtp's raw-socket sending, the two backends that
 * construct raw messages by hand.
 */
class MimeBuilder {
    /**
     * The HTML message as-is, or wrapped in multipart/mixed with the attachments.
     * @param MailAttachment[] $attachments
     * @return array{0: string, 1: string} [Content-Type header value, body]
     */
    public static function build(string $html, array $attachments): array {
        if (! $attachments) {
            return ['text/html; charset=UTF-8', $html];
        }

        $boundary = bin2hex(random_bytes(16));
        $body     = "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n";
        foreach ($attachments as $attachment) {
            // inside the quoted header parameter, " and \ would end or escape it
            $filename  = str_replace(["\r", "\n", "\0", '"', '\\'], '', $attachment->filename);
            // an upload's reported type is client-controlled: only a bare type/subtype gets through
            $mimeType  = preg_match('~^[\w.+-]+/[\w.+-]+$~', $attachment->mimeType) ? $attachment->mimeType : 'application/octet-stream';
            $body     .= "--{$boundary}\r\n";
            $body     .= 'Content-Type: ' . $mimeType . '; name="' . $filename . "\"\r\n";
            $body     .= "Content-Transfer-Encoding: base64\r\n";
            $body     .= 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n";
            $body     .= chunk_split(base64_encode($attachment->content));
        }
        $body .= "--{$boundary}--";

        return ['multipart/mixed; boundary="' . $boundary . '"', $body];
    }

    /** Strips CR/LF so a header value, subject or address can't inject further headers or SMTP commands. */
    public static function singleLine(string $value): string {
        return str_replace(["\r", "\n"], '', $value);
    }
}
