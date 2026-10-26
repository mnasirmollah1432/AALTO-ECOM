<?php

declare(strict_types=1);

/**
 * Database helper functions - thin wrappers around PDO prepared statements.
 * Always pass user input through $params, never concatenate it into SQL.
 */

use App\Core\Database;

function db(): PDO
{
    return Database::connection();
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** All rows. */
function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/** First row or null. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** First column of the first row, or null. */
function db_value(string $sql, array $params = []): mixed
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Insert a row and return the new id.
 * $table and array keys must be trusted identifiers (never user input).
 */
function db_insert(string $table, array $data): int
{
    $columns = array_keys($data);
    $sql = sprintf(
        'INSERT INTO `%s` (`%s`) VALUES (%s)',
        $table,
        implode('`, `', $columns),
        implode(', ', array_map(static fn ($c) => ':' . $c, $columns))
    );
    db_query($sql, $data);
    return (int) db()->lastInsertId();
}

/**
 * Update rows matching $where (column => value, joined with AND).
 * Returns the number of affected rows.
 */
function db_update(string $table, array $data, array $where): int
{
    $set = implode(', ', array_map(static fn ($c) => "`{$c}` = :set_{$c}", array_keys($data)));
    $cond = implode(' AND ', array_map(static fn ($c) => "`{$c}` = :where_{$c}", array_keys($where)));

    $params = [];
    foreach ($data as $k => $v) {
        $params['set_' . $k] = $v;
    }
    foreach ($where as $k => $v) {
        $params['where_' . $k] = $v;
    }

    return db_query("UPDATE `{$table}` SET {$set} WHERE {$cond}", $params)->rowCount();
}

/** Run $callback inside a transaction; rolls back on any exception. */
function db_transaction(callable $callback): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
