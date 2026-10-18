<?php
namespace EzMvc;

use EzMvc\Attr\ErrorMessage;
use EzMvc\Attr\Required;
use Validators\Validator;

/**
 * Binds decoded input into the public properties of a schema object — an API
 * request class or a form request — and validates it, returning the errors.
 * One rule set for both: the declared type (scalar cast, nested schema class,
 * `@var T[]` array), #[Required], and any Validators\* attribute.
 *
 * API mode follows JSON: stop at the first error. Form mode follows HTML forms:
 * strings are trimmed, an empty string counts as absent, a text field with no
 * Validators\* is checked as a single line of at most 255 characters without
 * `<>;'"`, and every error is collected so the form can show them all.
 */
final class SchemaBinder {

    /**
     * @param callable|null $untyped handles an untyped property in API mode
     *   (the legacy regex-default schemas): fn(\ReflectionProperty, object $schema, object $input)
     * @return array<int, array{field: string, message: string, customMessage: ?string}>
     *   field is the path ("lines[2].qty"), message the generic text, customMessage
     *   the property's #[Required]/#[ErrorMessage] text when it declares one
     */
    public static function bind(object $schema, object $input, bool $form = false, ?callable $untyped = null, string $path = ''): array {
        $errors = [];
        foreach ((new \ReflectionClass($schema))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if ($prop->isStatic() || ($form && $name[0] === '_')) {
                continue;
            }
            $propType = $prop->getType();
            if (! $propType && ! $form) {
                $untyped($prop, $schema, $input);
                continue;
            }

            $value = property_exists($input, $name) ? $input->$name : null;
            if ($form && is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            if ($value === null) {
                $required = $prop->getAttributes(Required::class)
                    || ($propType && ! $propType->allowsNull() && ! $prop->hasDefaultValue());
                if ($required) {
                    $errors[] = self::error('Missing required field: ', $path . $name, $prop, Required::class);
                    if (! $form) {
                        return $errors;
                    }
                } elseif (! $prop->hasDefaultValue()) {
                    $schema->$name = null;
                }
                continue;
            }

            $type = $propType instanceof \ReflectionNamedType ? $propType->getName() : null;
            if ($type === 'array') {
                $type = Core::arrayVarType($prop) ?? self::untypedArray($prop);
            }
            try {
                $schema->$name = self::coerce($type, $value, $path . $name, $prop, $form, $untyped);
            } catch (SchemaBindingFailed $failure) {
                array_push($errors, ...$failure->errors);
                if (! $form) {
                    return $errors;
                }
            }
        }
        return $errors;
    }

    /**
     * Checks one value against $type and returns what to store: a scalar type
     * casts (and runs the property's Validators\*), a class binds a nested object
     * into that class, `T[]` checks every element as T, and 'array' takes any
     * array. Other types (mixed, a union: null) pass the value through.
     * $field names the value in errors: "lines[2].qty".
     * @throws SchemaBindingFailed
     */
    private static function coerce(?string $type, mixed $value, string $field, \ReflectionProperty $prop, bool $form, ?callable $untyped): mixed {
        if ($type !== null && str_ends_with($type, '[]')) {
            if (! is_array($value)) {
                throw self::failure('Invalid value for field: ', $field, $prop);
            }
            $errors = [];
            foreach ($value as $index => $element) {
                try {
                    $value[$index] = self::coerce(substr($type, 0, -2), $element, $field . '[' . $index . ']', $prop, $form, $untyped);
                } catch (SchemaBindingFailed $failure) {
                    array_push($errors, ...$failure->errors);
                    if (! $form) {
                        break;
                    }
                }
            }
            if ($errors) {
                throw new SchemaBindingFailed($errors);
            }
            return $value;
        }

        $type = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float'][$type] ?? $type;
        if ($type === 'array') {
            if (! is_array($value)) {
                throw self::failure('Invalid value for field: ', $field, $prop);
            }
            return $value;
        }
        if ($type !== null && ! in_array($type, ['int', 'float', 'bool', 'string', 'mixed', 'object', 'iterable'], true)) {
            if (! class_exists($type)) {
                throw new \LogicException("Cannot resolve {$type}, the type of {$prop->class}::\${$prop->name} — only root-namespace classes are resolved.");
            }
            if (! is_object($value)) {
                throw self::failure('Invalid value for field: ', $field, $prop);
            }
            $nested = new $type();
            $errors = self::bind($nested, $value, $form, $untyped, $field . '.');
            if ($errors) {
                throw new SchemaBindingFailed($errors);
            }
            return $nested;
        }

        if (in_array($type, ['int', 'float', 'bool', 'string'], true)) {
            if (! is_scalar($value)) {
                throw self::failure('Invalid value for field: ', $field, $prop);
            }
            if ($type === 'int' || $type === 'float') {
                if (! is_numeric($value)) {
                    throw self::failure('Invalid value for field: ', $field, $prop);
                }
                $value = $type === 'int' ? (int) $value : (float) $value;
            } elseif ($type === 'bool') {
                $value = self::asBoolean((string) $value);
            } else {
                $value = (string) $value;
            }
        } elseif ($form && ! is_scalar($value)) {
            // an untyped form field is a single value; a[]=… input for it is invalid
            throw self::failure('Invalid value for field: ', $field, $prop);
        }

        if (is_scalar($value)) {
            $validators = array_map(
                fn($attr) => $attr->newInstance(),
                array_filter($prop->getAttributes(), fn($attr) => is_subclass_of($attr->getName(), Validator::class))
            );
            if (! $validators && $form && is_string($value)) {
                $validators = [new \Validators\Line(maxLength: 255, optional: true)];
            }
            foreach ($validators as $validator) {
                if (! preg_match((string) $validator, (string) $value)) {
                    throw self::failure('Invalid format for field: ', $field, $prop);
                }
            }
        }
        return $value;
    }

    /**
     * 'array' — any array — for an array property without a `@var T[]`; but when
     * docblocks are stripped (opcache.save_comments=0) a missing @var can't be
     * told from an unreadable one, so that throws instead of silently skipping
     * the element check.
     */
    private static function untypedArray(\ReflectionProperty $prop): string {
        static $docblocksStripped = null;
        $docblocksStripped ??= (new \ReflectionMethod(self::class, 'bind'))->getDocComment() === false;
        if ($docblocksStripped) {
            throw new \LogicException("Docblocks are stripped (opcache.save_comments=0), so the @var element type of {$prop->class}::\${$prop->name} can't be read.");
        }
        return 'array';
    }

    /** "true"/"1" (or any nonzero numeric string) → true, everything else → false. */
    private static function asBoolean(string $value): bool {
        if (is_numeric($value)) {
            return $value ? true : false;
        }
        return strtolower($value) === 'true';
    }

    /** One error record; $customAttribute supplies the property's own message, when it declares one. */
    private static function error(string $message, string $field, \ReflectionProperty $prop, string $customAttribute = ErrorMessage::class): array {
        $custom = $prop->getAttributes($customAttribute)[0] ?? null;
        return ['field' => $field, 'message' => $message . $field, 'customMessage' => $custom?->newInstance()->message];
    }

    private static function failure(string $message, string $field, \ReflectionProperty $prop): SchemaBindingFailed {
        return new SchemaBindingFailed([self::error($message, $field, $prop)]);
    }
}

/** Carries binding errors up from a nested value to the property being bound. */
final class SchemaBindingFailed extends \Exception {
    public function __construct(public array $errors) {
        parent::__construct($errors[0]['message'] ?? '');
    }
}
