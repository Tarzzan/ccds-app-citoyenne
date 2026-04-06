<?php
/**
 * Ma Commune Back-Office — Super Admin visuel
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../backend/controllers/AuditLogController.php';

$admin = require_admin_auth();
require_admin_role();

$page_title = 'Super Admin visuel';
$active_nav = 'visual_admin';

$settings = visual_admin_settings();
$themePresets = visual_admin_theme_presets();
$themeDefaults = visual_admin_theme_default_values();
$communeThemeProfiles = visual_admin_commune_theme_profiles($themePresets);
$message = null;
$error = null;

$db = Database::getInstance();

function visual_admin_log_change(PDO $db, array $admin, string $action, array $details = []): void
{
    if (empty($admin['id'])) {
        return;
    }

    AuditLogController::log($db, (int)$admin['id'], $action, 'visual_admin', null, $details);
}

function visual_admin_preview_media_url(?string $value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    if (generated_visual_reference_is_direct($value)) {
        return $value;
    }

    return generated_visual_url($value);
}

function visual_admin_is_custom_asset(?string $value): bool
{
    return is_string($value) && trim($value) !== '' && generated_visual_reference_is_direct(trim($value));
}

function visual_admin_surface_url_by_label(string $label, array $slotSurfaceMap): ?string
{
    $label = trim($label);
    if ($label === '') {
        return null;
    }

    $specialMap = [
        'Connexion' => '/admin/?page=login',
        'Shell lateral' => '/admin/?page=dashboard',
        'Categories' => '/admin/?page=categories',
    ];
    if (isset($specialMap[$label])) {
        return $specialMap[$label];
    }

    foreach ($slotSurfaceMap as $surface) {
        $surfaceLabel = (string)($surface['label'] ?? '');
        $surfaceUrl = (string)($surface['url'] ?? '');
        if ($surfaceLabel === $label && $surfaceUrl !== '') {
            return $surfaceUrl;
        }
    }

    return null;
}

function visual_admin_snapshot_coverage(array $familyCounts): ?array
{
    $normalized = [];
    foreach ($familyCounts as $family => $count) {
        if (!is_string($family) || $family === '') {
            continue;
        }
        $count = (int)$count;
        if ($count <= 0) {
            continue;
        }
        $normalized[$family] = $count;
    }

    if (!$normalized) {
        return null;
    }

    $keys = array_keys($normalized);
    if (count($keys) === 1) {
        $family = $keys[0];
        if ($family === 'branding') {
            return [
                'label' => 'Couverture rollback',
                'detail' => 'Retour cible surtout le branding et la connexion.',
            ];
        }
        if ($family === 'slots') {
            return [
                'label' => 'Couverture rollback',
                'detail' => 'Retour cible surtout les slots shell et heroes.',
            ];
        }
        if ($family === 'categories') {
            return [
                'label' => 'Couverture rollback',
                'detail' => 'Retour cible surtout les badges et scenes de categories.',
            ];
        }
    }

    return [
        'label' => 'Couverture rollback',
        'detail' => 'Retour mixte : plusieurs familles visuelles seront touchees.',
    ];
}

function visual_admin_snapshot_family_profile(array $familyCounts): ?array
{
    $normalized = [];
    foreach ($familyCounts as $family => $count) {
        if (!is_string($family) || $family === '') {
            continue;
        }
        $count = (int)$count;
        if ($count <= 0) {
            continue;
        }
        $normalized[$family] = $count;
    }

    if (!$normalized) {
        return null;
    }

    if (count($normalized) > 1) {
        return [
            'key' => 'mixed',
            'label' => 'Mixte',
            'detail' => 'Plusieurs familles visuelles',
        ];
    }

    $family = (string)array_key_first($normalized);
    $map = [
        'branding' => ['key' => 'branding', 'label' => 'Branding', 'detail' => 'Branding et connexion'],
        'slots' => ['key' => 'slots', 'label' => 'Slots', 'detail' => 'Shell et heroes'],
        'categories' => ['key' => 'categories', 'label' => 'Categories', 'detail' => 'Badges et scenes'],
    ];

    return $map[$family] ?? [
        'key' => $family,
        'label' => ucfirst($family),
        'detail' => ucfirst($family),
    ];
}

function visual_admin_snapshot_profile_pill_class(?array $profile): string
{
    $key = (string)($profile['key'] ?? '');
    return match ($key) {
        'branding' => 'visual-admin-change-pill--branding',
        'slots' => 'visual-admin-change-pill--slots',
        'categories' => 'visual-admin-change-pill--categories',
        'mixed' => 'visual-admin-change-pill--mixed',
        default => 'visual-admin-change-pill--snapshot',
    };
}

function visual_admin_commune_theme_profiles(array $themePresets): array
{
    $profiles = [
        [
            'id' => 'ville-centre',
            'label' => 'Ville-centre',
            'theme_variant' => 'neutral-civic',
            'subtitle' => 'Ville-centre · poste de coordination locale',
            'detail' => 'Base la plus sobre pour une mairie voulant une lecture tres institutionnelle.',
            'checks' => ['Login', 'Shell lateral', 'Tableau de bord'],
        ],
        [
            'id' => 'commune-littorale',
            'label' => 'Commune littorale',
            'theme_variant' => 'lagoon-public',
            'subtitle' => 'Commune littorale · poste de coordination locale',
            'detail' => 'Variation plus lumineuse, utile pour les communes voulant une lecture plus aeree.',
            'checks' => ['Login', 'Shell lateral', 'Carte'],
        ],
        [
            'id' => 'commune-forestiere',
            'label' => 'Commune forestiere',
            'theme_variant' => 'canopy-service',
            'subtitle' => 'Commune forestiere · poste de coordination locale',
            'detail' => 'Variation vegetale pour garder un ton calme sans perdre le cadre institutionnel.',
            'checks' => ['Login', 'Shell lateral', 'Signalements'],
        ],
        [
            'id' => 'bourg-solaire',
            'label' => 'Bourg solaire',
            'theme_variant' => 'terracotta-civic',
            'subtitle' => 'Bourg solaire · poste de coordination locale',
            'detail' => 'Variation plus chaude pour les communes voulant un accent civique plus marque.',
            'checks' => ['Login', 'Shell lateral', 'Tableau de bord'],
        ],
    ];

    foreach ($profiles as &$profile) {
        $variant = (string)($profile['theme_variant'] ?? '');
        $preset = $themePresets[$variant] ?? [];
        $profile['theme_label'] = (string)($preset['label'] ?? $variant);
        $profile['colors'] = [
            'primary' => (string)($preset['primary'] ?? '#355160'),
            'secondary' => (string)($preset['secondary'] ?? '#718892'),
            'accent' => (string)($preset['accent'] ?? '#C3A166'),
        ];
    }
    unset($profile);

    return $profiles;
}

function visual_admin_summarize_snapshot_profiles(array $snapshots): array
{
    $summary = [
        'branding' => 0,
        'slots' => 0,
        'categories' => 0,
        'mixed' => 0,
    ];

    foreach ($snapshots as $snapshot) {
        $profile = visual_admin_snapshot_family_profile((array)(($snapshot['metadata']['family_counts'] ?? [])));
        if (!$profile) {
            continue;
        }
        $key = (string)($profile['key'] ?? '');
        if (isset($summary[$key])) {
            $summary[$key]++;
        }
    }

    return $summary;
}

function visual_admin_latest_snapshot_by_profile(array $snapshots): array
{
    $latest = [];
    foreach ($snapshots as $snapshot) {
        $profile = visual_admin_snapshot_family_profile((array)(($snapshot['metadata']['family_counts'] ?? [])));
        if (!$profile) {
            continue;
        }
        $key = (string)($profile['key'] ?? '');
        if ($key === '' || isset($latest[$key])) {
            continue;
        }
        $latest[$key] = [
            'id' => (string)($snapshot['id'] ?? ''),
            'name' => (string)($snapshot['name'] ?? ''),
            'label' => (string)($profile['label'] ?? $key),
            'detail' => (string)($profile['detail'] ?? ''),
            'created_at' => (string)($snapshot['created_at'] ?? ''),
        ];
    }

    return $latest;
}

function visual_admin_collect_change_surfaces(string $action, array $details, array $visualPresets, array $slotSurfaceMap): array
{
    $surfaces = [];

    if (!empty($details['preset_id']) && isset($visualPresets[(string)$details['preset_id']])) {
        foreach (array_keys((array)($visualPresets[(string)$details['preset_id']]['slots'] ?? [])) as $slot) {
            $surfaceLabel = $slotSurfaceMap[$slot]['label'] ?? null;
            $surfaceUrl = $slotSurfaceMap[$slot]['url'] ?? null;
            if (is_string($surfaceLabel) && $surfaceLabel !== '') {
                $surfaces[$surfaceLabel] = [
                    'label' => $surfaceLabel,
                    'url' => is_string($surfaceUrl) && $surfaceUrl !== '' ? $surfaceUrl : '/admin/?page=dashboard',
                ];
            }
        }
    }

    if (!empty($details['slots_overridden']) || !empty($details['slot_count'])) {
        foreach ($slotSurfaceMap as $slot => $surface) {
            $surfaceLabel = $surface['label'] ?? null;
            $surfaceUrl = $surface['url'] ?? null;
            if (is_string($surfaceLabel) && $surfaceLabel !== '') {
                $surfaces[$surfaceLabel] = [
                    'label' => $surfaceLabel,
                    'url' => is_string($surfaceUrl) && $surfaceUrl !== '' ? $surfaceUrl : '/admin/?page=dashboard',
                ];
            }
        }
    }

    if (!empty($details['badges_overridden']) || !empty($details['scenes_overridden'])) {
        $surfaces['Categories'] = ['label' => 'Categories', 'url' => '/admin/?page=categories'];
    }

    if (array_key_exists('login_subtitle', $details)) {
        $surfaces['Connexion'] = ['label' => 'Connexion', 'url' => '/admin/?page=login'];
    }

    if (!$surfaces && str_contains($action, 'branding')) {
        $surfaces['Connexion'] = ['label' => 'Connexion', 'url' => '/admin/?page=login'];
        $surfaces['Shell lateral'] = ['label' => 'Shell lateral', 'url' => '/admin/?page=dashboard'];
    }

    return $surfaces;
}

function visual_admin_build_context_metadata(array $settings, array $visualPresets, array $slotSurfaceMap): array
{
    $settings = visual_admin_sanitize_settings($settings);
    $defaults = visual_admin_default_settings();

    $brandingOverrideCount = 0;
    foreach ((array)($settings['branding'] ?? []) as $key => $value) {
        $defaultValue = $defaults['branding'][$key] ?? null;
        if (is_string($value) && trim($value) !== '' && $value !== $defaultValue) {
            $brandingOverrideCount++;
        }
    }
    foreach ((array)($settings['theme'] ?? []) as $key => $value) {
        $defaultValue = $defaults['theme'][$key] ?? null;
        if (is_string($value) && trim($value) !== '' && $value !== $defaultValue) {
            $brandingOverrideCount++;
        }
    }

    $categoryOverrideCount = 0;
    foreach (['category_badges', 'category_scenes'] as $group) {
        foreach ((array)($settings[$group] ?? []) as $value) {
            if (is_string($value) && trim($value) !== '') {
                $categoryOverrideCount++;
            }
        }
    }

    $closestPreset = null;
    foreach ($visualPresets as $presetId => $preset) {
        $changeCount = 0;
        $surfaceCounts = [];
        foreach ((array)($preset['slots'] ?? []) as $slot => $targetValue) {
            $currentValue = (string)($settings['slots'][$slot] ?? '');
            if ($currentValue === (string)$targetValue) {
                continue;
            }
            $changeCount++;
            $surfaceLabel = (string)($slotSurfaceMap[$slot]['label'] ?? 'Surface');
            if ($surfaceLabel === '') {
                $surfaceLabel = 'Surface';
            }
            if (!isset($surfaceCounts[$surfaceLabel])) {
                $surfaceCounts[$surfaceLabel] = 0;
            }
            $surfaceCounts[$surfaceLabel]++;
        }

        if ($closestPreset === null || $changeCount < $closestPreset['slot_drift_count']) {
            arsort($surfaceCounts);
            $closestPreset = [
                'id' => (string)$presetId,
                'label' => (string)($preset['label'] ?? $presetId),
                'slot_drift_count' => $changeCount,
                'top_surfaces' => array_slice(array_keys($surfaceCounts), 0, 3),
            ];
        }
    }

    $familyCounts = [];
    if (($closestPreset['slot_drift_count'] ?? 0) > 0) {
        $familyCounts['slots'] = (int)$closestPreset['slot_drift_count'];
    }
    if ($brandingOverrideCount > 0) {
        $familyCounts['branding'] = $brandingOverrideCount;
    }
    if ($categoryOverrideCount > 0) {
        $familyCounts['categories'] = $categoryOverrideCount;
    }

    $interventionLabel = 'Preset actif';
    $interventionDetail = 'Le poste visuel est deja aligne sur sa base reconnue.';
    $interventionClass = 'light';
    $familyCount = count($familyCounts);
    $totalDriftCount = array_sum($familyCounts);
    if ($familyCount === 1 && isset($familyCounts['slots'])) {
        $interventionLabel = 'Realignement de famille recommande';
        $interventionDetail = 'Les slots shell ou heroes ont derive. Le plus propre est de les recoller au preset reconnu.';
        $interventionClass = 'medium';
    } elseif ($familyCount === 1 && $totalDriftCount <= 2 && $familyCount > 0) {
        $interventionLabel = 'Nettoyage local suffisant';
        $interventionDetail = 'Un nettoyage cible suffit en general pour revenir a la base reconnue.';
        $interventionClass = 'light';
    } elseif ($familyCount === 1 && $familyCount > 0) {
        $interventionLabel = 'Realignement de famille recommande';
        $interventionDetail = 'Une famille complete a derive. Mieux vaut utiliser le nettoyage ou realignement dedie.';
        $interventionClass = 'medium';
    } elseif ($familyCount > 1) {
        $interventionLabel = 'Retour strict a considerer';
        $interventionDetail = 'Plusieurs familles ont derive. Commencer par les nettoyages cibles, puis finir par un alignement strict si besoin.';
        $interventionClass = 'strong';
    }

    return [
        'recognized_preset_id' => (string)($closestPreset['id'] ?? ''),
        'recognized_preset_label' => (string)($closestPreset['label'] ?? ''),
        'slot_drift_count' => (int)($closestPreset['slot_drift_count'] ?? 0),
        'branding_override_count' => $brandingOverrideCount,
        'category_override_count' => $categoryOverrideCount,
        'family_counts' => $familyCounts,
        'top_surfaces' => (array)($closestPreset['top_surfaces'] ?? []),
        'intervention_label' => $interventionLabel,
        'intervention_detail' => $interventionDetail,
        'intervention_class' => $interventionClass,
    ];
}

function visual_admin_create_auto_backup(PDO $db, array $admin, string $sourceAction, string $sourceLabel): string
{
    global $visualPresets, $slotSurfaceMap;

    $snapshotId = visual_admin_save_snapshot(
        'Auto backup · ' . $sourceLabel,
        visual_admin_settings(),
        array_merge([
            'type' => 'auto',
            'source_action' => $sourceAction,
            'source_label' => $sourceLabel,
        ], visual_admin_build_context_metadata(visual_admin_settings(), $visualPresets, $slotSurfaceMap))
    );
    visual_admin_prune_auto_snapshots(12);
    visual_admin_log_change($db, $admin, 'visual_admin.snapshot_autobackup_saved', [
        'snapshot_id' => $snapshotId,
        'source_action' => $sourceAction,
        'source_label' => $sourceLabel,
    ]);

    return $snapshotId;
}

$slotGroups = [
    'Branding et shell' => [
        'login_scene' => ['label' => 'Login · scene principale', 'fallback' => 'ILL-05'],
        'login_inset' => ['label' => 'Login · vignette secondaire', 'fallback' => 'ILL-02'],
        'login_agent' => ['label' => 'Login · agent', 'fallback' => 'CHAR-04'],
        'sidebar_scene' => ['label' => 'Sidebar · scene principale', 'fallback' => 'ILL-02'],
        'sidebar_inset' => ['label' => 'Sidebar · vignette secondaire', 'fallback' => 'ILL-05'],
        'sidebar_agent' => ['label' => 'Sidebar · agent', 'fallback' => 'CHAR-04'],
        'topbar_scene' => ['label' => 'Topbar · scene principale', 'fallback' => 'ILL-01'],
        'topbar_inset' => ['label' => 'Topbar · vignette secondaire', 'fallback' => 'ILL-05'],
        'topbar_agent' => ['label' => 'Topbar · agent', 'fallback' => 'CHAR-05'],
    ],
    'Heroes cockpit' => [
        'dashboard_primary' => ['label' => 'Dashboard · portrait principal', 'fallback' => 'CHAR-04'],
        'dashboard_secondary' => ['label' => 'Dashboard · portrait secondaire', 'fallback' => 'CHAR-05'],
        'incidents_primary' => ['label' => 'Signalements · portrait principal', 'fallback' => 'CHAR-05'],
        'incidents_secondary' => ['label' => 'Signalements · portrait secondaire', 'fallback' => 'CHAR-04'],
        'services_primary' => ['label' => 'Services · portrait principal', 'fallback' => 'CHAR-04'],
        'services_secondary' => ['label' => 'Services · portrait secondaire', 'fallback' => 'CHAR-05'],
        'search_primary' => ['label' => 'Recherche · portrait principal', 'fallback' => 'CHAR-05'],
        'search_secondary' => ['label' => 'Recherche · portrait secondaire', 'fallback' => 'CHAR-04'],
    ],
    'Analyse et cartes' => [
        'map_scene' => ['label' => 'Carte · scene principale', 'fallback' => 'ILL-01'],
        'map_inset' => ['label' => 'Carte · vignette secondaire', 'fallback' => 'ILL-05'],
        'map_agent' => ['label' => 'Carte · agent', 'fallback' => 'CHAR-05'],
        'stats_scene' => ['label' => 'Statistiques · scene principale', 'fallback' => 'ILL-05'],
        'stats_inset' => ['label' => 'Statistiques · vignette secondaire', 'fallback' => 'ILL-02'],
        'stats_agent' => ['label' => 'Statistiques · agent', 'fallback' => 'CHAR-04'],
        'realtime_scene' => ['label' => 'Temps reel · scene principale', 'fallback' => 'ILL-02'],
        'realtime_inset' => ['label' => 'Temps reel · vignette secondaire', 'fallback' => 'ILL-05'],
        'realtime_agent' => ['label' => 'Temps reel · agent', 'fallback' => 'CHAR-05'],
        'predictive_scene' => ['label' => 'Predictif · scene principale', 'fallback' => 'ILL-05'],
        'predictive_inset' => ['label' => 'Predictif · vignette secondaire', 'fallback' => 'ILL-02'],
        'predictive_agent' => ['label' => 'Predictif · agent', 'fallback' => 'CHAR-04'],
    ],
];

$visualPresets = [
    'baseline_stylise' => [
        'label' => 'Baseline stylisee',
        'description' => 'Le socle visuel de reference reconstitue pour le backoffice live.',
        'slots' => [
            'login_scene' => 'ILL-05',
            'login_inset' => 'ILL-02',
            'login_agent' => 'CHAR-04',
            'sidebar_scene' => 'ILL-02',
            'sidebar_inset' => 'ILL-05',
            'sidebar_agent' => 'CHAR-04',
            'topbar_scene' => 'ILL-01',
            'topbar_inset' => 'ILL-05',
            'topbar_agent' => 'CHAR-05',
            'dashboard_primary' => 'CHAR-04',
            'dashboard_secondary' => 'CHAR-05',
            'incidents_primary' => 'CHAR-05',
            'incidents_secondary' => 'CHAR-04',
            'services_primary' => 'CHAR-04',
            'services_secondary' => 'CHAR-05',
            'search_primary' => 'CHAR-05',
            'search_secondary' => 'CHAR-04',
            'map_scene' => 'ILL-01',
            'map_inset' => 'ILL-05',
            'map_agent' => 'CHAR-05',
            'stats_scene' => 'ILL-05',
            'stats_inset' => 'ILL-02',
            'stats_agent' => 'CHAR-04',
            'realtime_scene' => 'ILL-02',
            'realtime_inset' => 'ILL-05',
            'realtime_agent' => 'CHAR-05',
            'predictive_scene' => 'ILL-05',
            'predictive_inset' => 'ILL-02',
            'predictive_agent' => 'CHAR-04',
        ],
    ],
    'guidage_local' => [
        'label' => 'Guidage local',
        'description' => 'Accent plus fort sur les scenes terrain et la lecture locale.',
        'slots' => [
            'login_scene' => 'ILL-01',
            'login_inset' => 'ILL-05',
            'login_agent' => 'CHAR-05',
            'sidebar_scene' => 'ILL-01',
            'sidebar_inset' => 'ILL-05',
            'sidebar_agent' => 'CHAR-05',
            'topbar_scene' => 'ILL-05',
            'topbar_inset' => 'ILL-02',
            'topbar_agent' => 'CHAR-04',
            'dashboard_primary' => 'CHAR-04',
            'dashboard_secondary' => 'CHAR-05',
            'incidents_primary' => 'CHAR-04',
            'incidents_secondary' => 'CHAR-05',
            'services_primary' => 'CHAR-05',
            'services_secondary' => 'CHAR-04',
            'search_primary' => 'CHAR-04',
            'search_secondary' => 'CHAR-05',
            'map_scene' => 'ILL-05',
            'map_inset' => 'ILL-01',
            'map_agent' => 'CHAR-04',
            'stats_scene' => 'ILL-01',
            'stats_inset' => 'ILL-02',
            'stats_agent' => 'CHAR-05',
            'realtime_scene' => 'ILL-05',
            'realtime_inset' => 'ILL-01',
            'realtime_agent' => 'CHAR-04',
            'predictive_scene' => 'ILL-01',
            'predictive_inset' => 'ILL-05',
            'predictive_agent' => 'CHAR-05',
        ],
    ],
    'duo_agents' => [
        'label' => 'Duo agents',
        'description' => 'Accent plus fort sur les portraits agents et la supervision humaine.',
        'slots' => [
            'login_scene' => 'ILL-02',
            'login_inset' => 'ILL-05',
            'login_agent' => 'CHAR-05',
            'sidebar_scene' => 'ILL-02',
            'sidebar_inset' => 'ILL-01',
            'sidebar_agent' => 'CHAR-05',
            'topbar_scene' => 'ILL-02',
            'topbar_inset' => 'ILL-01',
            'topbar_agent' => 'CHAR-04',
            'dashboard_primary' => 'CHAR-05',
            'dashboard_secondary' => 'CHAR-04',
            'incidents_primary' => 'CHAR-05',
            'incidents_secondary' => 'CHAR-04',
            'services_primary' => 'CHAR-05',
            'services_secondary' => 'CHAR-04',
            'search_primary' => 'CHAR-05',
            'search_secondary' => 'CHAR-04',
            'map_scene' => 'ILL-02',
            'map_inset' => 'ILL-05',
            'map_agent' => 'CHAR-05',
            'stats_scene' => 'ILL-02',
            'stats_inset' => 'ILL-05',
            'stats_agent' => 'CHAR-04',
            'realtime_scene' => 'ILL-02',
            'realtime_inset' => 'ILL-05',
            'realtime_agent' => 'CHAR-05',
            'predictive_scene' => 'ILL-02',
            'predictive_inset' => 'ILL-05',
            'predictive_agent' => 'CHAR-04',
        ],
    ],
];

$manifestAssets = generated_visuals_manifest()['assets'] ?? [];
$assetOptions = [];
foreach ($manifestAssets as $asset) {
    if (!is_array($asset) || empty($asset['id']) || empty($asset['targets']['admin'])) {
        continue;
    }
    $assetOptions[] = [
        'id' => (string)$asset['id'],
        'label' => (string)($asset['label'] ?? $asset['id']),
        'family' => explode('-', (string)$asset['id'])[0] ?? 'ASSET',
        'url' => generated_visual_url((string)$asset['id']),
    ];
}

usort($assetOptions, static function (array $a, array $b): int {
    return [$a['family'], $a['label']] <=> [$b['family'], $b['label']];
});
$badgeAssetOptions = $assetOptions;
$sceneAssetOptions = $assetOptions;
$categoryEntries = category_visuals_catalog();
$slotSurfaceMap = [
    'login_scene' => ['label' => 'Connexion', 'url' => '/admin/?page=login'],
    'login_inset' => ['label' => 'Connexion', 'url' => '/admin/?page=login'],
    'login_agent' => ['label' => 'Connexion', 'url' => '/admin/?page=login'],
    'sidebar_scene' => ['label' => 'Shell lateral', 'url' => '/admin/?page=dashboard'],
    'sidebar_inset' => ['label' => 'Shell lateral', 'url' => '/admin/?page=dashboard'],
    'sidebar_agent' => ['label' => 'Shell lateral', 'url' => '/admin/?page=dashboard'],
    'topbar_scene' => ['label' => 'Topbar partagee', 'url' => '/admin/?page=dashboard'],
    'topbar_inset' => ['label' => 'Topbar partagee', 'url' => '/admin/?page=dashboard'],
    'topbar_agent' => ['label' => 'Topbar partagee', 'url' => '/admin/?page=dashboard'],
    'dashboard_primary' => ['label' => 'Dashboard', 'url' => '/admin/?page=dashboard'],
    'dashboard_secondary' => ['label' => 'Dashboard', 'url' => '/admin/?page=dashboard'],
    'incidents_primary' => ['label' => 'Signalements', 'url' => '/admin/?page=incidents'],
    'incidents_secondary' => ['label' => 'Signalements', 'url' => '/admin/?page=incidents'],
    'services_primary' => ['label' => 'Services', 'url' => '/admin/?page=services'],
    'services_secondary' => ['label' => 'Services', 'url' => '/admin/?page=services'],
    'search_primary' => ['label' => 'Recherche', 'url' => '/admin/?page=search'],
    'search_secondary' => ['label' => 'Recherche', 'url' => '/admin/?page=search'],
    'map_scene' => ['label' => 'Carte', 'url' => '/admin/?page=map'],
    'map_inset' => ['label' => 'Carte', 'url' => '/admin/?page=map'],
    'map_agent' => ['label' => 'Carte', 'url' => '/admin/?page=map'],
    'stats_scene' => ['label' => 'Statistiques', 'url' => '/admin/?page=stats'],
    'stats_inset' => ['label' => 'Statistiques', 'url' => '/admin/?page=stats'],
    'stats_agent' => ['label' => 'Statistiques', 'url' => '/admin/?page=stats'],
    'realtime_scene' => ['label' => 'Temps reel', 'url' => '/admin/?page=realtime_dashboard'],
    'realtime_inset' => ['label' => 'Temps reel', 'url' => '/admin/?page=realtime_dashboard'],
    'realtime_agent' => ['label' => 'Temps reel', 'url' => '/admin/?page=realtime_dashboard'],
    'predictive_scene' => ['label' => 'Predictif', 'url' => '/admin/?page=predictive_analysis'],
    'predictive_inset' => ['label' => 'Predictif', 'url' => '/admin/?page=predictive_analysis'],
    'predictive_agent' => ['label' => 'Predictif', 'url' => '/admin/?page=predictive_analysis'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'download_settings') {
            visual_admin_log_change($db, $admin, 'visual_admin.settings_exported');
            $filename = 'ma-commune-admin-visual-settings-' . date('Ymd-His') . '.json';
            header('Content-Type: application/json; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $exportPayload = visual_admin_settings();
            $exportPayload['_meta'] = array_merge(
                visual_admin_build_context_metadata($exportPayload, $visualPresets, $slotSurfaceMap),
                [
                    'exported_at' => date(DATE_ATOM),
                    'exported_by_admin_id' => (int)($admin['id'] ?? 0),
                ]
            );
            echo json_encode($exportPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } elseif (($_POST['action'] ?? '') === 'import_settings') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'import_settings', 'Import JSON');
            $file = $_FILES['settings_json'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Choisir un fichier JSON de configuration visuelle.');
            }
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Le televersement du JSON a echoue.');
            }
            $raw = file_get_contents((string)($file['tmp_name'] ?? ''));
            $decoded = json_decode((string)$raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Le fichier importe n est pas un JSON valide.');
            }
            visual_admin_save_settings(visual_admin_sanitize_settings($decoded));
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.settings_imported', [
                'backup_snapshot_id' => $backupId,
                'keys' => array_keys($decoded),
            ]);
            $message = 'Configuration visuelle importee.';
        } elseif (($_POST['action'] ?? '') === 'apply_preset') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'apply_preset', 'Application preset');
            $presetId = trim((string)($_POST['preset_id'] ?? ''));
            if ($presetId === '' || !isset($visualPresets[$presetId])) {
                throw new RuntimeException('Preset visuel introuvable.');
            }
            $next = visual_admin_settings();
            foreach (($visualPresets[$presetId]['slots'] ?? []) as $slot => $value) {
                $next['slots'][$slot] = $value;
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.preset_applied', [
                'backup_snapshot_id' => $backupId,
                'preset_id' => $presetId,
                'slot_count' => count($visualPresets[$presetId]['slots'] ?? []),
            ]);
            $message = 'Preset visuel applique.';
        } elseif (($_POST['action'] ?? '') === 'align_preset_strict') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'align_preset_strict', 'Alignement strict preset');
            $presetId = trim((string)($_POST['preset_id'] ?? ''));
            if ($presetId === '' || !isset($visualPresets[$presetId])) {
                throw new RuntimeException('Preset visuel introuvable.');
            }
            $next = visual_admin_default_settings();
            foreach (($visualPresets[$presetId]['slots'] ?? []) as $slot => $value) {
                $next['slots'][$slot] = $value;
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.preset_strict_aligned', [
                'backup_snapshot_id' => $backupId,
                'preset_id' => $presetId,
                'slot_count' => count($visualPresets[$presetId]['slots'] ?? []),
                'branding_reset' => true,
                'category_overrides_reset' => true,
            ]);
            $message = 'Etat strict du preset restaure.';
        } elseif (($_POST['action'] ?? '') === 'align_preset_slots') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'align_preset_slots', 'Alignement slots preset');
            $presetId = trim((string)($_POST['preset_id'] ?? ''));
            if ($presetId === '' || !isset($visualPresets[$presetId])) {
                throw new RuntimeException('Preset visuel introuvable.');
            }
            $next = visual_admin_settings();
            $next['slots'] = visual_admin_default_settings()['slots'];
            foreach (($visualPresets[$presetId]['slots'] ?? []) as $slot => $value) {
                $next['slots'][$slot] = $value;
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.preset_slots_aligned', [
                'backup_snapshot_id' => $backupId,
                'preset_id' => $presetId,
                'slot_count' => count($visualPresets[$presetId]['slots'] ?? []),
            ]);
            $message = 'Slots realignes sur le preset.';
        } elseif (($_POST['action'] ?? '') === 'save_snapshot') {
            $snapshotName = trim((string)($_POST['snapshot_name'] ?? ''));
            $snapshotId = visual_admin_save_snapshot(
                $snapshotName,
                visual_admin_settings(),
                array_merge(
                    ['type' => 'manual'],
                    visual_admin_build_context_metadata(visual_admin_settings(), $visualPresets, $slotSurfaceMap)
                )
            );
            visual_admin_log_change($db, $admin, 'visual_admin.snapshot_saved', [
                'snapshot_id' => $snapshotId,
                'snapshot_name' => $snapshotName,
            ]);
            $message = 'Snapshot visuel enregistre.';
        } elseif (($_POST['action'] ?? '') === 'apply_snapshot') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'apply_snapshot', 'Application snapshot');
            $snapshotId = trim((string)($_POST['snapshot_id'] ?? ''));
            $snapshot = visual_admin_load_snapshot($snapshotId);
            visual_admin_save_settings((array)($snapshot['settings'] ?? []));
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.snapshot_applied', [
                'backup_snapshot_id' => $backupId,
                'snapshot_id' => $snapshot['id'] ?? $snapshotId,
                'snapshot_name' => $snapshot['name'] ?? $snapshotId,
            ]);
            $message = 'Snapshot visuel applique.';
        } elseif (($_POST['action'] ?? '') === 'delete_snapshot') {
            $snapshotId = trim((string)($_POST['snapshot_id'] ?? ''));
            $snapshot = visual_admin_load_snapshot($snapshotId);
            visual_admin_delete_snapshot($snapshotId);
            visual_admin_log_change($db, $admin, 'visual_admin.snapshot_deleted', [
                'snapshot_id' => $snapshot['id'] ?? $snapshotId,
                'snapshot_name' => $snapshot['name'] ?? $snapshotId,
            ]);
            $message = 'Snapshot visuel supprime.';
        } elseif (($_POST['action'] ?? '') === 'save_branding') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'save_branding', 'Mise a jour branding');
            $next = visual_admin_settings();
            $next['branding']['login_subtitle'] = trim((string)($_POST['login_subtitle'] ?? ''));
            $variant = trim((string)($_POST['theme_variant'] ?? ''));
            if ($variant === '' || !isset($themePresets[$variant])) {
                $variant = (string)($themeDefaults['variant'] ?? 'neutral-civic');
            }
            $next['theme']['variant'] = $variant;
            foreach (['primary', 'primary_dark', 'primary_light', 'secondary', 'accent'] as $themeKey) {
                $fallback = (string)($themePresets[$variant][$themeKey] ?? $themeDefaults[$themeKey] ?? '#355160');
                $next['theme'][$themeKey] = visual_admin_sanitize_hex_color($_POST['theme'][$themeKey] ?? null, $fallback);
            }
            $uploaded = visual_admin_store_uploaded_image($_FILES['brand_mark'] ?? [], 'brand-mark');
            if ($uploaded !== null) {
                $next['branding']['brand_mark_url'] = $uploaded;
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.branding_saved', [
                'backup_snapshot_id' => $backupId,
                'brand_mark_custom' => $uploaded !== null,
                'login_subtitle' => $next['branding']['login_subtitle'] ?? null,
                'theme_variant' => $next['theme']['variant'] ?? null,
                'theme_primary' => $next['theme']['primary'] ?? null,
                'theme_secondary' => $next['theme']['secondary'] ?? null,
                'theme_accent' => $next['theme']['accent'] ?? null,
            ]);
            $message = 'Branding visuel mis a jour.';
        } elseif (($_POST['action'] ?? '') === 'save_custom_theme') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'save_custom_theme', 'Creation theme personnalise');
            $next = visual_admin_settings();
            
            $customId = 'custom-' . preg_replace('/[^a-z0-9]/', '', strtolower($_POST['custom_theme_name'] ?? 'Theme')) . '-' . time();
            $label = trim((string)($_POST['custom_theme_name'] ?? 'Mode sans nom'));
            if ($label === '') $label = 'Nouveau Mode';
            
            $colors = [];
            foreach (['primary', 'primary_dark', 'primary_light', 'secondary', 'accent'] as $themeKey) {
                $colors[$themeKey] = visual_admin_sanitize_hex_color($_POST['theme'][$themeKey] ?? null, '#355160');
            }
            
            $next['custom_themes'][$customId] = [
                'label' => $label,
                'description' => 'Créé par ' . $admin['firstname'] . ' ' . $admin['lastname'],
                'primary' => $colors['primary'],
                'primary_dark' => $colors['primary_dark'],
                'primary_light' => $colors['primary_light'],
                'secondary' => $colors['secondary'],
                'accent' => $colors['accent']
            ];
            
            // Set it as active
            $next['theme']['variant'] = $customId;
            foreach ($colors as $k => $c) {
                $next['theme'][$k] = $c;
            }
            
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            $themePresets = visual_admin_theme_presets(); // Refresh presets
            
            visual_admin_log_change($db, $admin, 'visual_admin.custom_theme_saved', [
                'backup_snapshot_id' => $backupId,
                'theme_id' => $customId,
                'theme_label' => $label
            ]);
            $message = 'Nouveau rôle/thème sauvegardé avec succès.';
        } elseif (($_POST['action'] ?? '') === 'delete_custom_theme') {
            $themeId = trim((string)($_POST['theme_id'] ?? ''));
            $next = visual_admin_settings();
            if (isset($next['custom_themes'][$themeId])) {
                unset($next['custom_themes'][$themeId]);
                // If it was the active theme, fallback to default
                if (($next['theme']['variant'] ?? '') === $themeId) {
                    $next['theme']['variant'] = 'neutral-civic';
                }
                visual_admin_save_settings($next);
                $settings = visual_admin_settings(true);
                $themePresets = visual_admin_theme_presets();
                $message = 'Thème personnalisé supprimé.';
            } else {
                $error = 'Ce thème n\'existe pas ou ne peut pas être supprimé.';
            }
        } elseif (($_POST['action'] ?? '') === 'apply_commune_theme_profile') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'apply_commune_theme_profile', 'Application preset commune');
            $profileId = trim((string)($_POST['profile_id'] ?? ''));
            $selectedProfile = null;
            foreach ($communeThemeProfiles as $profile) {
                if ((string)($profile['id'] ?? '') === $profileId) {
                    $selectedProfile = $profile;
                    break;
                }
            }
            if ($selectedProfile === null) {
                throw new RuntimeException('Preset commune introuvable.');
            }
            $variant = (string)($selectedProfile['theme_variant'] ?? $themeDefaults['variant']);
            $preset = $themePresets[$variant] ?? [];
            $next = visual_admin_settings();
            $next['theme']['variant'] = $variant;
            foreach (['primary', 'primary_dark', 'primary_light', 'secondary', 'accent'] as $themeKey) {
                $fallback = (string)($preset[$themeKey] ?? $themeDefaults[$themeKey] ?? '#355160');
                $next['theme'][$themeKey] = visual_admin_sanitize_hex_color($fallback, $fallback);
            }
            $next['branding']['login_subtitle'] = (string)($selectedProfile['subtitle'] ?? ($next['branding']['login_subtitle'] ?? ''));
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.commune_theme_profile_applied', [
                'backup_snapshot_id' => $backupId,
                'profile_id' => $profileId,
                'profile_label' => $selectedProfile['label'] ?? $profileId,
                'theme_variant' => $variant,
                'login_subtitle' => $next['branding']['login_subtitle'] ?? null,
            ]);
            $message = 'Preset commune applique.';
        } elseif (($_POST['action'] ?? '') === 'reset_branding') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'reset_branding', 'Reset branding');
            $next = visual_admin_settings();
            $next['branding'] = visual_admin_default_settings()['branding'];
            $next['theme'] = visual_admin_default_settings()['theme'];
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.branding_reset', [
                'backup_snapshot_id' => $backupId,
            ]);
            $message = 'Branding visuel reinitialise.';
        } elseif (($_POST['action'] ?? '') === 'save_slots') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'save_slots', 'Mise a jour slots');
            $next = visual_admin_settings();
            $slotResets = is_array($_POST['slot_reset'] ?? null) ? $_POST['slot_reset'] : [];
            foreach ($slotGroups as $groupSlots) {
                foreach ($groupSlots as $slot => $meta) {
                    if (!empty($slotResets[$slot])) {
                        $next['slots'][$slot] = null;
                        continue;
                    }
                    $value = trim((string)($_POST['slot'][$slot] ?? ''));
                    $uploaded = visual_admin_store_uploaded_image(
                        visual_admin_nested_upload($_FILES['slot_upload'] ?? [], $slot),
                        'slot-' . $slot
                    );
                    if ($uploaded !== null) {
                        $next['slots'][$slot] = $uploaded;
                    } else {
                        $next['slots'][$slot] = $value !== '' ? $value : null;
                    }
                }
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.slots_saved', [
                'backup_snapshot_id' => $backupId,
                'slots_overridden' => count(array_filter($next['slots'], static fn($value) => is_string($value) && $value !== '')),
                'slots_reset' => array_keys(array_filter($slotResets)),
            ]);
            $message = 'Slots visuels mis a jour.';
        } elseif (($_POST['action'] ?? '') === 'reset_slots') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'reset_slots', 'Reset slots');
            $next = visual_admin_settings();
            foreach (array_keys(visual_admin_default_settings()['slots']) as $slot) {
                $next['slots'][$slot] = null;
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.slots_reset', [
                'backup_snapshot_id' => $backupId,
            ]);
            $message = 'Slots visuels reinitialises.';
        } elseif (($_POST['action'] ?? '') === 'save_category_badges') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'save_category_badges', 'Mise a jour badges categories');
            $next = visual_admin_settings();
            $badgeResets = is_array($_POST['category_badge_reset'] ?? null) ? $_POST['category_badge_reset'] : [];
            foreach ($categoryEntries as $entry) {
                $key = (string)($entry['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (!empty($badgeResets[$key])) {
                    $next['category_badges'][$key] = null;
                    continue;
                }
                $value = trim((string)($_POST['category_badge'][$key] ?? ''));
                $uploaded = visual_admin_store_uploaded_image(
                    visual_admin_nested_upload($_FILES['category_upload'] ?? [], $key),
                    'category-' . preg_replace('/[^a-z0-9\-]+/i', '-', $key)
                );
                if ($uploaded !== null) {
                    $next['category_badges'][$key] = $uploaded;
                } else {
                    $next['category_badges'][$key] = $value !== '' ? $value : null;
                }
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.category_badges_saved', [
                'backup_snapshot_id' => $backupId,
                'badges_overridden' => count(array_filter($next['category_badges'], static fn($value) => is_string($value) && $value !== '')),
                'badges_reset' => array_keys(array_filter($badgeResets)),
            ]);
            $message = 'Badges categories mis a jour.';
        } elseif (($_POST['action'] ?? '') === 'reset_category_badges') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'reset_category_badges', 'Reset badges categories');
            $next = visual_admin_settings();
            foreach (array_keys(visual_admin_default_settings()['category_badges']) as $key) {
                $next['category_badges'][$key] = null;
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.category_badges_reset', [
                'backup_snapshot_id' => $backupId,
            ]);
            $message = 'Badges categories reinitialises.';
        } elseif (($_POST['action'] ?? '') === 'save_category_scenes') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'save_category_scenes', 'Mise a jour scenes categories');
            $next = visual_admin_settings();
            $sceneResets = is_array($_POST['category_scene_reset'] ?? null) ? $_POST['category_scene_reset'] : [];
            foreach ($categoryEntries as $entry) {
                $key = (string)($entry['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                if (!empty($sceneResets[$key])) {
                    $next['category_scenes'][$key] = null;
                    continue;
                }
                $value = trim((string)($_POST['category_scene'][$key] ?? ''));
                $uploaded = visual_admin_store_uploaded_image(
                    visual_admin_nested_upload($_FILES['category_scene_upload'] ?? [], $key),
                    'scene-' . preg_replace('/[^a-z0-9\\-]+/i', '-', $key)
                );
                if ($uploaded !== null) {
                    $next['category_scenes'][$key] = $uploaded;
                } else {
                    $next['category_scenes'][$key] = $value !== '' ? $value : null;
                }
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.category_scenes_saved', [
                'backup_snapshot_id' => $backupId,
                'scenes_overridden' => count(array_filter($next['category_scenes'], static fn($value) => is_string($value) && $value !== '')),
                'scenes_reset' => array_keys(array_filter($sceneResets)),
            ]);
            $message = 'Scenes categories mises a jour.';
        } elseif (($_POST['action'] ?? '') === 'reset_category_scenes') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'reset_category_scenes', 'Reset scenes categories');
            $next = visual_admin_settings();
            foreach (array_keys(visual_admin_default_settings()['category_scenes']) as $key) {
                $next['category_scenes'][$key] = null;
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.category_scenes_reset', [
                'backup_snapshot_id' => $backupId,
            ]);
            $message = 'Scenes categories reinitialisees.';
        } elseif (($_POST['action'] ?? '') === 'save_nav_icons') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'save_nav_icons', 'Mise a jour icones de navigation');
            $next = visual_admin_settings();
            $navResets = is_array($_POST['nav_icon_reset'] ?? null) ? $_POST['nav_icon_reset'] : [];
            $navKeys = array_keys(visual_admin_default_settings()['nav_icons']);
            foreach ($navKeys as $key) {
                if (!empty($navResets[$key])) {
                    $next['nav_icons'][$key] = null;
                    continue;
                }
                $value = trim((string)($_POST['nav_icon'][$key] ?? ''));
                $uploaded = visual_admin_store_uploaded_image(
                    visual_admin_nested_upload($_FILES['nav_upload'] ?? [], $key),
                    'nav-' . preg_replace('/[^a-z0-9\-]+/i', '-', $key)
                );
                if ($uploaded !== null) {
                    $next['nav_icons'][$key] = $uploaded;
                } else {
                    $next['nav_icons'][$key] = $value !== '' ? $value : null;
                }
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.nav_icons_saved', [
                'backup_snapshot_id' => $backupId,
                'nav_overridden' => count(array_filter($next['nav_icons'], static fn($value) => is_string($value) && $value !== '')),
                'nav_reset' => array_keys(array_filter($navResets)),
            ]);
            $message = 'Icones de navigation mises a jour.';
        } elseif (($_POST['action'] ?? '') === 'reset_nav_icons') {
            $backupId = visual_admin_create_auto_backup($db, $admin, 'reset_nav_icons', 'Reset icones de navigation');
            $next = visual_admin_settings();
            foreach (array_keys(visual_admin_default_settings()['nav_icons']) as $key) {
                $next['nav_icons'][$key] = null;
            }
            visual_admin_save_settings($next);
            $settings = visual_admin_settings(true);
            visual_admin_log_change($db, $admin, 'visual_admin.nav_icons_reset', [
                'backup_snapshot_id' => $backupId,
            ]);
            $message = 'Icones de navigation reinitialisees.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$brandMarkUrl = visual_admin_brand_asset_url('brand_mark_url', '/admin/assets/img/ma-commune-guyane-mark.png');
$defaultLoginSubtitle = defined('APP_ADMIN_LOGIN_SUBTITLE') ? APP_ADMIN_LOGIN_SUBTITLE : 'Territoire · poste de suivi public';
$loginSubtitle = visual_admin_brand_text('login_subtitle', $defaultLoginSubtitle);
$themePresets = visual_admin_theme_presets();
$themeSettings = visual_admin_theme_settings();
$themeDefaults = visual_admin_theme_default_values();
$themePreviewVars = [
    '--primary' => $themeSettings['primary'],
    '--primary-dark' => $themeSettings['primary_dark'],
    '--primary-light' => $themeSettings['primary_light'],
    '--secondary' => $themeSettings['secondary'],
    '--accent' => $themeSettings['accent'],
];
$themePreviewStyle = implode(';', array_map(
    static fn(string $name, string $value): string => $name . ':' . $value,
    array_keys($themePreviewVars),
    array_values($themePreviewVars)
));
$activeCommuneThemeProfileId = null;
foreach ($communeThemeProfiles as $profile) {
    if (
        ($profile['theme_variant'] ?? null) === ($themeSettings['variant'] ?? null)
        && (string)($profile['subtitle'] ?? '') === $loginSubtitle
    ) {
        $activeCommuneThemeProfileId = (string)($profile['id'] ?? '');
        break;
    }
}
$activeBrandingOverrides = [];
if (!empty($settings['branding']['brand_mark_url'])) {
    $activeBrandingOverrides[] = [
        'label' => 'Logo de marque', 
        'value' => 'Logo custom actif',
        'preview_url' => visual_admin_preview_media_url($settings['branding']['brand_mark_url'])
    ];
}
if (($settings['branding']['login_subtitle'] ?? '') !== $defaultLoginSubtitle) {
    $activeBrandingOverrides[] = ['label' => 'Sous-titre login', 'value' => $loginSubtitle];
}
if (($themeSettings['variant'] ?? '') !== ($themeDefaults['variant'] ?? '')) {
    $activeBrandingOverrides[] = ['label' => 'Preset de theme', 'value' => $themeSettings['label']];
}
foreach ([
    'primary' => 'Primaire',
    'primary_dark' => 'Primaire sombre',
    'primary_light' => 'Primaire clair',
    'secondary' => 'Secondaire',
    'accent' => 'Accent',
] as $key => $label) {
    if (($themeSettings[$key] ?? '') !== ($themeDefaults[$key] ?? '')) {
        $activeBrandingOverrides[] = ['label' => 'Theme · ' . $label, 'value' => (string)$themeSettings[$key]];
    }
}

$activeSlotOverrides = [];
foreach ($slotGroups as $groupTitle => $groupSlots) {
    foreach ($groupSlots as $slot => $meta) {
        $currentAsset = $settings['slots'][$slot] ?? null;
        if (!is_string($currentAsset) || $currentAsset === '') {
            continue;
        }
        $surface = $slotSurfaceMap[$slot] ?? ['label' => $groupTitle, 'url' => '/admin/?page=dashboard'];
        $activeSlotOverrides[] = [
            'label' => $meta['label'],
            'value' => visual_admin_is_custom_asset($currentAsset) ? 'Visuel custom' : $currentAsset,
            'surface_label' => $surface['label'],
            'surface_url' => $surface['url'],
            'preview_url' => visual_admin_is_custom_asset($currentAsset) ? visual_admin_preview_media_url($currentAsset) : generated_visual_url($currentAsset),
        ];
    }
}

$activeCategoryBadgeOverrides = [];
$activeCategorySceneOverrides = [];
foreach ($categoryEntries as $entry) {
    $key = (string)($entry['key'] ?? '');
    if ($key === '') {
        continue;
    }
    $label = (string)($entry['short_label'] ?? $entry['label'] ?? $key);
    $badgeValue = $settings['category_badges'][$key] ?? null;
    if (is_string($badgeValue) && $badgeValue !== '') {
        $activeCategoryBadgeOverrides[] = [
            'label' => $label,
            'value' => visual_admin_is_custom_asset($badgeValue) ? 'Badge custom' : $badgeValue,
            'preview_url' => visual_admin_is_custom_asset($badgeValue) ? visual_admin_preview_media_url($badgeValue) : visual_admin_preview_media_url($badgeValue)
        ];
    }
    $sceneValue = $settings['category_scenes'][$key] ?? null;
    if (is_string($sceneValue) && $sceneValue !== '') {
        $activeCategorySceneOverrides[] = [
            'label' => $label,
            'value' => visual_admin_is_custom_asset($sceneValue) ? 'Scene custom' : $sceneValue,
            'preview_url' => visual_admin_is_custom_asset($sceneValue) ? visual_admin_preview_media_url($sceneValue) : visual_admin_preview_media_url($sceneValue)
        ];
    }
}

$recentVisualChanges = [];
try {
    $auditColumns = $db->query('SHOW COLUMNS FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($auditColumns)) {
        $userField = in_array('user_id', $auditColumns, true) ? 'user_id' : 'admin_id';
        $targetTypeField = in_array('target_type', $auditColumns, true) ? 'target_type' : 'entity';
        $detailsField = in_array('details', $auditColumns, true) ? 'details' : (in_array('new_value', $auditColumns, true) ? 'new_value' : null);
        $detailsSql = $detailsField ? ", al.{$detailsField} AS log_details" : '';

        $stmtRecentVisual = $db->prepare("
            SELECT
                al.action,
                al.created_at,
                al.ip_address,
                u.full_name AS admin_name
                {$detailsSql}
            FROM audit_logs al
            JOIN users u ON u.id = al.{$userField}
            WHERE al.action LIKE 'visual_admin.%' OR al.{$targetTypeField} = 'visual_admin'
            ORDER BY al.created_at DESC
            LIMIT 6
        ");
        $stmtRecentVisual->execute();
        $recentVisualChanges = $stmtRecentVisual->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $recentVisualChanges = [];
}

$visualActionLabels = [
    'visual_admin.settings_exported' => 'Configuration exportee',
    'visual_admin.settings_imported' => 'Configuration importee',
    'visual_admin.preset_applied' => 'Preset applique',
    'visual_admin.preset_slots_aligned' => 'Slots preset realignes',
    'visual_admin.preset_strict_aligned' => 'Preset strict restaure',
    'visual_admin.snapshot_autobackup_saved' => 'Backup automatique cree',
    'visual_admin.snapshot_saved' => 'Snapshot enregistre',
    'visual_admin.snapshot_applied' => 'Snapshot applique',
    'visual_admin.snapshot_deleted' => 'Snapshot supprime',
    'visual_admin.branding_saved' => 'Branding enregistre',
    'visual_admin.commune_theme_profile_applied' => 'Preset commune applique',
    'visual_admin.branding_reset' => 'Branding reinitialise',
    'visual_admin.slots_saved' => 'Slots enregistres',
    'visual_admin.slots_reset' => 'Slots reinitialises',
    'visual_admin.category_badges_saved' => 'Badges categories enregistres',
    'visual_admin.category_badges_reset' => 'Badges categories reinitialises',
    'visual_admin.category_scenes_saved' => 'Scenes categories enregistrees',
    'visual_admin.category_scenes_reset' => 'Scenes categories reinitialisees',
];

$visualActionKinds = [
    'visual_admin.preset_applied' => ['label' => 'Structurant', 'class' => 'structuring'],
    'visual_admin.preset_slots_aligned' => ['label' => 'Structurant', 'class' => 'structuring'],
    'visual_admin.preset_strict_aligned' => ['label' => 'Structurant', 'class' => 'structuring'],
    'visual_admin.settings_imported' => ['label' => 'Structurant', 'class' => 'structuring'],
    'visual_admin.snapshot_applied' => ['label' => 'Rollback', 'class' => 'rollback'],
    'visual_admin.branding_saved' => ['label' => 'Ajustement', 'class' => 'tuning'],
    'visual_admin.commune_theme_profile_applied' => ['label' => 'Structurant', 'class' => 'structuring'],
    'visual_admin.branding_reset' => ['label' => 'Ajustement', 'class' => 'tuning'],
    'visual_admin.slots_saved' => ['label' => 'Ajustement', 'class' => 'tuning'],
    'visual_admin.slots_reset' => ['label' => 'Ajustement', 'class' => 'tuning'],
    'visual_admin.category_badges_saved' => ['label' => 'Ajustement', 'class' => 'tuning'],
    'visual_admin.category_badges_reset' => ['label' => 'Ajustement', 'class' => 'tuning'],
    'visual_admin.category_scenes_saved' => ['label' => 'Ajustement', 'class' => 'tuning'],
    'visual_admin.category_scenes_reset' => ['label' => 'Ajustement', 'class' => 'tuning'],
    'visual_admin.snapshot_saved' => ['label' => 'Sauvegarde', 'class' => 'snapshot'],
    'visual_admin.snapshot_deleted' => ['label' => 'Sauvegarde', 'class' => 'snapshot'],
    'visual_admin.snapshot_autobackup_saved' => ['label' => 'Sauvegarde auto', 'class' => 'autobackup'],
    'visual_admin.settings_exported' => ['label' => 'Export', 'class' => 'export'],
];
$visualActionRecommendations = [
    'visual_admin.preset_applied' => 'Restaurer le backup associe si l application du preset a ete trop large.',
    'visual_admin.preset_slots_aligned' => 'Bonne marche arriere si un realignement shell ou hero a deplace trop de surfaces.',
    'visual_admin.preset_strict_aligned' => 'Retour complet deja joue ; verifier les surfaces avant toute nouvelle personnalisation.',
    'visual_admin.settings_imported' => 'Preferer le backup associe si l import a injecte trop d ecarts a la fois.',
    'visual_admin.snapshot_applied' => 'Verifier les surfaces touchees juste apres restauration pour confirmer le bon point de retour.',
    'visual_admin.branding_saved' => 'Un reset branding suffit en general si seul le logo ou le sous-titre a derive.',
    'visual_admin.commune_theme_profile_applied' => 'Verifier ensuite le trio login, shell lateral et tableau de bord pour confirmer que la commune reste lisible sur la base neutre.',
    'visual_admin.branding_reset' => 'Le branding a deja ete nettoye ; inutile de faire un retour strict si le reste est stable.',
    'visual_admin.slots_saved' => 'Un realignement des slots sur le preset est la bonne marche arriere pour une derive hero ou shell.',
    'visual_admin.slots_reset' => 'Les slots sont revenus au defaut ; verifier ensuite si un preset doit etre reapplique.',
    'visual_admin.category_badges_saved' => 'Nettoyer les badges categories suffit souvent sans toucher au reste du preset.',
    'visual_admin.category_badges_reset' => 'Les badges categories ont deja ete remis a plat.',
    'visual_admin.category_scenes_saved' => 'Nettoyer les scenes categories suffit souvent sans toucher au reste du preset.',
    'visual_admin.category_scenes_reset' => 'Les scenes categories ont deja ete remises a plat.',
];

$recentChangeCounts = [
    'structuring' => 0,
    'tuning' => 0,
    'rollback' => 0,
    'snapshot' => 0,
    'autobackup' => 0,
    'export' => 0,
];
foreach ($recentVisualChanges as $change) {
    $kindClass = $visualActionKinds[$change['action']]['class'] ?? 'tuning';
    if (!isset($recentChangeCounts[$kindClass])) {
        $recentChangeCounts[$kindClass] = 0;
    }
    $recentChangeCounts[$kindClass]++;
}

$recentSurfaceCounts = [];
foreach ($recentVisualChanges as $change) {
    $details = json_decode((string)($change['log_details'] ?? ''), true);
    $surfaces = visual_admin_collect_change_surfaces(
        (string)$change['action'],
        is_array($details) ? $details : [],
        $visualPresets,
        $slotSurfaceMap
    );
    foreach (array_keys($surfaces) as $surfaceLabel) {
        if (!isset($recentSurfaceCounts[$surfaceLabel])) {
            $recentSurfaceCounts[$surfaceLabel] = 0;
        }
        $recentSurfaceCounts[$surfaceLabel]++;
    }
}
arsort($recentSurfaceCounts);
$recentSurfaceCounts = array_slice($recentSurfaceCounts, 0, 6, true);

$snapshots = visual_admin_list_snapshots();
$manualSnapshots = array_values(array_filter(
    $snapshots,
    static fn(array $snapshot): bool => (($snapshot['metadata']['type'] ?? 'manual') !== 'auto')
));
$autoSnapshots = array_values(array_filter(
    $snapshots,
    static fn(array $snapshot): bool => (($snapshot['metadata']['type'] ?? 'manual') === 'auto')
));
$latestAutoSnapshot = $autoSnapshots[0] ?? null;
$manualSnapshotProfileSummary = visual_admin_summarize_snapshot_profiles($manualSnapshots);
$autoSnapshotProfileSummary = visual_admin_summarize_snapshot_profiles($autoSnapshots);
$manualLatestByProfile = visual_admin_latest_snapshot_by_profile($manualSnapshots);
$autoLatestByProfile = visual_admin_latest_snapshot_by_profile($autoSnapshots);
$snapshotsById = [];
foreach ($snapshots as $snapshot) {
    $snapshotsById[(string)($snapshot['id'] ?? '')] = $snapshot;
}

$slotMetaIndex = [];
foreach ($slotGroups as $groupTitle => $groupSlots) {
    foreach ($groupSlots as $slot => $meta) {
        $slotMetaIndex[$slot] = $meta + ['group' => $groupTitle];
    }
}

$presetComparisons = [];
foreach ($visualPresets as $presetId => $preset) {
    $changes = [];
    $unchanged = 0;
    $surfaceCounts = [];
    $surfaceBreakdown = [];
    foreach (($preset['slots'] ?? []) as $slot => $targetAsset) {
        $meta = $slotMetaIndex[$slot] ?? ['label' => $slot, 'fallback' => null, 'group' => 'Preset'];
        $currentEffective = visual_admin_slot_asset($slot, $meta['fallback'] ?? null);
        if ($currentEffective === $targetAsset) {
            $unchanged++;
            continue;
        }
        $changes[] = [
            'slot' => $slot,
            'label' => $meta['label'],
            'current' => $currentEffective ?: 'Aucun',
            'target' => $targetAsset ?: 'Aucun',
            'current_url' => $currentEffective ? generated_visual_url($currentEffective) : null,
            'target_url' => $targetAsset ? generated_visual_url($targetAsset) : null,
            'surface_label' => $slotSurfaceMap[$slot]['label'] ?? ($meta['group'] ?? 'Surface'),
            'surface_url' => $slotSurfaceMap[$slot]['url'] ?? '/admin/?page=dashboard',
        ];
        $surfaceLabel = $slotSurfaceMap[$slot]['label'] ?? ($meta['group'] ?? 'Surface');
        $surfaceUrl = $slotSurfaceMap[$slot]['url'] ?? '/admin/?page=dashboard';
        $surfaceKey = $surfaceLabel . '|' . $surfaceUrl;
        if (!isset($surfaceCounts[$surfaceKey])) {
            $surfaceCounts[$surfaceKey] = [
                'label' => $surfaceLabel,
                'url' => $surfaceUrl,
                'count' => 0,
            ];
        }
        $surfaceCounts[$surfaceKey]['count']++;
        if (!isset($surfaceBreakdown[$surfaceKey])) {
            $surfaceBreakdown[$surfaceKey] = [
                'label' => $surfaceLabel,
                'url' => $surfaceUrl,
                'count' => 0,
                'types' => [],
            ];
        }
        $surfaceBreakdown[$surfaceKey]['count']++;
        $surfaceBreakdown[$surfaceKey]['types']['slot'] = ($surfaceBreakdown[$surfaceKey]['types']['slot'] ?? 0) + 1;
    }

    $surfaceCounts = array_values($surfaceCounts);
    usort($surfaceCounts, static function (array $a, array $b): int {
        return $b['count'] <=> $a['count'];
    });
    $surfaceBreakdown = array_values($surfaceBreakdown);
    usort($surfaceBreakdown, static function (array $a, array $b): int {
        return $b['count'] <=> $a['count'];
    });

    $presetComparisons[$presetId] = [
        'change_count' => count($changes),
        'unchanged_count' => $unchanged,
        'sample_changes' => array_slice($changes, 0, 4),
        'top_surfaces' => array_slice($surfaceCounts, 0, 3),
        'surface_breakdown' => array_slice($surfaceBreakdown, 0, 4),
        'is_active' => count($changes) === 0,
    ];
}

$snapshotComparisons = [];
foreach ($snapshots as $snapshot) {
    $snapshotSettings = visual_admin_sanitize_settings((array)($snapshot['settings'] ?? []));
    $changes = [];
    $unchanged = 0;

    foreach (($settings['branding'] ?? []) as $key => $currentValue) {
        $targetValue = $snapshotSettings['branding'][$key] ?? null;
        if ($currentValue === $targetValue) {
            $unchanged++;
            continue;
        }
        $changes[] = [
            'label' => $key === 'brand_mark_url' ? 'Branding · logo' : 'Branding · sous-titre login',
            'current' => is_string($currentValue) && $currentValue !== '' ? $currentValue : 'Defaut',
            'target' => is_string($targetValue) && $targetValue !== '' ? $targetValue : 'Defaut',
            'current_url' => visual_admin_preview_media_url(is_string($currentValue) ? $currentValue : null),
            'target_url' => visual_admin_preview_media_url(is_string($targetValue) ? $targetValue : null),
            'surface_label' => $key === 'brand_mark_url' ? 'Shell / Connexion' : 'Connexion',
            'surface_url' => $key === 'brand_mark_url' ? '/admin/?page=dashboard' : '/admin/?page=login',
        ];
    }

    foreach ($slotMetaIndex as $slot => $meta) {
        $currentValue = $settings['slots'][$slot] ?? null;
        $targetValue = $snapshotSettings['slots'][$slot] ?? null;
        if ($currentValue === $targetValue) {
            $unchanged++;
            continue;
        }
        $currentEffective = visual_admin_slot_asset($slot, $meta['fallback'] ?? null);
        $targetEffective = null;
        if (is_string($targetValue) && $targetValue !== '' && generated_visual_url($targetValue) !== null) {
            $targetEffective = $targetValue;
        } elseif (!empty($meta['fallback']) && generated_visual_url((string)$meta['fallback']) !== null) {
            $targetEffective = (string)$meta['fallback'];
        }
        $changes[] = [
            'label' => $meta['label'],
            'current' => $currentEffective ?: 'Aucun',
            'target' => $targetEffective ?: 'Aucun',
            'current_url' => $currentEffective ? generated_visual_url($currentEffective) : null,
            'target_url' => $targetEffective ? generated_visual_url($targetEffective) : null,
            'surface_label' => $slotSurfaceMap[$slot]['label'] ?? ($meta['group'] ?? 'Surface'),
            'surface_url' => $slotSurfaceMap[$slot]['url'] ?? '/admin/?page=dashboard',
        ];
    }

    foreach ($categoryEntries as $entry) {
        $categoryKey = (string)($entry['key'] ?? '');
        if ($categoryKey === '') {
            continue;
        }

        $currentBadge = $settings['category_badges'][$categoryKey] ?? null;
        $targetBadge = $snapshotSettings['category_badges'][$categoryKey] ?? null;
        if ($currentBadge === $targetBadge) {
            $unchanged++;
        } else {
            $changes[] = [
                'label' => ($entry['label'] ?? $categoryKey) . ' · badge',
                'current' => is_string($currentBadge) && $currentBadge !== '' ? $currentBadge : 'Defaut',
                'target' => is_string($targetBadge) && $targetBadge !== '' ? $targetBadge : 'Defaut',
                'current_url' => visual_admin_preview_media_url(is_string($currentBadge) ? $currentBadge : null),
                'target_url' => visual_admin_preview_media_url(is_string($targetBadge) ? $targetBadge : null),
                'surface_label' => 'Categories',
                'surface_url' => '/admin/?page=categories',
            ];
        }

        $currentScene = $settings['category_scenes'][$categoryKey] ?? null;
        $targetScene = $snapshotSettings['category_scenes'][$categoryKey] ?? null;
        if ($currentScene === $targetScene) {
            $unchanged++;
            continue;
        }
        $changes[] = [
            'label' => ($entry['label'] ?? $categoryKey) . ' · scene',
            'current' => is_string($currentScene) && $currentScene !== '' ? $currentScene : 'Defaut',
            'target' => is_string($targetScene) && $targetScene !== '' ? $targetScene : 'Defaut',
            'current_url' => visual_admin_preview_media_url(is_string($currentScene) ? $currentScene : null),
            'target_url' => visual_admin_preview_media_url(is_string($targetScene) ? $targetScene : null),
            'surface_label' => 'Categories',
            'surface_url' => '/admin/?page=categories',
        ];
    }

    $snapshotComparisons[(string)$snapshot['id']] = [
        'change_count' => count($changes),
        'unchanged_count' => $unchanged,
        'sample_changes' => array_slice($changes, 0, 4),
        'is_active' => count($changes) === 0,
    ];
}

$activePresetMatches = [];
foreach ($visualPresets as $presetId => $preset) {
    if (($presetComparisons[$presetId]['is_active'] ?? false) === true) {
        $activePresetMatches[] = [
            'id' => $presetId,
            'label' => (string)($preset['label'] ?? $presetId),
        ];
    }
}

$closestPreset = null;
foreach ($visualPresets as $presetId => $preset) {
    $comparison = $presetComparisons[$presetId] ?? null;
    if (!is_array($comparison)) {
        continue;
    }
    if ($closestPreset === null || ($comparison['change_count'] ?? PHP_INT_MAX) < ($closestPreset['change_count'] ?? PHP_INT_MAX)) {
        $closestPreset = [
            'id' => $presetId,
            'label' => (string)($preset['label'] ?? $presetId),
            'slot_change_count' => (int)($comparison['change_count'] ?? 0),
            'change_count' => (int)($comparison['change_count'] ?? 0),
            'unchanged_count' => (int)($comparison['unchanged_count'] ?? 0),
            'top_surfaces' => (array)($comparison['top_surfaces'] ?? []),
            'surface_breakdown' => (array)($comparison['surface_breakdown'] ?? []),
            'sample_changes' => (array)($comparison['sample_changes'] ?? []),
        ];
    }
}

$activeSnapshotMatch = null;
foreach ($snapshots as $snapshot) {
    if (($snapshotComparisons[(string)$snapshot['id']]['is_active'] ?? false) === true) {
        $activeSnapshotMatch = $snapshot;
        break;
    }
}

$nonPresetOverrideCount = count($activeBrandingOverrides) + count($activeCategoryBadgeOverrides) + count($activeCategorySceneOverrides);
$isStrictPresetState = !empty($activePresetMatches) && $nonPresetOverrideCount === 0;

$currentVisualPostureLabel = $isStrictPresetState
    ? 'Preset actif'
    : 'Configuration custom';
$currentVisualPostureValue = $isStrictPresetState
    ? $activePresetMatches[0]['label']
    : (!empty($activePresetMatches) ? $activePresetMatches[0]['label'] . ' + custom' : 'Aucun preset exact');

$nonPresetSurfaces = [];
if ($activeBrandingOverrides) {
    $nonPresetSurfaces['Connexion'] = ['label' => 'Connexion', 'url' => '/admin/?page=login'];
    $nonPresetSurfaces['Shell lateral'] = ['label' => 'Shell lateral', 'url' => '/admin/?page=dashboard'];
}
if ($activeCategoryBadgeOverrides || $activeCategorySceneOverrides) {
    $nonPresetSurfaces['Categories'] = ['label' => 'Categories', 'url' => '/admin/?page=categories'];
}
$strictResetScopes = [];
if ($activeBrandingOverrides) {
    $strictResetScopes[] = sprintf('branding (%d)', count($activeBrandingOverrides));
}
if ($activeCategoryBadgeOverrides || $activeCategorySceneOverrides) {
    $strictResetScopes[] = sprintf(
        'categories (%d)',
        count($activeCategoryBadgeOverrides) + count($activeCategorySceneOverrides)
    );
}
$strictResetItems = array_slice(
    array_merge($activeBrandingOverrides, $activeCategoryBadgeOverrides, $activeCategorySceneOverrides),
    0,
    4
);
$closestPresetDriftItems = [];
foreach ($activeBrandingOverrides as $item) {
    $closestPresetDriftItems[] = [
        'label' => (string)$item['label'],
        'current' => (string)$item['value'],
        'target' => 'Defaut preset',
        'surface_label' => $item['label'] === 'Logo de marque' ? 'Shell lateral' : 'Connexion',
        'surface_url' => $item['label'] === 'Logo de marque' ? '/admin/?page=dashboard' : '/admin/?page=login',
    ];
}
foreach ($activeCategoryBadgeOverrides as $item) {
    $closestPresetDriftItems[] = [
        'label' => (string)$item['label'] . ' · badge',
        'current' => (string)$item['value'],
        'target' => 'Defaut preset',
        'surface_label' => 'Categories',
        'surface_url' => '/admin/?page=categories',
    ];
}
foreach ($activeCategorySceneOverrides as $item) {
    $closestPresetDriftItems[] = [
        'label' => (string)$item['label'] . ' · scene',
        'current' => (string)$item['value'],
        'target' => 'Defaut preset',
        'surface_label' => 'Categories',
        'surface_url' => '/admin/?page=categories',
    ];
}
if ($closestPreset) {
    $closestPreset['change_count'] += $nonPresetOverrideCount;
    $closestPreset['sample_changes'] = array_slice(
        array_merge($closestPresetDriftItems, (array)($closestPreset['sample_changes'] ?? [])),
        0,
        4
    );
    $closestPresetSurfaceBreakdown = [];
    foreach ((array)($closestPreset['surface_breakdown'] ?? []) as $surface) {
        $key = (string)($surface['label'] ?? '') . '|' . (string)($surface['url'] ?? '');
        if ($key === '|') {
            continue;
        }
        $closestPresetSurfaceBreakdown[$key] = [
            'label' => (string)($surface['label'] ?? 'Surface'),
            'url' => (string)($surface['url'] ?? '/admin/?page=dashboard'),
            'count' => (int)($surface['count'] ?? 0),
            'types' => (array)($surface['types'] ?? []),
        ];
    }
    foreach ($closestPresetDriftItems as $item) {
        $key = (string)$item['surface_label'] . '|' . (string)$item['surface_url'];
        if (!isset($closestPresetSurfaceBreakdown[$key])) {
            $closestPresetSurfaceBreakdown[$key] = [
                'label' => (string)$item['surface_label'],
                'url' => (string)$item['surface_url'],
                'count' => 0,
                'types' => [],
            ];
        }
        $closestPresetSurfaceBreakdown[$key]['count']++;
        $type = 'slot';
        if (str_contains((string)$item['label'], 'badge')) {
            $type = 'badge';
        } elseif (str_contains((string)$item['label'], 'scene')) {
            $type = 'scene';
        } elseif (str_contains((string)$item['label'], 'Logo') || str_contains((string)$item['label'], 'Sous-titre')) {
            $type = 'branding';
        }
        $closestPresetSurfaceBreakdown[$key]['types'][$type] = ($closestPresetSurfaceBreakdown[$key]['types'][$type] ?? 0) + 1;
    }
    $closestPresetSurfaceBreakdown = array_values($closestPresetSurfaceBreakdown);
    usort($closestPresetSurfaceBreakdown, static function (array $a, array $b): int {
        return $b['count'] <=> $a['count'];
    });
    $closestPreset['surface_breakdown'] = array_slice($closestPresetSurfaceBreakdown, 0, 4);
}
$driftFamilyCounts = [];
if ($closestPreset && !empty($closestPreset['slot_change_count'])) {
    $driftFamilyCounts['slots'] = (int)$closestPreset['slot_change_count'];
}
if ($activeBrandingOverrides) {
    $driftFamilyCounts['branding'] = count($activeBrandingOverrides);
}
$categoryDriftCount = count($activeCategoryBadgeOverrides) + count($activeCategorySceneOverrides);
if ($categoryDriftCount > 0) {
    $driftFamilyCounts['categories'] = $categoryDriftCount;
}
$driftRecommendations = [];
if ($activeBrandingOverrides) {
    $driftRecommendations[] = [
        'title' => 'Nettoyer le branding custom',
        'detail' => 'retire les overrides logo / sous-titre sans toucher aux slots ni aux categories.',
    ];
}
if ($closestPreset && !empty($closestPreset['slot_change_count'])) {
    $driftRecommendations[] = [
        'title' => 'Realigner les slots sur le preset',
        'detail' => 'recolle les heroes et visuels shell au preset reconnu sans effacer branding ou categories.',
    ];
}
if ($activeCategoryBadgeOverrides) {
    $driftRecommendations[] = [
        'title' => 'Nettoyer les badges categories',
        'detail' => 'retire les badges categories custom pour revenir a la base du preset.',
    ];
}
if ($activeCategorySceneOverrides) {
    $driftRecommendations[] = [
        'title' => 'Nettoyer les scenes categories',
        'detail' => 'retire les scenes categories custom pour revenir a la base du preset.',
    ];
}
if (count($driftFamilyCounts) > 1) {
    $driftRecommendations[] = [
        'title' => 'Finir par un alignement strict complet si necessaire',
        'detail' => 'utile seulement si plusieurs familles ont derive et qu un nettoyage local ne suffit plus.',
    ];
}
$driftDecision = null;
if ($driftFamilyCounts) {
    $familyCount = count($driftFamilyCounts);
    $totalDriftCount = array_sum($driftFamilyCounts);
    if ($familyCount === 1 && isset($driftFamilyCounts['slots'])) {
        $driftDecision = [
            'label' => 'Realignement de famille recommande',
            'detail' => 'Les slots shell ou heroes ont derive. Le plus propre est de les recoller au preset reconnu via le realignement dedie.',
            'class' => 'medium',
        ];
    } elseif ($familyCount === 1 && $totalDriftCount <= 2) {
        $driftDecision = [
            'label' => 'Nettoyage local suffisant',
            'detail' => 'Un ajustement cible devrait suffire pour revenir sur le preset reconnu.',
            'class' => 'light',
        ];
    } elseif ($familyCount === 1) {
        $driftDecision = [
            'label' => 'Realignement de famille recommande',
            'detail' => 'Une famille complete a derive. Mieux vaut utiliser le nettoyage ou realignement dedie a cette famille.',
            'class' => 'medium',
        ];
    } else {
        $driftDecision = [
            'label' => 'Retour strict a considerer',
            'detail' => 'Plusieurs familles ont derive. Commence par les nettoyages cibles, puis termine par un alignement strict si l etat reste hybride.',
            'class' => 'strong',
        ];
    }
}

$surfaceCards = [];
foreach ($slotSurfaceMap as $slot => $surface) {
    $meta = $slotMetaIndex[$slot] ?? ['label' => $slot, 'fallback' => null];
    $surfaceKey = md5(($surface['label'] ?? 'Surface') . '|' . ($surface['url'] ?? ''));
    if (!isset($surfaceCards[$surfaceKey])) {
        $surfaceCards[$surfaceKey] = [
            'label' => $surface['label'] ?? 'Surface',
            'url' => $surface['url'] ?? '/admin/?page=dashboard',
            'slots' => [],
            'override_count' => 0,
        ];
    }

    $currentRaw = $settings['slots'][$slot] ?? null;
    $effectiveAsset = visual_admin_slot_asset($slot, $meta['fallback'] ?? null);
    $surfaceCards[$surfaceKey]['slots'][] = [
        'label' => $meta['label'],
        'asset' => $effectiveAsset,
        'url' => $effectiveAsset ? generated_visual_url($effectiveAsset) : null,
        'is_override' => is_string($currentRaw) && $currentRaw !== '',
    ];
    if (is_string($currentRaw) && $currentRaw !== '') {
        $surfaceCards[$surfaceKey]['override_count']++;
    }
}

$visualAdminSectionLinks = [
    ['href' => '#visual-admin-state', 'step' => '01', 'label' => 'Etat du poste', 'detail' => 'Lire les overrides actifs et la derive courante.'],
    ['href' => '#visual-admin-presets', 'step' => '02', 'label' => 'Surfaces pilotees', 'detail' => 'Verifier les pages reelles impactees par les visuels.'],
    ['href' => '#visual-admin-recent', 'step' => '03', 'label' => 'Changements recents', 'detail' => 'Relire les mutations recentes et les actions rollback.'],
    ['href' => '#visual-admin-preset-library', 'step' => '04', 'label' => 'Presets et snapshots', 'detail' => 'Appliquer une base ou revenir a un etat enregistre.'],
    ['href' => '#visual-admin-commune-presets', 'step' => '05', 'label' => 'Presets commune', 'detail' => 'Decliner une commune sans refaire le shell.'],
    ['href' => '#visual-admin-branding', 'step' => '06', 'label' => 'Branding', 'detail' => 'Piloter logo et sous-titre partage.'],
    ['href' => '#visual-admin-inventory', 'step' => '06', 'label' => 'Inventaire et transfert', 'detail' => 'Exporter ou restaurer une configuration.'],
    ['href' => '#visual-admin-slots', 'step' => '07', 'label' => 'Slots visuels', 'detail' => 'Remapper shell et heroes.'],
    ['href' => '#visual-admin-badges', 'step' => '08', 'label' => 'Badges categories', 'detail' => 'Ajuster les reperes compacts de lecture.'],
    ['href' => '#visual-admin-scenes', 'step' => '09', 'label' => 'Scenes categories', 'detail' => 'Aligner les illustrations de contexte.'],
];

require_once __DIR__ . '/../includes/layout.php';
?>
<div class="page-async-scope" data-async-scope="visual-admin">
  <div class="page-hero page-hero--with-visual">
    <div class="page-hero-copy">
      <div class="page-hero-kicker">Studio de configuration</div>
      <div class="page-hero-title">Piloter le branding et les repères visuels sans repasser par le code</div>
      <div class="page-hero-text">
        Ce poste permet de remplacer le logo, ajuster le sous-titre de connexion et remapper les slots visuels réellement utilises par le shell et les pages clefs du backoffice.
      </div>
      <div class="page-hero-actions">
        <a href="#visual-admin-state" class="btn btn-outline">Etat du poste</a>
        <a href="#visual-admin-preset-library" class="btn btn-outline">Presets</a>
        <a href="#visual-admin-rollback" class="btn btn-outline">Rollbacks</a>
        <a href="#visual-admin-branding" class="btn btn-outline">Branding</a>
      </div>
    </div>
    <div class="page-hero-metrics">
      <div class="hero-chip">
        <span class="hero-chip-value"><?= count($assetOptions) ?></span>
        <span class="hero-chip-label">assets admin disponibles</span>
      </div>
      <div class="hero-chip">
        <span class="hero-chip-value"><?= array_sum(array_map('count', $slotGroups)) ?></span>
        <span class="hero-chip-label">slots configurables</span>
      </div>
      <div class="hero-chip">
        <span class="hero-chip-value">1</span>
        <span class="hero-chip-label">poste visuel centralise</span>
      </div>
    </div>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
        <img src="<?= e($brandMarkUrl) ?>" alt="Brand mark en vigueur" class="generated-visual generated-visual--contain hero-visual-stack-main">
        <?= generated_visual_html('CHAR-05', ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Relais studio visuel']) ?>
        <div class="generated-visual-caption hero-visual-stack-copy">
          <strong>Branding pilotable</strong>
          <span>Logo, shell et heroes partagent maintenant une base de configuration mutualisee.</span>
        </div>
      </div>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= e($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
  <?php endif; ?>

  <!-- Guidance removed to save vertical space as requested -->
  <?php if ($isTrainingMode): ?>
  <section class="card visual-admin-card visual-admin-card--summary visual-admin-card--index" id="visual-admin-index">
    <div class="card-header">
      <span class="card-title">Parcours du poste</span>
      <span class="text-small text-muted">Index local pour aller directement vers la bonne zone sans relire toute la page</span>
    </div>
    <div class="visual-admin-workflow-strip">
      <div class="visual-admin-workflow-item">
        <span class="visual-admin-workflow-step">01</span>
        <div>
          <strong>Poser la base commune</strong>
          <span>Commencer par le preset commune ou le branding avant d ouvrir les exceptions slot par slot.</span>
        </div>
      </div>
      <div class="visual-admin-workflow-item">
        <span class="visual-admin-workflow-step">02</span>
        <div>
          <strong>Verifier les surfaces reelles</strong>
          <span>Relire shell, dashboard, signalements et categories au lieu de rester dans un pilotage abstrait.</span>
        </div>
      </div>
      <div class="visual-admin-workflow-item">
        <span class="visual-admin-workflow-step">03</span>
        <div>
          <strong>Figer ou revenir vite</strong>
          <span>Si la direction est valide, enregistrer un snapshot. Sinon revenir par backup associe sans bricolage.</span>
        </div>
      </div>
    </div>
    <div class="visual-admin-section-index">
      <?php foreach ($visualAdminSectionLinks as $sectionLink): ?>
        <a href="<?= e($sectionLink['href']) ?>" class="visual-admin-section-link">
          <span class="visual-admin-section-step"><?= e($sectionLink['step']) ?></span>
          <strong><?= e($sectionLink['label']) ?></strong>
          <span><?= e($sectionLink['detail']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="card visual-admin-card visual-admin-card--summary" id="visual-admin-state">
    <div class="card-header">
      <span class="card-title">Personnalisations actives</span>
      <span class="text-small text-muted">Lecture rapide des changements qui s'ecartent du pack visuel par defaut</span>
    </div>
    <div class="visual-admin-summary-grid">
      <?php if (!empty($activeBrandingOverrides)): ?>
      <div class="visual-admin-summary-card">
        <strong><?= count($activeBrandingOverrides) ?></strong>
        <span>branding</span>
        <div class="visual-admin-summary-list">
            <div class="visual-admin-preview-grid">
              <?php foreach ($activeBrandingOverrides as $item): ?>
                <div class="visual-admin-preview-item">
                  <?php if (!empty($item['preview_url'])): ?>
                     <img src="<?= e($item['preview_url']) ?>" alt="Apercu <?= e($item['label']) ?>" class="visual-admin-preview-thumb">
                  <?php elseif (preg_match('/^#[a-f0-9]{3,6}$/i', $item['value'])): ?>
                     <div class="visual-admin-preview-thumb" style="background: <?= e($item['value']) ?>;"></div>
                  <?php elseif (in_array($item['label'], ['Sous-titre login', 'Preset de theme'])): ?>
                     <div class="visual-admin-preview-thumb visual-admin-preview-thumb--text"><span>Aa</span></div>
                  <?php else: ?>
                     <div class="visual-admin-preview-thumb visual-admin-preview-thumb--fallback"></div>
                  <?php endif; ?>
                  <div class="visual-admin-preview-meta">
                    <strong><?= e($item['label']) ?></strong>
                    <span><?= e($item['value']) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!empty($activeSlotOverrides)): ?>
      <div class="visual-admin-summary-card">
        <strong><?= count($activeSlotOverrides) ?></strong>
        <span>emplacements visuels</span>
        <div class="visual-admin-summary-list">
            <div class="visual-admin-preview-grid">
              <?php foreach (array_slice($activeSlotOverrides, 0, 6) as $item): ?>
                <div class="visual-admin-preview-item">
                  <?php if (!empty($item['preview_url'])): ?>
                     <img src="<?= e($item['preview_url']) ?>" alt="Apercu <?= e($item['label']) ?>" class="visual-admin-preview-thumb">
                  <?php else: ?>
                     <div class="visual-admin-preview-thumb visual-admin-preview-thumb--fallback"></div>
                  <?php endif; ?>
                  <div class="visual-admin-preview-meta">
                    <strong><?= e($item['label']) ?></strong>
                    <span><a href="<?= e($item['surface_url']) ?>" data-async-link data-async-scope="admin-main"><?= e($item['surface_label']) ?></a></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($activeSlotOverrides) > 6): ?>
              <span class="text-small text-muted">+<?= count($activeSlotOverrides) - 6 ?> element(s) d'emplacement(s)</span>
            <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!empty($activeCategoryBadgeOverrides) || !empty($activeCategorySceneOverrides)): ?>
      <div class="visual-admin-summary-card">
        <strong><?= count($activeCategoryBadgeOverrides) + count($activeCategorySceneOverrides) ?></strong>
        <span>surcharges categories</span>
        <div class="visual-admin-summary-list">
            <div class="visual-admin-preview-grid">
              <?php foreach (array_slice(array_merge($activeCategoryBadgeOverrides, $activeCategorySceneOverrides), 0, 6) as $item): ?>
                <div class="visual-admin-preview-item">
                  <?php if (!empty($item['preview_url'])): ?>
                     <img src="<?= e($item['preview_url']) ?>" alt="Apercu <?= e($item['label']) ?>" class="visual-admin-preview-thumb">
                  <?php else: ?>
                     <div class="visual-admin-preview-thumb visual-admin-preview-thumb--fallback"></div>
                  <?php endif; ?>
                  <div class="visual-admin-preview-meta">
                    <strong><?= e($item['label']) ?></strong>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if ((count($activeCategoryBadgeOverrides) + count($activeCategorySceneOverrides)) > 6): ?>
              <span class="text-small text-muted">+<?= (count($activeCategoryBadgeOverrides) + count($activeCategorySceneOverrides)) - 6 ?> element(s)</span>
            <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="visual-admin-summary-card visual-admin-summary-card--state">
        <div class="visual-admin-summary-list">
          <?php if ($isStrictPresetState): ?>
            <div>
              <div class="visual-admin-status-indicator visual-admin-status-indicator--green"></div>
              <strong>Preset reconnu</strong>
              <span><?= e($activePresetMatches[0]['label']) ?></span>
            </div>
          <?php else: ?>
            <div>
              <div class="visual-admin-status-indicator visual-admin-status-indicator--yellow"></div>
              <strong>Etat courant</strong>
              <span>Combinaison customisée d'overrides et de slots actifs.</span>
            </div>
            <?php if ($activePresetMatches): ?>
              <div>
                <strong>Base reconnue</strong>
                <span><?= e($activePresetMatches[0]['label']) ?></span>
                <div class="visual-admin-drift-gauge visual-admin-drift-gauge--low">
                   <div class="gauge-fill" style="width: 15%"></div>
                </div>
                <span class="text-small"><?= (int)$nonPresetOverrideCount ?> override(s) hors preset.</span>
              </div>
            <?php endif; ?>
            <?php if ($nonPresetSurfaces): ?>
              <div>
                <strong>Surfaces hors preset</strong>
                <div class="visual-admin-tag-list">
                  <?php foreach ($nonPresetSurfaces as $surface): ?>
                    <a href="<?= e((string)$surface['url']) ?>" class="visual-admin-tag" data-async-link data-async-scope="admin-main">
                      <img src="/admin/assets/img/kpi-icons/clay_icon_warning.png" alt="Warning" class="visual-admin-inline-icon">
                      <?= e((string)$surface['label']) ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
            <?php if ($closestPreset): ?>
              <div>
                <strong>Preset le plus proche</strong>
                <span><?= e($closestPreset['label']) ?></span>
                <div class="visual-admin-drift-gauge visual-admin-drift-gauge--<?= $closestPreset['change_count'] > 10 ? 'high' : 'medium' ?>">
                   <div class="gauge-fill" style="width: <?= min(100, max(5, $closestPreset['change_count'] * 2)) ?>%"></div>
                </div>
                <span class="text-small text-muted"><?= (int)$closestPreset['change_count'] ?> ecart(s) restant(s) pour retrouver ce socle.</span>
              </div>
              <?php if (!empty($closestPreset['sample_changes'])): ?>
                <div>
                  <strong>Ecarts restants vers le preset</strong>
                  <span class="visual-admin-inline-diff-list">
                    <?php foreach (array_slice($closestPreset['sample_changes'], 0, 3) as $index => $change): ?>
                      <span class="visual-admin-inline-diff-item">
                        <?php if ($index > 0): ?> · <?php endif; ?>
                        <strong><?= e((string)$change['label']) ?></strong>
                        <span><?= e((string)$change['current']) ?> → <?= e((string)$change['target']) ?></span>
                        <a href="<?= e((string)$change['surface_url']) ?>" data-async-link data-async-scope="admin-main"><?= e((string)$change['surface_label']) ?></a>
                      </span>
                    <?php endforeach; ?>
                  </span>
                </div>
              <?php endif; ?>
              <?php if (!empty($closestPreset['surface_breakdown'])): ?>
                <div>
                  <strong>Derive par surface</strong>
                  <span class="visual-admin-inline-diff-list">
                    <?php foreach (array_slice($closestPreset['surface_breakdown'], 0, 3) as $index => $surface): ?>
                      <?php
                        $typeLabels = [];
                        foreach ((array)($surface['types'] ?? []) as $type => $count) {
                            $typeLabels[] = sprintf('%s (%d)', $type, (int)$count);
                        }
                      ?>
                      <span class="visual-admin-inline-diff-item">
                        <?php if ($index > 0): ?> · <?php endif; ?>
                        <a href="<?= e((string)$surface['url']) ?>" data-async-link data-async-scope="admin-main"><?= e((string)$surface['label']) ?></a>
                        <span><?= (int)$surface['count'] ?> ecart(s) · <?= e(implode(', ', $typeLabels)) ?></span>
                      </span>
                    <?php endforeach; ?>
                  </span>
                </div>
              <?php endif; ?>
              <?php if ($driftFamilyCounts): ?>
                <div>
                  <strong>Derive par famille</strong>
                  <div class="visual-admin-family-badges">
                    <?php foreach ($driftFamilyCounts as $family => $count): ?>
                      <?php 
                        $iconMap = [
                          'slots' => 'clay_icon_document.png',
                          'branding' => 'clay_icon_id_badge.png',
                          'categories' => 'clay_icon_magnifier.png'
                        ];
                        $icon = $iconMap[$family] ?? 'clay_icon_gear.png';
                      ?>
                      <div class="visual-admin-family-badge">
                        <img src="/admin/assets/img/kpi-icons/<?= $icon ?>" alt="<?= e($family) ?>">
                        <span><strong><?= e($family) ?></strong> (<?= (int)$count ?>)</span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
              <?php if ($driftDecision): ?>
                <div class="visual-admin-decision visual-admin-decision--<?= e((string)$driftDecision['class']) ?>">
                  <strong>Niveau d intervention</strong>
                  <span><?= e((string)$driftDecision['label']) ?></span>
                  <p><?= e((string)$driftDecision['detail']) ?></p>
                </div>
              <?php endif; ?>
              <?php if ($driftRecommendations): ?>
                <div>
                  <strong>Plan de retour conseille</strong>
                  <span class="visual-admin-recommendation-list">
                    <?php foreach ($driftRecommendations as $index => $recommendation): ?>
                      <span class="visual-admin-recommendation-item">
                        <strong><?= ($index + 1) ?>. <?= e((string)$recommendation['title']) ?></strong>
                        <span><?= e((string)$recommendation['detail']) ?></span>
                      </span>
                    <?php endforeach; ?>
                  </span>
                </div>
              <?php endif; ?>
              <?php if (!empty($closestPreset['top_surfaces']) && !$nonPresetSurfaces): ?>
                <div>
                  <strong>Surfaces les plus ecartees</strong>
                  <span>
                    <?php foreach ($closestPreset['top_surfaces'] as $index => $surface): ?>
                      <?php if ($index > 0): ?> · <?php endif; ?>
                      <a href="<?= e((string)$surface['url']) ?>" data-async-link data-async-scope="admin-main"><?= e((string)$surface['label']) ?></a> (<?= (int)$surface['count'] ?>)
                    <?php endforeach; ?>
                  </span>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($activeSnapshotMatch): ?>
            <div>
              <strong>Snapshot actif</strong>
              <span><?= e((string)($activeSnapshotMatch['name'] ?? $activeSnapshotMatch['id'])) ?></span>
            </div>
          <?php elseif ($latestAutoSnapshot): ?>
            <div>
              <strong>Backup recent</strong>
              <span><?= e((string)($latestAutoSnapshot['name'] ?? $latestAutoSnapshot['id'])) ?></span>
            </div>
          <?php endif; ?>
          <?php if (!$isStrictPresetState && $closestPreset): ?>
            <?php if (!empty($driftFamilyCounts)): ?>
              <div>
                <strong>Actions conseillees par surface</strong>
                <span class="visual-admin-inline-action-list">
                  <?php if (!empty($closestPreset['slot_change_count'])): ?>
                    <form method="POST" class="visual-admin-form visual-admin-inline-form">
                      <input type="hidden" name="action" value="align_preset_slots">
                      <input type="hidden" name="preset_id" value="<?= e($closestPreset['id']) ?>">
                      <button type="submit" class="btn btn-outline">Realigner les slots sur le preset</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($activeBrandingOverrides): ?>
                    <form method="POST" class="visual-admin-form visual-admin-inline-form">
                      <input type="hidden" name="action" value="reset_branding">
                      <button type="submit" class="btn btn-outline">Nettoyer le branding custom</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($activeCategoryBadgeOverrides): ?>
                    <form method="POST" class="visual-admin-form visual-admin-inline-form">
                      <input type="hidden" name="action" value="reset_category_badges">
                      <button type="submit" class="btn btn-outline">Nettoyer les badges categories</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($activeCategorySceneOverrides): ?>
                    <form method="POST" class="visual-admin-form visual-admin-inline-form">
                      <input type="hidden" name="action" value="reset_category_scenes">
                      <button type="submit" class="btn btn-outline">Nettoyer les scenes categories</button>
                    </form>
                  <?php endif; ?>
                </span>
              </div>
            <?php endif; ?>
            <?php if ($strictResetScopes): ?>
              <div>
                <strong>L alignement strict supprimera</strong>
                <span><?= e(implode(' · ', $strictResetScopes)) ?></span>
              </div>
              <?php if ($strictResetItems): ?>
                <div>
                  <strong>Overrides concernes</strong>
                  <span>
                    <?php foreach ($strictResetItems as $index => $item): ?>
                      <?php if ($index > 0): ?> · <?php endif; ?>
                      <?= e((string)$item['label']) ?>
                    <?php endforeach; ?>
                  </span>
                </div>
              <?php endif; ?>
            <?php endif; ?>
            <form method="POST" class="visual-admin-form">
              <input type="hidden" name="action" value="align_preset_strict">
              <input type="hidden" name="preset_id" value="<?= e($closestPreset['id']) ?>">
              <button type="submit" class="btn btn-outline">Revenir a l etat strict du preset le plus proche</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="card visual-admin-card visual-admin-card--summary" id="visual-admin-presets">
    <div class="card-header">
      <span class="card-title">Surfaces pilotees</span>
      <span class="text-small text-muted">Lecture par page reelle du backoffice, avec les assets actuellement servis</span>
    </div>
    <div class="visual-admin-surface-grid">
      <?php foreach ($surfaceCards as $surface): ?>
        <div class="visual-admin-surface-card">
          <div class="visual-admin-surface-head">
            <div>
              <strong><?= e($surface['label']) ?></strong>
              <span><?= count($surface['slots']) ?> slot(s) · <?= (int)$surface['override_count'] ?> override(s)</span>
            </div>
            <a href="<?= e($surface['url']) ?>" data-async-link data-async-scope="admin-main">Ouvrir</a>
          </div>
          <div class="visual-admin-surface-media">
            <?php foreach (array_slice($surface['slots'], 0, 3) as $slot): ?>
              <div class="visual-admin-surface-media-card">
                <?php if (!empty($slot['url'])): ?>
                  <img src="<?= e($slot['url']) ?>" alt="<?= e($slot['label']) ?>">
                <?php endif; ?>
                <span><?= e($slot['label']) ?></span>
                <strong><?= e((string)($slot['asset'] ?? 'Aucun')) ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card visual-admin-card visual-admin-card--summary" id="visual-admin-recent">
    <div class="card-header">
      <span class="card-title">Derniers changements visuels</span>
      <span class="text-small text-muted">Historique recent du poste visuel, directement exploitable sans quitter cette page</span>
    </div>
    <div class="visual-admin-summary-grid visual-admin-summary-grid--changes">
      <div class="visual-admin-summary-card">
        <strong><?= (int)$recentChangeCounts['structuring'] ?></strong>
        <span>changements structurants</span>
      </div>
      <div class="visual-admin-summary-card">
        <strong><?= (int)$recentChangeCounts['tuning'] ?></strong>
        <span>ajustements fins</span>
      </div>
      <div class="visual-admin-summary-card">
        <strong><?= (int)$recentChangeCounts['rollback'] ?></strong>
        <span>rollbacks</span>
      </div>
      <div class="visual-admin-summary-card">
        <strong><?= (int)($recentChangeCounts['snapshot'] + $recentChangeCounts['autobackup']) ?></strong>
        <span>sauvegardes</span>
      </div>
    </div>
    <?php if ($recentSurfaceCounts): ?>
      <div class="visual-admin-summary-grid visual-admin-summary-grid--changes">
        <?php foreach ($recentSurfaceCounts as $surfaceLabel => $count): ?>
          <div class="visual-admin-summary-card">
            <strong><?= (int)$count ?></strong>
            <span><?= e($surfaceLabel) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="visual-admin-change-list">
      <?php if (!$recentVisualChanges): ?>
        <div class="text-small text-muted">Aucun changement visuel recent releve dans les logs d audit.</div>
      <?php else: ?>
        <?php foreach ($recentVisualChanges as $change): ?>
          <?php
            $label = $visualActionLabels[$change['action']] ?? $change['action'];
            $kind = $visualActionKinds[$change['action']] ?? ['label' => 'Ajustement', 'class' => 'tuning'];
            $details = json_decode((string)($change['log_details'] ?? ''), true);
            $summary = [];
            $relatedBackup = null;
            $changeSurfaces = visual_admin_collect_change_surfaces(
                (string)$change['action'],
                is_array($details) ? $details : [],
                $visualPresets,
                $slotSurfaceMap
            );
            if (is_array($details)) {
                foreach (['preset_id', 'login_subtitle', 'slot_count', 'slots_overridden', 'badges_overridden', 'scenes_overridden'] as $key) {
                    if (array_key_exists($key, $details) && $details[$key] !== null && $details[$key] !== '') {
                        $summary[] = $key . ': ' . (is_scalar($details[$key]) ? (string)$details[$key] : json_encode($details[$key], JSON_UNESCAPED_UNICODE));
                    }
                }
                $backupId = (string)($details['backup_snapshot_id'] ?? '');
                if ($backupId !== '' && isset($snapshotsById[$backupId])) {
                    $relatedBackup = $snapshotsById[$backupId];
                }
            }
            $changeRecommendation = $visualActionRecommendations[$change['action']] ?? null;
          ?>
          <div class="visual-admin-change-item visual-admin-change-item--<?= e($kind['class']) ?>">
            <div class="visual-admin-change-topline">
              <strong><?= e($label) ?></strong>
              <div class="visual-admin-change-pillset">
                <span class="visual-admin-change-pill visual-admin-change-pill--<?= e($kind['class']) ?>"><?= e($kind['label']) ?></span>
                <span><?= e(format_date($change['created_at'])) ?></span>
              </div>
            </div>
            <div class="visual-admin-change-meta">
              <span><?= e($change['admin_name'] ?? 'Administrateur') ?></span>
              <span><?= e($change['ip_address'] ?? 'IP indisponible') ?></span>
            </div>
            <?php if ($summary): ?>
              <div class="visual-admin-change-summary"><?= e(implode(' · ', $summary)) ?></div>
            <?php endif; ?>
            <?php if ($changeRecommendation): ?>
              <div class="visual-admin-guidance">
                <strong>Marche arriere conseillee</strong>
                <span><?= e($changeRecommendation) ?></span>
              </div>
            <?php endif; ?>
            <?php if ($changeSurfaces): ?>
              <div class="visual-admin-surface-chipset">
                <?php foreach ($changeSurfaces as $surface): ?>
                  <a href="<?= e((string)$surface['url']) ?>" class="visual-admin-surface-chip" data-async-link data-async-scope="admin-main"><?= e((string)$surface['label']) ?></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($relatedBackup): ?>
              <div class="visual-admin-change-summary">
                Backup associe · <?= e((string)($relatedBackup['name'] ?? $relatedBackup['id'])) ?> · <?= e(format_date((string)($relatedBackup['created_at'] ?? ''))) ?>
              </div>
              <div class="visual-admin-actions">
                <form method="POST">
                  <input type="hidden" name="action" value="apply_snapshot">
                  <input type="hidden" name="snapshot_id" value="<?= e((string)$relatedBackup['id']) ?>">
                  <button type="submit" class="btn btn-outline">Restaurer le backup associe</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="card visual-admin-card visual-admin-card--summary" id="visual-admin-preset-library">
    <div class="card-header">
      <span class="card-title">Presets visuels</span>
      <span class="text-small text-muted">Appliquer une direction cohérente sur l ensemble du shell et des surfaces clés</span>
    </div>
    <div class="visual-admin-summary-grid">
      <?php foreach ($visualPresets as $presetId => $preset): ?>
        <?php $comparison = $presetComparisons[$presetId] ?? ['change_count' => 0, 'unchanged_count' => 0, 'sample_changes' => [], 'is_active' => false]; ?>
        <div class="visual-admin-summary-card">
          <strong><?= e($preset['label']) ?></strong>
          <span style="display:block; margin-top:0.25rem; font-size:0.875rem; color:var(--text-muted);"><?= e($preset['description']) ?></span>
          
          <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:0.5rem; margin-top: 1rem; border-radius:8px; overflow:hidden;">
            <?php 
              // Apercu des 4 premiers assets phares du preset pour "sentir" l'ambiance
              $previewSlots = array_slice($preset['slots'] ?? [], 0, 4, true);
              foreach($previewSlots as $slot => $assetId):
                $url = generated_visual_url($assetId);
            ?>
              <div style="aspect-ratio: 16/9; background:#eee; position:relative;">
                <?php if($url): ?>
                  <img src="<?= e($url) ?>" style="width:100%; height:100%; object-fit:cover;" alt="Aperçu <?= e($slot) ?>">
                <?php endif; ?>
                <div style="position:absolute; bottom:0; left:0; right:0; background:rgba(0,0,0,0.5); color:#fff; font-size:0.65rem; padding:3px; text-align:center; font-family:monospace;">
                  <?= e($assetId) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div style="margin-top: 1rem; text-align:center;">
            <?php if ($comparison['is_active']): ?>
              <strong style="color:var(--success); font-size:0.85rem;">✅ Preset actuellement en service</strong>
            <?php else: ?>
              <span style="font-size:0.8rem; color:var(--text-muted);"><?= (int)$comparison['change_count'] ?> position(s) remplacée(s) à l'activation</span>
            <?php endif; ?>
          </div>
          
          <form method="POST" class="visual-admin-form" style="margin-top: 0.75rem;">
            <input type="hidden" name="action" value="apply_preset">
            <input type="hidden" name="preset_id" value="<?= e($presetId) ?>">
            <button type="submit" class="btn <?= $comparison['is_active'] ? 'btn-outline' : 'btn-secondary' ?>" style="width:100%;">
              <?= $comparison['is_active'] ? 'Forcer ce preset' : 'Installer ce Lookbook' ?>
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card visual-admin-card visual-admin-card--summary" id="visual-admin-commune-presets">
    <div class="card-header">
      <span class="card-title">Presets de reference par commune</span>
      <span class="text-small text-muted">Appliquer une surcouche locale rapide sur le socle neutre puis reverifier login, shell et tableau de bord</span>
    </div>
    <div class="visual-admin-guidance-board">
      <div class="visual-admin-guidance-board-item">
        <strong>Base produit</strong>
        <span>Le shell reste neutre. Le preset commune ne doit servir qu a colorer la lecture et la posture locale.</span>
      </div>
      <div class="visual-admin-guidance-board-item">
        <strong>Controle minimum</strong>
        <span>Verifier au minimum `Connexion`, `Shell lateral` et `Tableau de bord` avant d accepter un preset pour la commune.</span>
      </div>
      <div class="visual-admin-guidance-board-item">
        <strong>Bon reflexe</strong>
        <span>Si la commune demande un rendu trop different, preferer un preset cible + quelques slots, pas une divergence totale du poste.</span>
      </div>
    </div>
    <div class="visual-admin-summary-grid">
      <?php foreach ($communeThemeProfiles as $profile): ?>
        <?php
          $isActiveProfile = $activeCommuneThemeProfileId === (string)$profile['id'];
          $colors = (array)($profile['colors'] ?? []);
        ?>
        <div class="visual-admin-summary-card visual-admin-summary-card--theme-profile<?= $isActiveProfile ? ' visual-admin-summary-card--theme-profile-active' : '' ?>">
          <strong><?= e((string)$profile['label']) ?></strong>
          <span><?= e((string)$profile['theme_label']) ?></span>
          <div class="visual-admin-summary-list">
            <span><?= e((string)$profile['detail']) ?></span>
            <span class="text-small text-muted"><?= e((string)$profile['subtitle']) ?></span>
            <div class="visual-admin-theme-swatch-grid visual-admin-theme-swatch-grid--compact">
              <?php foreach ($colors as $colorLabel => $colorValue): ?>
                <div class="visual-admin-theme-swatch">
                  <span class="visual-admin-theme-swatch-chip" style="background: <?= e((string)$colorValue) ?>"></span>
                  <div><strong><?= e(ucfirst((string)$colorLabel)) ?></strong><span><?= e((string)$colorValue) ?></span></div>
                </div>
              <?php endforeach; ?>
            </div>
            <span class="visual-admin-inline-diff-list">
              <?php $checkIndex = 0; foreach ((array)($profile['checks'] ?? []) as $checkLabel): ?>
                <?php if ($checkIndex > 0): ?><span class="visual-admin-inline-diff-item"> · </span><?php endif; ?>
                <span class="visual-admin-inline-diff-item"><strong><?= e((string)$checkLabel) ?></strong></span>
              <?php $checkIndex++; endforeach; ?>
            </span>
            <div class="visual-admin-guidance">
              <strong>Usage recommande</strong>
              <span><?= $isActiveProfile ? 'Ce preset est deja la base active du poste. Recontrole seulement les surfaces sensibles avant un nouveau lot.' : 'Appliquer ce preset si la commune veut d abord une ambiance globale, puis ajuster seulement les slots qui divergent encore.' ?></span>
            </div>
            <?php if ($isActiveProfile): ?>
              <strong>Preset commune deja aligne sur le poste courant</strong>
            <?php endif; ?>
          </div>
          <form method="POST" class="visual-admin-form">
            <input type="hidden" name="action" value="apply_commune_theme_profile">
            <input type="hidden" name="profile_id" value="<?= e((string)$profile['id']) ?>">
            <button type="submit" class="btn btn-secondary"><?= $isActiveProfile ? 'Reappliquer ce preset commune' : 'Appliquer ce preset commune' ?></button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card visual-admin-card visual-admin-card--summary" id="visual-admin-rollback">
    <div class="card-header">
      <span class="card-title">Snapshots nommes</span>
      <span class="text-small text-muted">Sauvegarder un etat exact du poste visuel, puis le rejouer ou le supprimer</span>
    </div>
    <div class="visual-admin-snapshot-shell">
      <div class="visual-admin-summary-grid visual-admin-summary-grid--snapshots">
        <div class="visual-admin-summary-card">
          <strong><?= count($manualSnapshots) ?></strong>
          <span>snapshots manuels</span>
          <div class="visual-admin-summary-list">
            <span>Versions enregistrees volontairement pour figer un etat metier ou visuel.</span>
            <span class="visual-admin-inline-diff-list">
              <span class="visual-admin-inline-diff-item"><strong>branding</strong> <span>(<?= (int)$manualSnapshotProfileSummary['branding'] ?>)</span></span>
              <span class="visual-admin-inline-diff-item"> · <strong>slots</strong> <span>(<?= (int)$manualSnapshotProfileSummary['slots'] ?>)</span></span>
              <span class="visual-admin-inline-diff-item"> · <strong>categories</strong> <span>(<?= (int)$manualSnapshotProfileSummary['categories'] ?>)</span></span>
              <span class="visual-admin-inline-diff-item"> · <strong>mixte</strong> <span>(<?= (int)$manualSnapshotProfileSummary['mixed'] ?>)</span></span>
            </span>
            <?php if ($manualLatestByProfile): ?>
              <div>
                <strong>Derniers reperes rollback</strong>
                <span class="visual-admin-inline-action-list">
                  <?php $profileIndex = 0; foreach ($manualLatestByProfile as $profile): ?>
                    <span class="visual-admin-inline-diff-item">
                      <?php if ($profileIndex++ > 0): ?> · <?php endif; ?>
                      <strong><?= e((string)$profile['label']) ?></strong>
                      <a href="#snapshot-<?= e((string)$profile['id']) ?>"><?= e((string)$profile['name']) ?></a>
                    </span>
                    <form method="POST" class="visual-admin-inline-form">
                      <input type="hidden" name="action" value="apply_snapshot">
                      <input type="hidden" name="snapshot_id" value="<?= e((string)$profile['id']) ?>">
                      <button type="submit" class="btn btn-outline">Appliquer ce repere</button>
                    </form>
                  <?php endforeach; ?>
                </span>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="visual-admin-summary-card">
          <strong><?= count($autoSnapshots) ?></strong>
          <span>backups automatiques</span>
          <div class="visual-admin-summary-list">
            <span class="visual-admin-inline-diff-list">
              <span class="visual-admin-inline-diff-item"><strong>branding</strong> <span>(<?= (int)$autoSnapshotProfileSummary['branding'] ?>)</span></span>
              <span class="visual-admin-inline-diff-item"> · <strong>slots</strong> <span>(<?= (int)$autoSnapshotProfileSummary['slots'] ?>)</span></span>
              <span class="visual-admin-inline-diff-item"> · <strong>categories</strong> <span>(<?= (int)$autoSnapshotProfileSummary['categories'] ?>)</span></span>
              <span class="visual-admin-inline-diff-item"> · <strong>mixte</strong> <span>(<?= (int)$autoSnapshotProfileSummary['mixed'] ?>)</span></span>
            </span>
            <?php if ($autoLatestByProfile): ?>
              <div>
                <strong>Derniers reperes rollback</strong>
                <span class="visual-admin-inline-action-list">
                  <?php $profileIndex = 0; foreach ($autoLatestByProfile as $profile): ?>
                    <span class="visual-admin-inline-diff-item">
                      <?php if ($profileIndex++ > 0): ?> · <?php endif; ?>
                      <strong><?= e((string)$profile['label']) ?></strong>
                      <a href="#snapshot-<?= e((string)$profile['id']) ?>"><?= e((string)$profile['name']) ?></a>
                    </span>
                    <form method="POST" class="visual-admin-inline-form">
                      <input type="hidden" name="action" value="apply_snapshot">
                      <input type="hidden" name="snapshot_id" value="<?= e((string)$profile['id']) ?>">
                      <button type="submit" class="btn btn-outline">Restaurer ce repere</button>
                    </form>
                  <?php endforeach; ?>
                </span>
              </div>
            <?php endif; ?>
            <?php if ($latestAutoSnapshot): ?>
              <div>
                <strong>Dernier backup</strong>
                <span><?= e($latestAutoSnapshot['name']) ?></span>
              </div>
              <div>
                <strong>Source</strong>
                <span><?= e((string)($latestAutoSnapshot['metadata']['source_label'] ?? 'Action visuelle')) ?></span>
              </div>
              <form method="POST" class="visual-admin-form">
                <input type="hidden" name="action" value="apply_snapshot">
                <input type="hidden" name="snapshot_id" value="<?= e((string)$latestAutoSnapshot['id']) ?>">
                <button type="submit" class="btn btn-outline">Restaurer le dernier backup</button>
              </form>
            <?php else: ?>
              <span>Aucun backup automatique enregistre pour le moment.</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <form method="POST" class="visual-admin-form visual-admin-snapshot-form">
        <input type="hidden" name="action" value="save_snapshot">
        <label>
          <span>Nom du snapshot</span>
          <input type="text" name="snapshot_name" class="form-control" placeholder="Ex: baseline-neutre-22-mars" required>
        </label>
        <button type="submit" class="btn btn-secondary">Enregistrer le snapshot courant</button>
      </form>
      <div class="visual-admin-snapshot-split">
        <div class="visual-admin-snapshot-column">
          <div class="card-header">
            <span class="card-title">Snapshots manuels</span>
            <span class="text-small text-muted">Versions decidees par l administrateur</span>
          </div>
          <div class="visual-admin-change-list">
            <?php if (!$manualSnapshots): ?>
              <div class="text-small text-muted">Aucun snapshot manuel enregistre pour le moment.</div>
            <?php else: ?>
              <?php foreach ($manualSnapshots as $snapshot): ?>
                <?php $comparison = $snapshotComparisons[$snapshot['id']] ?? ['change_count' => 0, 'unchanged_count' => 0, 'sample_changes' => [], 'is_active' => false]; ?>
                <?php $snapshotProfile = visual_admin_snapshot_family_profile((array)($snapshot['metadata']['family_counts'] ?? [])); ?>
                <div class="visual-admin-change-item" id="snapshot-<?= e((string)$snapshot['id']) ?>">
                  <div class="visual-admin-change-topline">
                    <strong><?= e($snapshot['name']) ?></strong>
                    <div class="visual-admin-change-pillset">
                      <?php if ($snapshotProfile): ?>
                        <span class="visual-admin-change-pill <?= e(visual_admin_snapshot_profile_pill_class($snapshotProfile)) ?>">Famille rollback · <?= e((string)$snapshotProfile['label']) ?></span>
                      <?php endif; ?>
                      <span><?= e(format_date($snapshot['created_at'])) ?></span>
                    </div>
                  </div>
                  <div class="visual-admin-change-meta">
                    <span><?= e($snapshot['id']) ?></span>
                    <span>Manuel</span>
                    <?php if (!empty($snapshot['metadata']['recognized_preset_label'])): ?>
                      <span><?= e((string)$snapshot['metadata']['recognized_preset_label']) ?></span>
                    <?php endif; ?>
                    <?php if ($snapshotProfile): ?>
                      <span>Dominante rollback · <?= e((string)$snapshotProfile['label']) ?></span>
                    <?php endif; ?>
                    <?php if ($comparison['is_active']): ?>
                      <span>Snapshot deja actif</span>
                    <?php else: ?>
                      <span><?= (int)$comparison['change_count'] ?> changement(s) si applique</span>
                      <span><?= (int)$comparison['unchanged_count'] ?> deja alignes</span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($snapshot['metadata']['intervention_label'])): ?>
                    <div class="visual-admin-guidance">
                      <strong>Contexte exporte</strong>
                      <span><?= e((string)$snapshot['metadata']['intervention_label']) ?><?php if (!empty($snapshot['metadata']['intervention_detail'])): ?> · <?= e((string)$snapshot['metadata']['intervention_detail']) ?><?php endif; ?></span>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($snapshot['metadata']['top_surfaces']) || !empty($snapshot['metadata']['family_counts'])): ?>
                    <div class="visual-admin-guidance">
                      <strong>Portee exportee</strong>
                      <?php if (!empty($snapshot['metadata']['top_surfaces'])): ?>
                        <span class="visual-admin-inline-diff-list">
                          <?php $surfaceIndex = 0; foreach ((array)$snapshot['metadata']['top_surfaces'] as $surface): ?>
                            <?php if (!is_string($surface) || trim($surface) === '') { continue; } ?>
                            <?php $surfaceLabel = trim($surface); ?>
                            <?php $surfaceUrl = visual_admin_surface_url_by_label($surfaceLabel, $slotSurfaceMap); ?>
                            <span class="visual-admin-inline-diff-item">
                              <?php if ($surfaceIndex++ > 0): ?> · <?php endif; ?>
                              <?php if ($surfaceUrl): ?>
                                <a href="<?= e($surfaceUrl) ?>" data-async-link data-async-scope="admin-main"><strong><?= e($surfaceLabel) ?></strong></a>
                              <?php else: ?>
                                <strong><?= e($surfaceLabel) ?></strong>
                              <?php endif; ?>
                            </span>
                          <?php endforeach; ?>
                        </span>
                      <?php endif; ?>
                      <?php if (!empty($snapshot['metadata']['family_counts'])): ?>
                        <span class="visual-admin-inline-diff-list">
                          <?php $familyIndex = 0; foreach ((array)$snapshot['metadata']['family_counts'] as $family => $count): ?>
                            <?php if (!is_string($family) || $family === '') { continue; } ?>
                            <span class="visual-admin-inline-diff-item">
                              <?php if ($familyIndex++ > 0): ?> · <?php endif; ?>
                              <strong><?= e($family) ?></strong>
                              <span>(<?= (int)$count ?>)</span>
                            </span>
                          <?php endforeach; ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php $snapshotCoverage = visual_admin_snapshot_coverage((array)($snapshot['metadata']['family_counts'] ?? [])); ?>
                  <?php if ($snapshotCoverage): ?>
                    <div class="visual-admin-guidance">
                      <strong><?= e((string)$snapshotCoverage['label']) ?></strong>
                      <span><?= e((string)$snapshotCoverage['detail']) ?></span>
                    </div>
                  <?php endif; ?>
                  <?php if (!$comparison['is_active']): ?>
                    <div class="visual-admin-guidance">
                      <strong>Lecture rollback</strong>
                      <span>
                        <?php if (($comparison['change_count'] ?? 0) <= 2): ?>
                          Snapshot leger : bon candidat pour annuler une retouche recente.
                        <?php elseif (($comparison['change_count'] ?? 0) <= 6): ?>
                          Snapshot intermediaire : controle les surfaces avant application.
                        <?php else: ?>
                          Snapshot large : proche d un retour de theme, a utiliser en connaissance de cause.
                        <?php endif; ?>
                      </span>
                    </div>
                  <?php endif; ?>
                  <?php if (!$comparison['is_active'] && $comparison['sample_changes']): ?>
                    <div class="visual-admin-preset-preview-list visual-admin-snapshot-preview-list">
                      <?php foreach ($comparison['sample_changes'] as $change): ?>
                        <div class="visual-admin-preset-preview-item">
                          <div class="visual-admin-preset-preview-head">
                            <strong><?= e($change['label']) ?></strong>
                            <a href="<?= e($change['surface_url']) ?>" data-async-link data-async-scope="admin-main"><?= e($change['surface_label']) ?></a>
                          </div>
                          <div class="visual-admin-preset-preview-media">
                            <div class="visual-admin-preset-preview-card">
                              <?php if (!empty($change['current_url'])): ?>
                                <img src="<?= e($change['current_url']) ?>" alt="<?= e($change['current']) ?>">
                              <?php endif; ?>
                              <span>Actuel · <?= e($change['current']) ?></span>
                            </div>
                            <div class="visual-admin-preset-preview-arrow">→</div>
                            <div class="visual-admin-preset-preview-card">
                              <?php if (!empty($change['target_url'])): ?>
                                <img src="<?= e($change['target_url']) ?>" alt="<?= e($change['target']) ?>">
                              <?php endif; ?>
                              <span>Cible · <?= e($change['target']) ?></span>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <?php if ($comparison['change_count'] > count($comparison['sample_changes'])): ?>
                      <div class="visual-admin-change-summary">+<?= (int)($comparison['change_count'] - count($comparison['sample_changes'])) ?> autre(s) changement(s) dans ce snapshot.</div>
                    <?php endif; ?>
                  <?php endif; ?>
                  <div class="visual-admin-actions">
                    <form method="POST">
                      <input type="hidden" name="action" value="apply_snapshot">
                      <input type="hidden" name="snapshot_id" value="<?= e($snapshot['id']) ?>">
                      <button type="submit" class="btn btn-outline">Appliquer</button>
                    </form>
                    <form method="POST">
                      <input type="hidden" name="action" value="delete_snapshot">
                      <input type="hidden" name="snapshot_id" value="<?= e($snapshot['id']) ?>">
                      <button type="submit" class="btn btn-outline">Supprimer</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="visual-admin-snapshot-column">
          <div class="card-header">
            <span class="card-title">Backups automatiques</span>
            <span class="text-small text-muted">Protections creees avant les mutations importantes</span>
          </div>
          <div class="visual-admin-change-list">
            <?php if (!$autoSnapshots): ?>
              <div class="text-small text-muted">Aucun backup automatique enregistre pour le moment.</div>
            <?php else: ?>
              <?php foreach ($autoSnapshots as $snapshot): ?>
            <?php $comparison = $snapshotComparisons[$snapshot['id']] ?? ['change_count' => 0, 'unchanged_count' => 0, 'sample_changes' => [], 'is_active' => false]; ?>
            <?php $snapshotProfile = visual_admin_snapshot_family_profile((array)($snapshot['metadata']['family_counts'] ?? [])); ?>
            <div class="visual-admin-change-item" id="snapshot-<?= e((string)$snapshot['id']) ?>">
              <div class="visual-admin-change-topline">
                <strong><?= e($snapshot['name']) ?></strong>
                <div class="visual-admin-change-pillset">
                  <?php if ($snapshotProfile): ?>
                    <span class="visual-admin-change-pill <?= e(visual_admin_snapshot_profile_pill_class($snapshotProfile)) ?>">Famille rollback · <?= e((string)$snapshotProfile['label']) ?></span>
                  <?php endif; ?>
                  <span><?= e(format_date($snapshot['created_at'])) ?></span>
                </div>
              </div>
              <div class="visual-admin-change-meta">
                <span><?= e($snapshot['id']) ?></span>
                <span>Automatique</span>
                <?php if (!empty($snapshot['metadata']['source_label'])): ?>
                  <span><?= e((string)$snapshot['metadata']['source_label']) ?></span>
                <?php endif; ?>
                <?php if (!empty($snapshot['metadata']['recognized_preset_label'])): ?>
                  <span><?= e((string)$snapshot['metadata']['recognized_preset_label']) ?></span>
                <?php endif; ?>
                <?php if ($snapshotProfile): ?>
                  <span>Dominante rollback · <?= e((string)$snapshotProfile['label']) ?></span>
                <?php endif; ?>
                <?php if ($comparison['is_active']): ?>
                  <span>Snapshot deja actif</span>
                <?php else: ?>
                  <span><?= (int)$comparison['change_count'] ?> changement(s) si applique</span>
                  <span><?= (int)$comparison['unchanged_count'] ?> deja alignes</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($snapshot['metadata']['intervention_label'])): ?>
                <div class="visual-admin-guidance">
                  <strong>Contexte exporte</strong>
                  <span><?= e((string)$snapshot['metadata']['intervention_label']) ?><?php if (!empty($snapshot['metadata']['intervention_detail'])): ?> · <?= e((string)$snapshot['metadata']['intervention_detail']) ?><?php endif; ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($snapshot['metadata']['top_surfaces']) || !empty($snapshot['metadata']['family_counts'])): ?>
                <div class="visual-admin-guidance">
                  <strong>Portee exportee</strong>
                  <?php if (!empty($snapshot['metadata']['top_surfaces'])): ?>
                    <span class="visual-admin-inline-diff-list">
                      <?php $surfaceIndex = 0; foreach ((array)$snapshot['metadata']['top_surfaces'] as $surface): ?>
                        <?php if (!is_string($surface) || trim($surface) === '') { continue; } ?>
                        <?php $surfaceLabel = trim($surface); ?>
                        <?php $surfaceUrl = visual_admin_surface_url_by_label($surfaceLabel, $slotSurfaceMap); ?>
                        <span class="visual-admin-inline-diff-item">
                          <?php if ($surfaceIndex++ > 0): ?> · <?php endif; ?>
                          <?php if ($surfaceUrl): ?>
                            <a href="<?= e($surfaceUrl) ?>" data-async-link data-async-scope="admin-main"><strong><?= e($surfaceLabel) ?></strong></a>
                          <?php else: ?>
                            <strong><?= e($surfaceLabel) ?></strong>
                          <?php endif; ?>
                        </span>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                  <?php if (!empty($snapshot['metadata']['family_counts'])): ?>
                    <span class="visual-admin-inline-diff-list">
                      <?php $familyIndex = 0; foreach ((array)$snapshot['metadata']['family_counts'] as $family => $count): ?>
                        <?php if (!is_string($family) || $family === '') { continue; } ?>
                        <span class="visual-admin-inline-diff-item">
                          <?php if ($familyIndex++ > 0): ?> · <?php endif; ?>
                          <strong><?= e($family) ?></strong>
                          <span>(<?= (int)$count ?>)</span>
                        </span>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php $snapshotCoverage = visual_admin_snapshot_coverage((array)($snapshot['metadata']['family_counts'] ?? [])); ?>
              <?php if ($snapshotCoverage): ?>
                <div class="visual-admin-guidance">
                  <strong><?= e((string)$snapshotCoverage['label']) ?></strong>
                  <span><?= e((string)$snapshotCoverage['detail']) ?></span>
                </div>
              <?php endif; ?>
              <?php if (!$comparison['is_active']): ?>
                <div class="visual-admin-guidance">
                  <strong>Lecture rollback</strong>
                  <span>
                    <?php if (($comparison['change_count'] ?? 0) <= 2): ?>
                      Backup leger : utile pour annuler un ajustement recent sans repartir d une base plus ancienne.
                    <?php elseif (($comparison['change_count'] ?? 0) <= 6): ?>
                      Backup intermediaire : verifier les apercus avant restauration, car plusieurs surfaces seront touchees.
                    <?php else: ?>
                      Backup large : proche d un rollback structurant, a utiliser seulement si le poste a vraiment diverge.
                    <?php endif; ?>
                  </span>
                </div>
              <?php endif; ?>
              <?php if (!$comparison['is_active'] && $comparison['sample_changes']): ?>
                <div class="visual-admin-preset-preview-list visual-admin-snapshot-preview-list">
                  <?php foreach ($comparison['sample_changes'] as $change): ?>
                    <div class="visual-admin-preset-preview-item">
                      <div class="visual-admin-preset-preview-head">
                        <strong><?= e($change['label']) ?></strong>
                        <a href="<?= e($change['surface_url']) ?>" data-async-link data-async-scope="admin-main"><?= e($change['surface_label']) ?></a>
                      </div>
                      <div class="visual-admin-preset-preview-media">
                        <div class="visual-admin-preset-preview-card">
                          <?php if (!empty($change['current_url'])): ?>
                            <img src="<?= e($change['current_url']) ?>" alt="<?= e($change['current']) ?>">
                          <?php endif; ?>
                          <span>Actuel · <?= e($change['current']) ?></span>
                        </div>
                        <div class="visual-admin-preset-preview-arrow">→</div>
                        <div class="visual-admin-preset-preview-card">
                          <?php if (!empty($change['target_url'])): ?>
                            <img src="<?= e($change['target_url']) ?>" alt="<?= e($change['target']) ?>">
                          <?php endif; ?>
                          <span>Cible · <?= e($change['target']) ?></span>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php if ($comparison['change_count'] > count($comparison['sample_changes'])): ?>
                  <div class="visual-admin-change-summary">+<?= (int)($comparison['change_count'] - count($comparison['sample_changes'])) ?> autre(s) changement(s) dans ce snapshot.</div>
                <?php endif; ?>
              <?php endif; ?>
              <div class="visual-admin-actions">
                <form method="POST">
                  <input type="hidden" name="action" value="apply_snapshot">
                  <input type="hidden" name="snapshot_id" value="<?= e($snapshot['id']) ?>">
                  <button type="submit" class="btn btn-outline">Restaurer ce backup</button>
                </form>
                <form method="POST">
                  <input type="hidden" name="action" value="delete_snapshot">
                  <input type="hidden" name="snapshot_id" value="<?= e($snapshot['id']) ?>">
                  <button type="submit" class="btn btn-outline">Supprimer</button>
                </form>
              </div>
            </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div class="visual-admin-grid">
    <details class="card visual-admin-card" id="visual-admin-branding">
      <summary class="card-header" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; list-style:none;">
        <div>
          <span class="card-title" style="display:block;">Branding de base</span>
          <span class="text-small text-muted">Logo partage entre login et shell</span>
        </div>
        <span style="font-size:20px; color:var(--primary);">▼</span>
      </summary>
      <div style="padding: 16px;">
      <?php if ($isTrainingMode): ?>
      <div class="admin-form-guide visual-admin-form-guide">
        <strong>Usage recommande</strong>
        <div class="admin-form-guide-list">
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">01</span>
            <div>
              <strong>Mettre a jour le socle</strong>
              <span>Ce bloc sert a changer le logo commun, le sous-titre du login et la surcouche couleur sans toucher aux slots surface par surface.</span>
            </div>
          </div>
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">02</span>
            <div>
              <strong>Eviter la surcharge</strong>
              <span>Utiliser ce niveau seulement pour le branding commun. Les exceptions locales doivent rester dans les slots ou les categories.</span>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="visual-admin-brand-preview">
        <img src="<?= e($brandMarkUrl) ?>" alt="Logo actuel" class="visual-admin-brand-mark">
        <div>
          <strong><?= defined('APP_NAME') ? e(APP_NAME) : 'Ma Commune' ?></strong>
          <div class="text-small text-muted"><?= e($loginSubtitle) ?></div>
        </div>
      </div>
      <div class="visual-admin-theme-preview" style="<?= e($themePreviewStyle) ?>">
        <div class="visual-admin-theme-preview-head">
          <strong>Surcouche commune</strong>
          <span><?= e($themeSettings['label']) ?></span>
        </div>
        <div class="visual-admin-theme-swatch-grid">
          <div class="visual-admin-theme-swatch">
            <span class="visual-admin-theme-swatch-chip" style="background: <?= e($themeSettings['primary']) ?>"></span>
            <div><strong>Primaire</strong><span><?= e($themeSettings['primary']) ?></span></div>
          </div>
          <div class="visual-admin-theme-swatch">
            <span class="visual-admin-theme-swatch-chip" style="background: <?= e($themeSettings['primary_dark']) ?>"></span>
            <div><strong>Primaire sombre</strong><span><?= e($themeSettings['primary_dark']) ?></span></div>
          </div>
          <div class="visual-admin-theme-swatch">
            <span class="visual-admin-theme-swatch-chip" style="background: <?= e($themeSettings['primary_light']) ?>"></span>
            <div><strong>Primaire clair</strong><span><?= e($themeSettings['primary_light']) ?></span></div>
          </div>
          <div class="visual-admin-theme-swatch">
            <span class="visual-admin-theme-swatch-chip" style="background: <?= e($themeSettings['secondary']) ?>"></span>
            <div><strong>Secondaire</strong><span><?= e($themeSettings['secondary']) ?></span></div>
          </div>
          <div class="visual-admin-theme-swatch">
            <span class="visual-admin-theme-swatch-chip" style="background: <?= e($themeSettings['accent']) ?>"></span>
            <div><strong>Accent</strong><span><?= e($themeSettings['accent']) ?></span></div>
          </div>
        </div>
      </div>
      <form method="POST" enctype="multipart/form-data" class="visual-admin-form">
        <input type="hidden" id="main-branding-action" name="action" value="save_branding">
        <input type="hidden" id="custom-theme-name" name="custom_theme_name" value="">
        <label>
          <span>Sous-titre login</span>
          <input type="text" name="login_subtitle" value="<?= e($loginSubtitle) ?>" class="form-control">
        </label>
        <label>
          <span>Preset de theme</span>
          <select name="theme_variant" class="form-control">
            <?php foreach ($themePresets as $presetId => $preset): ?>
              <option value="<?= e($presetId) ?>" <?= $themeSettings['variant'] === $presetId ? 'selected' : '' ?>>
                <?= e((string)$preset['label']) ?> <?= ($preset['is_custom'] ?? false) ? '(Sur-mesure)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted">La base reste neutre. Cette surcouche sert seulement a adapter la commune sans refaire le shell.</small>
        </label>
        <div class="visual-admin-theme-fields">
          <label>
            <span>Primaire</span>
            <input type="color" name="theme[primary]" value="<?= e($themeSettings['primary']) ?>" class="form-control form-control-color">
          </label>
          <label>
            <span>Primaire sombre</span>
            <input type="color" name="theme[primary_dark]" value="<?= e($themeSettings['primary_dark']) ?>" class="form-control form-control-color">
          </label>
          <label>
            <span>Primaire clair</span>
            <input type="color" name="theme[primary_light]" value="<?= e($themeSettings['primary_light']) ?>" class="form-control form-control-color">
          </label>
          <label>
            <span>Secondaire</span>
            <input type="color" name="theme[secondary]" value="<?= e($themeSettings['secondary']) ?>" class="form-control form-control-color">
          </label>
          <label>
            <span>Accent</span>
            <input type="color" name="theme[accent]" value="<?= e($themeSettings['accent']) ?>" class="form-control form-control-color">
          </label>
        </div>
        <label>
          <span>Remplacer le logo</span>
          <input type="file" name="brand_mark" accept=".png,.jpg,.jpeg,.webp,.svg" class="form-control">
        </label>
        <div class="visual-admin-actions" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
          <button type="submit" class="btn btn-primary" onclick="document.getElementById('main-branding-action').value='save_branding';">Enregistrer les couleurs</button>
          <button type="button" class="btn btn-outline" style="border-style: dashed;" onclick="
            var nom = prompt('Veuillez nommer ce nouveau thème personnalisé :');
            if (nom) {
              document.getElementById('custom-theme-name').value = nom;
              document.getElementById('main-branding-action').value = 'save_custom_theme';
              this.closest('form').submit();
            }
          ">+ Créer un nouveau thème avec ces couleurs</button>
        </div>
      </form>
      
      <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <form method="POST" class="visual-admin-reset-form" style="margin: 0;">
          <input type="hidden" name="action" value="reset_branding">
          <button type="submit" class="btn btn-outline" style="background: transparent;">Revenir au branding par defaut</button>
        </form>
        <?php if (($themePresets[$themeSettings['variant']]['is_custom'] ?? false)): ?>
        <form method="POST" class="visual-admin-reset-form" style="margin: 0;" onsubmit="return confirm('Détruire complètement ce thème ?');">
          <input type="hidden" name="action" value="delete_custom_theme">
          <input type="hidden" name="theme_id" value="<?= e($themeSettings['variant']) ?>">
          <button type="submit" class="btn btn-outline" style="color: var(--danger); border-color: var(--danger); background: transparent;">🗑️ Supprimer ce thème sur-mesure</button>
        </form>
        <?php endif; ?>
      </div>
    </details>

    <details class="card visual-admin-card" id="visual-admin-inventory">
      <summary class="card-header" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; list-style:none;">
        <div>
          <span class="card-title" style="display:block;">Inventaire rapide</span>
          <span class="text-small text-muted">Apercu du pack admin disponible</span>
        </div>
        <span style="font-size:20px; color:var(--primary);">▼</span>
      </summary>
      <div style="padding: 16px;">
      <?php if ($isTrainingMode): ?>
      <div class="admin-form-guide visual-admin-form-guide">
        <strong>Quand passer ici</strong>
        <div class="admin-form-guide-list">
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">A</span>
            <div>
              <strong>Exporter avant un gros lot</strong>
              <span>Conserver un JSON avant un remapping large ou avant un nettoyage d overrides.</span>
            </div>
          </div>
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">B</span>
            <div>
              <strong>Importer pour rejouer un etat</strong>
              <span>Preferer l import si tu as deja un fichier de reference valide, plutot que de reconstruire a la main.</span>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="visual-admin-transfer">
        <form method="POST" class="visual-admin-form">
          <input type="hidden" name="action" value="download_settings">
          <div class="visual-admin-transfer-head">
            <strong>Exporter la base courante</strong>
            <span>Produit un JSON de reprise pour audit, partage ou rollback manuel.</span>
          </div>
          <button type="submit" class="btn btn-secondary">Exporter la configuration JSON</button>
        </form>
        <form method="POST" enctype="multipart/form-data" class="visual-admin-form">
          <input type="hidden" name="action" value="import_settings">
          <div class="visual-admin-transfer-head">
            <strong>Restaurer un JSON connu</strong>
            <span>Charge une configuration exportee et remet le poste sur cet etat.</span>
          </div>
          <label>
            <span>Restaurer une configuration exportee</span>
            <input type="file" name="settings_json" accept=".json,application/json" class="form-control">
          </label>
          <button type="submit" class="btn btn-outline">Importer le JSON</button>
        </form>
      </div>
      <div class="visual-admin-asset-grid">
        <?php foreach (array_slice($assetOptions, 0, 12) as $asset): ?>
          <div class="visual-admin-asset-tile">
            <img src="<?= e($asset['url']) ?>" alt="<?= e($asset['label']) ?>">
            <strong><?= e($asset['id']) ?></strong>
            <span><?= e($asset['label']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      </div>
    </details>
  </div>

  <style>
  .card.visual-admin-card {
      overflow: visible !important;
  }
  .visual-slot-scroller {
      display: flex; 
      overflow-x: auto; 
      gap: 12px; 
      padding: 8px 4px 16px 4px;
      scrollbar-width: thin;
      scrollbar-color: var(--gray-300) transparent;
  }
  .visual-slot-scroller::-webkit-scrollbar {
      height: 6px;
  }
  .visual-slot-scroller::-webkit-scrollbar-track {
      background: transparent;
  }
  .visual-slot-scroller::-webkit-scrollbar-thumb {
      background-color: var(--gray-300);
      border-radius: 10px;
  }
  .visual-slot-option {
      cursor: pointer; 
      flex-shrink: 0; 
      text-align: center;
      position: relative;
      width: 84px;
  }
  .visual-slot-option input {
      display: none;
  }
  .visual-slot-option img {
      width: 84px; 
      height: 84px; 
      object-fit: cover; 
      border-radius: 16px; 
      border: 3px solid transparent; 
      opacity: 0.5; 
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 4px 6px rgba(0,0,0,0.05);
      background: var(--gray-100);
  }
  .visual-slot-option:hover img {
      opacity: 0.9;
      transform: translateY(-2px);
      box-shadow: 0 8px 16px rgba(0,0,0,0.1);
  }
  .visual-slot-option input:checked + img {
      border-color: var(--primary); 
      opacity: 1;
      transform: scale(1.05);
      box-shadow: 0 8px 24px rgba(0,0,0,0.15);
  }
  .visual-slot-label {
      font-size: 10px; 
      color: var(--gray-500); 
      margin-top: 6px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
      transition: all 0.2s;
  }
  .visual-slot-option input:checked ~ .visual-slot-label {
      color: var(--primary);
      font-weight: 800;
  }

  /* Custom Visual Form Dropdown */
  .visual-select { position: relative; width: 100%; max-width: 400px; }
  .visual-select-trigger { 
      display: flex; align-items: center; gap: 12px;
      padding: 10px 14px; border: 2px solid var(--gray-200); border-radius: 12px;
      background: white; cursor: pointer; transition: all 0.2s;
  }
  .visual-select-trigger:hover { border-color: var(--gray-400); }
  .visual-select.is-open .visual-select-trigger { border-color: var(--primary); box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary) 15%, transparent); }
  .visual-select-trigger img { width: 36px; height: 36px; object-fit: contain; background: var(--gray-100); border-radius: 8px; padding: 4px; }
  
  .visual-select-dropdown {
      position: absolute; top: 100%; left: 0; right: 0;
      margin-top: 8px; padding: 16px; background: white;
      border: 1px solid var(--gray-200); border-radius: 16px;
      box-shadow: 0 16px 40px rgba(0,0,0,0.1);
      display: none; z-index: 100;
      grid-template-columns: repeat(auto-fill, minmax(65px, 1fr)); gap: 12px;
      max-height: 280px; overflow-y: auto;
  }
  .visual-select.is-open .visual-select-dropdown { display: grid; }
  .visual-option {
      cursor: pointer; text-align: center; padding: 6px;
      border-radius: 12px; border: 2px solid transparent; transition: 0.15s;
  }
  .visual-option:hover { background: var(--gray-50); transform: translateY(-2px); }
  .visual-option.selected { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 8%, white); }
  .visual-option img { width: 44px; height: 44px; object-fit: contain; margin: 0 auto; display: block; border-radius: 8px; }
  .visual-option span { display: block; font-size: 10px; margin-top: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--gray-600); }
  </style>

  <details class="card visual-admin-card visual-admin-card--slots" id="visual-admin-slots">
    <summary class="card-header" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; list-style:none;">
      <div>
        <span class="card-title" style="display:block;">Remapping des slots visuels</span>
        <span class="text-small text-muted">Chaque slot correspond a une surface live du backoffice</span>
      </div>
      <span style="font-size:20px; color:var(--primary);">▼</span>
    </summary>
    <div style="padding: 16px;">
    <?php if ($isTrainingMode): ?>
    <div class="admin-form-guide visual-admin-form-guide">
      <strong>Ordre conseille</strong>
      <div class="admin-form-guide-list">
        <div class="admin-form-guide-item">
          <span class="admin-form-guide-step">01</span>
          <div>
            <strong>Choisir un seul groupe a la fois</strong>
            <span>Modifier le shell ou un hero, enregistrer, puis verifier les surfaces avant d ouvrir un autre groupe.</span>
          </div>
        </div>
        <div class="admin-form-guide-item">
          <span class="admin-form-guide-step">02</span>
          <div>
            <strong>Garder le defaut si possible</strong>
            <span>Les overrides doivent couvrir une exception reelle, pas remplacer tout le pack sans besoin.</span>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" class="visual-admin-form visual-admin-form--slots">
      <input type="hidden" name="action" value="save_slots">
      <?php foreach ($slotGroups as $groupTitle => $groupSlots): ?>
        <div class="visual-admin-slot-group">
          <h3><?= e($groupTitle) ?></h3>
          <div class="visual-admin-slot-grid">
            <?php foreach ($groupSlots as $slot => $meta): ?>
              <?php
                $currentAsset = $settings['slots'][$slot] ?? null;
                $effectiveAsset = visual_admin_slot_asset($slot, $meta['fallback']);
                $effectiveUrl = $effectiveAsset ? generated_visual_url($effectiveAsset) : null;
                $isCustomAsset = visual_admin_is_custom_asset($currentAsset);
                
                $activeLabel = 'Défaut';
                if ($isCustomAsset) $activeLabel = 'Upload Manuel';
                foreach ($assetOptions as $asset) {
                    if ($currentAsset === $asset['id']) { $activeLabel = $asset['label']; break; }
                }
              ?>
              <div class="visual-admin-slot-field" style="display: flex; flex-direction: column; gap: 8px;">
                <span style="font-weight: 700; font-size: 13px; color: var(--gray-800);"><?= e($meta['label']) ?></span>
                
                <div class="visual-select" data-input-name="slot[<?= e($slot) ?>]">
                    <input type="hidden" class="visual-select-input" name="slot[<?= e($slot) ?>]" value="<?= e($currentAsset) ?>">
                    <div class="visual-select-trigger">
                        <img src="<?= e($currentAsset ? $effectiveUrl : generated_visual_url($meta['fallback'])) ?>" class="trigger-img">
                        <div style="flex:1">
                            <span class="trigger-label" style="display:block; font-size:13px; font-weight:700; color:var(--gray-800);"><?= e($activeLabel) ?></span>
                        </div>
                        <span style="color:var(--gray-400);">▼</span>
                    </div>
                    <div class="visual-select-dropdown">
                        <div class="visual-option <?= $currentAsset === null || $currentAsset === '' ? 'selected' : '' ?>" data-val="" data-url="<?= e(generated_visual_url($meta['fallback'])) ?>" data-label="Défaut">
                            <img src="<?= e(generated_visual_url($meta['fallback'])) ?>">
                            <span>Défaut</span>
                        </div>
                        <?php if ($isCustomAsset): ?>
                        <div class="visual-option selected" data-val="<?= e($currentAsset) ?>" data-url="<?= e($effectiveUrl) ?>" data-label="Manuel">
                            <img src="<?= e($effectiveUrl) ?>">
                            <span>Manuel</span>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($assetOptions as $asset): ?>
                        <div class="visual-option <?= $currentAsset === $asset['id'] ? 'selected' : '' ?>" data-val="<?= e($asset['id']) ?>" data-url="<?= e($asset['url']) ?>" data-label="<?= e($asset['label']) ?>">
                            <img src="<?= e($asset['url']) ?>" loading="lazy">
                            <span><?= e($asset['label']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--gray-200); padding-top: 8px;">
                  <label style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--gray-500); cursor: pointer; margin: 0;">
                    <span style="background: var(--gray-100); padding: 4px 8px; border-radius: 6px;">Envoyer un fichier local :</span>
                    <input type="file" name="slot_upload[<?= e($slot) ?>]" accept=".png,.jpg,.jpeg,.webp,.svg">
                  </label>
                  <label class="visual-admin-inline-toggle" style="margin: 0; font-size: 11px;">
                    <input type="checkbox" name="slot_reset[<?= e($slot) ?>]" value="1">
                    <span style="color: var(--danger);">Reset pur</span>
                  </label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="visual-admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer les slots visuels</button>
      </div>
    </form>
    <form method="POST" class="visual-admin-reset-form">
      <input type="hidden" name="action" value="reset_slots">
      <button type="submit" class="btn btn-outline">Revenir aux slots par defaut</button>
    </form>
    </div>
  </details>

  <details class="card visual-admin-card visual-admin-card--slots" id="visual-admin-badges">
    <summary class="card-header" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; list-style:none;">
      <div>
        <span class="card-title" style="display:block;">Badges de categories</span>
        <span class="text-small text-muted">Ces overrides s appliquent aux reperes categorie du dashboard, des listes et des dossiers</span>
      </div>
      <span style="font-size:20px; color:var(--primary);">▼</span>
    </summary>
    <div style="padding: 16px;">
    <?php if ($isTrainingMode): ?>
    <div class="admin-form-guide visual-admin-form-guide">
      <strong>Bon reflexe</strong>
      <div class="admin-form-guide-list">
        <div class="admin-form-guide-item">
          <span class="admin-form-guide-step">01</span>
          <div>
            <strong>Reserver ce niveau au repere compact</strong>
            <span>Les badges servent surtout a la lecture rapide dans les listes et les cartes, pas a refaire toute la scene.</span>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" class="visual-admin-form visual-admin-form--slots">
      <input type="hidden" name="action" value="save_category_badges">
      <div class="visual-admin-slot-grid">
        <?php foreach ($categoryEntries as $entry): ?>
          <?php
            $key = (string)($entry['key'] ?? '');
            if ($key === '') { continue; }
            $defaultBadgeAsset = category_badge_asset_id($key, $entry['label'] ?? null);
            $currentBadge = $settings['category_badges'][$key] ?? null;
            $effectiveBadge = visual_admin_category_badge($key, $defaultBadgeAsset);
            $effectiveBadgeUrl = $effectiveBadge ? generated_visual_url($effectiveBadge) : null;
            $isCustomBadge = visual_admin_is_custom_asset($currentBadge);
            
            $activeLabel = 'Défaut';
            if ($isCustomBadge) $activeLabel = 'Upload Manuel';
            foreach ($badgeAssetOptions as $asset) {
                if ($currentBadge === $asset['id']) { $activeLabel = $asset['label']; break; }
            }
          ?>
          <div class="visual-admin-slot-field" style="display: flex; flex-direction: column; gap: 8px;">
            <span style="font-weight: 700; font-size: 13px; color: var(--gray-800);"><?= e($entry['short_label'] ?? $entry['label'] ?? $key) ?></span>
            
            <div class="visual-select" data-input-name="category_badge[<?= e($key) ?>]">
                <input type="hidden" class="visual-select-input" name="category_badge[<?= e($key) ?>]" value="<?= e($currentBadge) ?>">
                <div class="visual-select-trigger">
                    <img src="<?= e($currentBadge ? $effectiveBadgeUrl : generated_visual_url($defaultBadgeAsset)) ?>" class="trigger-img" style="object-fit: contain; padding: 4px; background: white;">
                    <div style="flex:1">
                        <span class="trigger-label" style="display:block; font-size:13px; font-weight:700; color:var(--gray-800);"><?= e($activeLabel) ?></span>
                    </div>
                    <span style="color:var(--gray-400);">▼</span>
                </div>
                <div class="visual-select-dropdown">
                    <div class="visual-option <?= $currentBadge === null || $currentBadge === '' ? 'selected' : '' ?>" data-val="" data-url="<?= e(generated_visual_url($defaultBadgeAsset)) ?>" data-label="Défaut">
                        <img src="<?= e(generated_visual_url($defaultBadgeAsset)) ?>" style="object-fit: contain; padding: 4px; background: white;">
                        <span>Défaut</span>
                    </div>
                    <?php if ($isCustomBadge): ?>
                    <div class="visual-option selected" data-val="<?= e($currentBadge) ?>" data-url="<?= e($effectiveBadgeUrl) ?>" data-label="Manuel">
                        <img src="<?= e($effectiveBadgeUrl) ?>" style="object-fit: contain; padding: 4px; background: white;">
                        <span>Manuel</span>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($badgeAssetOptions as $asset): ?>
                    <div class="visual-option <?= $currentBadge === $asset['id'] ? 'selected' : '' ?>" data-val="<?= e($asset['id']) ?>" data-url="<?= e($asset['url']) ?>" data-label="<?= e($asset['label']) ?>">
                        <img src="<?= e($asset['url']) ?>" loading="lazy" style="object-fit: contain; padding: 4px; background: white;">
                        <span><?= e($asset['label']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--gray-200); padding-top: 8px;">
              <label style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--gray-500); cursor: pointer; margin: 0;">
                <span style="background: var(--gray-100); padding: 4px 8px; border-radius: 6px;">Envoyer un fichier local :</span>
                <input type="file" name="category_upload[<?= e($key) ?>]" accept=".png,.jpg,.jpeg,.webp,.svg" style="max-width: 150px;">
              </label>
              <label class="visual-admin-inline-toggle" style="margin: 0; font-size: 11px;">
                <input type="checkbox" name="category_badge_reset[<?= e($key) ?>]" value="1">
                <span style="color: var(--danger);">Reset pur</span>
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="visual-admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer les badges categories</button>
      </div>
    </form>
    <form method="POST" class="visual-admin-reset-form">
      <input type="hidden" name="action" value="reset_category_badges">
      <button type="submit" class="btn btn-outline">Revenir aux badges par defaut</button>
    </form>
    </div>
  </details>

  <details class="card visual-admin-card visual-admin-card--slots" id="visual-admin-scenes">
    <summary class="card-header" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; list-style:none;">
      <div>
        <span class="card-title" style="display:block;">Scenes de categories</span>
        <span class="text-small text-muted">Ces overrides s appliquent aux illustrations terrain associees aux categories</span>
      </div>
      <span style="font-size:20px; color:var(--primary);">▼</span>
    </summary>
    <div style="padding: 16px;">
    <?php if ($isTrainingMode): ?>
    <div class="admin-form-guide visual-admin-form-guide">
      <strong>Bon reflexe</strong>
      <div class="admin-form-guide-list">
        <div class="admin-form-guide-item">
          <span class="admin-form-guide-step">01</span>
          <div>
            <strong>Traiter les scenes en second</strong>
            <span>Verifier d abord le badge et le repere de categorie, puis aligner la scene si le contexte terrain reste ambigu.</span>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" class="visual-admin-form visual-admin-form--slots">
      <input type="hidden" name="action" value="save_category_scenes">
      <div class="visual-admin-slot-grid">
        <?php foreach ($categoryEntries as $entry): ?>
          <?php
            $key = (string)($entry['key'] ?? '');
            if ($key === '') { continue; }
            $defaultSceneAsset = category_scene_asset_id($key, $entry['label'] ?? null);
            $currentScene = $settings['category_scenes'][$key] ?? null;
            $effectiveScene = visual_admin_category_scene($key, $defaultSceneAsset);
            $effectiveSceneUrl = $effectiveScene ? generated_visual_url($effectiveScene) : null;
            $isCustomScene = visual_admin_is_custom_asset($currentScene);
            
            $activeLabel = 'Défaut';
            if ($isCustomScene) $activeLabel = 'Upload Manuel';
            foreach ($sceneAssetOptions as $asset) {
                if ($currentScene === $asset['id']) { $activeLabel = $asset['label']; break; }
            }
          ?>
          <div class="visual-admin-slot-field" style="display: flex; flex-direction: column; gap: 8px;">
            <span style="font-weight: 700; font-size: 13px; color: var(--gray-800);"><?= e($entry['short_label'] ?? $entry['label'] ?? $key) ?></span>
            
            <div class="visual-select" data-input-name="category_scene[<?= e($key) ?>]">
                <input type="hidden" class="visual-select-input" name="category_scene[<?= e($key) ?>]" value="<?= e($currentScene) ?>">
                <div class="visual-select-trigger">
                    <img src="<?= e($currentScene ? $effectiveSceneUrl : generated_visual_url($defaultSceneAsset)) ?>" class="trigger-img">
                    <div style="flex:1">
                        <span class="trigger-label" style="display:block; font-size:13px; font-weight:700; color:var(--gray-800);"><?= e($activeLabel) ?></span>
                    </div>
                    <span style="color:var(--gray-400);">▼</span>
                </div>
                <div class="visual-select-dropdown">
                    <div class="visual-option <?= $currentScene === null || $currentScene === '' ? 'selected' : '' ?>" data-val="" data-url="<?= e(generated_visual_url($defaultSceneAsset)) ?>" data-label="Défaut">
                        <img src="<?= e(generated_visual_url($defaultSceneAsset)) ?>">
                        <span>Défaut</span>
                    </div>
                    <?php if ($isCustomScene): ?>
                    <div class="visual-option selected" data-val="<?= e($currentScene) ?>" data-url="<?= e($effectiveSceneUrl) ?>" data-label="Manuel">
                        <img src="<?= e($effectiveSceneUrl) ?>">
                        <span>Manuel</span>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($sceneAssetOptions as $asset): ?>
                    <div class="visual-option <?= $currentScene === $asset['id'] ? 'selected' : '' ?>" data-val="<?= e($asset['id']) ?>" data-url="<?= e($asset['url']) ?>" data-label="<?= e($asset['label']) ?>">
                        <img src="<?= e($asset['url']) ?>" loading="lazy">
                        <span><?= e($asset['label']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--gray-200); padding-top: 8px;">
              <label style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--gray-500); cursor: pointer; margin: 0;">
                <span style="background: var(--gray-100); padding: 4px 8px; border-radius: 6px;">Envoyer un fichier local :</span>
                <input type="file" name="category_scene_upload[<?= e($key) ?>]" accept=".png,.jpg,.jpeg,.webp,.svg" style="max-width: 150px;">
              </label>
              <label class="visual-admin-inline-toggle" style="margin: 0; font-size: 11px;">
                <input type="checkbox" name="category_scene_reset[<?= e($key) ?>]" value="1">
                <span style="color: var(--danger);">Reset pur</span>
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="visual-admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer les scenes categories</button>
      </div>
    </form>
    <form method="POST" class="visual-admin-reset-form">
      <input type="hidden" name="action" value="reset_category_scenes">
      <button type="submit" class="btn btn-outline">Revenir aux scenes par defaut</button>
    </form>
    </div>
  </details>

  <details class="card visual-admin-card visual-admin-card--slots" id="visual-admin-nav">
    <summary class="card-header" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center; list-style:none;">
      <div>
        <span class="card-title" style="display:block;">Barre de Menus</span>
        <span class="text-small text-muted">Ces overrides s'appliquent aux petites icones se situant dans le menu lateral du BackOffice (Dashboard, Carte, Utilisateurs)</span>
      </div>
      <span style="font-size:20px; color:var(--primary);">▼</span>
    </summary>
    <div style="padding: 16px;">
    <form method="POST" enctype="multipart/form-data" class="visual-admin-form visual-admin-form--slots">
      <input type="hidden" name="action" value="save_nav_icons">
      <div class="visual-admin-slot-grid">
        <?php foreach (array_keys(visual_admin_default_settings()['nav_icons']) as $navKey): ?>
          <?php
            $fileMapping = [
                'visual_admin' => 'settings',
                'icon_packs' => 'identity',
                'audit_logs' => 'logs'
            ];
            $mappedFileName = $fileMapping[$navKey] ?? $navKey;
            $defaultNavAsset = '/admin/assets/img/nav-icons/' . rawurlencode($mappedFileName) . '.png';
            
            $currentNav = $settings['nav_icons'][$navKey] ?? null;
            $effectiveNav = visual_admin_nav_icon_url($navKey);
            $isCustomNav = visual_admin_is_custom_asset($currentNav);
            
            $activeLabel = 'Défaut';
            if ($isCustomNav) $activeLabel = 'Upload Manuel';
            foreach ($badgeAssetOptions as $asset) {
                if ($currentNav === $asset['id']) { $activeLabel = $asset['label']; break; }
            }
          ?>
          <div class="visual-admin-slot-field" style="display: flex; flex-direction: column; gap: 8px;">
            <span style="font-weight: 700; font-size: 13px; color: var(--gray-800); text-transform: capitalize;"><?= e(str_replace('_', ' ', $navKey)) ?></span>
            
            <div class="visual-select" data-input-name="nav_icon[<?= e($navKey) ?>]">
                <input type="hidden" class="visual-select-input" name="nav_icon[<?= e($navKey) ?>]" value="<?= e($currentNav) ?>">
                <div class="visual-select-trigger">
                    <img src="<?= e($effectiveNav) ?>" class="trigger-img">
                    <div style="flex:1">
                        <span class="trigger-label" style="display:block; font-size:13px; font-weight:700; color:var(--gray-800);"><?= e($activeLabel) ?></span>
                    </div>
                    <span style="color:var(--gray-400);">▼</span>
                </div>
                <div class="visual-select-dropdown">
                    <div class="visual-option <?= $currentNav === null || $currentNav === '' ? 'selected' : '' ?>" data-val="" data-url="<?= e($defaultNavAsset) ?>" data-label="Défaut">
                        <img src="<?= e($defaultNavAsset) ?>">
                        <span>Défaut</span>
                    </div>
                    <?php if ($isCustomNav): ?>
                    <div class="visual-option selected" data-val="<?= e($currentNav) ?>" data-url="<?= e($effectiveNav) ?>" data-label="Manuel">
                        <img src="<?= e($effectiveNav) ?>">
                        <span>Manuel</span>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($badgeAssetOptions as $asset): ?>
                    <div class="visual-option <?= $currentNav === $asset['id'] ? 'selected' : '' ?>" data-val="<?= e($asset['id']) ?>" data-url="<?= e($asset['url']) ?>" data-label="<?= e($asset['label']) ?>">
                        <img src="<?= e($asset['url']) ?>" loading="lazy">
                        <span><?= e($asset['label']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--gray-200); padding-top: 8px;">
              <label style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--gray-500); cursor: pointer; margin: 0;">
                <span style="background: var(--gray-100); padding: 4px 8px; border-radius: 6px;">Envoyer local :</span>
                <input type="file" name="nav_upload[<?= e($navKey) ?>]" accept=".png,.jpg,.jpeg,.webp,.svg" style="max-width: 150px;">
              </label>
              <label class="visual-admin-inline-toggle" style="margin: 0; font-size: 11px;">
                <input type="checkbox" name="nav_icon_reset[<?= e($navKey) ?>]" value="1">
                <span style="color: var(--danger);">Reset</span>
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="visual-admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer le Menu</button>
      </div>
    </form>
    <form method="POST" class="visual-admin-reset-form">
      <input type="hidden" name="action" value="reset_nav_icons">
      <button type="submit" class="btn btn-outline">Revenir au Menu par defaut</button>
    </form>
    </div>
  </details>

