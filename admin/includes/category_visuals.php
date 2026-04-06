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

    // Chercher le JSON dans plusieurs emplacements possibles
    $candidates = [
        dirname(__DIR__, 2) . '/assets/category-visuals/category-visuals.json',
        dirname(__DIR__) . '/includes/category-visuals.json',
        dirname(__DIR__) . '/assets/category-visuals.json',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded) && count($decoded) > 0) {
                $catalog = $decoded;
                return $catalog;
            }
        }
    }

    // Catalogue embarqué (fallback Docker)
    $catalog = [
        ['key' => 'road',           'label' => 'Voirie & Chaussee',      'short_label' => 'Voirie',        'description' => 'Chaussées lateritiques et voirie du quotidien.',        'accent' => '#D96B2B', 'glow' => '#F4B46A', 'aliases' => ['voirie','voiriechaussee','chaussee','road']],
        ['key' => 'lightbulb',      'label' => 'Eclairage Public',       'short_label' => 'Eclairage',     'description' => 'Lampadaires, câbles et points lumineux défaillants.',   'accent' => '#D9A22E', 'glow' => '#F6D47C', 'aliases' => ['eclairage','eclairagepublic','lightbulb']],
        ['key' => 'tree',           'label' => 'Espaces Verts',          'short_label' => 'Espaces verts', 'description' => 'Végétation, branches et entretien des abords.',         'accent' => '#2E8B57', 'glow' => '#6FCF97', 'aliases' => ['espacesverts','tree','vegetation']],
        ['key' => 'trash',          'label' => 'Proprete & Dechets',     'short_label' => 'Proprete',      'description' => 'Poubelles, dépôts sauvages et propreté publique.',      'accent' => '#7C4FD9', 'glow' => '#B69BFF', 'aliases' => ['proprete','dechets','trash']],
        ['key' => 'bench',          'label' => 'Mobilier Urbain',        'short_label' => 'Mobilier',      'description' => 'Bancs, abribus, barrières et équipements.',             'accent' => '#287C96', 'glow' => '#73C5DB', 'aliases' => ['mobilier','mobilierurbain','bench']],
        ['key' => 'droplets',       'label' => 'Reseaux & Inondations',  'short_label' => 'Reseaux',       'description' => 'Caniveaux, ruissellement, fuites et stagnations.',      'accent' => '#2676D2', 'glow' => '#78B3FF', 'aliases' => ['reseaux','reseauxinondations','droplets','eau','inondations']],
        ['key' => 'triangle-alert', 'label' => 'Signalisation',          'short_label' => 'Signalisation', 'description' => 'Panneaux, marquages et sécurité routière.',             'accent' => '#E07A22', 'glow' => '#FFC680', 'aliases' => ['signalisation','trianglealert','triangle-alert','panneaux']],
        ['key' => 'building-2',     'label' => 'Batiments Communaux',    'short_label' => 'Batiments',     'description' => 'Écoles, mairies et bâtiments communaux.',              'accent' => '#5A6F7F', 'glow' => '#B8C5CF', 'aliases' => ['batiments','batimentscommunaux','building-2']],
    ];

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

/**
 * Retourne le pack d'icônes actif (ex: 'kourou', 'cayenne').
 */
function category_visual_active_pack(): string
{
    static $pack = null;
    if ($pack !== null) return $pack;

    $configFile = dirname(__DIR__) . '/includes/.icon_pack_config.json';
    if (is_file($configFile)) {
        $cfg = json_decode((string)file_get_contents($configFile), true);
        $pack = $cfg['active_pack'] ?? 'kourou';
    } else {
        $pack = 'kourou';
    }
    return $pack;
}

function category_visual_asset_url(?string $icon, ?string $name = null): string
{
    $entry = category_visual_resolve($icon, $name);

    // Mapping clé → ID catégorie
    $keyToId = [
        'road' => 1, 'lightbulb' => 2, 'tree' => 3, 'trash' => 4,
        'bench' => 5, 'droplets' => 6, 'triangle-alert' => 7, 'building-2' => 8,
    ];
    $catId = $keyToId[$entry['key'] ?? 'road'] ?? 1;
    $activePack = category_visual_active_pack();

    // 1. Icône du pack actif (admin/assets/img/icon-packs/{pack}/{id}.png)
    $packPath = dirname(__DIR__) . '/assets/img/icon-packs/' . $activePack . '/' . $catId . '.png';
    if (is_file($packPath)) {
        return '/admin/assets/img/icon-packs/' . rawurlencode($activePack) . '/' . $catId . '.png';
    }

    // 2. Fichier legacy local
    $localPath = dirname(__DIR__) . '/assets/img/category-icons/' . ($entry['key'] ?? 'road') . '.png';
    if (is_file($localPath)) {
        return '/admin/assets/img/category-icons/' . rawurlencode($entry['key']) . '.png';
    }

    // 3. Fallback VPS
    return 'https://admin.netetfix.com/uploads/demo-seed/category-' . $catId . '-master.png';
}


function category_badge_asset_id(?string $icon, ?string $name = null): ?string
{
    $entry = category_visual_resolve($icon, $name);
    $key = $entry['key'] ?? 'road';

    return [
        'road' => 'CAT-01',
        'lightbulb' => 'CAT-02',
        'tree' => 'CAT-03',
        'trash' => 'CAT-04',
        'bench' => 'CAT-05',
        'droplets' => 'CAT-06',
        'triangle-alert' => 'CAT-07',
        'building-2' => 'CAT-08',
    ][$key] ?? null;
}

function category_scene_asset_id(?string $icon, ?string $name = null): ?string
{
    $entry = category_visual_resolve($icon, $name);
    $key = $entry['key'] ?? 'road';

    return [
        'road' => 'ILL-01',
        'lightbulb' => 'ILL-02',
        'trash' => 'ILL-03',
        'droplets' => 'ILL-04',
        'tree' => 'ILL-05',
        'bench' => 'ILL-06',
        'triangle-alert' => 'ILL-07',
        'building-2' => 'ILL-08',
    ][$key] ?? null;
}

function category_scene_visual_url(?string $icon, ?string $name = null): ?string
{
    $entry = category_visual_resolve($icon, $name);
    $assetId = visual_admin_category_scene((string)($entry['key'] ?? 'road'), category_scene_asset_id($icon, $name));
    if (!$assetId) {
        return null;
    }

    return generated_visual_url($assetId);
}

function category_scene_visual_html(?string $icon, ?string $name = null, array $options = []): string
{
    $entry = category_visual_resolve($icon, $name);
    $assetId = visual_admin_category_scene((string)($entry['key'] ?? 'road'), category_scene_asset_id($icon, $name));
    if (!$assetId) {
        return '';
    }

    return generated_visual_html($assetId, $options + [
        'label' => 'Scene terrain ' . ($name ?: ($icon ?: 'categorie')),
        'class' => 'generated-visual generated-visual--cover generated-visual--scene',
    ]);
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
