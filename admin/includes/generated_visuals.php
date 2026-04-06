<?php
/**
 * Helpers de resolution des visuels generes installes.
 */

function generated_visuals_manifest(): array
{
    static $manifest = null;

    if ($manifest !== null) {
        return $manifest;
    }

    $path = __DIR__ . '/installed-visual-assets.json';
    if (!is_file($path)) {
        $path = dirname(__DIR__, 2) . '/assets/visual-production/generated/installed-visual-assets.json';
    }
    if (!is_file($path)) {
        $manifest = [];
        return $manifest;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    $manifest = is_array($decoded) ? $decoded : [];
    if (!isset($manifest['assets'])) {
        $manifest['assets'] = [];
    }

    // Injection architecturale des icônes locales pour unifier la bibliotheque
    $staticIconsPath = dirname(__DIR__) . '/assets/img/nav-icons/*.png';
    foreach (glob($staticIconsPath) as $navIconFile) {
        if (strpos($navIconFile, 'old_icons') !== false) {
            continue;
        }
        $filename = basename($navIconFile);
        $id = 'NAV-' . strtoupper(basename($filename, '.png'));
        $manifest['assets'][] = [
            'id' => $id,
            'label' => 'Nav · ' . ucfirst(str_replace('_', ' ', basename($filename, '.png'))),
            'targets' => [
                'admin' => 'admin/assets/img/nav-icons/' . rawurlencode($filename)
            ]
        ];
    }

    return $manifest;
}

function generated_visuals_asset_index(): array
{
    static $index = null;

    if ($index !== null) {
        return $index;
    }

    $index = [];
    foreach ((generated_visuals_manifest()['assets'] ?? []) as $asset) {
        $id = trim((string)($asset['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $index[$id] = is_array($asset) ? $asset : [];
    }

    return $index;
}

function generated_visual_entry(?string $assetId): ?array
{
    if (!is_string($assetId) || trim($assetId) === '') {
        return null;
    }

    return generated_visuals_asset_index()[$assetId] ?? null;
}

function generated_visual_reference_is_direct(?string $assetId): bool
{
    return is_string($assetId) && $assetId !== '' && preg_match('#^(https?://|/)#', $assetId) === 1;
}

function generated_visual_url(?string $assetId, string $target = 'admin'): ?string
{
    if (!is_string($assetId) || trim($assetId) === '') {
        return null;
    }

    $assetId = trim($assetId);
    if (generated_visual_reference_is_direct($assetId)) {
        return $assetId;
    }

    $asset = generated_visual_entry($assetId);
    $relative = $asset['targets'][$target] ?? null;
    if (!is_string($relative) || $relative === '') {
        return null;
    }

    return '/' . ltrim(str_replace('\\', '/', $relative), '/');
}

function generated_visual_label(?string $assetId): ?string
{
    if (!is_string($assetId) || trim($assetId) === '') {
        return null;
    }

    $assetId = trim($assetId);
    if (generated_visual_reference_is_direct($assetId)) {
        return basename(parse_url($assetId, PHP_URL_PATH) ?: $assetId);
    }

    $asset = generated_visual_entry($assetId);
    $label = $asset['label'] ?? null;

    return is_string($label) && $label !== '' ? $label : null;
}

function generated_visual_html(?string $assetId, array $options = []): string
{
    $src = generated_visual_url($assetId, $options['target'] ?? 'admin');
    if (!$src) {
        return '';
    }

    $class = trim((string)($options['class'] ?? 'generated-visual generated-visual--cover'));
    $label = (string)($options['label'] ?? generated_visual_label($assetId) ?? 'Visuel Ma Commune');
    $loading = (string)($options['loading'] ?? 'lazy');

    return sprintf(
        '<img class="%s" src="%s" alt="%s" loading="%s">',
        htmlspecialchars($class, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($loading, ENT_QUOTES, 'UTF-8')
    );
}

function generated_visual_asset_exists(?string $assetId, string $target = 'admin'): bool
{
    if (!is_string($assetId) || trim($assetId) === '') {
        return false;
    }

    $assetId = trim($assetId);
    if (generated_visual_reference_is_direct($assetId)) {
        return true;
    }
    
    return generated_visual_url($assetId, $target) !== null;
}

function visual_admin_default_settings(): array
{
    return [
        'branding' => [
            'brand_mark_url' => null,
            'login_subtitle' => defined('APP_ADMIN_LOGIN_SUBTITLE') ? APP_ADMIN_LOGIN_SUBTITLE : 'Territoire · poste de suivi public',
        ],
        'theme' => [
            'variant' => defined('APP_THEME_VARIANT') ? APP_THEME_VARIANT : 'neutral-civic',
            'primary' => defined('APP_THEME_PRIMARY') ? APP_THEME_PRIMARY : '#355160',
            'primary_dark' => defined('APP_THEME_PRIMARY_DARK') ? APP_THEME_PRIMARY_DARK : '#223743',
            'primary_light' => defined('APP_THEME_PRIMARY_LIGHT') ? APP_THEME_PRIMARY_LIGHT : '#E7EEF1',
            'secondary' => defined('APP_THEME_SECONDARY') ? APP_THEME_SECONDARY : '#718892',
            'accent' => defined('APP_THEME_ACCENT') ? APP_THEME_ACCENT : '#C3A166',
        ],
        'slots' => [
            'login_scene' => null,
            'login_inset' => null,
            'login_agent' => null,
            'sidebar_scene' => null,
            'sidebar_inset' => null,
            'sidebar_agent' => null,
            'topbar_scene' => null,
            'topbar_inset' => null,
            'topbar_agent' => null,
            'dashboard_primary' => null,
            'dashboard_secondary' => null,
        ],
        'category_badges' => [
            'road' => null,
            'lightbulb' => null,
            'tree' => null,
            'trash' => null,
            'bench' => null,
            'droplets' => null,
            'triangle-alert' => null,
            'building-2' => null,
        ],
        'category_scenes' => [
            'road' => null,
            'lightbulb' => null,
            'tree' => null,
            'trash' => null,
            'bench' => null,
            'droplets' => null,
            'triangle-alert' => null,
            'building-2' => null,
        ],
        'nav_icons' => [
            'dashboard' => null,
            'incidents' => null,
            'map' => null,
            'users' => null,
            'services' => null,
            'categories' => null,
            'notifications' => null,
            'moderation' => null,
            'audit_logs' => null,
            'stats' => null,
            'search' => null,
            'visual_admin' => null,
            'icon_packs' => null,
        ],
        'custom_themes' => [],
    ];
}
function visual_admin_theme_presets(): array
{
    $basePresets = [
        'neutral-civic' => [
            'label' => 'Neutral Civic',
            'description' => 'Socle institutionnel neutre, dense et premium.',
            'primary' => '#355160',
            'primary_dark' => '#223743',
            'primary_light' => '#E7EEF1',
            'secondary' => '#718892',
            'accent' => '#C3A166',
        ],
        'lagoon-public' => [
            'label' => 'Lagoon Public',
            'description' => 'Variation plus claire et aerienne pour les communes littorales.',
            'primary' => '#2F6071',
            'primary_dark' => '#1D4150',
            'primary_light' => '#E4F1F4',
            'secondary' => '#6FA1AF',
            'accent' => '#D7A86E',
        ],
        'canopy-service' => [
            'label' => 'Canopy Service',
            'description' => 'Variation vegetale calme, orientee exploitation terrain.',
            'primary' => '#355848',
            'primary_dark' => '#223A31',
            'primary_light' => '#E5EEE8',
            'secondary' => '#6E877A',
            'accent' => '#C5A469',
        ],
        'terracotta-civic' => [
            'label' => 'Terracotta Civic',
            'description' => 'Variation plus chaude pour un accent civique marque.',
            'primary' => '#6A4A45',
            'primary_dark' => '#4A312E',
            'primary_light' => '#F2E8E4',
            'secondary' => '#92746E',
            'accent' => '#D19A5C',
        ],
    ];

    $settings = visual_admin_settings();
    $customThemes = is_array($settings['custom_themes'] ?? null) ? $settings['custom_themes'] : [];
    
    // Merge base presets with user-defined custom themes
    foreach ($customThemes as $key => $customTheme) {
        if (!isset($basePresets[$key]) && is_array($customTheme)) {
            $basePresets[$key] = [
                'label' => trim((string)($customTheme['label'] ?? $key)),
                'description' => trim((string)($customTheme['description'] ?? 'Thème personnalisé. (Créé par un administrateur)')),
                'primary' => visual_admin_sanitize_hex_color($customTheme['primary'] ?? null, '#355160'),
                'primary_dark' => visual_admin_sanitize_hex_color($customTheme['primary_dark'] ?? null, '#223743'),
                'primary_light' => visual_admin_sanitize_hex_color($customTheme['primary_light'] ?? null, '#E7EEF1'),
                'secondary' => visual_admin_sanitize_hex_color($customTheme['secondary'] ?? null, '#718892'),
                'accent' => visual_admin_sanitize_hex_color($customTheme['accent'] ?? null, '#C3A166'),
                'is_custom' => true,
            ];
        }
    }

    return $basePresets;
}

function visual_admin_theme_default_values(): array
{
    return visual_admin_default_settings()['theme'];
}

function visual_admin_ensure_storage_dir(string $path): string
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Impossible de preparer le stockage visuel.');
    }

    return $path;
}

function visual_admin_settings_path(): string
{
    $dir = visual_admin_ensure_storage_dir(rtrim(UPLOAD_DIR, '/\\') . '/admin-visual-state');
    return $dir . '/admin-visual-settings.json';
}

function visual_admin_snapshots_dir(): string
{
    return visual_admin_ensure_storage_dir(rtrim(UPLOAD_DIR, '/\\') . '/admin-visual-state/snapshots');
}

function visual_admin_snapshot_path(string $snapshotId): string
{
    $snapshotId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $snapshotId) ?: 'snapshot';
    return visual_admin_snapshots_dir() . '/' . $snapshotId . '.json';
}

function visual_admin_snapshot_id(): string
{
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $suffix = substr(sha1((string)microtime(true)), 0, 8);
    }

    return 'va-' . date('Ymd-His') . '-' . $suffix;
}

function visual_admin_save_settings(array $settings): void
{
    $payload = visual_admin_sanitize_settings($settings);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Impossible d encoder la configuration visuelle.');
    }

    $path = visual_admin_settings_path();
    visual_admin_ensure_storage_dir(dirname($path));
    if (file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException('Impossible d enregistrer la configuration visuelle.');
    }
}

