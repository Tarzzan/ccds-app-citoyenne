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
            ['name' => 'Voirie & Chaussée',     'icon' => 'road',           'color' => '#D96B2B', 'is_active' => 1],
            ['name' => 'Éclairage Public',      'icon' => 'lightbulb',      'color' => '#D9A22E', 'is_active' => 1],
            ['name' => 'Espaces Verts',         'icon' => 'tree',           'color' => '#2E8B57', 'is_active' => 1],
            ['name' => 'Propreté & Déchets',    'icon' => 'trash',          'color' => '#7C4FD9', 'is_active' => 1],
            ['name' => 'Mobilier Urbain',       'icon' => 'bench',          'color' => '#287C96', 'is_active' => 1],
            ['name' => 'Réseaux & Inondations', 'icon' => 'droplets',       'color' => '#2676D2', 'is_active' => 1],
            ['name' => 'Signalisation',         'icon' => 'triangle-alert', 'color' => '#E07A22', 'is_active' => 1],
            ['name' => 'Bâtiments Communaux',   'icon' => 'building-2',     'color' => '#5A6F7F', 'is_active' => 1],
        ])->save();

        // ── Comptes métier par défaut ──────────────────────────
        $this->table('users')->insert([
            [
                'full_name'     => 'Administrateur Ma Commune',
                'email'         => 'admin@macommune.local',
                'password_hash' => password_hash('Admin@MaCommune2026!', PASSWORD_DEFAULT),
                'role'          => 'admin',
                'is_active'     => 1,
                'created_at'    => date('Y-m-d H:i:s'),
            ],
            [
                'full_name'     => 'Agent Terrain Ma Commune',
                'email'         => 'agent@macommune.local',
                'password_hash' => password_hash('Agent@MaCommune2026!', PASSWORD_DEFAULT),
                'role'          => 'agent',
                'is_active'     => 1,
                'created_at'    => date('Y-m-d H:i:s'),
            ],
        ])->save();

        echo "✅ Données initiales insérées (8 catégories + 2 comptes métier)\n";
    }
}
