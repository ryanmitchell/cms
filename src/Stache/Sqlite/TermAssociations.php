<?php

namespace Statamic\Stache\Sqlite;

use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Stache\Indexes\Terms\Associations;

/**
 * A taxonomy's associations, read from the entry_terms table that the SQLite
 * entries stores keep, instead of querying and loading every entry that uses
 * the taxonomy. The rows, and their order, are the same as the Stache's.
 */
class TermAssociations extends Associations
{
    public function getItems()
    {
        $taxonomy = Taxonomy::findByHandle($handle = $this->store->childKey());
        $collections = $taxonomy->collections()->map->handle()->values()->all();

        if (! $collections) {
            return [];
        }

        $entries = Stache::store('entries');

        foreach ($collections as $collection) {
            // Makes sure every entry in the collection has its row, and its terms.
            $entries->store($collection)->ensureField('id');
        }

        $placeholders = implode(', ', array_fill(0, count($collections), '?'));
        $order = implode(' ', array_map(fn ($i) => "WHEN ? THEN {$i}", array_keys($collections)));

        $rows = app(Database::class)->select(
            "SELECT t.value, t.slug, t.id, t.collection, t.site
            FROM entry_terms t JOIN entries e ON e.key = t.key
            WHERE t.taxonomy = ? AND t.collection IN ({$placeholders})
            ORDER BY CASE t.collection {$order} END, e.seq, t.pos",
            [$handle, ...$collections, ...$collections]
        );

        return array_map(fn ($row) => [
            'value' => json_decode($row['value'], true),
            'slug' => $row['slug'],
            'entry' => $row['id'],
            'collection' => $row['collection'],
            'site' => $row['site'],
        ], $rows);
    }
}
