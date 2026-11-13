<?php

namespace Statamic\Stache\Sqlite;

use Statamic\Facades;
use Statamic\Stache\Query\EntryQueryBuilder as StacheEntryQueryBuilder;
use Statamic\Stache\Sqlite\Concerns\CompilesClauses;
use Statamic\Support\Arr;

/**
 * Finds entries with one SQL query instead of filtering and sorting every
 * entry's index values in PHP, and gives the same results as the Stache's
 * query builder: the same comparisons, the same left-to-right and/or, and the
 * same order, ties included.
 *
 * Most clauses are plain SQL over the encoded values. The rest (like, regex,
 * date parts, JSON, between, and comparisons PHP makes by its own rules) call
 * the Stache's own filters through a function registered with SQLite, so the
 * Stache's indexes are never needed.
 */
class EntryQueryBuilder extends StacheEntryQueryBuilder
{
    use CompilesClauses;

    protected function valuesTable(): string
    {
        return 'entry_values';
    }

    protected function scopeStore(string $scope)
    {
        return $this->entriesStore($scope);
    }

    public function get($columns = ['*'])
    {
        return $this->onceWithColumns(Arr::wrap($columns), fn () => $this->withFakeQueryLogging(function () {
            $keys = collect($this->findById() ?? $this->fetch($this->compile('e.key', order: true, limit: true)));

            $items = $this->getItems($keys);

            $items->each(fn ($item) => $item
                ->selectedQueryColumns($this->columns)
                ->selectedQueryRelations($this->with));

            return $this->collect($items)->values();
        }));
    }

    public function count()
    {
        // Like the Stache, a count respects the limit and offset.
        [$sql, $bindings] = $this->compile('e.key', order: false, limit: true);

        return (int) $this->fetch(['SELECT COUNT(*) FROM ('.$sql.')', $bindings])[0];
    }

    protected function getCountForPagination()
    {
        return (int) $this->fetch($this->compile('COUNT(*)', order: false, limit: false))[0];
    }

    /**
     * The same as the Stache's pluck: values grouped by collection, in each one's file
     * order unless they're keyed, when they're in the query's order.
     */
    public function pluck($column, $key = null)
    {
        return $this->onceWithColumns(array_filter([$column, $key]), function () use ($column, $key) {
            return $this->withFakeQueryLogging(function () use ($column, $key) {
                $keys = $this->fetch($this->compile('e.key', order: true, limit: true));

                return collect($keys)
                    ->map(fn ($rowKey) => explode('::', $rowKey, 2))
                    ->groupBy(0)
                    ->flatMap(function ($rows, $collection) use ($column, $key) {
                        $ids = $rows->pluck(1);

                        if ($column === 'id' && ! $key) {
                            return $ids;
                        }

                        $values = $this->valuesFor($collection, $column, $ids);

                        if (! $key) {
                            return collect($values)->values();
                        }

                        $keyValues = $this->valuesFor($collection, $key, $ids);

                        return $ids->mapWithKeys(fn ($id) => [$keyValues[$id] => $values[$id]]);
                    });
            });
        });
    }

    /**
     * A collection's values for a field, keyed by "collection::id" in file order, as a
     * Stache index's items would be.
     */
    public function getWhereColumnKeysFromStore($store, $where)
    {
        $this->entriesStore($store)->ensureField($where['column']);

        return collect($this->db()->select(
            'SELECT v.key, v.type, v.raw, v.num, v.php FROM entry_values v JOIN entries e ON e.key = v.key WHERE v.field = ? AND v.collection = ? ORDER BY e.seq',
            [$where['column'], $store]
        ))->mapWithKeys(fn ($row) => [$row['key'] => PhpFilter::value($row['type'], $row['raw'], $row['num'], $row['php'])]);
    }

    /**
     * @return array<string, mixed> Values keyed by id, in file order.
     */
    private function valuesFor(string $collection, string $field, $ids): array
    {
        $this->entriesStore($collection)->ensureField($field);

        $bindings = [$field];
        $list = $this->inList(collect($ids)->map(fn ($id) => "{$collection}::{$id}")->values()->all(), $bindings);

        $rows = $this->db()->select(
            "SELECT v.key, v.type, v.raw, v.num, v.php FROM entry_values v JOIN entries e ON e.key = v.key WHERE v.field = ? AND v.key IN {$list} ORDER BY e.seq",
            $bindings
        );

        $prefix = strlen($collection) + 2;
        $values = [];

        foreach ($rows as $row) {
            $values[substr($row['key'], $prefix)] = PhpFilter::value($row['type'], $row['raw'], $row['num'], $row['php']);
        }

        return $values;
    }

