<?php

namespace Statamic\Stache\Sqlite;

use Facades\Statamic\Stache\Traverser;
use Statamic\Facades\File;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Stache\Sqlite\Concerns\IndexesValues;
use Statamic\Stache\Stores\TaxonomyTermsStore as StacheTaxonomyTermsStore;
use Statamic\Support\Arr;
use Statamic\Support\Str;

/**
 * A taxonomy's terms, cached in SQLite instead of the Stache's cache.
 *
 * A taxonomy's terms are those its entries use and those with files, one per
 * site, in the order the Stache's value indexes hold them: the ones entries use,
 * in the order they're first used, then the ones that only have files. Each is a
 * row in `terms`, with the term serialized once it's been made, and its file if
 * it has one. The values they're queried by are in `term_values`.
 */
class TaxonomyTermsStore extends StacheTaxonomyTermsStore
{
    use IndexesValues;

    private bool $bulk = false;
    private bool $built = false;
    private ?int $seq = null;
    private ?array $timestamps = null;

    protected function valuesTable(): string
    {
        return 'term_values';
    }

    protected function fieldsTable(): string
    {
        return 'term_fields';
    }

    protected function itemsTable(): string
    {
        return 'terms';
    }

    protected function scopeColumn(): string
    {
        return 'taxonomy';
    }

    protected function unindexedFields(): array
    {
        return ['associations'];
    }

    private function state(string $column): bool
    {
        return (bool) $this->db()->run("SELECT {$column} FROM term_taxonomies WHERE taxonomy = ?", [$this->childKey()])->fetchColumn();
    }

    private function setState(string $column): void
    {
        $this->db()->run("INSERT INTO term_taxonomies (taxonomy, {$column}) VALUES (?, 1) ON CONFLICT(taxonomy) DO UPDATE SET {$column} = 1", [$this->childKey()]);
    }

    private function nextSeq(): int
    {
        $this->seq ??= (int) $this->db()->run('SELECT COALESCE(MAX(seq), 0) FROM terms WHERE taxonomy = ?', [$this->childKey()])->fetchColumn();

        return ++$this->seq;
    }

    /**
     * Works out the taxonomy's terms, unless they already have been.
     */
    public function ensureTerms(): void
    {
        if ($this->built) {
            return;
        }

        if ($this->state('terms_built')) {
            $this->built = true;

            return;
        }

        $this->db()->transaction(function () {
            $collections = Taxonomy::findByHandle($this->childKey())?->collections()->map->handle()->values()->all() ?? [];

            foreach ($collections as $collection) {
                // Makes sure every entry, and the terms it uses, has a row.
                Stache::store('entries')->store($collection)->ensureField('id');
            }

            $order = [];

            if ($collections) {
                $placeholders = implode(', ', array_fill(0, count($collections), '?'));
                $cases = implode(' ', array_map(fn ($i) => "WHEN ? THEN {$i}", array_keys($collections)));

                $used = $this->db()->select(
                    "SELECT t.site, t.slug FROM entry_terms t JOIN entries e ON e.key = t.key
                    WHERE t.taxonomy = ? AND t.collection IN ({$placeholders})
                    ORDER BY CASE t.collection {$cases} END, e.seq, t.pos",
                    [$this->childKey(), ...$collections, ...$collections]
                );

                foreach ($used as $term) {
                    $order[$term['site'].'::'.$term['slug']] = true;
                }
            }

            // Terms with files that no entry uses come after, in file order.
            foreach ($this->paths()->keys() as $key) {
                $order[$key] = true;
            }

            // Each term's place is set here, whether its row was made just now or when its file was read.
            $rows = [];
            $seq = 0;

            foreach (array_keys($order) as $key) {
                [$site, $slug] = explode('::', $key, 2);
                $rows[] = [$this->rowKey($key), $this->childKey(), $site, $slug, ++$seq, null, null];
            }

            $this->db()->insertMany('terms', ['key', 'taxonomy', 'site', 'slug', 'seq', 'path', 'data'], $rows, [6], 'INSERT', 'ON CONFLICT(key) DO UPDATE SET seq = excluded.seq');

            $this->seq = $seq;
            $this->setState('terms_built');
        });

        $this->built = true;
    }

