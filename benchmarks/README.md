# Stache benchmarks

Benchmarks for the Stache, on generated sites, with each run in a fresh PHP process
against a warm cache, like a production request.

## Quick start

```bash
# Make the sites (any size: 500, 1k, 10k, 50k...). Each is a copy of the Testbench
# skeleton with its own content, templates and cache, in benchmarks/.build/<size>.
php benchmarks/generate.php 1k
php benchmarks/generate.php 10k

# Run every scenario against the code checked out, five times each.
php benchmarks/run.php --sizes=1k,10k --label=before

# ...change the code, then run again and compare.
php benchmarks/run.php --sizes=1k,10k --label=after
php benchmarks/compare.php before after
```

`php benchmarks/scenario.php <size> <scenario> --dump` runs one scenario once and
prints its result, which is useful for profiling and for checking results by eye.

## Comparing the Stache with the SQLite Stache

The SQLite spike is on the `perf/stache-sqlite` branch, behind
`statamic.stache.sqlite.enabled`. `ab.php` runs both on the same code,
alternating between them so both see the same machine conditions:

```bash
git checkout perf/stache-sqlite
git checkout perf/stache-benchmarks -- benchmarks && git reset -q benchmarks

php benchmarks/generate.php 1k
php benchmarks/ab.php 1k                       # every scenario, 5 rounds
php benchmarks/ab.php 50k warm,refresh 3       # some scenarios, 3 rounds
```

It prints the median for each backend, the change, how many rounds SQLite won,
peak memory, and whether both returned the same results. The SQLite runs use their
own cache store (`file-alt`), so neither backend's runs change what the other finds.

## Scenarios

| Group | Scenarios |
| --- | --- |
| Cache build | `warm` (clear, then build), `refresh` |
| Control Panel | `cp-entries`, `cp-entries-sorted`, `cp-entries-filtered`, `cp-terms`, `save-entry` |
| Frontend | `render-home`, `render-article`, `render-tag`, `render-page` (full requests through Antlers) |
| Queries | `first-query`, `find-by-id`, `find-by-uri`, `latest-articles`, `deep-pagination`, `taxonomy-popular`, `taxonomy-median`, `taxonomy-in-and-field`, `custom-fields`, `custom-field-cold`, `term-counts`, `page-tree` |

Every scenario's result is checksummed (ids, counts, rendered HTML, listing JSON), so
two backends or two branches can be checked for returning the same data, not only
for speed. A request that returns an error fails the run rather than being timed.

`custom-field-cold` clears a Stache index on purpose, so it isn't a like-for-like
comparison with a backend that keeps every field indexed.

## Options

`run.php` and `scenario.php` take `--config=key=value` (any config value, decoded as
JSON where it can be), `--provider=Class` (an extra service provider, registered after
Statamic's), and `--clear-command`, `--warm-command`, `--refresh-command`, so another
backend can be measured without changing the scenarios.

## Caveats

- Times are only comparable on the same machine; take the changes, not the times.
- Runs on one machine drift by up to about 10%, so compare alternating runs
  (`ab.php`), or runs made close together, rather than runs from different days.
- `warm` builds only the default indexes, since clearing forgets which have been used.
- `save-entry` saves one article unchanged, so it rewrites that file in the site.
