<?php

namespace App\Appearance;

use InvalidArgumentException;

/**
 * The Lucide icons in resources/icons (ADR 0003 §6.2), served once as a
 * sprite (/icons.svg) that the browser keeps: a page names each icon with
 * <use> instead of carrying its drawing (the owner's review, 2026-09-28:
 * the Progress page was 44% icons). The sprite's address carries a version,
 * so an added or changed icon is fetched again.
 */
final class Icons
{
    /** The same for every Lucide icon, so it sits once on each symbol. */
    private const ROOT = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

    private static ?string $version = null;

    /** @var array<string, string> icon name => its address, for this request */
    private static array $urls = [];

    public static function exists(string $name): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $name) === 1 && is_file(self::path($name));
    }

    /** Where a page finds the icon: the sprite, at its version, and the icon's symbol. */
    public static function url(string $name): string
    {
        return self::$urls[$name] ??= self::exists($name)
            ? rtrim(request()->getBaseUrl(), '/').'/icons.svg?v='.self::version().'#'.$name
            : throw new InvalidArgumentException("Unknown icon {$name}.");
    }

    /** Changes whenever an icon is added, removed or redrawn. */
    public static function version(): string
    {
        return self::$version ??= substr(md5(implode('|', array_map(
            fn (string $file) => basename($file).':'.filemtime($file),
            self::files(),
        ))), 0, 10);
    }

    /** Every icon as a <symbol>, with its id and the shared drawing settings. */
    public static function sprite(): string
    {
        $symbols = '';
        foreach (self::files() as $file) {
            preg_match('/<svg\b[^>]*>(.*)<\/svg>/s', (string) file_get_contents($file), $m);
            $symbols .= '<symbol id="'.basename($file, '.svg').'" '.self::ROOT.'>'.trim($m[1] ?? '').'</symbol>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg">'.$symbols.'</svg>';
    }

    /** @return list<string> */
    private static function files(): array
    {
        $files = glob(resource_path('icons/*.svg')) ?: [];
        sort($files);

        return $files;
    }

    private static function path(string $name): string
    {
        return resource_path("icons/{$name}.svg");
    }
}