function visual_admin_save_snapshot(string $name, array $settings, array $metadata = []): string
{
    $snapshotId = visual_admin_snapshot_id();
    $payload = [
        'id' => $snapshotId,
        'name' => trim($name) !== '' ? trim($name) : 'Snapshot ' . date('d/m/Y H:i'),
        'created_at' => date(DATE_ATOM),
        'settings' => visual_admin_sanitize_settings($settings),
        'metadata' => is_array($metadata) ? $metadata : [],
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Impossible d encoder le snapshot visuel.');
    }

    if (file_put_contents(visual_admin_snapshot_path($snapshotId), $json . PHP_EOL) === false) {
        throw new RuntimeException('Impossible d enregistrer le snapshot visuel.');
    }

    return $snapshotId;
}

function visual_admin_load_snapshot(string $snapshotId): array
{
    $path = visual_admin_snapshot_path($snapshotId);
    if (!is_file($path)) {
        throw new RuntimeException('Snapshot visuel introuvable.');
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Snapshot visuel corrompu.');
    }

    $decoded['settings'] = visual_admin_sanitize_settings((array)($decoded['settings'] ?? []));
    $decoded['metadata'] = is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [];

    return $decoded;
}

function visual_admin_list_snapshots(): array
{
    $dir = visual_admin_snapshots_dir();
    $files = glob($dir . '/*.json') ?: [];
    $snapshots = [];

    foreach ($files as $file) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (!is_array($decoded)) {
            continue;
        }

        $decoded['settings'] = visual_admin_sanitize_settings((array)($decoded['settings'] ?? []));
        $decoded['metadata'] = is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [];
        $decoded['created_at'] = (string)($decoded['created_at'] ?? date(DATE_ATOM, filemtime($file) ?: time()));
        $snapshots[] = $decoded;
    }

    usort($snapshots, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    return $snapshots;
}

