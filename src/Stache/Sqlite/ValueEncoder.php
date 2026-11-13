<?php

namespace Statamic\Stache\Sqlite;

use Carbon\Carbon;
use Collator;
use DateTimeInterface;
use Statamic\Facades\Site;

/**
 * Turns a value from a Stache index into the columns of an `entry_values` row,
 * so SQL can reproduce the comparisons the Stache makes in PHP:
 *
 *  - eq:    what `=` compares: strtolower((string) $value)
 *  - inkey: what `whereIn` compares (Builder::normalizeLookupValue)
 *  - raw:   the value as a string, for PHP's string comparisons
 *  - num:   the value as a number, for PHP's numeric comparisons
 *  - snull, snum, skey: how DataCollection::multisort orders it. Strings use the
 *    same ICU collator as the Comparator, as a binary sort key.
 */
class ValueEncoder
{
    private ?Collator $collator = null;

    public function encode($value): array
    {
        $type = $this->type($value);

        [$snull, $snum, $skey] = $this->sortable($value);

        return [
            'type' => $type,
            'eq' => $this->eq($value, $type),
            'inkey' => $this->inkey($value, $type),
            'raw' => match ($type) {
                'string' => $value,
                'int', 'float', 'date' => (string) $value,
                default => null,
            },
            'num' => match ($type) {
                'int', 'float' => $value,
                'bool' => (int) $value,
                'string' => is_numeric($value) ? (float) $value : null,
                'date' => (float) $value->format('U.u'),
                default => null,
            },
            'snull' => $snull,
            'snum' => $snum,
            'skey' => $skey,
        ];
    }

    public function type($value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            $value instanceof DateTimeInterface => 'date',
            is_array($value) => 'array',
            default => 'object',
        };
    }

    public function eq($value, ?string $type = null): ?string
    {
        $type ??= $this->type($value);

        if ($type === 'array') {
            return null;
        }

        if ($type === 'object') {
            return method_exists($value, '__toString') ? strtolower((string) $value) : null;
        }

        return strtolower((string) ($value ?? ''));
    }

    public function inkey($value, ?string $type = null): ?string
    {
        return match ($type ?? $this->type($value)) {
            'string' => strtolower($value),
            'null' => '__NULL__',
            'bool' => $value ? '__TRUE__' : '__FALSE__',
            'int' => (string) $value,
            'float' => (string) (int) $value,
            default => null,
        };
    }

    /**
     * Mirrors DataCollection::normalizeSortableValue() and Comparator::values().
     */
    private function sortable($value): array
    {
        while (is_array($value)) {
            $value = count($value) ? array_values($value)[0] : null;
        }

        if ($value instanceof Carbon) {
            $value = $value->timestamp;
        }

        if ($value === null) {
            return [1, null, null];
        }

        if (is_bool($value)) {
            return [0, (int) $value, null];
        }

        if (is_object($value)) {
            return [0, null, null];
        }

        return [
            0,
            is_numeric($value) ? (float) $value : null,
            $this->collator()->getSortKey(mb_strtolower((string) $value)),
        ];
    }

    public function isNumericSortable($value): bool
    {
        [$snull, $snum] = $this->sortable($value);

        return $snull === 1 || $snum !== null;
    }

    /**
     * The locale the Comparator sorts strings in, so sort keys order the same way.
     */
    private function locale(): string
    {
        try {
            $comparator = \Statamic\Facades\Compare::getFacadeRoot();

            return (fn () => $this->locale)->call($comparator);
        } catch (\Throwable) {
            try {
                return Site::default()->locale();
            } catch (\Throwable) {
                return config('app.locale', 'en');
            }
        }
    }

    private function collator(): Collator
    {
        if ($this->collator) {
            return $this->collator;
        }

        $collator = new Collator($this->locale());
        $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);

        return $this->collator = $collator;
    }
}
