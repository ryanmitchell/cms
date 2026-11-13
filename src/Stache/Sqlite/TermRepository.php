<?php

namespace Statamic\Stache\Sqlite;

use Statamic\Contracts\Taxonomies\Term;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Stache\Repositories\TermRepository as StacheTermRepository;
use Statamic\Taxonomies\LocalizedTerm;

class TermRepository extends StacheTermRepository
{
    public function query()
    {
        // Terms are queried from the database, so there are no associations to load first.
        return new TermQueryBuilder($this->store);
    }

    /**
     * The entries that use a term: the same associations the Stache counts, matched
     * by the value as entries have it, counted in one query.
     */
    public function entriesCount(Term $term, ?string $status = null): int
    {
        $taxonomy = $term->taxonomyHandle();

        // Working out the taxonomy's terms loads the entries that use them, and is only
        // done once: looking up its collections for every count was most of the cost.
        Stache::store('terms')->store($taxonomy)->ensureTerms();

        $sql = 'SELECT id FROM entry_terms WHERE taxonomy = ? AND value_key = ?';
        $bindings = [$taxonomy, (string) $term->inDefaultLocale()->slug()];

        if ($term instanceof LocalizedTerm) {
            $sql .= ' AND site = ?';
            $bindings[] = $term->locale();
        }

        if ($collection = $term->collection()) {
            $sql .= ' AND collection = ?';
            $bindings[] = $collection->handle();
        }

        $ids = app(Database::class)->run($sql, $bindings)->fetchAll(\PDO::FETCH_COLUMN);

        if ($status) {
            return Entry::query()
                ->whereIn('id', $ids)
                ->when($collection, fn ($query) => $query->where('collection', $collection->handle()))
                ->whereStatus($status)
                ->count();
        }

        return count($ids);
    }
}
