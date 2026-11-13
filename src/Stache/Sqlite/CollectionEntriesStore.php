<?php

namespace Statamic\Stache\Sqlite;

use PDO;
use Statamic\Facades\Collection;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\CollectionEntriesStore as StacheCollectionEntriesStore;
use Statamic\Support\Str;

/**
 * A collection's entries, cached in SQLite instead of one cache item per entry
 * and one cache item per index.
 *
 * Entries are still made from their files the same way. What's cached is:
 *  - each entry, serialized (the `entries` table)
 *  - the value of each indexed field for each entry (`entry_values`), worked out
 *    by the same index classes the Stache uses, so it's the same value
 *  - the terms each entry uses (`entry_terms`)
 *
 * Fields are indexed when the store is warmed (the same ones the Stache would),
 * or the first time a query needs one, as the Stache builds indexes on demand.
 */
class CollectionEntriesStore extends StacheCollectionEntriesStore
{
    /**
     * Set while the whole entries store is being cleared, when the tables have
     * already been emptied in one go.
     */
    public static bool $tablesCleared = false;

    private bool $bulk = false;
    private bool $warming = false;
    private array $made = [];

    /**
     * Every entry in the collection, once something has needed them all. Indexing
     * a field needs every entry, and loading them all again for each field (and
     * each taxonomy's associations) was most of the cost of warming.
     */
    private ?array $items = null;
    private ?array $fields = null;
    private ?int $seq = null;
    private ?array $taxonomies = null;

    private function db(): Database
    {
        return app(Database::class);
    }

    private function encoder(): ValueEncoder
    {
        return app(ValueEncoder::class);
    }

    private function rowKey($key): string
    {
        return $this->childKey().'::'.$key;
    }

    public function paths()
    {
        if ($this->bulk) {
            return parent::paths();
        }

        // When the paths aren't cached, every file is made into an entry and cached
        // here. Do that in one transaction, and write their terms in bulk after.
        $this->bulk = true;

        try {
            return $this->db()->transaction(function () {
                $paths = parent::paths();

                if ($this->made) {
                    $this->writeTerms($this->made);

                    if ($fields = $this->populatedFields()) {
                        $this->writeValues($this->made, array_keys($fields));
                    }

                    if (count($this->made) === $paths->count()) {
                        $this->items = $this->made;
                    }
                }

                return $paths;
            });
        } finally {
            $this->bulk = false;
            $this->made = [];
        }
    }

    public function warm()
    {
        $this->warming = true;

        try {
            $this->db()->transaction(function () {
                $this->paths();
                $this->ensureFields($this->indexes()->keys()->all());
            });

            $this->db()->analyze();
        } finally {
            $this->warming = false;
            $this->items = null;
        }
    }

    public function resetMemoizedState()
    {
        parent::resetMemoizedState();

        $this->items = null;
        $this->fields = null;
        $this->seq = null;
    }

    public function clear()
    {
        if (! static::$tablesCleared) {
            $this->db()->transaction(function () {
                foreach (['entries', 'entry_values', 'entry_fields', 'entry_terms'] as $table) {
                    $this->db()->run("DELETE FROM {$table} WHERE collection = ?", [$this->childKey()]);
                }
            });
        }

        $this->fields = null;
        $this->seq = null;
        $this->made = [];
        $this->items = null;

        // What Store::clear() does, without forgetting each entry one at a time.
        $this->resolveIndexes()->each(function ($index) {
            $index->clear();
            app('stache.indexes')->forget("{$this->key()}.{$index->name()}");
        });

        $this->usedIndexes = collect();
        Stache::cacheStore()->forget($this->indexUsageCacheKey());
        $this->clearCachedPaths();
        $this->keys()->clear();
        Stache::cacheStore()->forget("stache::timestamps::{$this->key()}");
    }

    public function getItem($key)
    {
        $this->handleFileChanges();

        // Unlike the Stache, there's no need to load every path to know an entry exists.
        if ($item = $this->getCachedItem($key)) {
            $item->syncOriginal();

            return $item;
        }

        return parent::getItem($key);
    }

    public function save($item)
    {
        $this->writeItemToDisk($item);

        $key = $this->getItemKey($item);

        $this->setPath($key, $item->path());

        $this->forgetStacheIndexes();

        $this->cacheItem($item);
    }

