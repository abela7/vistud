<?php

namespace App\Appearance;

/**
 * The colours of the ViStud logo artwork, measured from the owner's
 * original file (resources/brand/logo-colours.json, DESIGN.md §2). They
 * describe the image and are never written into CSS. Themes use them only
 * to prove the logo stays legible on the plate behind it.
 */
final class Brand
{
    /** @return list<string> */
    public static function logoColors(): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/brand/logo-colours.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_values($data['logo']);
    }
}
