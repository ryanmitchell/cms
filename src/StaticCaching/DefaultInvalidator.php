<?php

namespace Statamic\StaticCaching;

use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Globals\GlobalSet;
use Statamic\Contracts\Structures\Nav;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\Support\Arr;

class DefaultInvalidator implements Invalidator
{
    protected $cacher;
    protected $rules;

    public function __construct(Cacher $cacher, $rules = [])
    {
        $this->cacher = $cacher;
        $this->rules = $rules;
    }

    public function invalidate($item)
    {
        if ($this->rules === 'all') {
            return $this->cacher->flush();
        }

        $urls = collect();

        if ($item instanceof Entry) {
            $urls = $this->getEntryUrls($item);
        } elseif ($item instanceof Term) {
            $urls = $this->getTermUrls($item);
        } elseif ($item instanceof Nav) {
            $urls = $this->getNavUrls($item);
        } elseif ($item instanceof GlobalSet) {
            $urls = $this->getGlobalUrls($item);
        } elseif ($item instanceof Collection) {
            $urls = $this->getCollectionUrls($item);
        } elseif ($item instanceof Asset) {
            $urls = $this->getAssetUrls($item);
        } elseif ($item instanceof Form) {
            $urls = $this->getFormUrls($item);
        }

        collect($urls)
            ->filter(fn ($url) => is_array($url))
            ->each(fn ($url) => $this->cacher->invalidateUrl(...$url));

        $urls = collect($urls)->filter(fn ($url) => ! is_array($url));
        if ($urls->isNotEmpty()) {
            $this->cacher->invalidateUrls($urls->values()->all());
        }
    }

    public function invalidateAndRecache($item)
    {
        if (! config('statamic.static_caching.background_recache', false)) {
            return $this->invalidate($item);
        }

        $urls = [];

        if ($this->rules === 'all') {
            $this->recacheUrls($this->cacher->getUrls());

            return;
        }

        if ($item instanceof Entry) {
            $urls = $this->getEntryUrls($item);
        } elseif ($item instanceof Term) {
            $urls = $this->getTermUrls($item);
        } elseif ($item instanceof Nav) {
            $urls = $this->getNavUrls($item);
        } elseif ($item instanceof GlobalSet) {
            $urls = $this->getGlobalUrls($item);
        } elseif ($item instanceof Collection) {
            $urls = $this->getCollectionUrls($item);
        } elseif ($item instanceof Asset) {
            $urls = $this->getAssetUrls($item);
        } elseif ($item instanceof Form) {
            $urls = $this->getFormUrls($item);
        }

        $this->cacher->recacheUrls($urls);
    }

    private function getFormUrls($form)
    {
        return Arr::get($this->rules, "forms.{$form->handle()}.urls");
    }

    protected function getAssetUrls($asset)
    {
        return Arr::get($this->rules, "assets.{$asset->container()->handle()}.urls");
    }

    protected function getEntryUrls($entry)
    {
        $urls = $entry->descendants()->merge([$entry])->map(function ($entry) {
            if (! $entry->isRedirect() && $url = $entry->absoluteUrl()) {
                return $this->splitUrlAndDomain($url);
            }
        })->filter();

        return $urls->merge(Arr::get($this->rules, "collections.{$entry->collectionHandle()}.urls"))->all();
    }

    protected function getTermUrls($term)
    {
        $urls = collect();
        if ($url = $term->absoluteUrl()) {
            $urls = $urls->push($this->splitUrlAndDomain($url));

            $urls = $urls->merge($term->taxonomy()->collections()->map(function ($collection) use ($term) {
                if ($url = $term->collection($collection)->absoluteUrl()) {
                    return $this->splitUrlAndDomain($url);
                }
            }))->filter();
        }

        return $urls->merge(Arr::get($this->rules, "taxonomies.{$term->taxonomyHandle()}.urls"))->all();
    }

    protected function getNavUrls($nav)
    {
        return Arr::get($this->rules, "navigation.{$nav->handle()}.urls");
    }

    protected function getGlobalUrls($set)
    {
        return Arr::get($this->rules, "globals.{$set->handle()}.urls");
    }

    protected function getCollectionUrls($collection)
    {
        $urls = [];
        if ($url = $collection->absoluteUrl()) {
            $urls[] = $this->splitUrlAndDomain($url);
        }

        return array_merge($urls, Arr::get($this->rules, "collections.{$collection->handle()}.urls"));
    }

    private function splitUrlAndDomain(string $url)
    {
        $parsed = parse_url($url);

        return [
            Arr::get($parsed, 'path', '/'),
            $parsed['scheme'].'://'.$parsed['host'],
        ];
    }
}