    public function delete($item)
    {
        $this->deleteItemFromDisk($item);

        $key = $this->getItemKey($item);

        $this->forgetItem($key);
        $this->forgetPath($key);
        $this->forgetStacheIndexes();
    }

    /**
     * Queries are answered from the database, so the Stache's own indexes for these
     * entries are only built when a query has to fall back to the Stache. Keeping
     * them up to date meant loading and saving every one of them on every save, so
     * they're forgotten instead, and built again if a query needs one. (The "path"
     * index holds the store's paths, which are kept up to date themselves.)
     */
    private function forgetStacheIndexes(): void
    {
        $this->resolveIndexes()->except('path')->each(function ($index) {
            $index->clear();
        });
    }

    /**
     * A tree or route change can change entries' uris, orders and parents. Caching
     * the entries again works out every indexed value for them afresh.
     */
    public function updateUris($ids = null)
    {
        $this->updateEntries($ids);
    }

    public function updateOrders($ids = null)
    {
        $this->updateEntries($ids);
    }

    public function updateParents($ids = null)
    {
        $this->updateEntries($ids);
    }

    private function updateEntries($ids): void
    {
        // Work the uris out now, while the change that caused this is in hand, and keep
        // them, as the Stache does by updating its uri index.
        $this->ensureField('uri');

        $ids = collect($ids)->isEmpty() ? $this->paths()->keys() : collect($ids);

        $this->db()->transaction(function () use ($ids) {
            $entries = $this->withoutBlinkingEntryUris(fn () => $ids->map(fn ($id) => $this->getItem($id))->filter());

            $entries->each(fn ($entry) => $this->cacheItem($entry));
        });

        // Stache indexes that something has built (the collections store uses "order") stay up to date.
        foreach (['uri', 'order', 'parent'] as $name) {
            if (($index = $this->resolveIndex($name))->isCached()) {
                $index->update();
            }
        }
    }

    protected function readCachedItem($key)
    {
        $data = $this->db()->run('SELECT data FROM entries WHERE key = ?', [$this->rowKey($key)])->fetchColumn();

        return $data === false ? null : unserialize($data);
    }

    protected function cachedUri($id)
    {
        // Without uris indexed there's nothing to look up; the entry works its own out.
        if (! array_key_exists('uri', $this->populatedFields())) {
            return null;
        }

        $uri = $this->db()->run("SELECT raw FROM entry_values WHERE field = 'uri' AND key = ?", [$this->rowKey($id)])->fetchColumn();

        return $uri === false ? null : $uri;
    }

    protected function writeCachedItem($item)
    {
        $key = $this->getItemKey($item);
        $row = $this->rowKey($key);

        // Files being made into entries in bulk are new rows, in file order.
        $seq = $this->bulk ? false : $this->db()->run('SELECT seq FROM entries WHERE key = ?', [$row])->fetchColumn();

        $statement = $this->db()->statement('INSERT OR REPLACE INTO entries (key, collection, id, seq, data) VALUES (?, ?, ?, ?, ?)');
        $statement->bindValue(1, $row);
        $statement->bindValue(2, $this->childKey());
        $statement->bindValue(3, (string) $item->id());
        $statement->bindValue(4, $seq === false ? $this->nextSeq() : (int) $seq, PDO::PARAM_INT);
        $statement->bindValue(5, serialize($item), PDO::PARAM_LOB);
        $statement->execute();

        if ($this->bulk) {
            $this->made[$key] = $item;

            return;
        }

        if ($this->items !== null) {
            $this->items[$key] = $item;
        }

        $this->writeTerms([$key => $item]);
        $this->writeValues([$key => $item], array_keys($this->populatedFields()));
    }

    protected function forgetCachedItem($key)
    {
        $row = $this->rowKey($key);

        foreach (['entries', 'entry_values', 'entry_terms'] as $table) {
            $this->db()->run("DELETE FROM {$table} WHERE key = ?", [$row]);
        }

        unset($this->items[$key]);
    }

    private function nextSeq(): int
    {
        $this->seq ??= (int) $this->db()->run('SELECT COALESCE(MAX(seq), 0) FROM entries WHERE collection = ?', [$this->childKey()])->fetchColumn();

        return ++$this->seq;
    }

