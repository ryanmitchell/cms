<?php

/*
 * Generates a deterministic benchmark site.
 *
 *   php benchmarks/generate.php 10k [--force] [--seed=1]
 *
 * The site has:
 *  - "articles": a dated collection of N entries with tags, categories and a
 *    handful of custom fields (rating, featured, author) to query on.
 *  - "pages": a structured, 3-level collection of N/10 entries (50..2000).
 *  - "tags" (N/50, min 50) and "categories" (12) taxonomies. Tag usage is
 *    skewed so a few tags are on lots of entries and most are on a few.
 *
 * Files are written directly (not via Entry::save()) so generating 50k
 * entries takes seconds, and the output is the same on every machine for a
 * given size and seed. Sample IDs/URIs/slugs for the scenarios are stored in
 * .bench-site.json so every run queries the same things.
 */

require __DIR__.'/bootstrap.php';

use Symfony\Component\Yaml\Yaml;

[$positional, $options] = bench_args($argv);

if (! $size = $positional[0] ?? null) {
    fwrite(STDERR, "Usage: php benchmarks/generate.php <size> [--force] [--seed=1]\n");
    exit(1);
}

$count = bench_size_to_count($size);
$seed = (int) ($options['seed'] ?? 1);
$base = bench_site_path($size);

if (is_dir($base)) {
    if (! isset($options['force'])) {
        fwrite(STDERR, "{$base} already exists. Use --force to regenerate it.\n");
        exit(1);
    }

    exec('rm -rf '.escapeshellarg($base));
}

mt_srand($seed);

$start = microtime(true);

// Copy the Testbench skeleton so the site is a self-contained Laravel base path.
$skeleton = dirname(__DIR__).'/vendor/orchestra/testbench-core/laravel/';
@mkdir($base, 0755, true);
exec(sprintf('rsync -a --exclude vendor --exclude "storage/framework/cache/data/*" %s %s', escapeshellarg($skeleton), escapeshellarg($base.'/')), $out, $code);

if ($code !== 0) {
    fwrite(STDERR, "Copying the Testbench skeleton failed.\n");
    exit(1);
}

$put = function (string $path, string $contents) use ($base) {
    $path = $base.'/'.$path;
    is_dir($dir = dirname($path)) || mkdir($dir, 0755, true);
    file_put_contents($path, $contents);
};

$yaml = fn (array $data) => Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

$frontMatter = fn (array $data, string $body = '') => "---\n".$yaml($data)."---\n".$body;

$uuid = function (string $seed) {
    $h = md5($seed);

    return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
};

$words = explode(' ', 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua enim ad minim veniam quis nostrud exercitation ullamco laboris nisi aliquip ex ea commodo consequat duis aute irure in reprehenderit voluptate velit esse cillum fugiat nulla pariatur excepteur sint occaecat cupidatat non proident sunt culpa qui officia deserunt mollit anim id est laborum');

$sentence = function (int $min, int $max) use ($words) {
    $n = mt_rand($min, $max);
    $s = [];
    for ($i = 0; $i < $n; $i++) {
        $s[] = $words[mt_rand(0, count($words) - 1)];
    }

    return ucfirst(implode(' ', $s));
};

$body = function () use ($sentence) {
    $paragraphs = [];
    for ($p = 0, $n = mt_rand(3, 6); $p < $n; $p++) {
        $lines = [];
        for ($l = 0, $m = mt_rand(3, 6); $l < $m; $l++) {
            $lines[] = $sentence(8, 18).'.';
        }
        $paragraphs[] = implode(' ', $lines);
    }

    return "\n".implode("\n\n", $paragraphs)."\n";
};

$slugify = fn (string $s) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');

// Sites ---------------------------------------------------------------------

$put('resources/sites.yaml', $yaml([
    'default' => ['name' => 'Default', 'url' => '/', 'locale' => 'en_US'],
]));

// Taxonomies -----------------------------------------------------------------

$tagCount = max(50, intdiv($count, 50));
$categoryCount = 12;

$put('content/taxonomies/tags.yaml', $yaml(['title' => 'Tags']));
$put('content/taxonomies/categories.yaml', $yaml(['title' => 'Categories']));

for ($i = 1; $i <= $tagCount; $i++) {
    $put("content/taxonomies/tags/tag-{$i}.yaml", $yaml(['title' => "Tag {$i}", 'description' => $sentence(6, 12)]));
}

for ($i = 1; $i <= $categoryCount; $i++) {
    $put("content/taxonomies/categories/category-{$i}.yaml", $yaml(['title' => "Category {$i}"]));
}

$put('resources/blueprints/taxonomies/tags/tag.yaml', $yaml([
    'title' => 'Tag',
    'tabs' => ['main' => ['sections' => [['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
        ['handle' => 'description', 'field' => ['type' => 'textarea']],
    ]]]]],
]));

