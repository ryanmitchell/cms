<?php

/*
 * Benchmark scenarios.
 *
 * Each scenario runs in its own fresh PHP process against a warm persistent
 * cache (except "warm", which builds it), mirroring a production request.
 *
 *  - setup: untimed preparation in the same process.
 *  - run:   the timed part. Its return value is normalised into a checksum so
 *           two backends can be checked for returning the same results.
 *
 * Groups: "cache", "listing" and "frontend" are the gating metrics - a
 * candidate backend has to be faster on all of them. "query" scenarios are
 * diagnostics for working out where a difference comes from.
 *
 * Scenarios only use public Statamic APIs (facades, query builders,
 * repositories), so any backend that swaps those in can be measured.
 */

use Illuminate\Support\Facades\Artisan;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\Term;

$ids = fn ($items) => collect($items)->map(fn ($item) => $item?->id())->all();

return [

    'warm' => [
        'description' => 'Clear then fully build the cache (cold boot / deploy).',
        'group' => 'cache',
        'setup' => fn ($ctx) => Artisan::call($ctx['clear_command']),
        'run' => function ($ctx) {
            Artisan::call($ctx['warm_command']);

            return null;
        },
    ],

    'refresh' => [
        'description' => 'stache:refresh on a warm cache (clear + rebuild, e.g. after a deploy).',
        'group' => 'cache',
        'run' => function ($ctx) {
            Artisan::call($ctx['refresh_command']);

            return null;
        },
    ],

    // Control Panel listing views. These hit the JSON endpoints the listing
    // tables load from, as a logged-in super user.

    'cp-entries' => [
        'description' => 'CP articles listing, first page of 50, default sort (date desc).',
        'group' => 'listing',
        'setup' => fn ($ctx) => actAsBenchUser(),
        'run' => fn ($ctx) => $ctx['get']('/cp/collections/articles/entries?perPage=50&page=1', cpHeaders()),
    ],

    'cp-entries-sorted' => [
        'description' => 'CP articles listing sorted by title, page 10 of 50.',
        'group' => 'listing',
        'setup' => fn ($ctx) => actAsBenchUser(),
        'run' => fn ($ctx) => $ctx['get']('/cp/collections/articles/entries?perPage=50&page=10&sort=title&order=asc', cpHeaders()),
    ],

    'cp-entries-filtered' => [
        'description' => 'CP articles listing filtered by status and the author field.',
        'group' => 'listing',
        'setup' => fn ($ctx) => actAsBenchUser(),
        'run' => fn ($ctx) => $ctx['get']('/cp/collections/articles/entries?perPage=50&page=1&filters='.urlencode(base64_encode(json_encode([
            'status' => ['status' => 'published'],
            'fields' => ['author' => ['operator' => '=', 'value' => $ctx['samples']['author']]],
        ]))), cpHeaders()),
    ],

    'cp-terms' => [
        'description' => 'CP tags listing, first page of 50.',
        'group' => 'listing',
        'setup' => fn ($ctx) => actAsBenchUser(),
        'run' => fn ($ctx) => $ctx['get']('/cp/taxonomies/tags/terms?perPage=50&page=1', cpHeaders()),
    ],

    // Frontend renders: full requests through Antlers templates (no static caching).

    'render-home' => [
        'description' => 'Home: latest 10 articles paginated, with nav, category list and footer.',
        'group' => 'frontend',
        'run' => fn ($ctx) => $ctx['get']('/'),
    ],

    'render-article' => [
        'description' => 'Article page: terms, related articles by shared tags, nav and footer.',
        'group' => 'frontend',
        'run' => fn ($ctx) => $ctx['get']($ctx['samples']['article_uris'][0]),
    ],

    'render-tag' => [
        'description' => 'Term page for the most used tag: its articles, paginated.',
        'group' => 'frontend',
        'run' => fn ($ctx) => $ctx['get']('/tags/'.$ctx['samples']['popular_tag']),
    ],

    'render-page' => [
        'description' => 'Deepest structured page: breadcrumbs, child nav, main nav.',
        'group' => 'frontend',
        'run' => fn ($ctx) => $ctx['get']($ctx['samples']['deep_page_uri']),
    ],

    // Lower-level query paths, useful for pinpointing where differences come from.

    'first-query' => [
        'description' => 'First query of a request: count published articles (loads indexes from cache).',
        'group' => 'query',
        'run' => fn () => Entry::query()->where('collection', 'articles')->whereStatus('published')->count(),
    ],

    'find-by-id' => [
        'description' => 'Entry::find() for 100 article IDs.',
        'group' => 'query',
        'run' => fn ($ctx) => $ids(array_map(fn ($id) => Entry::find($id), $ctx['samples']['article_ids'])),
    ],

    'find-by-uri' => [
        'description' => 'Entry::findByUri() for 25 article and 25 page URIs (routing).',
        'group' => 'query',
        'run' => fn ($ctx) => $ids(array_map(
            fn ($uri) => Entry::findByUri($uri),
            [...$ctx['samples']['article_uris'], ...$ctx['samples']['page_uris']]
        )),
    ],

    'latest-articles' => [
        'description' => '10 latest published articles.',
        'group' => 'query',
        'run' => fn () => $ids(Entry::query()
            ->where('collection', 'articles')
            ->whereStatus('published')
            ->orderBy('date', 'desc')
            ->limit(10)
            ->get()),
    ],

    'deep-pagination' => [
        'description' => 'Page 50 of articles ordered by title, 20 per page.',
        'group' => 'query',
        'run' => function () use ($ids) {
            $page = Entry::query()
                ->where('collection', 'articles')
                ->orderBy('title')
                ->paginate(20, ['*'], 'page', 50);

            return ['total' => $page->total(), 'ids' => $ids($page->items())];
        },
    ],

    'taxonomy-popular' => [
        'description' => 'Paginated published articles for the most used tag, newest first.',
        'group' => 'query',
        'run' => fn ($ctx) => taxonomyPage($ctx['samples']['popular_tag'], $ids),
    ],

    'taxonomy-median' => [
        'description' => 'Paginated published articles for a median-use tag, newest first.',
        'group' => 'query',
        'run' => fn ($ctx) => taxonomyPage($ctx['samples']['median_tag'], $ids),
    ],

    'taxonomy-in-and-field' => [
        'description' => 'Articles in any of 3 tags AND a category, by rating then title, top 20.',
        'group' => 'query',
        'run' => fn ($ctx) => $ids(Entry::query()
            ->where('collection', 'articles')
            ->whereTaxonomyIn(array_map(fn ($t) => "tags::{$t}", $ctx['samples']['tags_in']))
            ->whereTaxonomy('categories::'.$ctx['samples']['category'])
            ->orderBy('rating', 'desc')
            ->orderBy('title')
            ->limit(20)
            ->get()),
    ],

    'custom-fields' => [
        'description' => 'Featured articles rated 4+, by rating then date, top 20 (warm field indexes).',
        'group' => 'query',
        'run' => fn () => $ids(Entry::query()
            ->where('collection', 'articles')
            ->where('featured', true)
            ->where('rating', '>=', 4)
            ->orderBy('rating', 'desc')
            ->orderBy('date', 'desc')
            ->limit(20)
            ->get()),
    ],

    'custom-field-cold' => [
        'description' => 'Count articles by author when that field has never been queried (index build).',
        'group' => 'query',
        'setup' => function () {
            // Stache-specific: forget the persisted index so it's rebuilt. Other
            // backends that keep every field queryable ignore this.
            if (Stache::stores()->has('entries')) {
                Stache::store('entries')->store('articles')->index('author')->clear();
            }
        },
        'run' => fn ($ctx) => Entry::query()->where('collection', 'articles')->where('author', $ctx['samples']['author'])->count(),
    ],

    'term-counts' => [
        'description' => 'First 50 tags by title, with their article counts (tag cloud / archive).',
        'group' => 'query',
        'run' => fn () => Term::query()
            ->where('taxonomy', 'tags')
            ->orderBy('title')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($term) => [$term->id() => $term->collection(Collection::find('articles'))->entriesCount()])
            ->all(),
    ],

    'page-tree' => [
        'description' => 'Flatten the pages structure and read every page URI (nav / CP tree).',
        'group' => 'query',
        'run' => fn () => Collection::find('pages')
            ->structure()
            ->in('default')
            ->flattenedPages()
            ->map(fn ($page) => $page->uri())
            ->values()
            ->all(),
    ],

    'save-entry' => [
        'description' => 'Re-save one article unchanged (write path + index updates).',
        'group' => 'query',
        'setup' => fn ($ctx) => $ctx['state']->entry = Entry::find($ctx['samples']['article_ids'][0]),
        'run' => function ($ctx) {
            $ctx['state']->entry->save();

            return $ctx['state']->entry->id();
        },
    ],

];

function taxonomyPage(string $tag, Closure $ids): array
{
    $page = Entry::query()
        ->where('collection', 'articles')
        ->whereTaxonomy("tags::{$tag}")
        ->whereStatus('published')
        ->orderBy('date', 'desc')
        ->paginate(10);

    return ['total' => $page->total(), 'ids' => $ids($page->items())];
}

function actAsBenchUser(): void
{
    auth()->guard(config('statamic.users.guards.cp', 'web'))->setUser(\Statamic\Facades\User::find('bench-user'));
}

function cpHeaders(): array
{
    return ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
}
