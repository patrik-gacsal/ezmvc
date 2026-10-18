<?php
namespace EzMvc;

/**
 * A form's fields, read from the request and validated on construction — the
 * same rules as an API request class (SchemaBinder), with form semantics:
 * values are trimmed, an empty field counts as absent, and every error is
 * collected so the form can show them all. Each public property is one field;
 * give it a default so a template can read it before the form is sent:
 *
 *   #[Required('Please enter your email')]
 *   #[Validators\Email]
 *   #[ErrorMessage('Not a valid email address')]
 *   public string $email = '';
 *
 *   public ?int $age = null;      // cast; "12x" is a format error
 *
 * A text field with no Validators\* is checked as a single line of at most
 * 255 characters without <>;'". Cross-field checks go in postCheck().
 */
abstract class Request {
    const METHOD_GET     = 'GET';
    const METHOD_POST    = 'POST';
    const METHOD_COOKIE  = 'COOKIE';
    const METHOD_SESSION = 'SESSION';
    const METHOD_GP      = 'GP';
    const METHOD_GPC     = 'GPC';
    const METHOD_GPCS    = '';

    private string $_method         = self::METHOD_POST;
    private array $_raw             = [];
    private bool $_sent             = false;
    private array $_errorList       = [];
    private array $_errorFields     = [];
    private bool $_autoErrorMessage = false;
    private bool $_csrfCheck        = true;

    /**
     * Reads every field from the configured source and, if any was sent,
     * validates them all, then runs postCheck() when every field passed.
     * A form sent by POST must carry the CSRF token (csrfField()) unless
     * init() calls setCsrfCheck(false); without it the form is invalid.
     */
    public function __construct() {
        $this->init();
        $sources = match ($this->_method) {
            self::METHOD_GET     => [$_GET],
            self::METHOD_POST    => [$_POST],
            self::METHOD_COOKIE  => [$_COOKIE],
            self::METHOD_SESSION => [$_SESSION ?? []],
            self::METHOD_GPC     => [$_GET, $_POST, $_COOKIE],
            self::METHOD_GPCS    => [$_GET, $_POST, $_COOKIE, $_SESSION ?? []],
            default              => [$_GET, $_POST],
        };
        $input = new \stdClass();
        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if ($prop->isStatic() || $name[0] === '_') {
                continue;
            }
            foreach ($sources as $source) {
                if (isset($source[$name])) {
                    $input->$name = $source[$name];
                    break;
                }
            }
        }
        $this->_raw  = get_object_vars($input);
        $this->_sent = (bool) $this->_raw;
        if (! $this->_sent) {
            return;
        }

        if ($this->_csrfCheck && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && ! hash_equals(self::csrfToken(), (string) ($_POST['_csrf'] ?? ''))) {
            $this->addError('_csrf', 'The form has expired — please submit it again.');
        }
        foreach (SchemaBinder::bind($this, $input, form: true) as $error) {
            $field = preg_replace('~[.\[].*~', '', $error['field']); // "tags[2]" is an error of the tags field
            $this->addError($field, $error['customMessage'] ?? ($this->_autoErrorMessage ? $error['message'] : null));
        }
        if ($this->isValid()) {
            $this->postCheck();
        }
    }

    /**
     * The hidden input carrying the session's CSRF token; put it in every form
     * sent by POST: <form method="post"><?= EzMvc\Request::csrfField() ?> …
     */
    public static function csrfField(): string {
        return '<input type="hidden" name="_csrf" value="' . self::csrfToken() . '">';
    }

    /**
     * One random token per session, created on first use. Kept across
     * Session::regenerate(), so logging in doesn't expire an open form.
     * @throws \LogicException without a started session: the token couldn't
     *   persist, so every POST would fail as expired
     */
    private static function csrfToken(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \LogicException('The CSRF token needs a started session (Session::init()).');
        }
        $token = Session::get('_csrf') ?? bin2hex(random_bytes(32));
        Session::set('_csrf', $token);
        return $token;
    }

    /**
     * Optional setup before the fields are read: setMethod(),
     * setAutoErrorMessage(), setCsrfCheck().
     */
    protected function init() {
    }

    /**
     * Turns the CSRF token check off for this form — only for one that receives
     * cross-site POSTs by design, e.g. a payment provider's callback.
     */
    protected function setCsrfCheck(bool $check = true) {
        $this->_csrfCheck = $check;
    }

    /** Optional cross-field checks, run after every field passed; report failures with addError(). */
    protected function postCheck() {
    }

    /** Marks $field invalid, optionally with a message for getErrorMessages(). */
    public function addError(string $field, ?string $message = null) {
        if (! in_array($field, $this->_errorFields)) {
            $this->_errorFields[] = $field;
        }

        if ($message) {
            $this->_errorList[$field][] = $message;
        }
    }

    /** Generates a generic message for a failed field that declares none. */
    protected function setAutoErrorMessage($auto = true) {
        $this->_autoErrorMessage = $auto;
    }

    /** Where fields are read from: a METHOD_* constant (default METHOD_POST). */
    protected function setMethod(string $method) {
        $this->_method = $method;
    }

    /**
     * HTML-escaped field value for a template — the value as submitted when the
     * field was sent (so an invalid entry is redisplayed for correction), else
     * the property. Fields stay raw on the object itself; escaping happens here,
     * at the point of output, same as templates/components/text.php.
     */
    public function esc(string $key): string {
        $value = array_key_exists($key, $this->_raw) ? $this->_raw[$key] : ($this->$key ?? '');
        return is_scalar($value) ? htmlspecialchars(trim((string) $value), ENT_QUOTES, 'UTF-8') : '';
    }

    /** Messages for $field, or all of them; an array, or one string joined with $glue. */
    public function getErrorMessages(?string $field = null, ?string $glue = null) {
        $array = [];
        if ($field === null) {
            foreach ($this->_errorList as $list) {
                foreach ($list as $message) {
                    $array[] = $message;
                }
            }

        } else {
            $array = $this->_errorList[$field] ?? [];
        }
        return $glue === null
            ? $array
            : implode($glue, $array);
    }

    /** Whether $field — or, with null, every field — passed validation. */
    public function isValid(?string $field = null) {
        if ($field === null) {
            return count($this->_errorFields) === 0;
        }

        return ! in_array($field, $this->_errorFields);
    }

    /** Whether any field was present in the request, i.e. the form was submitted. */
    public function isSent() {
        return $this->_sent;
    }

}