// Articles -------------------------------------------------------------------

$put('content/collections/articles.yaml', $yaml([
    'title' => 'Articles',
    'route' => '/articles/{slug}',
    'date' => true,
    'date_behavior' => ['past' => 'public', 'future' => 'private'],
    'sort_dir' => 'desc',
    'taxonomies' => ['tags', 'categories'],
    'template' => 'article',
]));

$put('resources/blueprints/collections/articles/article.yaml', $yaml([
    'title' => 'Article',
    'tabs' => [
        'main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
            ['handle' => 'content', 'field' => ['type' => 'markdown']],
            ['handle' => 'author', 'field' => ['type' => 'text']],
            ['handle' => 'rating', 'field' => ['type' => 'integer']],
            ['handle' => 'featured', 'field' => ['type' => 'toggle']],
        ]]]],
        'sidebar' => ['sections' => [['fields' => [
            ['handle' => 'slug', 'field' => ['type' => 'slug']],
            ['handle' => 'date', 'field' => ['type' => 'date']],
            ['handle' => 'tags', 'field' => ['type' => 'terms', 'taxonomies' => ['tags']]],
            ['handle' => 'categories', 'field' => ['type' => 'terms', 'taxonomies' => ['categories']]],
        ]]]],
    ],
]));

$authors = array_map(fn ($i) => "Author {$i}", range(1, 30));
$startDate = strtotime('2016-01-01');
$endDate = strtotime('2026-01-01');

$articleIds = [];
$articleUris = [];
$publishedArticleUris = [];
$tagUsage = array_fill(1, $tagCount, 0);

for ($i = 1; $i <= $count; $i++) {
    $title = $sentence(3, 7);
    $slug = $slugify($title).'-'.$i;
    $date = date('Y-m-d', mt_rand($startDate, $endDate));
    $id = $uuid("article-{$seed}-{$i}");

    // Skewed tag picks: r^2 bunches choices towards the low-numbered tags.
    $tags = [];
    for ($t = 0, $n = mt_rand(1, 5); $t < $n; $t++) {
        $r = mt_rand() / mt_getrandmax();
        $tags[] = 'tag-'.(1 + (int) floor(($tagCount - 1) * $r * $r));
    }
    $tags = array_values(array_unique($tags));
    foreach ($tags as $tag) {
        $tagUsage[(int) substr($tag, 4)]++;
    }

    $data = array_filter([
        'id' => $id,
        'blueprint' => 'article',
        'title' => $title,
        'author' => $authors[mt_rand(0, count($authors) - 1)],
        'rating' => mt_rand(1, 5),
        'featured' => mt_rand(1, 10) === 1,
        'tags' => $tags,
        'categories' => ['category-'.mt_rand(1, $categoryCount)],
        'published' => mt_rand(1, 10) !== 1,
    ], fn ($v) => $v !== null);

    $put("content/collections/articles/{$date}.{$slug}.md", $frontMatter($data, $body()));

    $articleIds[] = $id;
    $articleUris[] = "/articles/{$slug}";

    if ($data['published']) {
        $publishedArticleUris[] = "/articles/{$slug}";
    }
}

// Pages ----------------------------------------------------------------------

$put('content/collections/pages.yaml', $yaml([
    'title' => 'Pages',
    'route' => '{parent_uri}/{slug}',
    'template' => 'page',
    'structure' => ['root' => true, 'max_depth' => 3],
]));

