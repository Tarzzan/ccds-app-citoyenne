<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seeder — Données initiales pour le développement et les tests.
 * Usage : vendor/bin/phinx seed:run
 */
class InitialDataSeeder extends AbstractSeed
{
    public function run(): void
    {
        // ── Catégories ─────────────────────────────────────────
        $this->table('categories')->insert([
            ['name' => 'Voirie',              'icon' => '🚧', 'color' => '#F59E0B', 'is_active' => 1],
            ['name' => 'Éclairage',           'icon' => '💡', 'color' => '#EAB308', 'is_active' => 1],
            ['name' => 'Eau / Assainissement','icon' => '🌊', 'color' => '#3B82F6', 'is_active' => 1],
            ['name' => 'Propreté',            'icon' => '🗑️', 'color' => '#10B981', 'is_active' => 1],
            ['name' => 'Espaces verts',       'icon' => '🌿', 'color' => '#22C55E', 'is_active' => 1],
            ['name' => 'Sécurité',            'icon' => '🚨', 'color' => '#EF4444', 'is_active' => 1],
            ['name' => 'Bâtiments publics',   'icon' => '🏛️', 'color' => '#8B5CF6', 'is_active' => 1],
            ['name' => 'Autre',               'icon' => '📌', 'color' => '#6B7280', 'is_active' => 1],
        ])->save();

        // ── Utilisateur admin par défaut ───────────────────────
        $this->table('users')->insert([
            [
                'full_name'  => 'Administrateur CCDS',
                'email'      => 'admin@ccds.gf',
                'password'   => password_hash('Admin@CCDS2026!', PASSWORD_DEFAULT),
                'role'       => 'admin',
                'is_active'  => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ])->save();

        echo "✅ Données initiales insérées (8 catégories + 1 admin)\n";
    }
}
