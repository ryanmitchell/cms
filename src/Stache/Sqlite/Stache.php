<?php

namespace Statamic\Stache\Sqlite;

use Statamic\Stache\Stache as BaseStache;

class Stache extends BaseStache
{
    /**
     * Warming terms reads the entries, so the whole warm is one bulk load: into empty
     * tables, the rows go in first and the indexes are made once at the end.
     */
    public function warm()
    {
        app(Database::class)->bulkLoad(fn () => parent::warm());
    }
}
