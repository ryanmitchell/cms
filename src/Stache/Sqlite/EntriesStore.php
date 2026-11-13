<?php

namespace Statamic\Stache\Sqlite;

use Statamic\Stache\Stores\EntriesStore as StacheEntriesStore;

class EntriesStore extends StacheEntriesStore
{
    public function warm()
    {
        app(Database::class)->bulkLoad(fn () => parent::warm());
    }

    /**
     * Clearing every collection deletes the database, rather than each collection
     * deleting its own rows. It's only a cache, and it's made again when next used.
     */
    public function clear()
    {
        app(Database::class)->delete();

        CollectionEntriesStore::$tablesCleared = true;

        try {
            parent::clear();
        } finally {
            CollectionEntriesStore::$tablesCleared = false;
        }
    }
}