$put('resources/blueprints/collections/pages/page.yaml', $yaml([
    'title' => 'Page',
    'tabs' => ['main' => ['sections' => [['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
        ['handle' => 'content', 'field' => ['type' => 'markdown']],
    ]]]]],
]));

$pageCount = min(2000, max(50, intdiv($count, 10)));
$pageIds = [];
$pageUris = [];
$tree = [];

$makePage = function (int $i, string $parentUri, array $extra = []) use (&$pageIds, &$pageUris, $put, $frontMatter, $sentence, $body, $slugify, $uuid, $seed) {
    $title = $sentence(1, 3);
    $slug = $slugify($title).'-'.$i;
    $id = $uuid("page-{$seed}-{$i}");

    $put("content/collections/pages/{$slug}.md", $frontMatter([
        'id' => $id,
        'blueprint' => 'page',
        'title' => $title,
        ...$extra,
    ], $body()));

    $pageIds[] = $id;
    $pageUris[] = $uri = rtrim($parentUri, '/').'/'.$slug;

    return [$id, $uri];
};

// The root page (home) is first, with no children.
[$homeId] = $makePage(0, '/', ['template' => 'home']);
array_pop($pageUris);
$pageUris[] = '/';
$tree[] = ['entry' => $homeId];

$remaining = $pageCount - 1;
$i = 1;
$topLevel = max(5, (int) round($remaining / 40));

while ($remaining > 0) {
    for ($t = 0; $t < $topLevel && $remaining > 0; $t++) {
        [$id, $uri] = $makePage($i++, '/');
        $remaining--;
        $node = ['entry' => $id];

        for ($c = 0, $children = mt_rand(2, 6); $c < $children && $remaining > 0; $c++) {
            [$childId, $childUri] = $makePage($i++, $uri);
            $remaining--;
            $child = ['entry' => $childId];

            for ($g = 0, $grand = mt_rand(0, 4); $g < $grand && $remaining > 0; $g++) {
                [$grandId] = $makePage($i++, $childUri);
                $remaining--;
                $child['children'][] = ['entry' => $grandId];
            }

            $node['children'][] = $child;
        }

        $tree[] = $node;
    }
}

$put('content/trees/collections/pages.yaml', $yaml(['tree' => $tree]));

// Templates -------------------------------------------------------------------
//
// Typical-site Antlers templates, so frontend renders exercise the same tags
// real sites lean on: nav, collection (with taxonomy filters and pagination),
// taxonomy listings, term relationships and breadcrumbs.

$put('resources/views/layout.antlers.html', <<<'ANTLERS'
<!doctype html>
<html lang="en">
<head><title>{{ title }} | Bench</title></head>
<body>
<nav>
{{ nav:collection:pages max_depth="2" }}
    <a href="{{ url }}"{{ if is_current || is_parent }} class="active"{{ /if }}>{{ title }}</a>
    {{ if children }}<ul>{{ children }}<li><a href="{{ url }}">{{ title }}</a></li>{{ /children }}</ul>{{ /if }}
{{ /nav:collection:pages }}
</nav>
<main>{{ template_content }}</main>
<footer>
<ul>{{ taxonomy:categories sort="title" }}<li><a href="{{ url }}">{{ title }}</a></li>{{ /taxonomy:categories }}</ul>
<p>Latest: {{ collection:articles sort="date:desc" limit="3" }}<a href="{{ url }}">{{ title }}</a> {{ /collection:articles }}</p>
</footer>
</body>
</html>
ANTLERS);

$put('resources/views/home.antlers.html', <<<'ANTLERS'
<h1>{{ title }}</h1>
{{ collection:articles sort="date:desc" paginate="10" as="posts" }}
    {{ posts }}
    <article>
        <h2><a href="{{ url }}">{{ title }}</a></h2>
        <time>{{ date format="j F Y" }}</time> by {{ author }}
        <p>{{ content | strip_tags | safe_truncate:160 }}</p>
        {{ tags }}<a href="{{ url }}">#{{ title }}</a> {{ /tags }}
    </article>
    {{ /posts }}
    {{ paginate }}{{ total_items }} articles, page {{ current_page }} of {{ total_pages }}{{ /paginate }}
{{ /collection:articles }}
ANTLERS);

