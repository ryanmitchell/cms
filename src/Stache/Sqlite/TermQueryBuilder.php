<?php

namespace Statamic\Stache\Sqlite;

use Statamic\Facades;
use Statamic\Facades\Collection;
use Statamic\Facades\Stache;
use Statamic\Stache\Query\TermQueryBuilder as StacheTermQueryBuilder;
use Statamic\Stache\Sqlite\Concerns\CompilesClauses;
use Statamic\Support\Arr;

/**
 * Finds terms with one SQL query, with the same results as the Stache's term query
 * builder, including the order of terms that aren't sorted: by taxonomy, then in
 * the order the taxonomy's terms are kept.
 */
class TermQueryBuilder extends StacheTermQueryBuilder
{
    use CompilesClauses;

    protected function valuesTable(): string
    {
        return 'term_values';
    }

    protected function scopeStore(string $scope)
    {
        return $this->store->store($scope);
    }

    public function get($columns = ['*'])
    {
        $items = $this->onceWithColumns(Arr::wrap($columns), fn () => $this->withFakeQueryLogging(function () {
            $keys = collect($this->findById() ?? $this->fetch($this->compile('e.key', order: true, limit: true)));

            $items = $this->getItems($keys);

            $items->each(fn ($item) => $item
                ->selectedQueryColumns($this->columns)
                ->selectedQueryRelations($this->with));

            return $this->collect($items)->values();
        }));

        // As the Stache does: one collection queried (or given as context) scopes the terms' urls.
        $collection = $this->collections && count($this->collections) == 1
            ? $this->collections[0]
            : $this->collectionContext;

        if ($collection) {
            $items->each->collection(Collection::findByHandle($collection));
        }

        return $items;
    }

    public function count()
    {
        [$sql, $bindings] = $this->compile('e.key', order: false, limit: true);

        return (int) $this->fetch(['SELECT COUNT(*) FROM ('.$sql.')', $bindings])[0];
    }

    protected function getCountForPagination()
    {
        return (int) $this->fetch($this->compile('COUNT(*)', order: false, limit: false))[0];
    }

    public function pluck($column, $key = null)
    {
        return $this->onceWithColumns(array_filter([$column, $key]), function () use ($column, $key) {
            return $this->withFakeQueryLogging(function () use ($column, $key) {
                $keys = $this->fetch($this->compile('e.key', order: true, limit: true));

                return collect($keys)
                    ->map(fn ($rowKey) => explode('::', $rowKey, 2))
                    ->groupBy(0)
                    ->flatMap(function ($rows, $taxonomy) use ($column, $key) {
                        $ids = $rows->pluck(1);

                        if ($column === 'id' && ! $key) {
                            return $ids;
                        }

                        $values = $this->valuesFor($taxonomy, $column, $ids);

                        if (! $key) {
                            return collect($values)->values();
                        }

                        $keyValues = $this->valuesFor($taxonomy, $key, $ids);

                        return $ids->mapWithKeys(fn ($id) => [$keyValues[$id] => $values[$id]]);
                    });
            });
        });
    }

    public function getWhereColumnKeysFromStore($store, $where)
    {
        $this->scopeStore($store)->ensureField($where['column']);

        return collect($this->db()->select(
            'SELECT v.key, v.type, v.raw, v.num, v.php FROM term_values v JOIN terms e ON e.key = v.key WHERE v.field = ? AND v.taxonomy = ? ORDER BY e.seq',
            [$where['column'], $store]
        ))->mapWithKeys(fn ($row) => [$row['key'] => PhpFilter::value($row['type'], $row['raw'], $row['num'], $row['php'])]);
    }

    private function valuesFor(string $taxonomy, string $field, $keys): array
    {
        $this->scopeStore($taxonomy)->ensureField($field);

        $bindings = [$field];
        $list = $this->inList(collect($keys)->map(fn ($key) => "{$taxonomy}::{$key}")->values()->all(), $bindings);

        $rows = $this->db()->select(
            "SELECT v.key, v.type, v.raw, v.num, v.php FROM term_values v JOIN terms e ON e.key = v.key WHERE v.field = ? AND v.key IN {$list} ORDER BY e.seq",
            $bindings
        );

        $prefix = strlen($taxonomy) + 2;
        $values = [];

        foreach ($rows as $row) {
            $values[substr($row['key'], $prefix)] = PhpFilter::value($row['type'], $row['raw'], $row['num'], $row['php']);
        }

        return $values;
    }

