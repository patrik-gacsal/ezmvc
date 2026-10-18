<?php
namespace EzMvc;

/**
 * A parameterized query spec for Entity::find()/findFirst(): a WHERE tree (built
 * from Op::* nodes), plus optional GROUP BY / HAVING / ORDER BY / LIMIT. Compose
 * the conditions with Op and pass the whole thing to find():
 *
 *   $sc = SearchCriteria::where(Op::eq('status', 'active'), Op::gt('age', 18))
 *       ->orderBy('name')
 *       ->limit(20);
 *   $rows = (new User())->find($sc);
 *
 * getSQL()/getParams() are what Entity binds; the type string in getParams() is
 * inferred per value (int/bool -> i, float -> d, else s).
 */
class SearchCriteria {
    private ?Group $where  = null;
    private ?Group $having = null;
    private array $orderBy = [];
    private array $groupBy = [];
    private string $limit  = '';

    /** Start a criteria from top-level conditions, implicitly AND-ed together. */
    public static function where(Op ...$nodes): self {
        $sc        = new self();
        $sc->where = new Group('AND', $nodes);
        return $sc;
    }

    /** Post-aggregation conditions, implicitly AND-ed (needs a groupBy to be meaningful). */
    public function having(Op ...$nodes): self {
        $this->having = new Group('AND', $nodes);
        return $this;
    }

    /** Append an ORDER BY column; call repeatedly for secondary sorts. */
    public function orderBy(string $field, bool $asc = true): self {
        $this->orderBy[] = $field . ($asc ? ' ASC' : ' DESC');
        return $this;
    }

    /** Append one or more GROUP BY columns. */
    public function groupBy(string ...$fields): self {
        foreach ($fields as $field) {
            $this->groupBy[] = $field;
        }
        return $this;
    }

    /** Set LIMIT/OFFSET. Both are ints, so they're safe to inline (not bound). */
    public function limit(int $limit, int $offset = 0): self {
        $this->limit = ' LIMIT ' . $offset . ',' . $limit;
        return $this;
    }

    /** The WHERE + GROUP BY + HAVING + ORDER BY + LIMIT clause appended to the base query. */
    public function getSQL(): string {
        $sql = '';
        if ($this->where) {
            [$whereSql] = $this->where->toSql();
            if ($whereSql !== '') {
                $sql .= ' WHERE ' . $whereSql;
            }
        }
        if ($this->groupBy) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if ($this->having) {
            [$havingSql] = $this->having->toSql();
            if ($havingSql !== '') {
                $sql .= ' HAVING ' . $havingSql;
            }
        }
        if ($this->orderBy) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        return $sql . $this->limit;
    }

    /** bind_param array: [typeString, ...values] in placeholder order (WHERE then HAVING). */
    public function getParams(): array {
        $values = [];
        if ($this->where) {
            [, $whereValues] = $this->where->toSql();
            $values          = array_merge($values, $whereValues);
        }
        if ($this->having) {
            [, $havingValues] = $this->having->toSql();
            $values           = array_merge($values, $havingValues);
        }
        return self::bindArguments($values);
    }

    /** Just the ` WHERE …` fragment (no GROUP BY/HAVING/ORDER BY/LIMIT) — for COUNT/EXISTS. */
    public function getWhereSQL(): string {
        if (! $this->where) {
            return '';
        }
        [$sql] = $this->where->toSql();
        return $sql !== '' ? ' WHERE ' . $sql : '';
    }

    /** bind_param array for the WHERE alone: [typeString, ...values]. */
    public function getWhereParams(): array {
        return self::bindArguments($this->where ? $this->where->toSql()[1] : []);
    }

    /**
     * bind_param arguments for a values list: the inferred type string, then the
     * values. int/bool -> 'i' (a bool bound as 's' would send '' for false),
     * float -> 'd', everything else incl. null -> 's'.
     */
    public static function bindArguments(array $values): array {
        $types = '';
        foreach ($values as $value) {
            $types .= is_int($value) || is_bool($value) ? 'i' : (is_float($value) ? 'd' : 's');
        }
        return array_merge([$types], $values);
    }
}