function visual_admin_prune_auto_snapshots(int $keep = 12): void
{
    $keep = max(0, $keep);
    $autoSnapshots = array_values(array_filter(
        visual_admin_list_snapshots(),
        static fn(array $snapshot): bool => (($snapshot['metadata']['type'] ?? 'manual') === 'auto')
    ));

    if (count($autoSnapshots) <= $keep) {
        return;
    }

    foreach (array_slice($autoSnapshots, $keep) as $snapshot) {
        $snapshotId = trim((string)($snapshot['id'] ?? ''));
        if ($snapshotId === '') {
            continue;
        }
        visual_admin_delete_snapshot($snapshotId);
    }
}

function visual_admin_delete_snapshot(string $snapshotId): void
{
    $path = visual_admin_snapshot_path($snapshotId);
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Impossible de supprimer le snapshot visuel.');
    }
}

function visual_admin_nested_upload(array $files, string $key): array
{
    $result = [];
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
        $result[$field] = $files[$field][$key] ?? null;
    }

    return $result;
}

function visual_admin_store_uploaded_image(array $upload, string $slug): ?string
{
    $tmpName = (string)($upload['tmp_name'] ?? '');
    $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE || $tmpName === '') {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Upload image invalide.');
    }

    $mime = mime_content_type($tmpName) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new RuntimeException('Format image non pris en charge pour le poste visuel.');
    }

    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $safeSlug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($slug)) ?: 'visual';
    $filename = $safeSlug . '-' . date('Ymd-His');
    try {
        $filename .= '-' . bin2hex(random_bytes(3));
    } catch (Throwable $e) {
        $filename .= '-' . substr(sha1($tmpName . microtime(true)), 0, 6);
    }
    $filename .= '.' . ($extensionMap[$mime] ?? 'png');

    $relativePath = 'admin-visual/' . $filename;
    $absoluteDir = visual_admin_ensure_storage_dir(rtrim(UPLOAD_DIR, '/\\') . '/admin-visual');
    $absolutePath = $absoluteDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException('Impossible de stocker le visuel televerse.');
    }

    return '/uploads/' . $relativePath;
}

