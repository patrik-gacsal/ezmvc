<?php
namespace EzMvc;

/**
 * Static facade over a single Database instance, for apps that prefer a
 * global connection to passing one around. init() builds the instance; every
 * other method delegates to it. All real logic lives in Database — this holds
 * no mysqli logic of its own. Before init(), every call does nothing and
 * returns null, [] or 0.
 */
class StaticDatabase {
    private static ?Database $db = null;

    /** Connects; see Database::__construct(). */
    public static function init(string $host, string $user, #[\SensitiveParameter] ?string $pass, string $db) {
        self::$db = new Database($host, $user, $pass, $db);
    }

    /** @see Database::getConnection() */
    public static function getConnection() {
        return self::$db?->getConnection();
    }

    /** @see Database::beginTransaction() */
    public static function beginTransaction() {
        self::$db?->beginTransaction();
    }

    /** @see Database::commit() */
    public static function commit() {
        self::$db?->commit();
    }

    /** @see Database::rollback() */
    public static function rollback() {
        self::$db?->rollback();
    }

    /** @see Database::getTime() */
    public static function getTime(int $offsetMinutes = 0) {
        return self::$db?->getTime($offsetMinutes);
    }

    /** @see Database::runTransaction() */
    public static function runTransaction(callable $fn) {
        return self::$db?->runTransaction($fn);
    }

    /** @see Database::fetchAll() */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::$db?->fetchAll($sql, $params) ?? [];
    }

    /** @see Database::fetchOne() */
    public static function fetchOne(string $sql, array $params = []): ?array {
        return self::$db?->fetchOne($sql, $params);
    }

    /** @see Database::fetchColumn() */
    public static function fetchColumn(string $sql, array $params = []) {
        return self::$db?->fetchColumn($sql, $params);
    }

    /** @see Database::execute() */
    public static function execute(string $sql, array $params = []): int {
        return self::$db?->execute($sql, $params) ?? 0;
    }

    /** @see Database::lastInsertId() */
    public static function lastInsertId(): int|string {
        return self::$db?->lastInsertId() ?? 0;
    }
}