    /**
     * Entry::find() is the most common query by far (a nav finds every page's entry
     * this way), so a query for one id and nothing else skips building the SQL. Ids
     * are matched case-insensitively, so one that isn't found exactly goes the long way.
     */
    private function findById(): ?array
    {
        if (count($this->wheres) !== 1 || $this->taxonomyWheres || $this->orderBys || $this->randomize || $this->limit !== null || $this->offset) {
            return null;
        }

        $where = reset($this->wheres);

        if ($where['type'] !== 'Basic' || $where['column'] !== 'id' || $where['operator'] !== '=' || ! is_string($where['value'])) {
            return null;
        }

        $collections = empty($this->collections) ? Facades\Collection::handles()->all() : array_values(array_unique($this->collections));

        foreach ($collections as $collection) {
            $this->entriesStore($collection)->handleFileChanges();
        }

        $keys = [];

        foreach ($this->db()->select('SELECT key, collection FROM entries WHERE id = ?', [$where['value']]) as $row) {
            if (($position = array_search($row['collection'], $collections, true)) !== false) {
                $keys[$position] = $row['key'];
            }
        }

        ksort($keys);

        return $keys ? array_values($keys) : null;
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function compile(string $select, bool $order, bool $limit): array
    {
        $collections = array_values(array_unique(empty($this->collections)
            ? Facades\Collection::handles()->all()
            : $this->collections));

        if (empty($collections)) {
            return ["SELECT {$select} FROM entries e WHERE 0", []];
        }

        foreach ($collections as $collection) {
            $store = $this->entriesStore($collection);

            // Pick up files changed since the last request, as the Stache does when an
            // index is used, before the rows are queried.
            $store->handleFileChanges();

            // Indexing a field makes sure every entry in the collection has a row.
            $store->ensureField('id');
        }

        [$where, $whereBindings] = $this->fold($this->conditions($this->wheres, $collections));
        [$taxonomies, $taxonomyBindings] = $this->compileTaxonomyWheres();

        $joins = $orders = $joinBindings = $orderBindings = [];

        if ($order && $this->randomize) {
            $orders[] = 'RANDOM()';
        } elseif ($order) {
            foreach (array_values($this->orderBys) as $i => $orderBy) {
                $numeric = true;

                foreach ($collections as $collection) {
                    $store = $this->entriesStore($collection);
                    $store->ensureField($orderBy->sort);
                    $numeric = $numeric && $store->populatedFields()[$orderBy->sort];
                }

                $joins[] = "LEFT JOIN entry_values o{$i} ON o{$i}.field = ? AND o{$i}.key = e.key";
                $joinBindings[] = $orderBy->sort;

                // Nulls go last, and first when descending, as the Comparator's result is flipped.
                $direction = strtolower($orderBy->direction) === 'desc' ? 'DESC' : 'ASC';
                $orders[] = "COALESCE(o{$i}.snull, 1) {$direction}";
                $orders[] = 'o'.$i.($numeric ? '.snum ' : '.skey ').$direction;
            }

            // Without an order, entries come back in the order the clauses found them.
            if (empty($this->orderBys)) {
                foreach ($this->insertionOrder($this->wheres, $collections) as [$expression, $bindings]) {
                    $orders[] = $expression;
                    array_push($orderBindings, ...$bindings);
                }
            }

            // Ties keep the order the Stache would have them in: by collection as
            // queried, then in the order of the collection's files.
            $orders[] = 'CASE e.collection '
                .implode(' ', array_map(fn ($i) => "WHEN ? THEN {$i}", array_keys($collections)))
                .' END';
            array_push($orderBindings, ...$collections);
            $orders[] = 'e.seq';
        }

        $placeholders = implode(', ', array_fill(0, count($collections), '?'));

        $sql = "SELECT {$select} FROM entries e ".implode(' ', $joins)
            ." WHERE e.collection IN ({$placeholders})"
            .($where ? " AND {$where}" : '')
            .($taxonomies ? " AND {$taxonomies}" : '')
            .($orders ? ' ORDER BY '.implode(', ', $orders) : '');

        $limitBindings = [];

        if ($limit && ($this->limit !== null || $this->offset)) {
            $sql .= ' LIMIT ? OFFSET ?';
            $limitBindings = [$this->limit ?? -1, (int) $this->offset];
        }

        return [$sql, [...$joinBindings, ...$collections, ...$whereBindings, ...$taxonomyBindings, ...$orderBindings, ...$limitBindings]];
    }

    private function entriesStore(string $collection): CollectionEntriesStore
    {
        return $this->store->store($collection);
    }

    /**
     * The same as addTaxonomyWheres(), using the entry_terms table instead of
     * reading the terms stores' associations and querying by every entry id.
     *
     * @return array{0: ?string, 1: array}
     */
    private function compileTaxonomyWheres(): array
    {
        $included = $excluded = [];

        foreach ($this->taxonomyWheres as $where) {
            $terms = $where['type'] === 'Basic' ? [$where['value']] : $where['values'];

            $byTaxonomy = [];

            foreach ($terms as $term) {
                [$taxonomy, $slug] = array_pad(explode('::', (string) $term, 2), 2, '');
                $byTaxonomy[$taxonomy][] = $slug;
            }

            $groups = [];
            $groupBindings = [];

            foreach ($byTaxonomy as $taxonomy => $slugs) {
                $groupBindings[] = $taxonomy;
                $groups[] = '(taxonomy = ? AND slug IN '.$this->inList($slugs, $groupBindings).')';
            }

            $subquery = $groups
                ? 'e.id IN (SELECT id FROM entry_terms WHERE '.implode(' OR ', $groups).')'
                : '0';

            if ($where['type'] === 'NotIn') {
                $excluded[] = [$subquery, $groupBindings];
            } else {
                $included[] = [$subquery, $groupBindings];
            }
        }

        $sql = [];
        $bindings = [];

        foreach ($included as [$subquery, $subqueryBindings]) {
            $sql[] = $subquery;
            array_push($bindings, ...$subqueryBindings);
        }

        if ($excluded) {
            // An entry in any NotIn clause's terms is excluded.
            $sql[] = 'NOT ('.implode(' OR ', array_column($excluded, 0)).')';

            foreach ($excluded as [, $subqueryBindings]) {
                array_push($bindings, ...$subqueryBindings);
            }
        }

        return [$sql ? implode(' AND ', $sql) : null, $bindings];
    }
}
