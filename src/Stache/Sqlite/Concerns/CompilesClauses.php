<?php

namespace Statamic\Stache\Sqlite\Concerns;

use DateTimeInterface;
use PDO;
use Statamic\Stache\Sqlite\Database;
use Statamic\Stache\Sqlite\PhpFilter;
use Statamic\Stache\Sqlite\ValueEncoder;

/**
 * Compiles the Stache query builder's clauses into SQL over a store's values,
 * with the same comparisons, the same left-to-right and/or, and the same order.
 * The items being queried are aliased "e", and their values are in valuesTable().
 * Clauses SQL can't express with the same results call the Stache's own filters.
 */
trait CompilesClauses
{
    private const RANGE_OPERATORS = ['<', '>', '<=', '>='];

    private array $filterIds = [];

    abstract protected function valuesTable(): string;

    /**
     * The store for one collection, taxonomy, etc. that the query covers.
     */
    abstract protected function scopeStore(string $scope);

    protected function db(): Database
    {
        return app(Database::class);
    }

    /**
     * The query's first column. Rows are fetched before the clauses' registered filters
     * are forgotten, since SQLite runs them as it steps through the rows.
     */
    protected function fetch(array $compiled): array
    {
        try {
            return $this->db()->run(...$compiled)->fetchAll(PDO::FETCH_COLUMN);
        } finally {
            PhpFilter::forget($this->filterIds);
            $this->filterIds = [];
        }
    }

    /**
     * Each clause as SQL, with its bindings.
     *
     * @return array<int, array{where: array, sql: string, bindings: array}>
     */
    protected function conditions(array $wheres, array $collections): array
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
    protected function fold(array $conditions, ?int $count = null): array
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
    protected function insertionOrder(array $wheres, array $collections): array
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
    protected function compileWhere(array $where, array $collections): array
    {
        if ($where['type'] === 'Nested') {
            [$sql, $bindings] = $this->fold($this->conditions($where['query']->wheres, $collections));

            return [$sql ?? '1', $bindings];
        }

        foreach ($collections as $collection) {
            $this->scopeStore($collection)->ensureField($where['column']);
        }

        if ($where['type'] === 'Column') {
            foreach ($collections as $collection) {
                $this->scopeStore($collection)->ensureField($where['value']);
            }

            return [
                "e.key IN (SELECT a.key FROM {$this->valuesTable()} a JOIN {$this->valuesTable()} b ON b.key = a.key AND b.field = ? WHERE a.field = ? AND stache_compare(a.type, a.raw, a.num, a.php, b.type, b.raw, b.num, b.php, ?))",
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
            $condition = 'stache_match(type, raw, num, php, ?)';
            $bindings = [$this->filterIds[] = PhpFilter::register($where)];
        }

        return ["e.key IN (SELECT key FROM {$this->valuesTable()} WHERE field = ? AND {$condition})", [$where['column'], ...$bindings]];
    }

    protected function compileBasic(string $operator, $value, array &$bindings): string|false
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
    protected function compileRange(string $operator, $value, array &$bindings): string|false
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

    protected function compare($a, string $operator, $b): bool
    {
        return match ($operator) {
            '<' => $a < $b,
            '>' => $a > $b,
            '<=' => $a <= $b,
            '>=' => $a >= $b,
        };
    }

    protected function compileIn(array $values, array &$bindings, bool $not): string|false
    {
        $encoder = app(ValueEncoder::class);
        $keys = [];

        foreach ($values as $value) {
            if (! in_array($encoder->type($value), ['string', 'int', 'null', 'bool'])) {
                return false;
            }

            $keys[] = $encoder->inkey($value);
        }

        if (! $keys) {
            return $not ? '1' : '0';
        }

        $list = $this->inList($keys, $bindings);

        // Arrays (and anything else without a lookup key) are never "in".
        return $not
            ? "(inkey IS NULL OR inkey NOT IN {$list})"
            : "inkey IN {$list}";
    }

    /**
     * A list for IN. A binding per value is quickest, but SQLite allows only so many,
     * so a longer list is passed as one JSON array instead.
     */
    protected function inList(array $values, array &$bindings): string
    {
        if (count($values) > 10000) {
            $bindings[] = json_encode(array_values($values));

            return '(SELECT value FROM json_each(?))';
        }

        if (! $values) {
            return '(NULL)';
        }

        array_push($bindings, ...array_values($values));

        return '('.implode(', ', array_fill(0, count($values), '?')).')';
    }
}
