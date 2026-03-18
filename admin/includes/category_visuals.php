<?php
/**
 * Helpers d'affichage pour les univers visuels des catégories.
 */

function category_visuals_catalog(): array
{
    static $catalog = null;

    if ($catalog !== null) {
        return $catalog;
    }

    $path = dirname(__DIR__, 2) . '/assets/category-visuals/category-visuals.json';
    if (!is_file($path)) {
        $catalog = [];
        return $catalog;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    $catalog = is_array($decoded) ? $decoded : [];

    return $catalog;
}

function category_visual_normalize(?string $value): string
{
    $value = trim((string)$value);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);

    return preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
}

function category_visual_resolve(?string $icon, ?string $name = null): array
{
    $catalog = category_visuals_catalog();
    $iconKey = category_visual_normalize($icon);
    $nameKey = category_visual_normalize($name);

    foreach ($catalog as $entry) {
        $aliases = array_map('category_visual_normalize', $entry['aliases'] ?? []);
        $key = category_visual_normalize($entry['key'] ?? '');

        if ($iconKey && ($iconKey === $key || in_array($iconKey, $aliases, true))) {
            return $entry;
        }

        if ($nameKey && in_array($nameKey, $aliases, true)) {
            return $entry;
        }
    }

    return $catalog[0] ?? [
        'key' => 'road',
        'label' => 'Voirie & Chaussee',
        'short_label' => 'Voirie',
        'description' => 'Chaussées lateritiques et voirie du quotidien.',
        'accent' => '#D96B2B',
        'glow' => '#F4B46A',
    ];
}

function category_visual_asset_url(?string $icon, ?string $name = null): string
{
    $entry = category_visual_resolve($icon, $name);
    return '/admin/assets/img/category-icons/' . rawurlencode($entry['key']) . '.png';
}

function category_visual_html(?string $icon, ?string $name = null, string $size = 'md', ?string $color = null): string
{
    $entry = category_visual_resolve($icon, $name);
    $safeColor = htmlspecialchars((string)($color ?: $entry['accent']), ENT_QUOTES, 'UTF-8');
    $safeLabel = htmlspecialchars((string)($name ?: $entry['short_label']), ENT_QUOTES, 'UTF-8');
    $safeSrc = htmlspecialchars(category_visual_asset_url($icon, $name), ENT_QUOTES, 'UTF-8');

    return sprintf(
        '<span class="category-mark category-mark--%s" style="--category-accent:%s;"><img src="%s" alt="%s" loading="lazy"></span>',
        htmlspecialchars($size, ENT_QUOTES, 'UTF-8'),
        $safeColor,
        $safeSrc,
        $safeLabel
    );
}
