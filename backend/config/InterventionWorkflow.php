<?php
/**
 * Ma Commune — Workflow services / interventions
 *
 * Couche légère partagée entre API et back-office pour :
 * - résoudre le service responsable d'un dossier
 * - lire le plan d'intervention courant
 * - lire et écrire l'historique métier d'intervention
 */

function intervention_table_exists(PDO $db, string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$tableName]);
        $cache[$tableName] = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function intervention_get_services(PDO $db): array
{
    if (!intervention_table_exists($db, 'services')) {
        return [];
    }

    $stmt = $db->query("
        SELECT id, code, name, description, is_active
        FROM services
        WHERE is_active = 1
        ORDER BY name ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function intervention_get_service_by_id(PDO $db, int $serviceId): ?array
{
    if ($serviceId <= 0 || !intervention_table_exists($db, 'services')) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT id, code, name, description, is_active
        FROM services
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    return $service ?: null;
}

function intervention_sync_category_default_service(PDO $db, int $categoryId, ?int $serviceId): void
{
    if (
        $categoryId <= 0
        || !intervention_table_exists($db, 'service_category_map')
        || !intervention_table_exists($db, 'services')
    ) {
        return;
    }

    $db->prepare('DELETE FROM service_category_map WHERE category_id = ?')->execute([$categoryId]);

    if (empty($serviceId)) {
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO service_category_map (service_id, category_id, priority_order, is_default, created_at)
        VALUES (?, ?, 1, 1, NOW())
    ");
    $stmt->execute([(int) $serviceId, $categoryId]);
}

function intervention_get_user_memberships(PDO $db, int $userId): array
{
    if (
        $userId <= 0
        || !intervention_table_exists($db, 'user_service_memberships')
        || !intervention_table_exists($db, 'services')
    ) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT
            usm.user_id,
            usm.service_id,
            usm.role_in_service,
            usm.is_primary,
            usm.created_at,
            s.code AS service_code,
            s.name AS service_name
        FROM user_service_memberships usm
        JOIN services s ON s.id = usm.service_id
        WHERE usm.user_id = ?
        ORDER BY usm.is_primary DESC, s.name ASC
    ");
    $stmt->execute([$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function intervention_upsert_user_membership(
    PDO $db,
    int $userId,
    int $serviceId,
    string $roleInService = 'agent',
    bool $isPrimary = false
): void {
    if (
        $userId <= 0
        || $serviceId <= 0
        || !intervention_table_exists($db, 'user_service_memberships')
        || !intervention_table_exists($db, 'services')
    ) {
        return;
    }

    if ($isPrimary) {
        $db->prepare('UPDATE user_service_memberships SET is_primary = 0 WHERE user_id = ?')->execute([$userId]);
    }

    $existing = $db->prepare('SELECT user_id FROM user_service_memberships WHERE user_id = ? AND service_id = ? LIMIT 1');
    $existing->execute([$userId, $serviceId]);

    if ($existing->fetch(PDO::FETCH_ASSOC)) {
        $stmt = $db->prepare('
            UPDATE user_service_memberships
            SET role_in_service = ?, is_primary = ?
            WHERE user_id = ? AND service_id = ?
        ');
        $stmt->execute([$roleInService, $isPrimary ? 1 : 0, $userId, $serviceId]);
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO user_service_memberships (user_id, service_id, role_in_service, is_primary, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $serviceId, $roleInService, $isPrimary ? 1 : 0]);
}

function intervention_remove_user_membership(PDO $db, int $userId, int $serviceId): void
{
    if ($userId <= 0 || $serviceId <= 0 || !intervention_table_exists($db, 'user_service_memberships')) {
        return;
    }

    $db->prepare('DELETE FROM user_service_memberships WHERE user_id = ? AND service_id = ?')->execute([$userId, $serviceId]);
    intervention_ensure_primary_membership($db, $userId);
}

function intervention_ensure_primary_membership(PDO $db, int $userId): void
{
    if ($userId <= 0 || !intervention_table_exists($db, 'user_service_memberships')) {
        return;
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM user_service_memberships WHERE user_id = ? AND is_primary = 1');
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $stmt = $db->prepare('SELECT service_id FROM user_service_memberships WHERE user_id = ? ORDER BY created_at ASC, service_id ASC LIMIT 1');
    $stmt->execute([$userId]);
    $serviceId = $stmt->fetchColumn();
    if (!$serviceId) {
        return;
    }

    $db->prepare('UPDATE user_service_memberships SET is_primary = 1 WHERE user_id = ? AND service_id = ?')
        ->execute([$userId, (int) $serviceId]);
}

function intervention_get_current_plan(PDO $db, int $incidentId): ?array
{
    if (!intervention_table_exists($db, 'intervention_plans') || !intervention_table_exists($db, 'services')) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT
            p.*,
            s.code AS service_code,
            s.name AS service_name,
            planner.full_name AS planned_by_name,
            assignee.full_name AS assigned_user_name
        FROM intervention_plans p
        LEFT JOIN services s ON s.id = p.service_id
        LEFT JOIN users planner ON planner.id = p.planned_by_user_id
        LEFT JOIN users assignee ON assignee.id = p.assigned_user_id
        WHERE p.incident_id = ?
        ORDER BY
            FIELD(p.status, 'in_progress', 'scheduled', 'rescheduled', 'draft', 'completed', 'cancelled'),
            p.scheduled_date DESC,
            p.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$incidentId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    return $plan ?: null;
}

function intervention_get_incident_service_context(PDO $db, int $incidentId, ?int $categoryId, ?string $legacyService = null): ?array
{
    $currentPlan = intervention_get_current_plan($db, $incidentId);
    if ($currentPlan && !empty($currentPlan['service_id'])) {
        return [
            'service_id'   => (int) $currentPlan['service_id'],
            'service_code' => $currentPlan['service_code'] ?? null,
            'service_name' => $currentPlan['service_name'] ?? null,
            'source'       => 'plan',
        ];
    }

    if (
        $categoryId
        && intervention_table_exists($db, 'service_category_map')
        && intervention_table_exists($db, 'services')
    ) {
        $stmt = $db->prepare("
            SELECT s.id AS service_id, s.code AS service_code, s.name AS service_name
            FROM service_category_map scm
            JOIN services s ON s.id = scm.service_id
            WHERE scm.category_id = ?
            ORDER BY scm.is_default DESC, scm.priority_order ASC, scm.id ASC
            LIMIT 1
        ");
        $stmt->execute([$categoryId]);
        $mapped = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($mapped) {
            return [
                'service_id'   => (int) $mapped['service_id'],
                'service_code' => $mapped['service_code'] ?? null,
                'service_name' => $mapped['service_name'] ?? null,
                'source'       => 'category_map',
            ];
        }
    }

    if ($legacyService !== null && trim($legacyService) !== '') {
        return [
            'service_id'   => null,
            'service_code' => null,
            'service_name' => trim($legacyService),
            'source'       => 'legacy_category_service',
        ];
    }

    return null;
}

function intervention_get_history(PDO $db, int $incidentId, bool $citizenOnly = false): array
{
    if (!intervention_table_exists($db, 'incident_service_history')) {
        return [];
    }

    $citizenFilter = $citizenOnly ? 'AND (h.citizen_label IS NOT NULL AND h.citizen_label <> \'\')' : '';

    $stmt = $db->prepare("
        SELECT
            h.*,
            s.code AS service_code,
            s.name AS service_name,
            actor.full_name AS actor_name
        FROM incident_service_history h
        LEFT JOIN services s ON s.id = h.service_id
        LEFT JOIN users actor ON actor.id = h.actor_user_id
        WHERE h.incident_id = ?
        $citizenFilter
        ORDER BY h.created_at ASC, h.id ASC
    ");
    $stmt->execute([$incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function intervention_record_history(PDO $db, array $payload): void
{
    if (!intervention_table_exists($db, 'incident_service_history')) {
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO incident_service_history
            (incident_id, service_id, plan_id, actor_user_id, event_type, event_label, citizen_label, payload_json, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        (int) ($payload['incident_id'] ?? 0),
        !empty($payload['service_id']) ? (int) $payload['service_id'] : null,
        !empty($payload['plan_id']) ? (int) $payload['plan_id'] : null,
        !empty($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : null,
        $payload['event_type'] ?? 'event',
        $payload['event_label'] ?? null,
        $payload['citizen_label'] ?? null,
        !empty($payload['payload']) ? json_encode($payload['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}
