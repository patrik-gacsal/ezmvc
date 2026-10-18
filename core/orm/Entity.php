<?php
namespace EzMvc;

/**
 * Abstract base class for database entities (minimalist ORM).
 * Extend this class, declare public properties matching column names,
 * and implement init() to configure the table and ID field.
 *
 * Example:
 *
 *   class User extends Entity {
 *       public $id, $name, $email;
 *       protected function init() {
 *           $this->setTableName('users');
 *           $this->setIdField('id');
 *       }
 *   }
 *
 *   $user  = (new User())->get(1);
 *   $users = (new User())->find(SearchCriteria::where(Op::eq('active', 1)));
 */
abstract class Entity {
    private EntityMeta $_meta;
    /** Connection for this one instance; the most specific of the three tiers getDatabase() resolves. */
    private ?\mysqli $_db = null;
    private static ?\mysqli $_ddb = null;
    private static $_metas          = [];

    /**
     * Constructor.
     */
    public function __construct(?EntityMeta $meta = null) {
        if (! $meta) {
            $meta = self::getMeta();
        }

        if (! $meta) {
            $meta                       = new EntityMeta(get_called_class());
            self::$_metas[$meta->class] = $meta;
            $this->_meta                = $meta;
            $this->setup();
        } else {
            $this->_meta = $meta;
        }

    }

    /**
     * Internal setup called by the constructor on first instantiation.
     */
    private function setup() {

        $this->init();

        if ($this->_meta->numericBinding) {
            $this->_meta->readOnly = 1;
        }

        if ($this->_meta->autoIncrementId) {
            $this->_meta->readOnlyFields[] = $this->_meta->idField;
        }

        $this->_meta->insertFields = array_diff_key($this->_meta->fields, array_flip($this->_meta->readOnlyFields));
        $this->_meta->updateFields = array_diff_key($this->_meta->insertFields, [$this->_meta->idField => true]);

        $this->_meta->getQuery    = $this->createGetQuery();
        $this->_meta->insertQuery = $this->createInsertQuery();
        $this->_meta->deleteQuery = $this->createDeleteQuery();
        $this->_meta->updateQuery = $this->createUpdateQuery();
        $this->_meta->findQuery   = $this->createFindQuery();
    }

    /**
     * Configure the entity: call setTableName(), setIdField(), setReadOnlyFields(), etc.
     */
    abstract protected function init();
    /**
     * Set the table name. Defaults to the class name.
     * @param string $table
     */
    protected function setTableName($table) {
        $this->_meta->table = $table;
    }
    /**
     * Set the primary key field name. Defaults to the first declared property.
     * @param string $idField
     */
    protected function setIdField($idField) {
        $this->_meta->idField = $idField;
    }
    /**
     * Adds property names to exclude from INSERT and UPDATE, on top of the
     * #[ReadOnlyField] properties; the auto-increment id is excluded as well.
     * @param string[] $fields
     */
    protected function setReadOnlyFields($fields) {
        $fields = is_array($fields) ? $fields : func_get_args();
        $this->_meta->readOnlyFields = array_values(array_unique(array_merge($this->_meta->readOnlyFields, $fields)));
    }
    /**
     * Imperatively declare a relation loaded into $property — the init()-time
     * equivalent of #[HasOne]/#[HasMany]. $property is removed from the column
     * map (if present), so it's never selected/inserted/updated; it's populated
     * eagerly by get()/find() instead. See loadRelations().
     * @param string  $property   non-column property to populate
     * @param string  $entity     related Entity class
     * @param string  $foreignKey column on the related table pointing back here
     * @param bool    $many       true = 1:N (Entity[]), false = 1:1 (Entity|null)
     * @param ?string $localKey   column on this table (default: the id column)
     */
    protected function join(string $property, string $entity, string $foreignKey, bool $many = false, ?string $localKey = null): void {
        unset($this->_meta->fields[$property]);
        $this->_meta->relations[$property] = new RelationMeta($entity, $foreignKey, $many, $localKey);
    }
    /**
     * Mark the entity as read-only: store(), update(), delete() will be no-ops.
     * @param boolean $b
     */
    protected function setReadOnly($b = true) {
        $this->_meta->readOnly = $b;
    }
    /**
     * Set whether the primary key is auto-increment. Defaults to true.
     * @param boolean $b
     */
    protected function setAutoIncrementId($b = true) {
        $this->_meta->autoIncrementId = $b;
    }

