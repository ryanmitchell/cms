<?php

namespace Statamic\Stache\Sqlite;

use DateTimeInterface;
use Statamic\Facades;
use Statamic\Stache\Query\EntryQueryBuilder as StacheEntryQueryBuilder;
use Statamic\Support\Arr;

/**
 * Finds entries with one SQL query instead of filtering and sorting every
 * entry's index values in PHP. It gives the same results as the Stache's
 * query builder: the same comparisons, the same left-to-right and/or, and
 * the same order, ties included. Anything it can't express in SQL (like,
 * JSON and date-part clauses, random order) is left to the Stache's builder.
 */
class EntryQueryBuilder extends StacheEntryQueryBuilder
{
    private const RANGE_OPERATORS = ['<', '>', '<=', '>='];

    private const MAX_BINDINGS = 30000;

    private function db(): Database
    {
        return app(Database::class);
    }

    public function get($columns = ['*'])
    {
        if (! $compiled = $this->compile('e.key', order: true, limit: true)) {
            return parent::get($columns);
        }

        return $this->onceWithColumns(Arr::wrap($columns), fn () => $this->withFakeQueryLogging(function () use ($compiled) {
            $keys = collect($this->db()->run(...$compiled)->fetchAll(\PDO::FETCH_COLUMN));

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
        if (! $compiled = $this->compile('e.key', order: false, limit: true)) {
            return parent::count();
        }

        return (int) $this->db()->run('SELECT COUNT(*) FROM ('.$compiled[0].')', $compiled[1])->fetchColumn();
    }

    protected function getCountForPagination()
    {
        if (! $compiled = $this->compile('COUNT(*)', order: false, limit: false)) {
            return parent::getCountForPagination();
        }

        return (int) $this->db()->run(...$compiled)->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array}|null
     */
    private function compile(string $select, bool $order, bool $limit): ?array
    {
        if ($this->randomize) {
            return null;
        }

        // Without an order, the Stache returns an "or" clause's extra matches after the
        // ones before it, rather than in file order. Leave those queries to the Stache.
        if ($order && empty($this->orderBys) && $this->hasOrWhere($this->wheres)) {
            return null;
        }

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

        $whereBindings = [];

        if (false === $where = $this->compileWheres($this->wheres, $collections, $whereBindings)) {
            return null;
        }

        if (false === $taxonomies = $this->compileTaxonomyWheres($whereBindings)) {
            return null;
        }

        $joins = $orders = $joinBindings = $orderBindings = [];

        if ($order) {
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
        }

        $placeholders = implode(', ', array_fill(0, count($collections), '?'));

        $sql = "SELECT {$select} FROM entries e ".implode(' ', $joins)
            ." WHERE e.collection IN ({$placeholders})"
            .($where ? " AND {$where}" : '')
            .($taxonomies ? " AND {$taxonomies}" : '');

        if ($order) {
            // Ties keep the order the Stache would have them in: by collection as
            // queried, then in the order of the collection's files.
            $orders[] = 'CASE e.collection '
                .implode(' ', array_map(fn ($i) => "WHEN ? THEN {$i}", array_keys($collections)))
                .' END';
            $orderBindings = $collections;
            $orders[] = 'e.seq';
            $sql .= ' ORDER BY '.implode(', ', $orders);
        }

        $limitBindings = [];

        if ($limit && ($this->limit !== null || $this->offset)) {
            $sql .= ' LIMIT ? OFFSET ?';
            $limitBindings = [$this->limit ?? -1, (int) $this->offset];
        }

        $bindings = [...$joinBindings, ...$collections, ...$whereBindings, ...$orderBindings, ...$limitBindings];

        return count($bindings) > self::MAX_BINDINGS ? null : [$sql, $bindings];
    }

    private function hasOrWhere(array $wheres): bool
    {
        foreach ($wheres as $where) {
            if ($where['boolean'] === 'or' || ($where['type'] === 'Nested' && $this->hasOrWhere($where['query']->wheres))) {
                return true;
            }
        }

        return false;
    }

    private function entriesStore(string $collection): CollectionEntriesStore
    {
        return $this->store->store($collection);
    }

    /**
     * Folds the clauses left to right, like intersectKeysFromWhereClause().
     */
    private function compileWheres(array $wheres, array $collections, array &$bindings): string|false|null
    {
        $sql = null;

        foreach ($wheres as $where) {
            if (false === $condition = $this->compileWhere($where, $collections, $bindings)) {
                return false;
            }

            if ($sql === null) {
                $sql = $condition;
            } elseif ($where['boolean'] === 'or' && $where['type'] !== 'NotIn') {
                $sql = "({$sql} OR {$condition})";
            } else {
                $sql = "({$sql} AND {$condition})";
            }
        }

        return $sql;
    }

    private function compileWhere(array $where, array $collections, array &$bindings): string|false
    {
        if ($where['type'] === 'Nested') {
            $sql = $this->compileWheres($where['query']->wheres, $collections, $bindings);

            return $sql === null ? '1' : $sql;
        }

        if (! in_array($where['type'], ['Basic', 'In', 'NotIn', 'Null', 'NotNull'])) {
            return false;
        }

        $conditionBindings = [];

        $condition = match ($where['type']) {
            'Basic' => $this->compileBasic($where['operator'], $where['value'], $conditionBindings),
            'In' => $this->compileIn($where['values'], $conditionBindings, false),
            'NotIn' => $this->compileIn($where['values'], $conditionBindings, true),
            'Null' => "type = 'null'",
            'NotNull' => "type <> 'null'",
        };

        if ($condition === false) {
            return false;
        }

        foreach ($collections as $collection) {
            $this->entriesStore($collection)->ensureField($where['column']);
        }

        $bindings[] = $where['column'];
        array_push($bindings, ...$conditionBindings);

        return "e.key IN (SELECT key FROM entry_values WHERE field = ? AND {$condition})";
    }

    private function compileBasic(string $operator, $value, array &$bindings): string|false
    {
        if (is_array($value) || (is_object($value) && ! $value instanceof DateTimeInterface)) {
            return false;
        }

        $encoder = app(ValueEncoder::class);

        if ($operator === '=') {
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
            // Comparing with null or a boolean follows different rules per type. It's
            // rare enough to leave to the Stache.
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

        if (empty($keys)) {
            return $not ? '1' : '0';
        }

        array_push($bindings, ...$keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        // Arrays (and anything else without a lookup key) are never "in".
        return $not
            ? "(inkey IS NULL OR inkey NOT IN ({$placeholders}))"
            : "inkey IN ({$placeholders})";
    }

    /**
     * The same as addTaxonomyWheres(), using the entry_terms table instead of
     * reading the terms stores' associations and querying by every entry id.
     */
    private function compileTaxonomyWheres(array &$bindings): string|false|null
    {
        $included = $excluded = [];

        foreach ($this->taxonomyWheres as $where) {
            $terms = $where['type'] === 'Basic' ? [$where['value']] : $where['values'];

            $byTaxonomy = [];

            foreach ($terms as $term) {
                if (! is_string($term) || ! str_contains($term, '::')) {
                    return false;
                }

                [$taxonomy, $slug] = explode('::', $term, 2);
                $byTaxonomy[$taxonomy][] = $slug;
            }

            $groups = [];
            $groupBindings = [];

            foreach ($byTaxonomy as $taxonomy => $slugs) {
                $groups[] = '(taxonomy = ? AND slug IN ('.implode(', ', array_fill(0, count($slugs), '?')).'))';
                array_push($groupBindings, $taxonomy, ...$slugs);
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

        return $sql ? implode(' AND ', $sql) : null;
    }
}
