<?php
namespace EzMvc;

/**
 * Generates a typed Entity + Dto class by introspecting the tables of an existing
 * database. Also used by the `ezmvc scaffold` CLI command, which just resolves a
 * \mysqli connection (from Config.php or explicit --host/--db/... flags) and calls
 * createOrm().
 *
 * The Dto is a single class mirroring the Entity, used for both API input and
 * output on simple, single-entity endpoints. Server-managed fields (the
 * auto-increment id, create_date, delete_date) are nullable in the Dto so a
 * client can omit them on create; Entity::fromDto() ignores them regardless of
 * what's sent. Composite payloads spanning more than one entity should still be
 * hand-written Request/Response classes in requests/ and responses/ — the Dto
 * only covers the "one entity in, one entity out" case.
 */
class OrmScaffolder {

    /**
     * @param \mysqli $conn
     * @param string $entitiesDir
     * @param string $dtosDir
     * @param bool $overwrite
     * @param string[]|null $tables Specific tables to scaffold, or null for every table in the database
     * @return array<string,string> table name => 'created'|'skipped'|'error: ...'
     */
    public static function createOrm(
        \mysqli $conn,
        string $entitiesDir = 'entities/',
        string $dtosDir = 'dtos/',
        bool $overwrite = false,
        ?array $tables = null
    ): array {
        foreach ([$entitiesDir, $dtosDir] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $tables ??= self::listTables($conn);
        $results = [];
        foreach ($tables as $table) {
            $results[$table] = self::scaffoldTable($conn, $table, $entitiesDir, $dtosDir, $overwrite);
        }
        return $results;
    }

    private static function listTables(\mysqli $conn): array {
        $result = $conn->query('SHOW TABLES');
        $tables = [];
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        $result->close();
        return $tables;
    }

    private static function scaffoldTable(\mysqli $conn, string $table, string $entitiesDir, string $dtosDir, bool $overwrite): string {
        try {
            // Backtick-quote the identifier (double any embedded backtick) — table
            // names come from introspection or the --table flag, not request input,
            // but this keeps the one concatenated identifier from ever breaking out.
            $result = $conn->query('SELECT * FROM `' . str_replace('`', '``', $table) . '` LIMIT 0');
        } catch (\mysqli_sql_exception $e) {
            return 'error: ' . $e->getMessage();
        }
        if (! $result) {
            return 'error: ' . $conn->error;
        }
        $columns     = $result->fetch_fields();
        $charLengths = self::getCharacterMaxLengths($conn, $table);
        $result->close();

        $name     = self::tableToClassName($table);
        $dtoClass = $name . 'Dto';

        $entityFile = rtrim($entitiesDir, '/') . '/' . $name     . '.php';
        $dtoFile    = rtrim($dtosDir, '/')   . '/' . $dtoClass . '.php';

        // Either file existing means the table has been scaffolded already, so
        // neither is rebuilt: an entity whose Dto was deleted has no API surface
        // on purpose, and recreating it on every run would fight that choice.
        if (! $overwrite && (file_exists($entityFile) || file_exists($dtoFile))) {
            return 'skipped';
        }

        $fieldNames  = [];
        $camelNames  = [];
        $types       = [];
        $nullable    = [];
        $mysqliTypes = [];
        $lengths     = [];
        $unsigned    = [];
        $idField     = null;
        foreach ($columns as $column) {
            $fieldNames[]                = $column->name;
            $camelNames[$column->name]   = self::snakeToCamel($column->name);
            $types[$column->name]        = self::mysqliTypeToPhp($column->type, $column->length);
            $nullable[$column->name]     = ! ($column->flags & MYSQLI_NOT_NULL_FLAG);
            $mysqliTypes[$column->name]  = $column->type;
            $lengths[$column->name]      = $column->length;
            $unsigned[$column->name]     = (bool) ($column->flags & MYSQLI_UNSIGNED_FLAG);
            if ($column->name === 'id') {
                $idField = 'id';
            }
        }
        if ($idField === null) {
            $idField = $fieldNames[0];
        }

        $fieldConstLines = [];
        foreach ($fieldNames as $fieldName) {
            $fieldConstLines[] = '    const ' . strtoupper($fieldName) . ' = \'' . $fieldName . '\';';
        }
        $fieldConstsBlock = implode("\n", $fieldConstLines);

        $idProperty = $camelNames[$idField];

        $entityFieldLines = [];
        foreach ($fieldNames as $fieldName) {
            $type     = $types[$fieldName];
            $property = $camelNames[$fieldName];
            $attrLine = ($property !== $fieldName ? "    #[Column('{$fieldName}')]\n" : '')
                . ($fieldName === $idField ? "    #[Id]\n" : '')
                . ($fieldName === 'create_date' ? "    #[ReadOnlyField]\n" : '');
            if ($fieldName === $idField && $type === 'int') {
                // An unset auto-increment id is "not yet persisted", not the value 0.
                $entityFieldLines[] = $attrLine . '    public ?int $' . $property . ' = null;';
                continue;
            }
            $entityFieldLines[] = $attrLine . ($nullable[$fieldName]
                ? '    public ?' . $type . ' $' . $property . ' = null;'
                : '    public ' . $type . ' $' . $property . ' = ' . self::phpDefaultForType($type) . ';');
        }
        $entityFieldsBlock = implode("\n", $entityFieldLines);

        $hasCreateDate  = in_array('create_date', $fieldNames, true);
        $readOnlyLine   = $hasCreateDate ? "\n        \$this->setReadOnlyFields(['" . $camelNames['create_date'] . "']);" : '';
        $readOnlyImport = $hasCreateDate ? "\nuse EzMvc\\Attr\\ReadOnlyField;" : '';

        $deleteLogical = in_array('delete_date', $fieldNames, true)
            ? "\n\n    public function deleteLogical(): void {\n"
        . "        if (!\$this->{$camelNames['delete_date']}) {\n"
        . "            \$this->{$camelNames['delete_date']} = gmdate('Y-m-d H:i:s');\n"
        . "            \$this->save();\n"
        . "        }\n"
        . "    }"
            : '';

        // Server-managed fields a client never supplies: the id (auto-increment),
        // and the create_date/delete_date timestamps. Writable fields are
        // everything else.
        $writableFieldNames = array_values(array_diff($fieldNames, [$idField, 'create_date', 'delete_date']));

        $toDtoLines = [];
        foreach ($fieldNames as $fieldName) {
            $property     = $camelNames[$fieldName];
            $toDtoLines[] = '        $dto->' . $property . ' = $this->' . $property . ';';
        }
        $toDtoBody = implode("\n", $toDtoLines);

        $fromDtoLines = [];
        foreach ($writableFieldNames as $fieldName) {
            $property       = $camelNames[$fieldName];
            $fromDtoLines[] = '        $entity->' . $property . ' = $dto->' . $property . ';';
        }
        $fromDtoBody = implode("\n", $fromDtoLines);

        $entityTpl = <<<PHP
<?php

use EzMvc\Entity;
use EzMvc\Attr\Column;
use EzMvc\Attr\Id;{$readOnlyImport}
use EzMvc\Attr\Table;

#[Table('{$table}')]
class {$name} extends Entity {

{$fieldConstsBlock}

{$entityFieldsBlock}

    protected function init(): void {
        \$this->setIdField('{$idProperty}');{$readOnlyLine}
    }

    public function toDto(): {$dtoClass} {
        \$dto = new {$dtoClass}();
{$toDtoBody}
        return \$dto;
    }

    /**
     * @param {$name}[] \$entities
     * @return {$dtoClass}[]
     */
    public static function toDtoList(array \$entities): array {
        return array_map(fn({$name} \$e) => \$e->toDto(), \$entities);
    }

    public static function fromDto({$dtoClass} \$dto): self {
        \$entity = new self();
{$fromDtoBody}
        return \$entity;
    }{$deleteLogical}

}
PHP;

        // Server-managed fields are nullable in the Dto even if the column is
        // NOT NULL, so a client can omit them on create; fromDto() ignores them
        // regardless of what's sent.
        $dtoLines = [];
        foreach ($fieldNames as $fieldName) {
            $serverManaged = ! in_array($fieldName, $writableFieldNames, true);
            $optional      = $nullable[$fieldName] || $serverManaged;
            $prefix        = $optional ? '?' : '';
            $attrLine      = self::validatorAttributeFor(
                $mysqliTypes[$fieldName],
                $lengths[$fieldName],
                $unsigned[$fieldName],
                $optional,
                $charLengths[$fieldName] ?? null
            );
            $dtoLines[] = ($attrLine ? $attrLine . "\n" : '')
                . '    public ' . $prefix . $types[$fieldName] . ' $' . $camelNames[$fieldName] . ';';
        }
        $dtoBody = implode("\n", $dtoLines);

        $dtoTpl = <<<PHP
<?php

class {$dtoClass} {

{$dtoBody}

}
PHP;

        file_put_contents($entityFile, $entityTpl);
        file_put_contents($dtoFile, $dtoTpl);

        return 'created';
    }

    /**
     * Maps a column's mysqli type/length/unsigned-ness to a Validators\* property
     * attribute line for the Dto, or null if there's no sensible mapping (BLOB/binary
     * columns are skipped entirely rather than guessing a wrong validator for them).
     * MySQL int/decimal columns are SIGNED by default (UNSIGNED must be declared
     * explicitly), but Validators\Integer/Decimal default to signed: false — so the
     * common case (a plain, non-unsigned column) needs signed: true emitted explicitly.
     * $charMaxLength is the column's true CHARACTER count (from
     * INFORMATION_SCHEMA.COLUMNS.CHARACTER_MAXIMUM_LENGTH), used for Text's maxLength
     * instead of $length — mysqli's own reported column length is scaled by the
     * charset's max bytes-per-character (e.g. a utf8mb4 varchar(255) reports 1020,
     * not 255), so using $length directly there would be wrong for any multi-byte
     * charset, which utf8mb4 (MySQL's modern default) always is.
     */
    private static function validatorAttributeFor(int $type, int $length, bool $unsigned, bool $optional, ?int $charMaxLength = null): ?string {
        static $ints    = [MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_YEAR, MYSQLI_TYPE_BIT];
        static $floats  = [MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL];
        static $strings = [MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_STRING];

        $args = $optional ? ['optional: true'] : [];

        if ($type === MYSQLI_TYPE_TINY && $length === 1) {
            return self::attributeLine('Boolean', $args);
        }
        if (in_array($type, $ints, true)) {
            if (! $unsigned) {
                $args[] = 'signed: true';
            }
            return self::attributeLine('Integer', $args);
        }
        if (in_array($type, $floats, true)) {
            if (! $unsigned) {
                $args[] = 'signed: true';
            }
            return self::attributeLine('Decimal', $args);
        }
        if (in_array($type, $strings, true)) {
            array_unshift($args, 'maxLength: ' . ($charMaxLength ?? $length));
            return self::attributeLine('Text', $args);
        }
        if ($type === MYSQLI_TYPE_DATE) {
            return self::attributeLine('Date', $args);
        }
        if ($type === MYSQLI_TYPE_TIME) {
            return self::attributeLine('Time', $args);
        }
        if (in_array($type, [MYSQLI_TYPE_DATETIME, MYSQLI_TYPE_TIMESTAMP], true)) {
            return self::attributeLine('DateTime', $args);
        }
        return null;
    }

    private static function attributeLine(string $validator, array $args): string {
        $argList = $args ? '(' . implode(', ', $args) . ')' : '';
        return '    #[Validators\\' . $validator . $argList . ']';
    }

    /** @return array<string,int> column name => true character count (not byte count) */
    private static function getCharacterMaxLengths(\mysqli $conn, string $table): array {
        $stmt = $conn->prepare(
            'SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result  = $stmt->get_result();
        $lengths = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['CHARACTER_MAXIMUM_LENGTH'] !== null) {
                $lengths[$row['COLUMN_NAME']] = (int) $row['CHARACTER_MAXIMUM_LENGTH'];
            }
        }
        $stmt->close();
        return $lengths;
    }

