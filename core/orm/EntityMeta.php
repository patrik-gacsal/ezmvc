<?php
namespace EzMvc;

use EzMvc\Attr\Column;
use EzMvc\Attr\HasMany;
use EzMvc\Attr\HasOne;
use EzMvc\Attr\Id;
use EzMvc\Attr\ReadOnlyField;
use EzMvc\Attr\Table;

/**
 * Per-class mapping metadata for an Entity subclass: table name, the
 * property→column map, the primary key, read-only fields, and the prebuilt
 * CRUD SQL. One instance is built per Entity class and cached/shared across
 * every instance of that class (Entity keeps the cache) — it holds no per-row
 * data, only the class-level schema mapping.
 *
 * The constructor fills the mapping fields (class, table, fields, idField,
 * readOnlyFields) by reflecting on the #[Table]/#[Column]/#[Id]/#[ReadOnlyField]
 * attributes. The query strings and the remaining flags are populated by
 * Entity::setup() and the entity's own setXxx() config calls, not here.
 */
class EntityMeta {
    /** Property name of the primary key (the #[Id] property, or the first field). */
    public string $idField;
    /** Database table name (#[Table('...')], or the class name). */
    public string $table;
    /** Fully-qualified Entity subclass name this metadata describes. */
    public string $class;
    /**
     * Map of property name => database column name.
     * Defaults to the property name itself unless overridden by #[Column('...')].
     */
    public $fields = [];
    /** Property names excluded from INSERT/UPDATE (#[ReadOnlyField] + the auto-increment id). */
    public $readOnlyFields = [];
    /** The property => column pairs store() writes, and the subset update() writes (no id). Built by Entity::setup(). */
    public array $insertFields = [];
    public array $updateFields = [];
    /** When set, store()/update()/delete() are no-ops (also forced on by numericBinding). */
    public bool $readOnly = false;
    /** Prebuilt CRUD SQL, generated once by Entity::setup() — not by this constructor. */
    public ?string $getQuery = null;
    public ?string $insertQuery = null;
    public ?string $updateQuery = null;
    public ?string $deleteQuery = null;
    public ?string $findQuery = null;
    /** Optional per-class connection override; falls back to the default bound connection. */
    public ?\mysqli $db = null;
    /** Primary key is auto-increment: excluded from INSERT, set from insert_id after store(). */
    public bool $autoIncrementId = true;
    /** Primary key binds as int ('i') rather than string ('s'). */
    public bool $numericId = true;
    /**
     * Hydrate rows by column index instead of column name — for queries whose
     * column names aren't valid property identifiers. Forces readOnly on.
     */
    public $numericBinding = 0;
    /**
     * Map of property name => RelationMeta for #[HasOne]/#[HasMany] properties
     * (and $this->join() calls). These properties are kept OUT of $fields — a
     * relation is loaded separately by Entity, never selected/inserted/updated.
     */
    public array $relations = [];

    /**
     * Reflects on $class to populate the table name and the property→column map
     * from its #[Table]/#[Column] attributes, and marks the #[Id] / #[ReadOnlyField]
     * properties. The id defaults to the first declared property when no #[Id] is present.
     * @param string $class Entity subclass name
     * @throws \Exception if more than one property carries #[Id] (composite keys unsupported)
     */
    public function __construct($class) {
        $this->class = $class;
        $this->table = $class;
        $ref         = new \ReflectionClass($class);
        foreach ($ref->getAttributes(Table::class) as $attr) {
            $this->table = $attr->newInstance()->name;
        }
        $idFromAttr = null;
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $key = $prop->getName();
            if ($key[0] === '_') {
                continue;
            }
            // #[HasOne]/#[HasMany] properties are relations, not columns — record
            // them and skip $fields entirely.
            $relAttr = $prop->getAttributes(HasMany::class) ?: $prop->getAttributes(HasOne::class);
            if ($relAttr) {
                $a                     = $relAttr[0]->newInstance();
                $this->relations[$key] = new RelationMeta($a->entity, $a->foreignKey, $relAttr[0]->getName() === HasMany::class, $a->localKey);
                continue;
            }
            $column = $key;
            foreach ($prop->getAttributes(Column::class) as $attr) {
                $column = $attr->newInstance()->name;
            }
            $this->fields[$key] = $column;

            if ($prop->getAttributes(Id::class)) {
                if ($idFromAttr !== null) {
                    throw new \Exception("Entity {$class} has #[Id] on more than one property ({$idFromAttr}, {$key}) — composite keys are not supported.");
                }
                $idFromAttr = $key;
            }
            if ($prop->getAttributes(ReadOnlyField::class)) {
                $this->readOnlyFields[] = $key;
            }
        }
        $this->idField = $idFromAttr ?? array_key_first($this->fields);
    }
}

/**
 * One entity relation: the related class, the join columns, and 1:N vs 1:1.
 * $foreignKey/$localKey are column names (used in the loader's IN query);
 * $localKey defaults to this entity's id column. Built from #[HasOne]/#[HasMany]
 * or Entity::join(), and loaded by Entity::loadRelations().
 */
class RelationMeta {
    public function __construct(
        public string $entity,
        public string $foreignKey,
        public bool $many,
        public ?string $localKey = null
    ) {}
}