    public function paths()
    {
        if ($this->paths) {
            return $this->paths;
        }

        if ($this->state('files_read')) {
            return $this->paths = collect($this->keysWithPaths());
        }

        $files = Traverser::filter([$this, 'getItemFilter'])->traverse($this);

        $this->bulk = true;

        try {
            $paths = $this->db()->transaction(function () use ($files) {
                $paths = $files->mapWithKeys(function ($timestamp, $path) {
                    $term = $this->makeItemFromFile($path, File::get($path));

                    return $term->localizations()->flatMap(function ($localization) use ($path) {
                        $this->cacheItem($localization);

                        return [$this->getItemKey($localization) => $path];
                    })->all();
                });

                $update = $this->db()->statement('UPDATE terms SET path = ? WHERE key = ?');

                foreach ($paths as $key => $path) {
                    $update->execute([$path, $this->rowKey($key)]);
                }

                $this->setState('files_read');
                $this->cacheTimestamps($files->all());

                return $paths;
            });
        } finally {
            $this->bulk = false;
        }

        return $this->paths = $paths;
    }

    public function warm()
    {
        $this->warming = true;

        try {
            $this->db()->transaction(function () {
                $this->ensureTerms();
                $this->ensureFields($this->indexes()->keys()->all());
            });

            if (! $this->db()->bulkLoading) {
                $this->db()->analyze();
            }
        } finally {
            $this->warming = false;
        }
    }

    public function clear()
    {
        $this->db()->transaction(function () {
            foreach (['terms', 'term_values', 'term_fields', 'term_taxonomies', 'term_files'] as $table) {
                $this->db()->run("DELETE FROM {$table} WHERE taxonomy = ?", [$this->childKey()]);
            }
        });

        $this->resolveIndexes()->each(function ($index) {
            app('stache.indexes')->forget("{$this->key()}.{$index->name()}");
        });

        $this->resetMemoizedState();
    }

    public function resetMemoizedState()
    {
        parent::resetMemoizedState();

        $this->built = false;
        $this->seq = null;
        $this->timestamps = null;
        $this->keys = null;
        $this->usedIndexes = null;
        $this->forgetPopulatedFields();
    }

    protected function allItems(): array
    {
        $this->ensureTerms();

        $prefix = strlen($this->childKey()) + 2;
        $rows = $this->db()->select('SELECT key, data FROM terms WHERE taxonomy = ? ORDER BY seq', [$this->childKey()]);
        $items = [];

        foreach ($rows as $row) {
            $key = substr($row['key'], $prefix);
            $items[$key] = $row['data'] === null ? $this->getItem($key) : unserialize($row['data']);
        }

        return $items;
    }

    public function getItem($key)
    {
        $this->handleFileChanges();

        if ($item = $this->getCachedItem($key)) {
            if (empty($item->term()->getOriginal())) {
                $item->term()->syncOriginal();
            }

            return $item;
        }

        [$site, $slug] = explode('::', $key);

        $row = $this->db()->run('SELECT path FROM terms WHERE key = ?', [$this->rowKey($key)])->fetch();

        if ($path = $row['path'] ?? null) {
            $term = $this->makeItemFromFile($path, File::get($path));
        } else {
            // A term only used by entries is titled by its slug, as the Stache's title index has it.
            $term = Term::make($slug)
                ->taxonomy($this->childKey())
                ->set('title', $row ? $slug : null);
        }

        $term->syncOriginal();

        $item = $term->in($site);

        $this->cacheItem($item);

        return $item;
    }

    protected function getCachedItem($key)
    {
        $data = $this->db()->run('SELECT data FROM terms WHERE key = ?', [$this->rowKey($key)])->fetchColumn();

        return $data ? unserialize($data) : null;
    }

    protected function cacheItem($item)
    {
        $key = $this->getItemKey($item);
        [$site, $slug] = explode('::', $key, 2);

        // A row that's already there keeps its place and its path.
        $statement = $this->db()->statement(
            'INSERT INTO terms (key, taxonomy, site, slug, seq, path, data) VALUES (?, ?, ?, ?, ?, NULL, ?)
            ON CONFLICT(key) DO UPDATE SET data = excluded.data'
        );
        $statement->bindValue(1, $this->rowKey($key));
        $statement->bindValue(2, $this->childKey());
        $statement->bindValue(3, $site);
        $statement->bindValue(4, $slug);
        $statement->bindValue(5, $this->nextSeq(), \PDO::PARAM_INT);
        $statement->bindValue(6, serialize($item), \PDO::PARAM_LOB);
        $statement->execute();

        if (! $this->bulk && ($fields = array_keys($this->populatedFields()))) {
            $this->writeValues([$key => $item], $fields);
        }
    }

    public function forgetItem($key)
    {
        $row = $this->rowKey($key);

        $this->db()->run('DELETE FROM terms WHERE key = ?', [$row]);
        $this->db()->run('DELETE FROM term_values WHERE key = ?', [$row]);
    }

