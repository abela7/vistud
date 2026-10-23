<?php

namespace App\Appearance;

/**
 * How ViStud shows up outside itself (the owner's ask, 2026-10-23): in a search result, a shared link's preview (an
 * invitation sent on WhatsApp or Telegram, say), a browser tab and a phone's home screen. One place for the words,
 * the picture and the colours, which come from the default theme like everything else (DESIGN.md §3).
 *
 * Only the signed-out pages anyone may see are for search engines (INDEXABLE); every other page says noindex, in
 * its head and in a header, and robots.txt keeps crawlers out of the private areas. A preview never shows anything of
 * a student's: an invitation's code is after the # of its link, which a preview never sees.
 */
final class Share
{
    public const NAME = 'ViStud';

    /**
     * The words people search for come first (the owner, 2026-10-23): a study organiser, an AI tutor, exams and
     * assignments. The tutor helps with an assignment; it never does it for you.
     */
    public const TITLE = 'ViStud: All-in-one study organiser with an AI tutor';

    /** The headline on the share picture and the sign-in page, with SUMMARY under it. */
    public const TAGLINE = 'Ace your exams and assignments.';

    public const SUMMARY = 'All-in-one study organiser with an AI tutor.';

    public const DESCRIPTION = 'Organise your courses, notes and deadlines in one place, with an AI tutor that helps with every assignment. Ace your exams.';

    /** What an invitation or a class link's preview says, under its title. */
    public const JOIN = 'Join the all-in-one study organiser with an AI tutor. Ace your exams and assignments.';

    /** The picture a shared link shows, 1200 by 630 (public/brand/share.png). */
    public const IMAGE = 'brand/share.png';

    public const IMAGE_ALT = 'ViStud: ace your exams and assignments. All-in-one study organiser with an AI tutor.';

    /** The pages search engines may list: signed out, the same for everyone. */
    public const INDEXABLE = ['login', 'privacy'];

    /** The private areas robots.txt keeps crawlers out of (each needs an account anyway). */
    public const PRIVATE = ['/admin', '/ai', '/ai-engine', '/api', '/calendar', '/connections', '/courses', '/files', '/join', '/journal', '/livewire', '/mcp', '/notes', '/oauth', '/settings', '/user', '/workspaces', '/email', '/reset-password', '/two-factor-challenge'];

    /**
     * The browser's bar colour, light and dark: where the default theme's header starts.
     *
     * @return array{light: string, dark: string}
     */
    public static function barColors(): array
    {
        $themes = app(Themes::class);

        return [
            'light' => $themes->get((string) config('vistud.appearance.light'))->gradient('header')->stops[0][0]->toHex(),
            'dark' => $themes->get((string) config('vistud.appearance.dark'))->gradient('header')->stops[0][0]->toHex(),
        ];
    }
}