$put('resources/views/article.antlers.html', <<<'ANTLERS'
<article>
    <h1>{{ title }}</h1>
    <time>{{ date format="j F Y" }}</time> by {{ author }}
    {{ content }}
    <ul>{{ tags }}<li><a href="{{ url }}">{{ title }}</a></li>{{ /tags }}</ul>
    {{ categories }}<a href="{{ url }}">{{ title }}</a>{{ /categories }}
</article>
<aside>
    <h2>Related</h2>
    {{ collection:articles :taxonomy:tags:any="tags | pluck('slug') | join('|')" :id:not="id" sort="date:desc" limit="5" }}
        <a href="{{ url }}">{{ title }}</a>
    {{ /collection:articles }}
</aside>
ANTLERS);

$put('resources/views/page.antlers.html', <<<'ANTLERS'
<ol>{{ nav:breadcrumbs }}<li><a href="{{ url }}">{{ title }}</a></li>{{ /nav:breadcrumbs }}</ol>
<h1>{{ title }}</h1>
{{ content }}
<ul>{{ nav from="{url}" max_depth="1" }}<li><a href="{{ url }}">{{ title }}</a></li>{{ /nav }}</ul>
ANTLERS);

$termTemplate = <<<'ANTLERS'
<h1>{{ title }}</h1>
{{ collection:articles :taxonomy:{handle}="slug" sort="date:desc" paginate="10" as="posts" }}
    {{ posts }}<article><h2><a href="{{ url }}">{{ title }}</a></h2><time>{{ date format="j F Y" }}</time></article>{{ /posts }}
    {{ paginate }}{{ total_items }} articles{{ /paginate }}
{{ /collection:articles }}
ANTLERS;

$put('resources/views/tags/show.antlers.html', str_replace('{handle}', 'tags', $termTemplate));
$put('resources/views/categories/show.antlers.html', str_replace('{handle}', 'categories', $termTemplate));

// The CP reads Statamic's version from the app's composer.lock.
$put('composer.lock', json_encode(['packages' => [['name' => 'statamic/cms', 'version' => 'v6.0.0-bench']], 'packages-dev' => []], JSON_PRETTY_PRINT));

// A super user for Control Panel listing requests.
$put('users/bench@example.com.yaml', $yaml([
    'id' => 'bench-user',
    'name' => 'Bench User',
    'super' => true,
]));

// Samples for the scenarios ----------------------------------------------------

$pick = function (array $items, int $n) {
    $picked = [];
    for ($i = 0; $i < $n; $i++) {
        $picked[] = $items[mt_rand(0, count($items) - 1)];
    }

    return $picked;
};

arsort($tagUsage);
$byUsage = array_keys($tagUsage);

$meta = [
    'size' => $size,
    'seed' => $seed,
    'counts' => [
        'articles' => $count,
        'pages' => $pageCount,
        'tags' => $tagCount,
        'categories' => $categoryCount,
    ],
    'samples' => [
        'article_ids' => $pick($articleIds, 100),
        'article_uris' => $pick($articleUris, 25),
        // Renders need one that's published, or they're (correctly) a 404.
        'published_article_uri' => $pick($publishedArticleUris, 1)[0],
        'page_uris' => $pick($pageUris, 25),
        'deep_page_uri' => array_reduce($pageUris, fn ($deepest, $uri) => substr_count($uri, '/') > substr_count((string) $deepest, '/') ? $uri : $deepest),
        'popular_tag' => 'tag-'.$byUsage[0],
        'median_tag' => 'tag-'.$byUsage[intdiv(count($byUsage), 2)],
        'tags_in' => ['tag-'.$byUsage[1], 'tag-'.$byUsage[intdiv(count($byUsage), 4)], 'tag-'.$byUsage[intdiv(count($byUsage), 2)]],
        'category' => 'category-3',
        'author' => 'Author 7',
    ],
    'tag_usage' => [
        'popular' => $tagUsage[$byUsage[0]],
        'median' => $tagUsage[$byUsage[intdiv(count($byUsage), 2)]],
    ],
];

$put('.bench-site.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf(
    "Generated %s: %d articles, %d pages, %d tags in %.1fs\n",
    $base, $count, $pageCount, $tagCount, microtime(true) - $start
);
