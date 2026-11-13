<?php

namespace Statamic\Stache\Sqlite;

use Statamic\Stache\Stores\Keys as StacheKeys;

/**
 * The keys a store's items have, and the files they came from, for spotting
 * duplicates. Every item's row holds its key and path, so they're read from there
 * rather than kept as a list of their own.
 */
class Keys extends StacheKeys
{
    public function __construct($store)
    {
        parent::__construct($store);

        $this->load();
    }

    public function load()
    {
        $this->keys = $this->store->keysWithPaths();

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
