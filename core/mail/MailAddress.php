<?php
namespace EzMvc;

/**
 * A single "Name <email>" contact. Mail/MailSender use this internally and
 * in MailSender's own signature; Mail's public API still accepts a bare
 * string too (see MailAddress::from()), so existing callers passing a plain
 * email address keep working unchanged.
 *
 * Lists of addresses are just plain arrays of MailAddress — no separate
 * "list" type. listFrom() normalizes a single string/MailAddress or an array
 * of either into a plain MailAddress[]; joinAll() turns such an array into
 * the RFC 5322 comma-joined string a header needs. Both are shared here so
 * every backend that needs one doesn't reimplement it separately.
 */
class MailAddress implements \Stringable {
    public function __construct(
        public string $email,
        public ?string $name = null
    ) {}

    /** Wraps a bare email string; passes a MailAddress through. */
    public static function from(string|self $value): self {
        return $value instanceof self ? $value : new self($value);
    }

    /**
     * @param string|self|array<string|self> $value
     * @return self[]
     */
    public static function listFrom(string|self|array $value): array {
        if ($value === '' || $value === []) {
            return [];
        }

        $list = is_array($value) ? $value : [$value];
        return array_map(fn($v) => self::from($v), $list);
    }

    /**
     * RFC 5322-style comma-joined list, e.g. '"Jane Doe" <jane@x.com>, "Bob Smith" <bob@y.com>'.
     * @param self[] $addresses
     */
    public static function joinAll(array $addresses): string {
        return implode(', ', array_map(fn(self $a) => (string) $a, $addresses));
    }

    /** `"Name" <email>`, or the bare email when there is no name. */
    public function __toString(): string {
        if ($this->name === null || $this->name === '') {
            return $this->email;
        }

        return '"' . str_replace(['"', '\\'], '', $this->name) . '" <' . $this->email . '>';
    }
}