    private function taxonomies(): array
    {
        return array_values(array_unique(empty($this->taxonomies)
            ? Facades\Taxonomy::handles()->all()
            : $this->taxonomies));
    }

    /**
     * Term::find() looks a term up by its id (taxonomy::slug), in every site.
     */
    private function findById(): ?array
    {
        if (count($this->wheres) !== 1 || $this->collections || $this->orderBys || $this->randomize || $this->limit !== null || $this->offset) {
            return null;
        }

        $where = reset($this->wheres);

        if ($where['type'] !== 'Basic' || $where['column'] !== 'id' || $where['operator'] !== '=' || ! is_string($where['value']) || ! str_contains($where['value'], '::')) {
            return null;
        }

        [$taxonomy, $slug] = explode('::', $where['value'], 2);

        if (! in_array($taxonomy, $this->taxonomies())) {
            return null;
        }

        $store = $this->scopeStore($taxonomy);
        $store->handleFileChanges();
        $store->ensureTerms();

        $keys = $this->db()->run('SELECT key FROM terms WHERE taxonomy = ? AND slug = ? ORDER BY seq', [$taxonomy, $slug])->fetchAll(\PDO::FETCH_COLUMN);

        return $keys ?: null;
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function compile(string $select, bool $order, bool $limit): array
    {
        $taxonomies = $this->taxonomies();

        if (empty($taxonomies)) {
            return ["SELECT {$select} FROM terms e WHERE 0", []];
        }

        foreach ($taxonomies as $taxonomy) {
            $store = $this->scopeStore($taxonomy);
            $store->handleFileChanges();
            $store->ensureTerms();
        }

        [$where, $whereBindings] = $this->fold($this->conditions($this->wheres, $taxonomies));

        // Terms used by entries in the queried collections, in any site.
        $usage = null;
        $usageBindings = [];

        if ($this->collections) {
            foreach ($this->collections as $collection) {
                Stache::store('entries')->store($collection)->ensureField('id');
            }

            $usage = 'EXISTS (SELECT 1 FROM entry_terms u WHERE u.taxonomy = e.taxonomy AND u.slug = e.slug AND u.collection IN '.$this->inList($this->collections, $usageBindings).')';
        }

        $joins = $orders = $joinBindings = $orderBindings = [];

        if ($order && $this->randomize) {
            $orders[] = 'RANDOM()';
        } elseif ($order) {
            foreach (array_values($this->orderBys) as $i => $orderBy) {
                $numeric = true;

                foreach ($taxonomies as $taxonomy) {
                    $store = $this->scopeStore($taxonomy);
                    $store->ensureField($orderBy->sort);
                    $numeric = $numeric && $store->populatedFields()[$orderBy->sort];
                }

                $joins[] = "LEFT JOIN term_values o{$i} ON o{$i}.field = ? AND o{$i}.key = e.key";
                $joinBindings[] = $orderBy->sort;

                $direction = strtolower($orderBy->direction) === 'desc' ? 'DESC' : 'ASC';
                $orders[] = "COALESCE(o{$i}.snull, 1) {$direction}";
                $orders[] = 'o'.$i.($numeric ? '.snum ' : '.skey ').$direction;
            }

            if (empty($this->orderBys)) {
                foreach ($this->insertionOrder($this->wheres, $taxonomies) as [$expression, $bindings]) {
                    $orders[] = $expression;
                    array_push($orderBindings, ...$bindings);
                }
            }

            $orders[] = 'CASE e.taxonomy '
                .implode(' ', array_map(fn ($i) => "WHEN ? THEN {$i}", array_keys($taxonomies)))
                .' END';
            array_push($orderBindings, ...$taxonomies);
            $orders[] = 'e.seq';
        }

        $placeholders = implode(', ', array_fill(0, count($taxonomies), '?'));

        $sql = "SELECT {$select} FROM terms e ".implode(' ', $joins)
            ." WHERE e.taxonomy IN ({$placeholders})"
            .($where ? " AND {$where}" : '')
            .($usage ? " AND {$usage}" : '')
            .($orders ? ' ORDER BY '.implode(', ', $orders) : '');

        $limitBindings = [];

        if ($limit && ($this->limit !== null || $this->offset)) {
            $sql .= ' LIMIT ? OFFSET ?';
            $limitBindings = [$this->limit ?? -1, (int) $this->offset];
        }

        return [$sql, [...$joinBindings, ...$taxonomies, ...$whereBindings, ...$usageBindings, ...$orderBindings, ...$limitBindings]];
    }
}