    /**
     * @return array<string, bool> The indexed fields, and whether they sort as numbers.
     */
    public function populatedFields(): array
    {
        return $this->fields ??= collect($this->db()->select('SELECT field, numeric_sort FROM entry_fields WHERE collection = ?', [$this->childKey()]))
            ->mapWithKeys(fn ($row) => [$row['field'] => (bool) $row['numeric_sort']])
            ->all();
    }

    /**
     * Index a field for every entry, unless it already is.
     */
    public function ensureField(string $field): void
    {
        $this->ensureFields([$field]);
    }

    /**
     * Index fields for every entry, in one pass over the entries.
     */
    public function ensureFields(array $fields): void
    {
        $missing = array_values(array_diff($fields, array_keys($this->populatedFields())));

        if (! $missing) {
            return;
        }

        $this->db()->transaction(function () use ($missing) {
            $placeholders = implode(', ', array_fill(0, count($missing), '?'));
            $this->db()->run("DELETE FROM entry_values WHERE collection = ? AND field IN ({$placeholders})", [$this->childKey(), ...$missing]);

            foreach ($this->writeValues($this->allItems(), $missing, true) as $field => $numeric) {
                $this->db()->run('INSERT OR REPLACE INTO entry_fields (collection, field, numeric_sort) VALUES (?, ?, ?)', [$this->childKey(), $field, (int) $numeric]);
                $this->fields[$field] = $numeric;
            }
        });

        // So the next warm indexes them straight away, as the Stache does with indexes it has used.
        foreach ($missing as $field) {
            $this->cacheIndexUsage($field);
        }

        if (! $this->warming) {
            $this->db()->analyze();
        }
    }

    private function allItems(): array
    {
        $paths = $this->paths();

        if ($this->items !== null && count($this->items) === $paths->count()) {
            return $this->items;
        }

        $items = [];
        $prefix = strlen($this->childKey()) + 2;

        foreach ($this->db()->run('SELECT key, data FROM entries WHERE collection = ? ORDER BY seq', [$this->childKey()]) as $row) {
            $items[substr($row['key'], $prefix)] = unserialize($row['data']);
        }

        // Anything with a path but no row yet (the database was deleted, say) is made from its file.
        foreach ($paths->keys() as $key) {
            if (! isset($items[$key]) && ($item = parent::getItem($key))) {
                $items[$key] = $item;
            }
        }

        return $this->items = $items;
    }

    /**
     * @return array<string, bool> Whether each field still sorts as numbers.
     */
    private function writeValues(array $items, array $fields, bool $fresh = false): array
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
                    $encoded['raw'], $encoded['num'], $encoded['snull'], $encoded['snum'], $encoded['skey'], serialize($value),
                ];
            }

            $this->db()->insertMany('entry_values', ['field', 'key', 'collection', 'type', 'eq', 'inkey', 'raw', 'num', 'snull', 'snum', 'skey', 'php'], $rows, [10, 11], 'INSERT OR REPLACE');
            $rows = [];

            if (! $fresh && $numeric[$field] !== ($this->populatedFields()[$field] ?? true)) {
                $this->db()->run('UPDATE entry_fields SET numeric_sort = 0 WHERE collection = ? AND field = ?', [$this->childKey(), $field]);
                $this->fields[$field] = false;
            }
        }

        return $numeric;
    }

    /**
     * What each terms store's associations index holds for these entries.
     */
    private function writeTerms(array $items): void
    {
        $this->taxonomies ??= Collection::findByHandle($this->childKey())?->taxonomies()->map->handle()->all() ?? [];

        if (! $this->taxonomies) {
            return;
        }

        $delete = $this->db()->statement('DELETE FROM entry_terms WHERE key = ?');
        $rows = [];
        $slugs = [];

        foreach ($items as $key => $item) {
            $row = $this->rowKey($key);

            $delete->execute([$row]);

            foreach ($this->taxonomies as $taxonomy) {
                if ($item->getQueryableValue($taxonomy) === null) {
                    continue;
                }

                foreach (collect($item->value($taxonomy))->values() as $pos => $value) {
                    $slug = is_string($value) ? ($slugs[$value] ??= Str::slug($value)) : Str::slug($value);
                    $rows[] = [$taxonomy, $slug, json_encode($value), (string) $item->id(), $row, $this->childKey(), $item->locale(), $pos];
                }
            }
        }

        $this->db()->insertMany('entry_terms', ['taxonomy', 'slug', 'value', 'id', 'key', 'collection', 'site', 'pos'], $rows);
    }
}