function visual_admin_array_merge(array $defaults, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
            $defaults[$key] = visual_admin_array_merge($defaults[$key], $value);
        } else {
            $defaults[$key] = $value;
        }
    }

    return $defaults;
}

function visual_admin_sanitize_hex_color(?string $value, string $fallback): string
{
    $value = is_string($value) ? trim($value) : '';
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : $fallback;
}

function visual_admin_sanitize_settings(array $input, ?array $defaults = null): array
{
    $defaults = $defaults ?? visual_admin_default_settings();
    $clean = $defaults;

    foreach ($defaults as $key => $defaultValue) {
        if (!array_key_exists($key, $input)) {
            continue;
        }

        $incoming = $input[$key];
        if (is_array($defaultValue)) {
            if (!is_array($incoming)) {
                continue;
            }
            $clean[$key] = visual_admin_sanitize_settings($incoming, $defaultValue);
            continue;
        }

        if ($incoming === null) {
            $clean[$key] = null;
            continue;
        }

        if (is_string($incoming)) {
            $trimmed = trim($incoming);
            $clean[$key] = $trimmed !== '' ? $trimmed : null;
            continue;
        }

        $clean[$key] = $incoming;
    }

    if (isset($clean['theme']) && is_array($clean['theme'])) {
        $clean['theme']['primary'] = visual_admin_sanitize_hex_color($clean['theme']['primary'] ?? null, $defaults['theme']['primary'] ?? '#355160');
        $clean['theme']['primary_dark'] = visual_admin_sanitize_hex_color($clean['theme']['primary_dark'] ?? null, $defaults['theme']['primary_dark'] ?? '#223743');
        $clean['theme']['primary_light'] = visual_admin_sanitize_hex_color($clean['theme']['primary_light'] ?? null, $defaults['theme']['primary_light'] ?? '#E7EEF1');
        $clean['theme']['secondary'] = visual_admin_sanitize_hex_color($clean['theme']['secondary'] ?? null, $defaults['theme']['secondary'] ?? '#718892');
        $clean['theme']['accent'] = visual_admin_sanitize_hex_color($clean['theme']['accent'] ?? null, $defaults['theme']['accent'] ?? '#C3A166');
        $variant = trim((string)($clean['theme']['variant'] ?? $defaults['theme']['variant']));
        $clean['theme']['variant'] = $variant !== '' ? $variant : ($defaults['theme']['variant'] ?? 'neutral-civic');
    }

    return $clean;
}

