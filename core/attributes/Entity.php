<?php
namespace EzMvc\Attr;

use Attribute;

/**
 * Sets an Entity's database table name via a class attribute, as an alternative
 * to calling setTableName() in init().
 * Usage: #[Table('users')] class User extends Entity { ... }
 * Without this attribute, the table name defaults to the class name.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Table {
    public function __construct(
        public string $name
    ) {}
}

/**
 * Maps an Entity property to a differently-named database column.
 * Usage: #[Column('numeric_value')] public ?int $numericValue = null;
 * Without this attribute, the property name is assumed to match the column name.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column {
    public function __construct(
        public string $name
    ) {}
}

/**
 * Marks the property that is the entity's primary key, as an alternative
 * to calling setIdField() in init(). Only one property may carry this
 * attribute — composite/multi-column keys are not supported.
 * Usage: #[Id] public ?int $id = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Id {
}

/**
 * Marks a property as excluded from INSERT/UPDATE statements, as an alternative
 * to listing it in setReadOnlyFields() in init(). (Named ReadOnlyField, not
 * ReadOnly, because `readonly` is a reserved word since PHP 8.1.)
 * Usage: #[ReadOnlyField] public string $createDate = '';
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ReadOnlyField {
}

/**
 * Declares a 1:1 relation loaded into a non-column property (an Entity or null).
 * Usage: #[HasOne(Profile::class, 'user_id')] public ?Profile $profile = null;
 *
 * $foreignKey is the column on the RELATED table pointing back to this entity;
 * $localKey is the column on THIS table it matches, defaulting to the id column.
 * Both are column names (as used in SQL), not property names. The imperative
 * equivalent is $this->join(...) in init().
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasOne {
    public function __construct(
        public string $entity,
        public string $foreignKey,
        public ?string $localKey = null
    ) {}
}

/**
 * Declares a 1:N relation loaded into a non-column property (an Entity[]).
 * Usage: #[HasMany(Order::class, 'user_id')] public array $orders = [];
 * Same keys as HasOne; the imperative equivalent is $this->join(..., many: true).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasMany {
    public function __construct(
        public string $entity,
        public string $foreignKey,
        public ?string $localKey = null
    ) {}
}