    /**
     * Sets that the id is numeric or not
     * @param boolean $b
     */
    protected function setNumericId($b = true) {
        $this->_meta->numericId = $b;
        if (! $b) {
            $this->_meta->autoIncrementId = false;
        }

    }
    /**
     * Sets that the queried fields will associated
     * to object fields by column number or by field name.
     * If true, the entity will be read only.
     * It can be useful only when the queried column names cannot be valid identifiers.
     * Default: false;
     *  @param mixed $b
     */
    protected function setNumericBinding($b = false) {
        $this->_meta->numericBinding = $b;
    }
    /**
     * Generate the SELECT query for get(). Override in subclasses to customise.
     * @return string
     */
    protected function createGetQuery() {
        return 'SELECT ' . implode(',', $this->_meta->fields) . ' FROM ' . $this->_meta->table
            . ' WHERE ' . $this->_meta->fields[$this->_meta->idField] . '=?';
    }
    /**
     * Generate the INSERT query for store().
     * @return string
     */
    protected function createInsertQuery() {
        $columns = $this->_meta->insertFields;
        return 'INSERT INTO ' . $this->_meta->table . ' (' . implode(',', $columns) . ')'
            . ' VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
    }
    /**
     * Generate the DELETE query for delete(). Override in subclasses to customise.
     * @return string
     */
    protected function createDeleteQuery() {
        return 'DELETE FROM ' . $this->_meta->table . ' WHERE ' . $this->_meta->fields[$this->_meta->idField] . '=?';
    }
    /**
     * Generate the UPDATE query for update(). Override in subclasses to customise.
     * @return string
     */
    protected function createUpdateQuery() {
        $assignments = array_map(fn($column) => $column . '=?', $this->_meta->updateFields);
        return 'UPDATE ' . $this->_meta->table . ' SET ' . implode(',', $assignments)
            . ' WHERE ' . $this->_meta->fields[$this->_meta->idField] . '=?';
    }
    /**
     * Generate the base SELECT … FROM query for find(). Override to customise;
     * column names must match public properties. A SearchCriteria appends its
     * own WHERE, so an override used with one must not include WHERE. One that
     * does, with ? placeholders, is called with raw bind params instead:
     * ->find(null, ['i', 123]).
     * @return string
     */
    protected function createFindQuery() {
        return 'SELECT * FROM ' . $this->_meta->table;
    }
    /**
     * Get cached EntityMeta for the called class, if already initialised.
     * @return EntityMeta
     */
    private static function getMeta() {
        $class = get_called_class();
        if (isset(self::$_metas[$class])) {
            return self::$_metas[$class];
        }
        return null;
    }
    /**
     * Build a new instance and fill it from the given object or array.
     * Never pass request data: see fill().
     * @param mixed $obj
     * @return static
     */
    public static function build($obj): static {
        return (new static())->fill($obj);
    }
    /**
     * Fill properties from an associative array or object — every mapped field
     * present in it, the id and read-only fields included. Never pass request
     * data: build($_POST)->save() lets a client pick the id and overwrite any
     * row. Map a validated Dto with the generated fromDto(), which skips the
     * server-managed fields.
     * @param mixed $obj
     * @return static
     */
    public function fill($obj): static {
        $array = (array) $obj;
        foreach ($this->_meta->fields as $field => $column) {
            if (isset($array[$field])) {
                $this->$field = $array[$field];
            }
        }
        return $this;
    }

    /**
     * Set the default mysqli connection for all entities.
     * @param \mysqli $conn
     */
    public static function bindDefaultConnection($conn) {
        self::$_ddb = $conn;
    }
    /**
     * Set a connection for this entity CLASS, overriding the default. Despite
     * being an instance method it affects every instance of the class, present
     * and future: EntityMeta is cached one-per-class and shared. Use
     * bindInstanceConnection() to bind only the object you call it on.
     * @param \mysqli $conn
     */
    public function bindConnection($conn) {
        $this->_meta->db = $conn;
    }
    /**
     * Set a connection for this instance alone, overriding both the class
     * binding and the default. Entities hydrated by this instance's queries,
     * and relations loaded through it, inherit the same connection.
     * @param \mysqli|null $conn
     */
    public function bindInstanceConnection(?\mysqli $conn): static {
        $this->_db = $conn;
        return $this;
    }
    /**
     * Return the default connection.
     *  @return \mysqli
     * @throws \Exception
     */
    protected static function getDefaultDatabase() {
        if (! self::$_ddb) {
            throw new \Exception('No database bound.');
        }
        return self::$_ddb;
    }
    /**
     * Return the connection for this entity, most specific tier first:
     * this instance, then this class, then the global default.
     *  @return mixed
     */
    protected function getDatabase() {
        return $this->_db ?? $this->_meta->db ?? self::getDefaultDatabase();
    }
    /**
     * Fetch a single row by primary key, with its relations. Returns null if
     * not found.
     * @param int|string $id
     * @param int $depth relation levels to eager-load (default 1; 0 = none)
     * @param string[] $exclude related entity classes to skip loading (e.g. [Order::class])
     * @return ?static
     */
    public function get($id, int $depth = 1, array $exclude = []): ?static {
        $entity = $this->fetchById($id);
        if ($entity && $this->_meta->relations) {
            $this->loadRelations([$entity], $depth, self::excludeSet($exclude));
        }
        return $entity;
    }

