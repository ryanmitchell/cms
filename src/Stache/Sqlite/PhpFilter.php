<?php

namespace Statamic\Stache\Sqlite;

use Statamic\Stache\Query\EntryQueryBuilder as StacheEntryQueryBuilder;

/**
 * Runs the Stache query builder's own filters on a single value, for SQLite to
 * call on clauses it can't express itself (like, regex, date parts, JSON, between,
 * and comparisons PHP makes by its own rules). Using the Stache's filters, rather
 * than reimplementing them in SQL, means they give exactly the same answers.
 */
class PhpFilter extends StacheEntryQueryBuilder
{
    private static array $registered = [];

    private static int $next = 0;

    public function __construct()
    {
        // No store: only the filters are used.
    }

    public static function register(array $where): int
    {
        self::$registered[$id = ++self::$next] = $where;

        return $id;
    }

    public static function forget(array $ids): void
    {
        foreach ($ids as $id) {
            unset(self::$registered[$id]);
        }
    }

    public function matches(array $value, int $id): bool
    {
        $where = self::$registered[$id];
        $method = 'filterWhere'.$where['type'];

        return $this->{$method}(collect([static::value(...$value)]), $where)->isNotEmpty();
    }

    public function compare(array $a, array $b, string $operator): bool
    {
        $method = 'filterTest'.$this->operators[$operator];

        return (bool) $this->{$method}(static::value(...$a), static::value(...$b));
    }

    /**
     * The value an entry_values row was made from.
     */
    public static function value(string $type, $raw, $num, $php)
    {
        return match ($type) {
            'null' => null,
            'bool' => (bool) $num,
            'int' => (int) $raw,
            'float' => (float) $num,
            'string' => (string) $raw,
            default => unserialize($php),
        };
    }
}
