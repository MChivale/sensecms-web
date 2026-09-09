<?php

declare(strict_types=1);

namespace App\Core;

/** Installation-owned URLs, independent of whichever public theme is active. */
final class PublicPagePath
{
    public static function validate(mixed $value): ?string
    {
        if (!is_string($value) && $value !== null) throw new \RuntimeException('Enter a public path, for example /about.');
        $path = trim($value ?? '');
        if ($path === '') return null;
        if (strlen($path) > 255 || !preg_match('#^/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)?$#D', $path)) {
            throw new \RuntimeException('Use a path such as /about or /docs/installation, without a hostname, query, fragment or trailing slash.');
        }
        $first = explode('/', substr($path, 1))[0];
        if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D', $first) || in_array($first, [
            'install','login','logout','dashboard','settings','license','account','profile','content','appearance',
            'marketplace','system','api','calendar','surveys','forms','seo','ai','conversations','forgot-password',
            'reset-password','set-password','captcha','extension-assets','theme-assets','assets','media','theme',
            'sensecms','storage','config','app','database','scripts',
        ], true)) throw new \RuntimeException('This path is reserved by Core. Choose another public address.');
        return $path;
    }
}