function visual_admin_settings(bool $refresh = false): array
{
    static $settings = null;

    if ($refresh || $settings === null) {
        $path = visual_admin_settings_path();
        $defaults = visual_admin_default_settings();
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            $settings = is_array($decoded)
                ? visual_admin_sanitize_settings(visual_admin_array_merge($defaults, $decoded), $defaults)
                : $defaults;
        } else {
            $settings = $defaults;
        }
    }

    return $settings;
}

function visual_admin_brand_asset_url(string $key, string $fallbackUrl): string
{
    $settings = visual_admin_settings();
    $value = $settings['branding'][$key] ?? null;
    return is_string($value) && $value !== '' ? $value : $fallbackUrl;
}

function visual_admin_brand_text(string $key, string $fallback): string
{
    $settings = visual_admin_settings();
    $value = $settings['branding'][$key] ?? null;
    return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
}

function visual_admin_theme_settings(): array
{
    $defaults = visual_admin_default_settings()['theme'];
    $presets = visual_admin_theme_presets();
    $settings = visual_admin_settings();
    $theme = is_array($settings['theme'] ?? null) ? $settings['theme'] : [];
    $variant = trim((string)($theme['variant'] ?? $defaults['variant']));

    // Dérogation : Si l'agent appartient à un service ayant un thème spécifique assigné
    if (function_exists('current_admin') && class_exists('Database')) {
        $admin = current_admin();
        if ($admin && !empty($admin['primary_service_id'])) {
            $db = Database::getInstance();
            if (admin_db_has_table($db, 'services') && admin_db_has_column($db, 'services', 'theme_variant')) {
                $stmt = $db->prepare('SELECT theme_variant FROM services WHERE id = ?');
                $stmt->execute([$admin['primary_service_id']]);
                $serviceVariant = $stmt->fetchColumn();
                // Si le service a un thème et qu'il existe dans la bibliothèque
                if ($serviceVariant && isset($presets[$serviceVariant])) {
                    $variant = $serviceVariant;
                    $theme = []; // Force la récupération des couleurs pures du preset
                }
            }
        }
    }

    $preset = $presets[$variant] ?? [];

    return [
        'variant' => $variant,
        'label' => trim((string)($preset['label'] ?? $variant)),
        'description' => trim((string)($preset['description'] ?? '')),
        'primary' => visual_admin_sanitize_hex_color($theme['primary'] ?? null, (string)($preset['primary'] ?? $defaults['primary'])),
        'primary_dark' => visual_admin_sanitize_hex_color($theme['primary_dark'] ?? null, (string)($preset['primary_dark'] ?? $defaults['primary_dark'])),
        'primary_light' => visual_admin_sanitize_hex_color($theme['primary_light'] ?? null, (string)($preset['primary_light'] ?? $defaults['primary_light'])),
        'secondary' => visual_admin_sanitize_hex_color($theme['secondary'] ?? null, (string)($preset['secondary'] ?? $defaults['secondary'])),
        'accent' => visual_admin_sanitize_hex_color($theme['accent'] ?? null, (string)($preset['accent'] ?? $defaults['accent'])),
    ];
}