    public function save($term)
    {
        // A changed slug is a different term, so the old one goes, as the Stache does.
        if (($originalSlug = $term->getOriginal('slug')) && $originalSlug != $term->slug()) {
            $existing = Term::find($term->taxonomyHandle().'::'.$originalSlug);
            $this->delete($existing->term());
        }

        $term = clone $term;
        $term->syncOriginal();

        $this->writeItemToDisk($term);
        $this->ensureTerms();

        foreach ($term->localizations() as $item) {
            $this->cacheItem($item);
            $this->setPath($this->getItemKey($item), $item->path());
        }

        $this->forgetStacheIndexes();
    }

    public function delete($term)
    {
        $this->deleteItemFromDisk($term);

        foreach ($term->localizations() as $item) {
            $key = $this->getItemKey($item);
            $this->forgetItem($key);
            $this->forgetPath($key);
        }

        $this->forgetStacheIndexes();
    }

    /**
     * An entry's terms changed. The entries store has already written what it uses,
     * so new terms are added, and the values of the ones it uses are worked out again
     * (their entry counts, say), as the Stache updates them in its indexes.
     */
    public function sync($entry, $terms)
    {
        $this->resolveIndex('associations')->resetMemoizedState();

        if (! $this->built && ! $this->state('terms_built')) {
            // Not worked out yet: they will be, from what the entries use, when they're needed.
            return;
        }

        $this->built = true;
        $site = $entry->locale();
        $keys = collect(Arr::wrap($terms))->map(fn ($value) => $site.'::'.Str::slug($value))->unique()->values();

        $this->db()->transaction(function () use ($keys) {
            foreach ($keys as $key) {
                [$site, $slug] = explode('::', $key, 2);

                $this->db()->run(
                    'INSERT OR IGNORE INTO terms (key, taxonomy, site, slug, seq, path, data) VALUES (?, ?, ?, ?, ?, NULL, NULL)',
                    [$this->rowKey($key), $this->childKey(), $site, $slug, $this->nextSeq()]
                );
            }

            if ($fields = array_keys($this->populatedFields())) {
                $this->writeValues($keys->mapWithKeys(fn ($key) => [$key => $this->getItem($key)])->all(), $fields);
            }
        });

        $this->forgetStacheIndexes();
    }

    public function keys()
    {
        return $this->keys ??= new Keys($this);
    }

    /**
     * Each term with a file, and the file.
     */
    public function keysWithPaths(): array
    {
        $prefix = strlen($this->childKey()) + 2;
        $keys = [];

        foreach ($this->db()->run('SELECT key, path FROM terms WHERE taxonomy = ? AND path IS NOT NULL ORDER BY seq', [$this->childKey()]) as $row) {
            $keys[substr($row['key'], $prefix)] = $row['path'];
        }

        return $keys;
    }

    protected function setPath($key, $path)
    {
        $this->db()->run('UPDATE terms SET path = ? WHERE key = ?', [$path, $this->rowKey($key)]);

        $this->paths?->put($key, $path);
        $this->keys?->set($key, $path);
    }

    protected function forgetPath($key)
    {
        $this->paths?->forget($key);
        $this->keys?->forget($key);
    }

    public function clearCachedPaths()
    {
        $this->paths = null;

        $this->db()->run('UPDATE term_taxonomies SET files_read = 0 WHERE taxonomy = ?', [$this->childKey()]);
    }

    protected function getCachedTimestamps(): array
    {
        $timestamps = [];

        foreach ($this->db()->run('SELECT path, mtime FROM term_files WHERE taxonomy = ?', [$this->childKey()]) as $row) {
            $timestamps[$row['path']] = (int) $row['mtime'];
        }

        return $this->timestamps = $timestamps;
    }

    /**
     * Only the files that changed are written, rather than every file on every request.
     */
    protected function cacheTimestamps(array $files): void
    {
        $known = $this->timestamps ?? $this->getCachedTimestamps();

        $this->db()->transaction(function () use ($known, $files) {
            $delete = $this->db()->statement('DELETE FROM term_files WHERE taxonomy = ? AND path = ?');

            foreach (array_diff_key($known, $files) as $path => $mtime) {
                $delete->execute([$this->childKey(), $path]);
            }

            $changed = [];

            foreach ($files as $path => $mtime) {
                if (($known[$path] ?? null) !== (int) $mtime) {
                    $changed[] = [$this->childKey(), $path, (int) $mtime];
                }
            }

            $this->db()->insertMany('term_files', ['taxonomy', 'path', 'mtime'], $changed, [], 'INSERT OR REPLACE');
        });

        $this->timestamps = array_map('intval', $files);
    }
}