/**
 * A node in a WHERE/HAVING condition tree AND the factory for building one.
 *
 * As a type, Op is what SearchCriteria::where()/having() and and()/or()/not()
 * accept — every node (Condition, Group, Negation) is an Op. As a factory, its
 * static methods are the whole public surface: comparison/text/set/null
 * operators produce leaf Conditions, and and()/or()/not() compose them into
 * nestable groups:
 *
 *   SearchCriteria::where(
 *       Op::eq('status', 'active'),
 *       Op::or(Op::eq('role', 'admin'), Op::eq('role', 'mod')),
 *       Op::gt('age', 18),
 *   )
 *
 * Values are always parameterized. Column names and the raw() fragment are
 * NOT — they're concatenated as-is, so they must be developer-supplied, never
 * request input.
 */
abstract class Op {
    /** @return array{0: string, 1: array} [sql fragment, ordered bind values] */
    abstract public function toSql(): array;

    /** `field = ?` */
    public static function eq(string $field, $value): Condition { return Condition::scalar($field, '=', $value); }
    /** `field <> ?` */
    public static function neq(string $field, $value): Condition { return Condition::scalar($field, '<>', $value); }
    /** `field > ?` */
    public static function gt(string $field, $value): Condition { return Condition::scalar($field, '>', $value); }
    /** `field >= ?` */
    public static function gte(string $field, $value): Condition { return Condition::scalar($field, '>=', $value); }
    /** `field < ?` */
    public static function lt(string $field, $value): Condition { return Condition::scalar($field, '<', $value); }
    /** `field <= ?` */
    public static function lte(string $field, $value): Condition { return Condition::scalar($field, '<=', $value); }

    /** `field LIKE ?` — $value's % and _ act as wildcards. */
    public static function like(string $field, $value): Condition { return Condition::scalar($field, 'LIKE', $value); }
    /** `field NOT LIKE ?` — $value's % and _ act as wildcards. */
    public static function notLike(string $field, $value): Condition { return Condition::scalar($field, 'NOT LIKE', $value); }
    /** `field LIKE '%value%'` with %/_ in $value escaped to literals. */
    public static function contains(string $field, string $value): Condition { return Condition::scalar($field, 'LIKE', '%' . self::escapeLike($value) . '%'); }
    /** `field LIKE 'value%'` with %/_ in $value escaped. */
    public static function startsWith(string $field, string $value): Condition { return Condition::scalar($field, 'LIKE', self::escapeLike($value) . '%'); }
    /** `field LIKE '%value'` with %/_ in $value escaped. */
    public static function endsWith(string $field, string $value): Condition { return Condition::scalar($field, 'LIKE', '%' . self::escapeLike($value)); }

    /** `field IN (?, …)`; an empty list matches nothing. */
    public static function in(string $field, array $list): Condition { return Condition::list($field, 'IN', $list); }
    /** `field NOT IN (?, …)`; an empty list matches everything. */
    public static function notIn(string $field, array $list): Condition { return Condition::list($field, 'NOT IN', $list); }
    /** `field BETWEEN ? AND ?`, both ends inclusive. */
    public static function between(string $field, $low, $high): Condition { return Condition::range($field, 'BETWEEN', $low, $high); }
    /** `field NOT BETWEEN ? AND ?` */
    public static function notBetween(string $field, $low, $high): Condition { return Condition::range($field, 'NOT BETWEEN', $low, $high); }

    /** `field IS NULL` */
    public static function isNull(string $field): Condition { return Condition::nullary($field, 'IS NULL'); }
    /** `field IS NOT NULL` */
    public static function isNotNull(string $field): Condition { return Condition::nullary($field, 'IS NOT NULL'); }