function visual_admin_theme_css_variables(): array
{
    $theme = visual_admin_theme_settings();

    return [
        '--primary' => $theme['primary'],
        '--primary-dark' => $theme['primary_dark'],
        '--primary-light' => $theme['primary_light'],
        '--secondary' => $theme['secondary'],
        '--accent' => $theme['accent'],
    ];
}

function visual_admin_nav_icon_url(string $navKey): string
{
    $settings = visual_admin_settings();
    $custom = $settings['nav_icons'][$navKey] ?? null;
    
    if (is_string($custom) && trim($custom) !== '') {
        $custom = trim($custom);
        if (generated_visual_asset_exists($custom)) {
            return (string)generated_visual_url($custom);
        }
        return $custom; 
    }
    
    $fileMapping = [
        'visual_admin' => 'settings',
        'icon_packs' => 'identity',
        'audit_logs' => 'logs'
    ];
    
    $filename = $fileMapping[$navKey] ?? $navKey;

    return '/admin/assets/img/nav-icons/' . rawurlencode($filename) . '.png';
}

function visual_admin_theme_inline_style(): string
{
    $rules = [];
    foreach (visual_admin_theme_css_variables() as $name => $value) {
        $rules[] = $name . ':' . $value;
    }

    return implode(';', $rules);
}

function visual_admin_data_palette(): array
{
    $theme = visual_admin_theme_settings();

    return [
        'primary' => $theme['primary'],
        'primary_dark' => $theme['primary_dark'],
        'primary_light' => $theme['primary_light'],
        'secondary' => $theme['secondary'],
        'accent' => $theme['accent'],
        'surface' => '#ffffff',
        'grid' => '#f1f5f9',
        'ink' => '#203138',
        'muted' => '#61706d',
        'success' => '#44725d',
        'warning' => '#c89446',
        'danger' => '#b75d4e',
        'info' => '#597985',
    ];
}

function visual_admin_status_palette(): array
{
    $palette = visual_admin_data_palette();

    return [
        'submitted' => $palette['secondary'],
        'acknowledged' => $palette['primary'],
        'in_progress' => $palette['accent'],
        'resolved' => $palette['success'],
        'rejected' => $palette['danger'],
        'default' => $palette['muted'],
    ];
}

function visual_admin_resolve_asset_override($configured, ?string $fallbackAsset = null): ?string
{
    $configured = is_string($configured) ? trim($configured) : '';
    if ($configured !== '' && generated_visual_asset_exists($configured)) {
        return $configured;
    }

    $fallbackAsset = is_string($fallbackAsset) ? trim($fallbackAsset) : null;
    if ($fallbackAsset !== null && $fallbackAsset !== '' && generated_visual_asset_exists($fallbackAsset)) {
        return $fallbackAsset;
    }

    return null;
}

function visual_admin_slot_asset(string $slot, ?string $fallbackAsset = null): ?string
{
    $settings = visual_admin_settings();
    return visual_admin_resolve_asset_override($settings['slots'][$slot] ?? null, $fallbackAsset);
}

function visual_admin_slot_url(string $slot, ?string $fallbackAsset = null): ?string
{
    $asset = visual_admin_slot_asset($slot, $fallbackAsset);
    return $asset ? generated_visual_url($asset) : null;
}

function visual_admin_category_badge(string $categoryKey, ?string $fallbackAsset = null): ?string
{
    return visual_admin_resolve_asset_override(visual_admin_settings()['category_badges'][$categoryKey] ?? null, $fallbackAsset);
}

function visual_admin_category_scene(string $categoryKey, ?string $fallbackAsset = null): ?string
{
    return visual_admin_resolve_asset_override(visual_admin_settings()['category_scenes'][$categoryKey] ?? null, $fallbackAsset);
}