    private static function mysqliTypeToPhp(int $type, int $length): string {
        // tinyint(1) is MySQL's conventional boolean column; wider tinyints are real small ints.
        if ($type === MYSQLI_TYPE_TINY && $length === 1) {
            return 'bool';
        }
        static $ints   = [MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_YEAR, MYSQLI_TYPE_BIT];
        static $floats = [MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL];
        if (in_array($type, $ints, true)) {
            return 'int';
        }
        if (in_array($type, $floats, true)) {
            return 'float';
        }
        return 'string';
    }

    private static function phpDefaultForType(string $type): string {
        return match ($type) {
            'int'   => '0',
            'float' => '0.0',
            'bool'  => 'false',
            default => "''",
        };
    }

    /**
     * Generates the per-database class: one accessor per table returning an
     * entity already bound to this database's connection.
     *
     * It holds the Database in a property instead of extending it, so no table
     * name can collide with or shadow a Database method: properties and methods
     * live in separate symbol tables.
     *
     * Skipped when the file exists; $overwrite discards hand-added queries.
     *
     * @param string|null $className defaults to the database's own name + 'Db'
     * @param string[]|null $tables specific tables, or null for every table
     * @return array{class: string, file: string, status: string}
     *         status is 'created', 'skipped' or 'error: ...'
     */
    public static function createDbClass(
        \mysqli $conn,
        string $databasesDir = 'databases/',
        ?string $className = null,
        bool $overwrite = false,
        ?array $tables = null
    ): array {
        $databaseName = $conn->query('SELECT DATABASE()')->fetch_row()[0] ?? '';
        $className ??= self::tableToClassName($databaseName) . 'Db';
        $file        = rtrim($databasesDir, '/\\') . '/' . $className . '.php';

        if (! $overwrite && file_exists($file)) {
            return ['class' => $className, 'file' => $file, 'status' => 'skipped'];
        }
        if (! is_dir($databasesDir)) {
            mkdir($databasesDir, 0755, true);
        }

        $accessors = [];
        $used      = ['__construct' => true];
        foreach ($tables ?? self::listTables($conn) as $table) {
            $entityClass = self::tableToClassName($table);
            // two tables can still camelCase to one name (user_account / userAccount);
            // a duplicate method would be a compile error, so suffix instead
            $method = self::snakeToCamel($table);
            $unique = $method;
            for ($n = 2; isset($used[strtolower($unique)]); $n++) {
                $unique = $method . $n;
            }
            $used[strtolower($unique)] = true;

            $accessors[] = "    public function {$unique}(): {$entityClass} {\n"
                . "        return (new {$entityClass}())->bindInstanceConnection(\$this->db->getConnection());\n"
                . '    }';
        }

        $tpl = "<?php\n\nuse EzMvc\\Database;\n\n"
            . "/**\n"
            . " * The {$databaseName} database: each accessor returns an entity bound to \$this->db,\n"
            . " * which also serves raw queries (fetchAll, execute, runTransaction).\n"
            . " * Add cross-table queries below; `ezmvc scaffold --overwrite` discards them.\n"
            . " */\n"
            . "class {$className} {\n\n"
            . "    public function __construct(public Database \$db) {}\n\n"
            . implode("\n\n", $accessors)
            . "\n}\n";

        $written = file_put_contents($file, $tpl) !== false;
        return [
            'class'  => $className,
            'file'   => $file,
            'status' => $written ? 'created' : 'error: could not write ' . $file,
        ];
    }

    private static function tableToClassName(string $table): string {
        $frags = explode('_', $table);
        foreach ($frags as &$frag) {
            $frag = ucfirst($frag);
        }
        return implode('', $frags);
    }

    private static function snakeToCamel(string $field): string {
        $frags = explode('_', $field);
        foreach ($frags as $i => &$frag) {
            $frag = $i === 0 ? $frag : ucfirst($frag);
        }
        return implode('', $frags);
    }
}
