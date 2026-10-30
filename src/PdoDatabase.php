<?php

declare(strict_types=1);

namespace WilyCode\Database\DriverPdo;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use WilyCode\Database\Driver\DatabaseInterface;
use WilyCode\Database\Driver\Exception\ConnectionException;
use WilyCode\Database\Driver\Exception\DatabaseException;
use WilyCode\Database\Driver\Exception\NestedTransactionException;
use WilyCode\Database\Driver\Exception\QueryException;
use WilyCode\Database\Driver\Exception\TransactionException;
use WilyCode\Database\Driver\PlatformProviderInterface;

final readonly class PdoDatabase implements DatabaseInterface, PlatformProviderInterface
{
    private const array DEFAULT_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    public function __construct(private PDO $pdo) {}

    /**
     * @param array<int, mixed> $options
     * @throws ConnectionException
     */
    public static function connect(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
    ): self {
        try {
            return new self(new PDO($dsn, $username, $password, $options + self::DEFAULT_OPTIONS));
        } catch (PDOException $e) {
            throw new ConnectionException('Failed to create PDO connection.', 0, $e);
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function execute(string $query, array $params = [], array $types = []): int
    {
        return $this->run($query, $params, $types)->rowCount();
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function fetch(string $query, array $params = [], array $types = []): array
    {
        $row = $this->run($query, $params, $types)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function fetchAll(string $query, array $params = [], array $types = []): array
    {
        return $this->run($query, $params, $types)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function fetchColumn(string $query, array $params = [], int $columnIndex = 0, array $types = []): mixed
    {
        if ($columnIndex < 0) {
            throw new \InvalidArgumentException('Column index cannot be negative.');
        }

        $row = $this->run($query, $params, $types)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : ($row[$columnIndex] ?? null);
    }

    public function getPlatformName(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function getLastInsertId(): string
    {
        try {
            $id = $this->pdo->lastInsertId();
            if ($id === false) {
                throw new DatabaseException('The driver did not return a last insert id.');
            }

            return $id;
        } catch (DatabaseException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DatabaseException('Failed to get last insert id.', 0, $e);
        }
    }

    private function begin(): void
    {
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new TransactionException('Failed to begin transaction.');
            }
        } catch (TransactionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TransactionException('Failed to begin transaction.', 0, $e);
        }
    }

    private function commit(): void
    {
        try {
            if (!$this->pdo->commit()) {
                throw new TransactionException('Failed to commit transaction.');
            }
        } catch (TransactionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TransactionException('Failed to commit transaction.', 0, $e);
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->inTransaction()) {
            throw new NestedTransactionException('Nested transactions are not supported by the driver.');
        }

        $this->begin();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollbackQuietly();
            throw $e;
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    private function run(string $query, array $params, array $types): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($query);
            if ($statement === false) {
                throw new \RuntimeException('PDO statement preparation returned false.');
            }

            $this->bindParameters($statement, $params, $types);
            if ($statement->execute() === false) {
                throw new \RuntimeException('PDO statement execution returned false.');
            }

            return $statement;
        } catch (Throwable $e) {
            throw QueryException::fromThrowable($e, $query, $params);
        }
    }

    private function rollbackQuietly(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable) {
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    private function bindParameters(PDOStatement $statement, array $params, array $types): void
    {
        foreach ($params as $key => $value) {
            if (is_int($key)) {
                $position = $key + 1;
                $this->bindValue($statement, $position, $value, $types[$key] ?? $types[$position] ?? null);
                continue;
            }

            $name = ':' . ltrim((string) $key, ':');
            $this->bindValue($statement, $name, $value, $types[$name] ?? $types[ltrim((string) $key, ':')] ?? null);
        }
    }

    private function bindValue(PDOStatement $statement, int|string $parameter, mixed $value, mixed $type): void
    {
        if ($value === null) {
            $bound = $statement->bindValue($parameter, null, PDO::PARAM_NULL);
            if ($bound === false) {
                throw new \RuntimeException('PDO parameter binding returned false.');
            }
            return;
        }

        if ($type !== null) {
            $bound = $statement->bindValue($parameter, $value, (int) $type);
            if ($bound === false) {
                throw new \RuntimeException('PDO parameter binding returned false.');
            }
            return;
        }

        $detected = match (true) {
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };

        if ($statement->bindValue($parameter, $value, $detected) === false) {
            throw new \RuntimeException('PDO parameter binding returned false.');
        }
    }
}