<script>
/* Init: Dropdowns fonctionnels en SPA (Event Delegation) + Restauration ancre */
(function() {
  document.addEventListener('click', function(e) {
    /* 1. Toggle Dropdown */
    var trigger = e.target.closest ? e.target.closest('.visual-select-trigger') : null;
    if (trigger) {
      e.stopPropagation();
      var sel = trigger.closest('.visual-select');
      if (!sel) return;
      var wasOpen = sel.classList.contains('is-open');
      document.querySelectorAll('.visual-select.is-open').forEach(function(x){x.classList.remove('is-open');});
      if (!wasOpen) sel.classList.add('is-open');
      return;
    }
    
    /* 2. Sélection d'une option */
    var opt = e.target.closest ? e.target.closest('.visual-option') : null;
    if (opt) {
      e.stopPropagation();
      var sel2 = opt.closest('.visual-select');
      if (!sel2) return;
      var inp = sel2.querySelector('.visual-select-input');
      if (inp) inp.value = opt.getAttribute('data-val') || '';
      var img = sel2.querySelector('.trigger-img');
      if (img) img.src = opt.getAttribute('data-url') || '';
      var lbl = sel2.querySelector('.trigger-label');
      if (lbl) lbl.textContent = opt.getAttribute('data-label') || '';
      
      sel2.querySelectorAll('.visual-option').forEach(function(x){x.classList.remove('selected');});
      opt.classList.add('selected');
      sel2.classList.remove('is-open');
      return;
    }
    
    /* 3. Fermer si on clique en dehors */
    if (!e.target.closest || !e.target.closest('.visual-select')) {
      document.querySelectorAll('.visual-select.is-open').forEach(function(x){x.classList.remove('is-open');});
    }
  });

  /* Restauration de l'ouverture du bon <details> si on arrive par ancre */
  var hash = window.location.hash.replace('#', '');
  if(hash) {
      var target = document.getElementById(hash);
      if(target && target.tagName.toLowerCase() === 'details') {
          target.open = true;
      }
  }
})();
</script>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
