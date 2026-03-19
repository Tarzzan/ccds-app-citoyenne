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

    $path = dirname(__DIR__, 2) . '/assets/visual-production/generated/installed-visual-assets.json';
    if (!is_file($path)) {
        $manifest = [];
        return $manifest;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    $manifest = is_array($decoded) ? $decoded : [];

    return $manifest;
}

function generated_visual_entry(string $assetId): ?array
{
    $manifest = generated_visuals_manifest();
    foreach (($manifest['assets'] ?? []) as $asset) {
        if (($asset['id'] ?? '') === $assetId) {
            return $asset;
        }
    }

    return null;
}

function generated_visual_url(string $assetId, string $target = 'admin'): ?string
{
    $asset = generated_visual_entry($assetId);
    $relative = $asset['targets'][$target] ?? null;
    if (!is_string($relative) || $relative === '') {
        return null;
    }

    return '/' . ltrim(str_replace('\\', '/', $relative), '/');
}

function generated_visual_label(string $assetId): ?string
{
    $asset = generated_visual_entry($assetId);
    $label = $asset['label'] ?? null;

    return is_string($label) && $label !== '' ? $label : null;
}

function generated_visual_html(string $assetId, array $options = []): string
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
