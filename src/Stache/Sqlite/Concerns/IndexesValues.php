<?php

namespace Statamic\Stache\Sqlite\Concerns;

use Statamic\Stache\Sqlite\Database;
use Statamic\Stache\Sqlite\PhpFilter;
use Statamic\Stache\Sqlite\ValueEncoder;

/**
 * Keeps the values a store's items are queried by in SQLite: the value of each
 * indexed field for each item, worked out by the same index classes the Stache
 * uses, so it's the same value. Fields are indexed when the store is warmed, or
 * the first time a query needs one, as the Stache builds indexes on demand.
 */
trait IndexesValues
{
    protected bool $warming = false;
    private ?array $fields = null;

    /**
     * The tables this store's values live in, and the column that says which store a row is for.
     */
    abstract protected function valuesTable(): string;

    abstract protected function fieldsTable(): string;

    abstract protected function itemsTable(): string;

    abstract protected function scopeColumn(): string;

    /**
     * Every item in the store, keyed as the store keys them.
     */
    abstract protected function allItems(): array;

    protected function db(): Database
    {
        return app(Database::class);
    }

    protected function encoder(): ValueEncoder
    {
        return app(ValueEncoder::class);
    }

    public function rowKey($key): string
    {
        return $this->childKey().'::'.$key;
    }

    /**
     * @return array<string, bool> The indexed fields, and whether they sort as numbers.
     */
    public function populatedFields(): array
    {
        return $this->fields ??= collect($this->db()->select("SELECT field, numeric_sort FROM {$this->fieldsTable()} WHERE {$this->scopeColumn()} = ?", [$this->childKey()]))
            ->mapWithKeys(fn ($row) => [$row['field'] => (bool) $row['numeric_sort']])
            ->all();
    }

    protected function forgetPopulatedFields(): void
    {
        $this->fields = null;
    }

    /**
     * Index a field for every item, unless it already is.
     */
    public function ensureField(string $field): void
    {
        $this->ensureFields([$field]);
    }

    /**
     * Index fields for every item, in one pass over the items.
     */
    public function ensureFields(array $fields): void
    {
        $missing = array_values(array_diff($fields, array_keys($this->populatedFields()), $this->unindexedFields()));

        if (! $missing) {
            return;
        }

        $this->db()->transaction(function () use ($missing) {
            $placeholders = implode(', ', array_fill(0, count($missing), '?'));
            $this->db()->run("DELETE FROM {$this->valuesTable()} WHERE {$this->scopeColumn()} = ? AND field IN ({$placeholders})", [$this->childKey(), ...$missing]);

            foreach ($this->writeValues($this->allItems(), $missing, true) as $field => $numeric) {
                $this->db()->run("INSERT OR REPLACE INTO {$this->fieldsTable()} ({$this->scopeColumn()}, field, numeric_sort) VALUES (?, ?, ?)", [$this->childKey(), $field, (int) $numeric]);
                $this->fields[$field] = $numeric;
            }
        });

        if (! $this->warming && ! $this->db()->bulkLoading) {
            $this->db()->analyze();
        }
    }

    /**
     * Index names that aren't values to keep (the terms stores' associations, say).
     */
    protected function unindexedFields(): array
    {
        return [];
    }

    /**
     * @return array<string, bool> Whether each field still sorts as numbers.
     */
    protected function writeValues(array $items, array $fields, bool $fresh = false): array
    {
        $encoder = $this->encoder();
        $numeric = [];
        $rows = [];

        foreach ($fields as $field) {
            $index = $this->resolveIndex($field);
            $numeric[$field] = $fresh ? true : ($this->populatedFields()[$field] ?? true);

            foreach ($items as $key => $item) {
                $value = $index->getItemValue($item);
                $encoded = $encoder->encode($value);
                $numeric[$field] = $numeric[$field] && $encoder->isNumericSortable($value);

                $rows[] = [
                    $field, $this->rowKey($key), $this->childKey(), $encoded['type'], $encoded['eq'], $encoded['inkey'],
                    $encoded['raw'], $encoded['num'], $encoded['snull'], $encoded['snum'], $encoded['skey'],
                    // Scalars can be rebuilt from their type, raw and num; anything else is kept whole.
                    in_array($encoded['type'], ['date', 'array', 'object']) ? serialize($value) : null,
                ];
            }

            $this->db()->insertMany($this->valuesTable(), ['field', 'key', $this->scopeColumn(), 'type', 'eq', 'inkey', 'raw', 'num', 'snull', 'snum', 'skey', 'php'], $rows, [10, 11], 'INSERT OR REPLACE');
            $rows = [];

            if (! $fresh && $numeric[$field] !== ($this->populatedFields()[$field] ?? true)) {
                $this->db()->run("UPDATE {$this->fieldsTable()} SET numeric_sort = 0 WHERE {$this->scopeColumn()} = ? AND field = ?", [$this->childKey(), $field]);
                $this->fields[$field] = false;
            }
        }

        return $numeric;
    }

    /**
     * Indexes asked for directly are made from the tables when they're needed (see
     * index()), and only kept in memory, so after a change they're just let go of.
     */
    protected function forgetStacheIndexes(): void
    {
        $this->resolveIndexes()->each->resetMemoizedState();
    }

    /**
     * An index, made from the indexed values rather than the Stache's cache, for
     * anything that asks the store for one directly.
     */
    public function index($name)
    {
        $this->handleFileChanges();

        $index = $this->resolveIndex($name);

        if (in_array($name, $this->unindexedFields())) {
            return $index->load();
        }

        if (! (fn () => $this->loaded)->call($index)) {
            $this->ensureField($name);

            $prefix = strlen($this->childKey()) + 2;
            $items = [];

            $rows = $this->db()->run(
                "SELECT v.key, v.type, v.raw, v.num, v.php FROM {$this->valuesTable()} v JOIN {$this->itemsTable()} i ON i.key = v.key WHERE v.field = ? AND v.{$this->scopeColumn()} = ? ORDER BY i.seq",
                [$name, $this->childKey()]
            );

            foreach ($rows as $row) {
                $items[substr($row['key'], $prefix)] = PhpFilter::value($row['type'], $row['raw'], $row['num'], $row['php']);
            }

            (function () use ($items) {
                $this->items = $items;
                $this->loaded = true;
            })->call($index);
        }

        return $index;
    }

    /**
     * The fields that have been indexed are the ones that have been used.
     */
    public function indexUsage()
    {
        return $this->usedIndexes = collect(array_keys($this->populatedFields()));
    }

    public function cacheIndexUsage($index)
    {
        // Indexing a field records it.
    }
}