    /** Bare single-row fetch by primary key — no relation loading (see get()). */
    private function fetchById($id) {
        return $this->fetchRows($this->_meta->getQuery, [$this->idBindType(), $id])[0] ?? null;
    }

    /**
     * Insert this entity into the database. Sets the ID on success if auto-increment.
     * @return int affected rows; 0 when the entity is read-only
     */
    public function store(): int {
        if ($this->_meta->readOnly) {
            return 0;
        }

        $stmt = $this->runStatement($this->_meta->insertQuery, $this->bindArgumentsFor($this->_meta->insertFields));
        if ($this->_meta->autoIncrementId) {
            $id        = $this->_meta->idField;
            $this->$id = $stmt->insert_id;
        }
        return self::closeWithAffectedRows($stmt);
    }
    /**
     * Update this entity's row in the database.
     * @return int affected rows; 0 when the entity is read-only
     */
    public function update(): int {
        if ($this->_meta->readOnly) {
            return 0;
        }

        $id         = $this->_meta->idField;
        $arguments  = $this->bindArgumentsFor($this->_meta->updateFields);
        $arguments[0] .= $this->idBindType();
        $arguments[]   = $this->$id;
        return self::closeWithAffectedRows($this->runStatement($this->_meta->updateQuery, $arguments));
    }
    /**
     * Upsert: update if ID exists, insert otherwise.
     * @return int affected rows; 0 when the entity is read-only
     */
    public function save(): int {
        if ($this->_meta->readOnly) {
            return 0;
        }
        $id     = $this->_meta->idField;
        $exists = $this->_meta->autoIncrementId ? $this->$id : $this->fetchById($this->$id);
        return $exists ? $this->update() : $this->store();
    }
    /**
     * Delete this entity from the database.
     * @return int affected rows; 0 when the entity is read-only
     */
    public function delete(): int {
        if ($this->_meta->readOnly) {
            return 0;
        }

        $id = $this->_meta->idField;
        return self::closeWithAffectedRows($this->runStatement($this->_meta->deleteQuery, [$this->idBindType(), $this->$id]));
    }
    /**
     * Find all entities matching the given criteria, with their relations.
     * @param SearchCriteria $criteria
     * @param mixed $params raw bind params when createFindQuery() is overridden with its own WHERE
     * @param int $depth relation levels to eager-load (default 1; 0 = none)
     * @param string[] $exclude related entity classes to skip loading (e.g. [Order::class])
     * @return static[] results (empty array when nothing matches, never null)
     */
    public function find(?SearchCriteria $criteria = null, $params = null, int $depth = 1, array $exclude = []): array {
        $entities = $this->fetch($criteria, $params);
        if ($entities && $this->_meta->relations) {
            $this->loadRelations($entities, $depth, self::excludeSet($exclude));
        }
        return $entities;
    }

    /** Bare multi-row fetch — no relation loading (see find()). */
    private function fetch(?SearchCriteria $criteria, $params): array {
        return $this->fetchRows($this->_meta->findQuery . $criteria?->getSQL(), $params ?: $criteria?->getParams());
    }

    /** Runs a SELECT and hydrates every row it returns. */
    private function fetchRows(string $sql, ?array $bindArguments): array {
        $stmt     = $this->runStatement($sql, $bindArguments);
        $result   = $stmt->get_result(); // throws if mysqlnd driver is not installed
        $type     = $this->_meta->numericBinding ? MYSQLI_NUM : MYSQLI_ASSOC;
        $entities = [];
        while (($row = $result->fetch_array($type)) !== null) {
            $entities[] = $this->hydrate($row);
        }
        $result->close();
        $stmt->close();
        return $entities;
    }

    /**
     * Prepares and executes $sql on this entity's connection.
     * @param ?array $bindArguments bind_param arguments: [types, ...values]
     */
    private function runStatement(string $sql, ?array $bindArguments): \mysqli_stmt {
        $stmt = $this->getDatabase()->prepare($sql);
        if ($bindArguments && count($bindArguments) > 1) {
            $stmt->bind_param(...$bindArguments);
        }
        $stmt->execute();
        return $stmt;
    }

