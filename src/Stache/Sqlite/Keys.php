<?php

namespace Statamic\Stache\Sqlite;

use Statamic\Stache\Stores\Keys as StacheKeys;

/**
 * The keys a collection's entries have, and the files they came from, for spotting
 * duplicates. Every entry's row holds its key and path, so they're read from there
 * rather than kept as a list of their own.
 */
class Keys extends StacheKeys
{
    public function __construct(CollectionEntriesStore $store)
    {
        parent::__construct($store);

        $this->load();
    }

    public function load()
    {
        $prefix = strlen($this->store->childKey()) + 2;
        $this->keys = [];

        foreach (app(Database::class)->run('SELECT key, path FROM entries WHERE collection = ?', [$this->store->childKey()]) as $row) {
            $this->keys[substr($row['key'], $prefix)] = $row['path'];
        }

        return $this;
    }

    public function cache()
    {
        // The rows are the list.
    }

    public function clear()
    {
        $this->keys = [];
    }
}