    /** `(a AND b …)`, nestable. */
    public static function and(Op ...$nodes): Group { return new Group('AND', $nodes); }
    /** `(a OR b …)`, nestable. */
    public static function or(Op ...$nodes): Group { return new Group('OR', $nodes); }
    /** `NOT (…)` */
    public static function not(Op $node): Negation { return new Negation($node); }

    /** Raw condition fragment with explicitly-bound params, e.g. Op::raw('JSON_CONTAINS(tags, ?)', $tag). */
    public static function raw(string $sql, ...$params): Condition { return Condition::rawSql($sql, $params); }

    /** Escapes LIKE wildcards (\ % _) so a value matches literally. */
    private static function escapeLike(string $value): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

/**
 * A single leaf condition (one column against an operator). Its shape decides
 * how it renders — a plain `col = ?`, a nullary `col IS NULL`, an `IN (?, …)`
 * list, a `BETWEEN ? AND ?` range, or a raw fragment. Built via Op::*.
 */
class Condition extends Op {
    private const SCALAR = 0, NULLARY = 1, LIST = 2, RANGE = 3, RAW = 4;

    private function __construct(
        private string $field,
        private string $operator,
        private array $values,
        private int $shape
    ) {}

    /** `field <op> ?` */
    public static function scalar(string $field, string $op, $value): self {
        return new self($field, $op, [$value], self::SCALAR);
    }
    /** `field <op>` (IS NULL / IS NOT NULL) */
    public static function nullary(string $field, string $op): self {
        return new self($field, $op, [], self::NULLARY);
    }
    /** `field <op> (?, ?, …)` (IN / NOT IN) */
    public static function list(string $field, string $op, array $values): self {
        return new self($field, $op, array_values($values), self::LIST);
    }
    /** `field <op> ? AND ?` (BETWEEN / NOT BETWEEN) */
    public static function range(string $field, string $op, $lo, $hi): self {
        return new self($field, $op, [$lo, $hi], self::RANGE);
    }
    /** A raw fragment with its own explicitly-bound params. */
    public static function rawSql(string $sql, array $values): self {
        return new self($sql, '', $values, self::RAW);
    }

    public function toSql(): array {
        switch ($this->shape) {
            case self::NULLARY:
                return ["{$this->field} {$this->operator}", []];
            case self::LIST:
                if (! $this->values) {
                    // empty IN matches nothing; empty NOT IN matches everything
                    return [strpos($this->operator, 'NOT') !== false ? '1=1' : '1=0', []];
                }
                $placeholders = implode(',', array_fill(0, count($this->values), '?'));
                return ["{$this->field} {$this->operator} ({$placeholders})", $this->values];
            case self::RANGE:
                return ["{$this->field} {$this->operator} ? AND ?", $this->values];
            case self::RAW:
                return [$this->field, $this->values];
            default: // SCALAR
                return ["{$this->field} {$this->operator} ?", $this->values];
        }
    }
}

/**
 * An AND/OR group of child nodes, rendered parenthesized. Empty children (and
 * empty groups) collapse away, so a group with nothing to say emits nothing.
 * Built via Op::and() / Op::or().
 */
class Group extends Op {
    /** @param Op[] $nodes */
    public function __construct(private string $joiner, private array $nodes) {}

    public function toSql(): array {
        $fragments = [];
        $values    = [];
        foreach ($this->nodes as $node) {
            [$sql, $vals] = $node->toSql();
            if ($sql === '') {
                continue;
            }
            $fragments[] = $sql;
            $values      = array_merge($values, $vals);
        }
        if (! $fragments) {
            return ['', []];
        }
        return ['(' . implode(' ' . $this->joiner . ' ', $fragments) . ')', $values];
    }
}

/** Negates a node: `NOT (…)`. Built via Op::not(). */
class Negation extends Op {
    public function __construct(private Op $node) {}

    public function toSql(): array {
        [$sql, $values] = $this->node->toSql();
        if ($sql === '') {
            return ['', []];
        }
        return ['NOT (' . $sql . ')', $values];
    }
}
