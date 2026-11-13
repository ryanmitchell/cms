<?php

namespace Statamic\Stache\Sqlite;

use DateTimeInterface;
use PDO;
use Statamic\Facades;
use Statamic\Stache\Query\EntryQueryBuilder as StacheEntryQueryBuilder;
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
    private const RANGE_OPERATORS = ['<', '>', '<=', '>='];

    private array $filterIds = [];

    private function db(): Database
    {
        return app(Database::class);
    }

    public function get($columns = ['*'])
    {
        return $this->onceWithColumns(Arr::wrap($columns), fn () => $this->withFakeQueryLogging(function () {
            $keys = collect($this->fetch($this->compile('e.key', order: true, limit: true)));

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
            'SELECT v.key, v.php FROM entry_values v JOIN entries e ON e.key = v.key WHERE v.field = ? AND v.collection = ? ORDER BY e.seq',
            [$where['column'], $store]
        ))->mapWithKeys(fn ($row) => [$row['key'] => unserialize($row['php'])]);
    }

    /**
     * @return array<string, mixed> Values keyed by id, in file order.
     */
    private function valuesFor(string $collection, string $field, $ids): array
    {
        $this->entriesStore($collection)->ensureField($field);

        $rows = $this->db()->select(
            'SELECT v.key, v.php FROM entry_values v JOIN entries e ON e.key = v.key
            WHERE v.field = ? AND v.key IN (SELECT value FROM json_each(?)) ORDER BY e.seq',
            [$field, json_encode(collect($ids)->map(fn ($id) => "{$collection}::{$id}")->values()->all())]
        );

        $prefix = strlen($collection) + 2;
        $values = [];

        foreach ($rows as $row) {
            $values[substr($row['key'], $prefix)] = unserialize($row['php']);
        }

        return $values;
    }

    /**
     * The query's first column. Rows are fetched before the clauses' registered filters
     * are forgotten, since SQLite runs them as it steps through the rows.
     */
    private function fetch(array $compiled): array
    {
        try {
            return $this->db()->run(...$compiled)->fetchAll(PDO::FETCH_COLUMN);
        } finally {
            PhpFilter::forget($this->filterIds);
            $this->filterIds = [];
        }
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
     * Each clause as SQL, with its bindings.
     *
     * @return array<int, array{where: array, sql: string, bindings: array}>
     */
    private function conditions(array $wheres, array $collections): array
    {
        return array_map(function ($where) use ($collections) {
            [$sql, $bindings] = $this->compileWhere($where, $collections);

            return compact('where', 'sql', 'bindings');
        }, array_values($wheres));
    }

    /**
     * Folds the first $count clauses left to right, like intersectKeysFromWhereClause():
     * an "or" adds its matches, anything else (a "not in" included) keeps only those
     * that match both.
     *
     * @return array{0: ?string, 1: array}
     */
    private function fold(array $conditions, ?int $count = null): array
    {
        $sql = null;
        $bindings = [];

        foreach (array_slice($conditions, 0, $count ?? count($conditions)) as $condition) {
            $where = $condition['where'];

            if ($sql === null) {
                $sql = $condition['sql'];
            } elseif ($where['boolean'] === 'or' && $where['type'] !== 'NotIn') {
                $sql = "({$sql} OR {$condition['sql']})";
            } else {
                $sql = "({$sql} AND {$condition['sql']})";
            }

            array_push($bindings, ...$condition['bindings']);
        }

        return [$sql, $bindings];
    }

    /**
     * The order the Stache finds unordered entries in. Each clause's matches come in
     * file order, and an "or" adds its new matches after the ones before it. So an
     * entry is placed by the clause that (last) added it, then by that clause's own
     * order, if it has one.
     *
     * @return array<int, array{0: string, 1: array}>
     */
    private function insertionOrder(array $wheres, array $collections): array
    {
        $wheres = array_values($wheres);

        if (! $wheres) {
            return [];
        }

        $adders = [0];

        foreach ($wheres as $i => $where) {
            if ($i > 0 && $where['boolean'] === 'or' && $where['type'] !== 'NotIn') {
                $adders[] = $i;
            }
        }

        $expressions = [];
        $rank = null;

        if (count($adders) > 1) {
            $conditions = $this->conditions($wheres, $collections);
            $rank = ['CASE', []];

            // The last clause that an entry wasn't yet (or no longer) matched by.
            for ($i = count($conditions) - 2; $i >= 0; $i--) {
                [$sql, $bindings] = $this->fold($conditions, $i + 1);
                $rank[0] .= " WHEN NOT {$sql} THEN ".($i + 1);
                array_push($rank[1], ...$bindings);
            }

            $rank[0] .= ' ELSE 0 END';
            $expressions[] = $rank;
        }

        foreach ($adders as $i) {
            if ($wheres[$i]['type'] !== 'Nested') {
                continue;
            }

            foreach ($this->insertionOrder($wheres[$i]['query']->wheres, $collections) as [$sql, $bindings]) {
                $expressions[] = $rank
                    ? ["CASE WHEN {$rank[0]} = {$i} THEN {$sql} END", [...$rank[1], ...$bindings]]
                    : [$sql, $bindings];
            }
        }

        return $expressions;
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function compileWhere(array $where, array $collections): array
    {
        if ($where['type'] === 'Nested') {
            [$sql, $bindings] = $this->fold($this->conditions($where['query']->wheres, $collections));

            return [$sql ?? '1', $bindings];
        }

        foreach ($collections as $collection) {
            $this->entriesStore($collection)->ensureField($where['column']);
        }

        if ($where['type'] === 'Column') {
            foreach ($collections as $collection) {
                $this->entriesStore($collection)->ensureField($where['value']);
            }

            return [
                'e.key IN (SELECT a.key FROM entry_values a JOIN entry_values b ON b.key = a.key AND b.field = ? WHERE a.field = ? AND stache_compare(a.php, b.php, ?))',
                [$where['value'], $where['column'], $where['operator']],
            ];
        }

        $bindings = [];

        $condition = match ($where['type']) {
            'Basic' => $this->compileBasic($where['operator'], $where['value'], $bindings),
            'In' => $this->compileIn($where['values'], $bindings, false),
            'NotIn' => $this->compileIn($where['values'], $bindings, true),
            'Null' => "type = 'null'",
            'NotNull' => "type <> 'null'",
            default => false,
        };

        if ($condition === false) {
            // Left to the Stache's own filter for this kind of clause.
            $condition = 'stache_match(php, ?)';
            $bindings = [$this->filterIds[] = PhpFilter::register($where)];
        }

        return ["e.key IN (SELECT key FROM entry_values WHERE field = ? AND {$condition})", [$where['column'], ...$bindings]];
    }

    private function compileBasic(string $operator, $value, array &$bindings): string|false
    {
        if (is_array($value) || (is_object($value) && ! $value instanceof DateTimeInterface)) {
            return false;
        }

        $encoder = app(ValueEncoder::class);

        if ($operator === '=') {
            if ($value instanceof DateTimeInterface) {
                // Two dates are equal when they're the same moment, however they're displayed.
                // Anything else is compared as a string, as the Stache does.
                $bindings[] = (float) $value->format('U.u');
                $bindings[] = $encoder->eq($value);

                return "((type = 'date' AND ABS(num - ?) < 0.0000005) OR (type <> 'date' AND eq = ?))";
            }

            $bindings[] = $encoder->eq($value);

            return 'eq = ?';
        }

        if ($operator === '<>' || $operator === '!=') {
            // Strings compare case-insensitively; anything else must be identical.
            $bindings[] = $encoder->eq($value);

            $identical = match ($encoder->type($value)) {
                'null' => "type = 'null'",
                'bool' => "(type = 'bool' AND num = ".(int) $value.')',
                'int' => "(type = 'int' AND num = ".(int) $value.')',
                'float' => "(type = 'float' AND num = ".(float) $value.')',
                default => '0',
            };

            return "((type = 'string' AND eq <> ?) OR (type <> 'string' AND NOT {$identical}))";
        }

        if (in_array($operator, self::RANGE_OPERATORS)) {
            return $this->compileRange($operator, $value, $bindings);
        }

        return false;
    }

    /**
     * PHP's comparison rules, by the type of the stored value.
     */
    private function compileRange(string $operator, $value, array &$bindings): string|false
    {
        $outcome = fn ($probe) => $this->compare($probe, $operator, $value) ? '1' : '0';

        $parts = [
            "(type = 'null' AND {$outcome(null)})",
            "(type = 'bool' AND num = 1 AND {$outcome(true)})",
            "(type = 'bool' AND num = 0 AND {$outcome(false)})",
            "(type = 'array' AND {$outcome([1])})",
        ];

        if ($value instanceof DateTimeInterface) {
            $parts[] = "(type = 'date' AND num {$operator} ?)";
            $bindings[] = (float) $value->format('U.u');
            $parts[] = "(type = 'string' AND raw {$operator} ?)";
            $bindings[] = (string) $value;
        } elseif (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $parts[] = "(type IN ('int', 'float') AND num {$operator} ?)";
            $bindings[] = (float) $value;
            $parts[] = "(type = 'string' AND num IS NOT NULL AND num {$operator} ?)";
            $bindings[] = (float) $value;
            $parts[] = "(type = 'string' AND num IS NULL AND raw {$operator} ?)";
            $bindings[] = (string) $value;
        } elseif (is_string($value)) {
            $parts[] = "(type IN ('int', 'float', 'string', 'date') AND raw {$operator} ?)";
            $bindings[] = $value;
        } else {
            // Comparing with null or a boolean follows different rules per type.
            return false;
        }

        return '('.implode(' OR ', $parts).')';
    }

    private function compare($a, string $operator, $b): bool
    {
        return match ($operator) {
            '<' => $a < $b,
            '>' => $a > $b,
            '<=' => $a <= $b,
            '>=' => $a >= $b,
        };
    }

    private function compileIn(array $values, array &$bindings, bool $not): string|false
    {
        $encoder = app(ValueEncoder::class);
        $keys = [];

        foreach ($values as $value) {
            if (! in_array($encoder->type($value), ['string', 'int', 'null', 'bool'])) {
                return false;
            }

            $keys[] = $encoder->inkey($value);
        }

        // One binding however many values there are.
        $bindings[] = json_encode($keys);

        // Arrays (and anything else without a lookup key) are never "in".
        return $not
            ? '(inkey IS NULL OR inkey NOT IN (SELECT value FROM json_each(?)))'
            : 'inkey IN (SELECT value FROM json_each(?))';
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
                $groups[] = '(taxonomy = ? AND slug IN (SELECT value FROM json_each(?)))';
                array_push($groupBindings, $taxonomy, json_encode($slugs));
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
