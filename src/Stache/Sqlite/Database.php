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
    const SCHEMA_VERSION = 7;

    private ?PDO $pdo = null;

    /**
     * Whether empty tables are being filled, without their indexes.
     */
    public bool $bulkLoading = false;
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
        $pdo->exec('PRAGMA cache_size = -65536');

        $this->migrate($pdo);
        $this->registerFunctions($pdo);

        return $this->pdo = $pdo;
    }

    /**
     * The Stache's own filters, for the clauses SQL can't express with the same results.
     */
    private function registerFunctions(PDO $pdo): void
    {
        $filter = new PhpFilter;

        $register = method_exists($pdo, 'createFunction')
            ? fn ($name, $callback, $args) => $pdo->createFunction($name, $callback, $args)
            : fn ($name, $callback, $args) => $pdo->sqliteCreateFunction($name, $callback, $args);

        $register('stache_match', fn ($type, $raw, $num, $php, $id) => (int) $filter->matches([$type, $raw, $num, $php], (int) $id), 5);
        $register('stache_compare', fn ($at, $ar, $an, $ap, $bt, $br, $bn, $bp, $operator) => (int) $filter->compare([$at, $ar, $an, $ap], [$bt, $br, $bn, $bp], $operator), 9);
    }

    private const INDEXES = [
        'CREATE INDEX entries_collection ON entries (collection, seq)',
        'CREATE INDEX entries_id ON entries (id)',
        'CREATE INDEX entry_values_eq ON entry_values (field, eq)',
        'CREATE INDEX entry_values_inkey ON entry_values (field, inkey)',
        'CREATE INDEX entry_values_num ON entry_values (field, num)',
        'CREATE INDEX entry_values_snum ON entry_values (field, snull, snum)',
        'CREATE INDEX entry_values_skey ON entry_values (field, snull, skey)',
        'CREATE INDEX entry_values_key ON entry_values (key)',
        'CREATE INDEX entry_terms_slug ON entry_terms (taxonomy, slug)',
        'CREATE INDEX entry_terms_key ON entry_terms (key)',
        'CREATE INDEX entry_terms_value ON entry_terms (taxonomy, value_key)',
    ];

    private function createIndexes(PDO $pdo): void
    {
        foreach (self::INDEXES as $index) {
            $pdo->exec(str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $index));
        }
    }

    /**
     * Loads rows into empty tables without their indexes, then makes the indexes once.
     * Keeping six indexes up to date row by row is most of the cost of filling the
     * tables for a large site.
     */
    public function bulkLoad(callable $callback)
    {
        $pdo = $this->pdo();

        if ((int) $pdo->query('SELECT COUNT(*) FROM entries')->fetchColumn() > 0) {
            return $callback();
        }

        foreach (self::INDEXES as $index) {
            preg_match('/CREATE INDEX (\w+)/', $index, $match);
            $pdo->exec("DROP INDEX IF EXISTS {$match[1]}");
        }

        $this->statements = [];
        $this->bulkLoading = true;

        try {
            return $callback();
        } finally {
            $this->bulkLoading = false;
            $this->createIndexes($pdo);
            $this->statements = [];
            $this->analyze();
        }
    }

    private function migrate(PDO $pdo): void
    {
        if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() === self::SCHEMA_VERSION) {
            return;
        }

        $pdo->exec('DROP TABLE IF EXISTS entries; DROP TABLE IF EXISTS entry_values; DROP TABLE IF EXISTS entry_fields; DROP TABLE IF EXISTS entry_terms; DROP TABLE IF EXISTS entry_collections; DROP TABLE IF EXISTS entry_files;');

        $pdo->exec(<<<'SQL'
            CREATE TABLE entries (
                key TEXT PRIMARY KEY,
                collection TEXT NOT NULL,
                id TEXT NOT NULL,
                seq INTEGER NOT NULL,
                path TEXT NOT NULL,
                data BLOB NOT NULL
            );

            -- The collections whose files have been read, so an empty one isn't read again.
            CREATE TABLE entry_collections (
                collection TEXT PRIMARY KEY
            ) WITHOUT ROWID;

            -- Each file and its modified time, as the watcher last saw them.
            CREATE TABLE entry_files (
                collection TEXT NOT NULL,
                path TEXT NOT NULL,
                mtime INTEGER NOT NULL,
                PRIMARY KEY (collection, path)
            ) WITHOUT ROWID;

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
                php BLOB,
                PRIMARY KEY (field, key)
            ) WITHOUT ROWID;

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
                value_key TEXT,
                id TEXT NOT NULL,
                key TEXT NOT NULL,
                collection TEXT NOT NULL,
                site TEXT NOT NULL,
                pos INTEGER NOT NULL
            );

            SQL);

        $this->createIndexes($pdo);

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
