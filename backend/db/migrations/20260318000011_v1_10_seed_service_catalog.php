<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V110SeedServiceCatalog extends AbstractMigration
{
    private const SERVICE_CATALOG = [
        'voirie' => 'Voirie',
        'eclairage_public' => 'Eclairage public',
        'espaces_verts' => 'Espaces verts',
        'proprete_urbaine' => 'Proprete urbaine',
        'mobilier_urbain' => 'Mobilier urbain',
        'reseaux_inondations' => 'Reseaux et inondations',
        'signalisation' => 'Signalisation',
        'batiments_communaux' => 'Batiments communaux',
    ];

    public function up(): void
    {
        if (!$this->hasTable('services') || !$this->hasTable('service_category_map')) {
            return;
        }

        $pdo = $this->getAdapter()->getConnection();

        foreach (self::SERVICE_CATALOG as $code => $name) {
            $stmt = $pdo->prepare('SELECT id FROM services WHERE code = ? LIMIT 1');
            $stmt->execute([$code]);
            $exists = $stmt->fetch();
            if (!$exists) {
                $stmt = $pdo->prepare('INSERT INTO services (code, name, is_active, created_at) VALUES (?, ?, 1, NOW())');
                $stmt->execute([$code, $name]);
            }
        }

        $categories = $this->fetchAll('SELECT id, name, icon FROM categories ORDER BY id ASC');
        foreach ($categories as $category) {
            $serviceCode = $this->resolveServiceCode((string)($category['icon'] ?? ''), (string)($category['name'] ?? ''));
            if (!$serviceCode) {
                continue;
            }

            $stmt = $pdo->prepare('SELECT id, name FROM services WHERE code = ? LIMIT 1');
            $stmt->execute([$serviceCode]);
            $service = $stmt->fetch();
            if (!$service) {
                continue;
            }

            $stmt = $pdo->prepare('SELECT id FROM service_category_map WHERE service_id = ? AND category_id = ? LIMIT 1');
            $stmt->execute([(int)$service['id'], (int)$category['id']]);
            $mappingExists = $stmt->fetch();
            if (!$mappingExists) {
                $stmt = $pdo->prepare(
                    'INSERT INTO service_category_map (service_id, category_id, priority_order, is_default, created_at) VALUES (?, ?, 1, 1, NOW())'
                );
                $stmt->execute([(int)$service['id'], (int)$category['id']]);
            }

            $stmt = $pdo->prepare('UPDATE categories SET service = ? WHERE id = ? AND (service IS NULL OR TRIM(service) = \'\')');
            $stmt->execute([(string)$service['name'], (int)$category['id']]);
        }
    }

    public function down(): void
    {
        // Pas de rollback destructif sur ce seed de catalogue.
    }

    private function resolveServiceCode(string $icon, string $name): ?string
    {
        $icon = trim(mb_strtolower($icon));
        $name = trim(mb_strtolower($name));

        return match (true) {
            $icon === 'road', str_contains($name, 'voirie'), str_contains($name, 'chauss') => 'voirie',
            $icon === 'lightbulb', str_contains($name, 'eclairage') => 'eclairage_public',
            $icon === 'tree', str_contains($name, 'vert') => 'espaces_verts',
            $icon === 'trash', str_contains($name, 'propret'), str_contains($name, 'dechet') => 'proprete_urbaine',
            $icon === 'bench', str_contains($name, 'mobilier') => 'mobilier_urbain',
            $icon === 'droplets', str_contains($name, 'reseaux'), str_contains($name, 'inond') => 'reseaux_inondations',
            $icon === 'triangle-alert', str_contains($name, 'signal') => 'signalisation',
            $icon === 'building-2', str_contains($name, 'batiment') => 'batiments_communaux',
            default => null,
        };
    }
}
