<?php

namespace App\Support;

/**
 * Canonical resolver for the running Keystone version.
 *
 * The version used to be read from the VERSION file at *config load* time and
 * served through config('app.version'). That works only while the config cache
 * is empty: `php artisan config:cache` freezes whatever was on disk at that
 * moment, so every later bump of VERSION is invisible until someone remembers
 * to clear the cache. On a box where config caching is on, the About dialog
 * showed a permanently stale number — and, worse, so did every `?v=` asset
 * query string in the layout, meaning the service worker kept serving stale
 * CSS/JS to every client after a deploy.
 *
 * Reading the file per request removes the coupling. The result is memoized for
 * the lifetime of the process, so the file is touched at most once per request
 * no matter how many places ask.
 *
 * Resolution order:
 *   1. APP_VERSION env — an explicit pin for a locked-down install.
 *   2. The VERSION file at the project root.
 *   3. config('app.version') — whatever the cached config holds.
 *   4. '1.0.0' when there is no VERSION file at all.
 */
class AppVersion
{
    protected static ?string $resolved = null;

    public static function current(): string
    {
        if (static::$resolved !== null) {
            return static::$resolved;
        }

        $override = env('APP_VERSION');
        if (is_string($override) && trim($override) !== '') {
            return static::$resolved = trim($override);
        }

        $file = base_path('VERSION');
        if (is_file($file) && is_readable($file)) {
            $contents = trim((string) file_get_contents($file));
            if ($contents !== '') {
                return static::$resolved = $contents;
            }
        }

        $cached = config('app.version');

        return static::$resolved = (is_string($cached) && $cached !== '' ? $cached : '1.0.0');
    }

    /**
     * Forget the memoized value. For tests and for long-lived processes that
     * write a new VERSION in place (the update manager).
     */
    public static function flush(): void
    {
        static::$resolved = null;
    }

    /**
     * The cache key the service worker namespaces its caches with.
     *
     * public/service-worker.js hardcodes the same number as APP_ASSET_VERSION.
     * They must match: the worker prefetches `?v=<version>` URLs, so if the two
     * drift the shell is primed with files the layout never asks for.
     */
    public static function assetVersion(): string
    {
        return static::current();
    }
}