    private static function closeWithAffectedRows(\mysqli_stmt $stmt): int {
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        return $affectedRows;
    }

    /** bind_param arguments for the current values of the given property => column pairs. */
    private function bindArgumentsFor(array $fields): array {
        return SearchCriteria::bindArguments(array_map(fn($field) => $this->$field, array_keys($fields)));
    }

    private function idBindType(): string {
        return $this->_meta->numericId ? 'i' : 's';
    }
    /**
     * Find the first entity matching the given criteria, or null if none.
     * Delegates to find() with the criteria's LIMIT forced to 1, so only one
     * row is transferred — set orderBy in the criteria to control which row.
     * (In the raw-$params path the SQL is yours, so no LIMIT is injected.)
     * @param SearchCriteria $criteria
     * @return ?static
     */
    public function findFirst(?SearchCriteria $criteria = null, $params = null, int $depth = 1, array $exclude = []): ?static {
        if (! $params) {
            $criteria = $criteria ? clone $criteria : new SearchCriteria();
            $criteria->limit(1);
        }
        $result = $this->find($criteria, $params, $depth, $exclude);
        return $result[0] ?? null;
    }

    /**
     * String representation: a JSON object of the mapped fields, keyed by
     * property name (so the attribute names are visible), invoked automatically
     * when the entity is cast to string or echoed. Relations aren't included —
     * only the entity's own columns.
     */
    public function __toString(): string {
        $data = [];
        foreach ($this->_meta->fields as $field => $column) {
            $data[$field] = $this->$field;
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The mapped field values as one RFC-4180 CSV row — every value quoted,
     * embedded quotes doubled. Values only, in field order (no header).
     */
    public function toCsvLine(): string {
        $cells = [];
        foreach ($this->_meta->fields as $field => $column) {
            $cells[] = '"' . str_replace('"', '""', (string) $this->$field) . '"';
        }
        return implode(',', $cells);
    }

    /** Same class and strictly equal mapped field values; relations are not compared. */
    public function equals(Entity $e) {
        if ($this->_meta->class !== get_class($e)) {
            return false;
        }

        foreach ($this->_meta->fields as $field => $column) {
            if ($this->$field !== $e->$field) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fetch a single row by primary key. Returns null if not found.
     * @param mixed $id
     * @param int $depth relation levels to eager-load (default 1)
     * @param string[] $exclude related entity classes to skip loading
     * @return static|null
     */
    public static function getById(mixed $id, int $depth = 1, array $exclude = []): ?static {
        return (new static())->get($id, $depth, $exclude);
    }
    /**
     * @param int $depth relation levels to eager-load (default 1)
     * @param string[] $exclude related entity classes to skip loading
     * @return static[] empty array when nothing matches, never null
     */
    public static function findAll(?SearchCriteria $criteria, int $depth = 1, array $exclude = []): array {
        return (new static())->find($criteria, null, $depth, $exclude);
    }
    /**
     * Returns the first matching entity, or null. The limit is forced to 1;
     * set orderBy in the $criteria to control which row comes first.
     * @param int $depth relation levels to eager-load (default 1)
     * @param string[] $exclude related entity classes to skip loading
     * @return static|null
     */
    public static function findOne(?SearchCriteria $criteria, int $depth = 1, array $exclude = []): ?static {
        return (new static())->findFirst($criteria, null, $depth, $exclude);
    }
    /**
     * Count the rows matching the criteria's WHERE (its GROUP BY/ORDER BY/LIMIT
     * are ignored — this is a scalar total). Doesn't hydrate anything.
     * @return int
     */
    public static function count(?SearchCriteria $criteria = null): int {
        $row = (new static())->whereScalar('SELECT COUNT(*)', $criteria);
        return (int) ($row[0] ?? 0);
    }
    /**
     * Whether any row matches the criteria's WHERE. Cheaper than count() — it
     * stops at the first match (SELECT 1 … LIMIT 1) and hydrates nothing.
     * @return bool
     */
    public static function exists(?SearchCriteria $criteria = null): bool {
        return (new static())->whereScalar('SELECT 1', $criteria, ' LIMIT 1') !== null;
    }

    /**
     * Build an entity of the mapped class from one fetched row, assigning each
     * mapped field. Shared by get() and find(); honors numericBinding (bind by
     * column index) vs. the default (bind by column name).
     * @param array $row
     * @return static
     */
    private function hydrate(array $row): static {
        $entity      = new $this->_meta->class($this->_meta);
        $entity->_db = $this->_db;   // rows stay on the connection that fetched them
        $i           = 0;
        foreach ($this->_meta->fields as $field => $column) {
            $entity->$field = $this->_meta->numericBinding
                ? $row[$i++]
                : $row[$column];
        }
        return $entity;
    }

    /**
     * Eagerly load every defined relation into $entities — one batched IN query
     * per relation — then descend into the loaded rows up to $depth levels.
     * $path carries the identities (class#id) of every ancestor already loaded
     * on this branch; a related entity already on the path is attached but NOT
     * descended into, so a bidirectional relation can't recurse forever.
     * @param static[] $entities
     */
    private function loadRelations(array $entities, int $depth, array $exclude, array $path = []): void {
        if ($depth <= 0 || ! $entities) {
            return;
        }
        foreach ($entities as $e) {
            $path[$this->keyOf($e)] = true;
        }
        foreach ($this->_meta->relations as $property => $rel) {
            $this->loadRelation($entities, $property, $rel, $depth, $exclude, $path);
        }
    }

    /**
     * Load one relation: collect the local-key values across $entities, fetch
     * the related rows whose foreign key is IN those values, group them by that
     * key, and assign — Entity[] for a HasMany, first-or-null for a HasOne —
     * then recurse into the related rows not already on $path (depth - 1).
     * @param static[] $entities
     */
    private function loadRelation(array $entities, string $property, RelationMeta $rel, int $depth, array $exclude, array $path): void {
        // local key: a column -> its property, or the id property by default
        $localProp = $rel->localKey !== null
            ? (array_search($rel->localKey, $this->_meta->fields, true) ?: $rel->localKey)
            : $this->_meta->idField;

        // baseline empty result on every entity (empty array / null)
        foreach ($entities as $e) {
            $e->$property = $rel->many ? [] : null;
        }

        // a relation to an excluded class is left at its default, not loaded
        if (isset($exclude[ltrim($rel->entity, '\\')])) {
            return;
        }

        $keys = [];
        foreach ($entities as $e) {
            if ($e->$localProp !== null) {
                $keys[$e->$localProp] = true;
            }
        }
        if (! $keys) {
            return;
        }

        // bare fetch of the related rows (this method drives the descent itself).
        // $relatedEntity runs the query, hydrates the rows and drives the
        // recursion, so binding it covers the whole related subtree.
        $relatedEntity      = new $rel->entity();
        $relatedEntity->_db = $this->_db;
        $related            = $relatedEntity->fetch(SearchCriteria::where(Op::in($rel->foreignKey, array_keys($keys))), null);

        // group by the related entity's property that maps to the foreign key column
        $fkProp  = array_search($rel->foreignKey, $relatedEntity->_meta->fields, true) ?: $rel->foreignKey;
        $grouped = [];
        foreach ($related as $r) {
            $grouped[$r->$fkProp][] = $r;
        }

        foreach ($entities as $e) {
            $k = $e->$localProp;
            if ($k !== null && isset($grouped[$k])) {
                $e->$property = $rel->many ? $grouped[$k] : $grouped[$k][0];
            }
        }

        // descend into related rows not already on the path (cycle guard)
        $descend = [];
        foreach ($related as $r) {
            if (! isset($path[$this->keyOf($r)])) {
                $descend[] = $r;
            }
        }
        $relatedEntity->loadRelations($descend, $depth - 1, $exclude, $path);
    }

    /** Identity of an entity for cycle detection: its class plus primary-key value. */
    private function keyOf(Entity $e): string {
        $idField = $e->_meta->idField;
        return $e->_meta->class . '#' . $e->$idField;
    }

    /**
     * Runs "$select FROM table [WHERE …] $suffix" against the criteria's WHERE
     * only, and returns the first result row (or null). Shared by count()/exists().
     * @return array|null the first row as a numeric array, or null if none
     */
    private function whereScalar(string $select, ?SearchCriteria $criteria, string $suffix = ''): ?array {
        $stmt   = $this->runStatement(
            $select . ' FROM ' . $this->_meta->table . $criteria?->getWhereSQL() . $suffix,
            $criteria?->getWhereParams()
        );
        $result = $stmt->get_result();
        $row    = $result->fetch_row();
        $result->close();
        $stmt->close();
        return $row;
    }

    /** Normalizes an exclude list of class-strings into a leading-\-insensitive lookup set. */
    private static function excludeSet(array $classes): array {
        $set = [];
        foreach ($classes as $class) {
            $set[ltrim($class, '\\')] = true;
        }
        return $set;
    }

}
