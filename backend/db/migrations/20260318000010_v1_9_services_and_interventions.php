<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V19ServicesAndInterventions extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('services')) {
            $this->table('services')
                ->addColumn('code', 'string', ['limit' => 80])
                ->addColumn('name', 'string', ['limit' => 120])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['code'], ['unique' => true])
                ->addIndex(['name'])
                ->create();
        }

        if (!$this->hasTable('service_category_map')) {
            $this->table('service_category_map')
                ->addColumn('service_id', 'integer', ['signed' => false])
                ->addColumn('category_id', 'integer', ['signed' => false])
                ->addColumn('priority_order', 'integer', ['default' => 1])
                ->addColumn('is_default', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['service_id'])
                ->addIndex(['category_id'])
                ->addIndex(['service_id', 'category_id'], ['unique' => true])
                ->addForeignKey('service_id', 'services', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        if (!$this->hasTable('user_service_memberships')) {
            $this->table('user_service_memberships')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('service_id', 'integer', ['signed' => false])
                ->addColumn('role_in_service', 'enum', ['values' => ['manager', 'agent', 'viewer'], 'default' => 'agent'])
                ->addColumn('is_primary', 'boolean', ['default' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['user_id'])
                ->addIndex(['service_id'])
                ->addIndex(['user_id', 'service_id'], ['unique' => true])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('service_id', 'services', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        if (!$this->hasTable('intervention_plans')) {
            $this->table('intervention_plans')
                ->addColumn('incident_id', 'integer', ['signed' => false])
                ->addColumn('service_id', 'integer', ['signed' => false])
                ->addColumn('planned_by_user_id', 'integer', ['signed' => false])
                ->addColumn('assigned_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('status', 'enum', ['values' => ['draft', 'scheduled', 'rescheduled', 'in_progress', 'completed', 'cancelled'], 'default' => 'scheduled'])
                ->addColumn('scheduled_date', 'date', ['null' => true])
                ->addColumn('time_window_start', 'time', ['null' => true])
                ->addColumn('time_window_end', 'time', ['null' => true])
                ->addColumn('internal_note', 'text', ['null' => true])
                ->addColumn('citizen_message', 'text', ['null' => true])
                ->addColumn('source_type', 'enum', ['values' => ['internal', 'provider'], 'default' => 'internal'])
                ->addColumn('provider_name', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['incident_id'])
                ->addIndex(['service_id'])
                ->addIndex(['status'])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('service_id', 'services', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('planned_by_user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('assigned_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->create();
        }

        if (!$this->hasTable('incident_service_history')) {
            $this->table('incident_service_history')
                ->addColumn('incident_id', 'integer', ['signed' => false])
                ->addColumn('service_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('plan_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('actor_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('event_type', 'string', ['limit' => 80])
                ->addColumn('event_label', 'string', ['limit' => 180, 'null' => true])
                ->addColumn('citizen_label', 'string', ['limit' => 180, 'null' => true])
                ->addColumn('payload_json', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['incident_id'])
                ->addIndex(['service_id'])
                ->addIndex(['event_type'])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('service_id', 'services', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('plan_id', 'intervention_plans', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->create();
        }

        $this->backfillServicesFromCategories();
        $this->backfillMembershipsFromAssignedIncidents();
    }

    public function down(): void
    {
        // Pas de rollback destructif sur ce socle métier.
    }

    private function backfillServicesFromCategories(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $rows = $this->fetchAll("SELECT id, service FROM categories WHERE service IS NOT NULL AND TRIM(service) <> '' ORDER BY id ASC");
        if (!$rows) {
            return;
        }

        $serviceIdsByName = [];
        foreach ($this->fetchAll("SELECT id, name FROM services") as $service) {
            $serviceIdsByName[mb_strtolower(trim((string) $service['name']))] = (int) $service['id'];
        }

        foreach ($rows as $row) {
            $rawName = trim((string) ($row['service'] ?? ''));
            if ($rawName === '') {
                continue;
            }

            $normalized = mb_strtolower($rawName);
            if (!isset($serviceIdsByName[$normalized])) {
                $baseCode = $this->slugify($rawName);
                $code = $baseCode;
                $suffix = 2;
                while ($this->fetchRow('SELECT id FROM services WHERE code = ?', [$code])) {
                    $code = $baseCode . '-' . $suffix++;
                }

                $stmt = $pdo->prepare('INSERT INTO services (code, name, is_active, created_at) VALUES (?, ?, 1, NOW())');
                $stmt->execute([$code, $rawName]);
                $serviceIdsByName[$normalized] = (int) $pdo->lastInsertId();
            }

            $serviceId = $serviceIdsByName[$normalized];
            $existing = $this->fetchRow(
                'SELECT id FROM service_category_map WHERE service_id = ? AND category_id = ?',
                [$serviceId, (int) $row['id']]
            );
            if (!$existing) {
                $stmt = $pdo->prepare(
                    'INSERT INTO service_category_map (service_id, category_id, priority_order, is_default, created_at) VALUES (?, ?, 1, 1, NOW())'
                );
                $stmt->execute([$serviceId, (int) $row['id']]);
            }
        }
    }

    private function backfillMembershipsFromAssignedIncidents(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $rows = $this->fetchAll("
            SELECT DISTINCT i.assigned_to AS user_id, c.service
            FROM incidents i
            JOIN categories c ON c.id = i.category_id
            WHERE i.assigned_to IS NOT NULL
              AND c.service IS NOT NULL
              AND TRIM(c.service) <> ''
            ORDER BY i.assigned_to ASC
        ");

        if (!$rows) {
            return;
        }

        $primaryByUser = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $serviceName = trim((string) ($row['service'] ?? ''));
            if ($userId <= 0 || $serviceName === '') {
                continue;
            }

            $service = $this->fetchRow('SELECT id FROM services WHERE name = ? LIMIT 1', [$serviceName]);
            if (!$service) {
                continue;
            }

            $serviceId = (int) $service['id'];
            $exists = $this->fetchRow(
                'SELECT id FROM user_service_memberships WHERE user_id = ? AND service_id = ? LIMIT 1',
                [$userId, $serviceId]
            );
            if ($exists) {
                continue;
            }

            $isPrimary = empty($primaryByUser[$userId]) ? 1 : 0;
            $primaryByUser[$userId] = true;

            $stmt = $pdo->prepare(
                'INSERT INTO user_service_memberships (user_id, service_id, role_in_service, is_primary, created_at) VALUES (?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$userId, $serviceId, 'agent', $isPrimary]);
        }
    }

    private function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value);
        $value = trim((string) $value, '-');
        return $value !== '' ? $value : 'service';
    }
}
