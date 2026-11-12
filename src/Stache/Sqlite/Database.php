<?php

namespace Statamic\Stache\Sqlite;

use PDO;
use PDOStatement;

/**
 * The SQLite file that holds the Stache's entries, the values they're queried by,
 * and which terms they use. It's a cache: the content files are the source of
 * truth, and the file can be deleted at any time and rebuilt by warming.
 */
class Database
{
    const SCHEMA_VERSION = 2;

    private ?PDO $pdo = null;
    private array $statements = [];
    private int $transactionDepth = 0;

    public function __construct(private string $path)
    {
        //
    }

    public function pdo(): PDO
    {
        if ($this->pdo) {
            return $this->pdo;
        }

        if ($this->path !== ':memory:' && ! is_dir($dir = dirname($this->path))) {
            mkdir($dir, 0755, true);
        }

        $pdo = new PDO('sqlite:'.$this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $this->migrate($pdo);

        return $this->pdo = $pdo;
    }

    private function migrate(PDO $pdo): void
    {
        if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() === self::SCHEMA_VERSION) {
            return;
        }

        $pdo->exec('DROP TABLE IF EXISTS entries; DROP TABLE IF EXISTS entry_values; DROP TABLE IF EXISTS entry_fields; DROP TABLE IF EXISTS entry_terms;');

        $pdo->exec(<<<'SQL'
            CREATE TABLE entries (
                key TEXT PRIMARY KEY,
                collection TEXT NOT NULL,
                id TEXT NOT NULL,
                seq INTEGER NOT NULL,
                data BLOB NOT NULL
            );
            CREATE INDEX entries_collection ON entries (collection, seq);
            CREATE INDEX entries_id ON entries (id);

            -- One row per entry per indexed field. The columns hold the value in the
            -- forms the Stache's PHP comparisons need, so SQL can give the same answers.
            CREATE TABLE entry_values (
                field TEXT NOT NULL,
                key TEXT NOT NULL,
                collection TEXT NOT NULL,
                type TEXT NOT NULL,
                eq TEXT,
                inkey TEXT,
                raw TEXT,
                num REAL,
                snull INTEGER NOT NULL,
                snum REAL,
                skey BLOB,
                PRIMARY KEY (field, key)
            ) WITHOUT ROWID;
            CREATE INDEX entry_values_eq ON entry_values (field, eq);
            CREATE INDEX entry_values_inkey ON entry_values (field, inkey);
            CREATE INDEX entry_values_num ON entry_values (field, num);
            CREATE INDEX entry_values_snum ON entry_values (field, snull, snum);
            CREATE INDEX entry_values_skey ON entry_values (field, snull, skey);
            CREATE INDEX entry_values_key ON entry_values (key);

            -- Which fields have been indexed for which collections, and whether every
            -- value in them sorts as a number.
            CREATE TABLE entry_fields (
                collection TEXT NOT NULL,
                field TEXT NOT NULL,
                numeric_sort INTEGER NOT NULL,
                PRIMARY KEY (collection, field)
            ) WITHOUT ROWID;

            -- The terms each entry uses: what the terms stores' associations hold.
            CREATE TABLE entry_terms (
                taxonomy TEXT NOT NULL,
                slug TEXT NOT NULL,
                value TEXT NOT NULL,
                id TEXT NOT NULL,
                key TEXT NOT NULL,
                collection TEXT NOT NULL,
                site TEXT NOT NULL,
                pos INTEGER NOT NULL
            );
            CREATE INDEX entry_terms_slug ON entry_terms (taxonomy, slug);
            CREATE INDEX entry_terms_key ON entry_terms (key);
            SQL);

        $pdo->exec('PRAGMA user_version = '.self::SCHEMA_VERSION);
    }

    public function statement(string $sql): PDOStatement
    {
        return $this->statements[$sql] ??= $this->pdo()->prepare($sql);
    }

    public function run(string $sql, array $bindings = []): PDOStatement
    {
        $statement = $this->statement($sql);
        $statement->execute($bindings);

        return $statement;
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->run($sql, $bindings)->fetchAll();
    }

    public function transaction(callable $callback)
    {
        if ($this->transactionDepth++ === 0) {
            $this->pdo()->beginTransaction();
        }

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            if (--$this->transactionDepth === 0) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        if (--$this->transactionDepth === 0) {
            $this->pdo->commit();
        }

        return $result;
    }

    /**
     * Without statistics, SQLite can't tell that a lookup on one value is far more
     * selective than "every entry in these collections", and scans the collection.
     * A sampled analysis is enough to get the right plans, and is quick.
     */
    public function analyze(): void
    {
        $this->pdo()->exec('PRAGMA analysis_limit = 1000');
        $this->pdo()->exec('ANALYZE');
        $this->statements = [];
    }

    /**
     * Inserts rows in batches, which is much quicker than a statement per row.
     *
     * @param  array<int, array>  $rows  Each row's values, in column order.
     * @param  array<int, int>  $lobs  The positions of columns holding binary data.
     */
    public function insertMany(string $table, array $columns, array $rows, array $lobs = [], string $verb = 'INSERT'): void
    {
        $width = count($columns);
        $lobs = array_flip($lobs);

        foreach (array_chunk($rows, 100) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '('.implode(', ', array_fill(0, $width, '?')).')'));
            $statement = $this->statement("{$verb} INTO {$table} (".implode(', ', $columns).") VALUES {$values}");

            $i = 0;

            foreach ($chunk as $row) {
                foreach ($row as $position => $value) {
                    $type = match (true) {
                        $value === null => \PDO::PARAM_NULL,
                        isset($lobs[$position]) => \PDO::PARAM_LOB,
                        is_int($value) => \PDO::PARAM_INT,
                        default => \PDO::PARAM_STR,
                    };

                    $statement->bindValue(++$i, $value, $type);
                }
            }

            $statement->execute();
        }
    }

    public function delete(): void
    {
        $this->pdo = null;
        $this->statements = [];

        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm'] as $file) {
            @unlink($file);
        }
    }
}
