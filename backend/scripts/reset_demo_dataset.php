<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

function cli_log(string $message): void
{
    fwrite(STDERR, "[demo-reset] {$message}\n");
}

function bool_option(mixed $value, bool $default = false): bool
{
    if ($value === null || $value === false) {
        return $default;
    }

    if ($value === true) {
        return true;
    }

    $normalized = strtolower((string)$value);
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function table_exists(PDO $db, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    $cache[$table] = ((int)$stmt->fetchColumn()) > 0;
    return $cache[$table];
}

function table_columns(PDO $db, string $table): array
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    if (!table_exists($db, $table)) {
        $cache[$table] = [];
        return $cache[$table];
    }

    $stmt = $db->prepare(
        'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position'
    );
    $stmt->execute([$table]);
    $cache[$table] = array_map(
        static fn(array $row): string => (string)($row['column_name'] ?? $row['COLUMN_NAME'] ?? reset($row)),
        $stmt->fetchAll()
    );
    return $cache[$table];
}

function has_column(PDO $db, string $table, string $column): bool
{
    return in_array($column, table_columns($db, $table), true);
}

function insert_row(PDO $db, string $table, array $data): int
{
    $columns = table_columns($db, $table);
    if ($columns === []) {
        throw new RuntimeException("Table absente ou non lisible: {$table}");
    }

    $filtered = [];
    foreach ($data as $column => $value) {
        if (in_array($column, $columns, true)) {
            $filtered[$column] = $value;
        }
    }

    if ($filtered === []) {
        throw new RuntimeException("Aucune colonne compatible pour la table {$table}");
    }

    $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
    $quotedColumns = implode(', ', array_map(static fn(string $column): string => "`{$column}`", array_keys($filtered)));

    $stmt = $db->prepare("INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$placeholders})");
    $stmt->execute(array_values($filtered));
    return (int)$db->lastInsertId();
}

function update_row(PDO $db, string $table, array $data, string $whereClause, array $whereParams): void
{
    $columns = table_columns($db, $table);
    if ($columns === []) {
        return;
    }

    $filtered = [];
    foreach ($data as $column => $value) {
        if (in_array($column, $columns, true)) {
            $filtered[$column] = $value;
        }
    }

    if ($filtered === []) {
        return;
    }

    $assignments = implode(', ', array_map(static fn(string $column): string => "`{$column}` = ?", array_keys($filtered)));
    $stmt = $db->prepare("UPDATE `{$table}` SET {$assignments} WHERE {$whereClause}");
    $stmt->execute(array_merge(array_values($filtered), $whereParams));
}

function ensure_dir(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Impossible de creer le dossier: {$path}");
    }
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            rrmdir($full);
        } else {
            @unlink($full);
        }
    }

    @rmdir($path);
}

function generate_reference_seed(int $id): string
{
    return APP_REFERENCE_PREFIX . '-' . date('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function slugify_ascii(string $value): string
{
    $value = strtolower(trim($value));
    $map = [
        'a' => ['a', 'aa', 'ae', 'à', 'á', 'â', 'ã', 'ä', 'å'],
        'c' => ['c', 'ç'],
        'e' => ['e', 'è', 'é', 'ê', 'ë'],
        'i' => ['i', 'ì', 'í', 'î', 'ï'],
        'n' => ['n', 'ñ'],
        'o' => ['o', 'ò', 'ó', 'ô', 'õ', 'ö', 'oe'],
        'u' => ['u', 'ù', 'ú', 'û', 'ü'],
        'y' => ['y', 'ÿ'],
    ];

    foreach ($map as $ascii => $variants) {
        $value = str_replace($variants, $ascii, $value);
    }

    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
    return trim($value, '-');
}

function create_openai_image(string $apiKey, string $prompt): string
{
    $payload = json_encode([
        'model' => getenv('OPENAI_IMAGE_MODEL') ?: 'gpt-image-1',
        'prompt' => $prompt,
        'size' => '1024x1024',
    ], JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Impossible d encoder la requete image.');
    }

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 180,
    ]);

    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $error !== '') {
        throw new RuntimeException('Echec reseau image OpenAI: ' . $error);
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || $code >= 400) {
        $message = is_array($data) ? (($data['error']['message'] ?? 'Erreur API image OpenAI')) : 'Reponse image OpenAI invalide';
        throw new RuntimeException($message);
    }

    $item = $data['data'][0] ?? null;
    if (!is_array($item)) {
        throw new RuntimeException('Aucune image retournee par OpenAI.');
    }

    if (!empty($item['b64_json'])) {
        $bytes = base64_decode((string)$item['b64_json'], true);
        if ($bytes === false) {
            throw new RuntimeException('Base64 image OpenAI invalide.');
        }
        return $bytes;
    }

    if (!empty($item['url'])) {
        $bytes = @file_get_contents((string)$item['url']);
        if ($bytes === false) {
            throw new RuntimeException('Impossible de telecharger l image OpenAI distante.');
        }
        return $bytes;
    }

    throw new RuntimeException('Format image OpenAI non supporte.');
}

$options = getopt('', [
    'label::',
    'summary::',
    'with-photos::',
]);

$label = $options['label'] ?? 'demo';
$summaryPath = $options['summary'] ?? null;
$withPhotos = bool_option($options['with-photos'] ?? null, getenv('OPENAI_API_KEY') !== false && getenv('OPENAI_API_KEY') !== '');
$apiKey = trim((string)(getenv('OPENAI_API_KEY') ?: ''));

$db = Database::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$categories = $db->query('SELECT id, name, service FROM categories WHERE is_active = 1 ORDER BY id ASC')->fetchAll();
$services = table_exists($db, 'services')
    ? $db->query('SELECT id, name FROM services WHERE is_active = 1 ORDER BY id ASC')->fetchAll()
    : [];

$serviceByName = [];
foreach ($services as $service) {
    $serviceByName[mb_strtolower(trim((string)$service['name']))] = (int)$service['id'];
}

$categoryById = [];
foreach ($categories as $category) {
    $categoryById[(int)$category['id']] = $category;
}

$tablesToReset = [
    'comment_mentions',
    'comment_reports',
    'photo_reports',
    'event_rsvps',
    'poll_votes',
    'poll_options',
    'votes',
    'photos',
    'notifications',
    'push_tokens',
    'user_badges',
    'user_gamification',
    'gdpr_export_requests',
    'audit_logs',
    'incident_service_history',
    'intervention_plans',
    'status_history',
    'comments',
    'events',
    'polls',
    'incidents',
    'user_service_memberships',
    'users',
];

cli_log('Purge des donnees utilisateur et incidentielles');
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tablesToReset as $table) {
    if (table_exists($db, $table)) {
        $db->exec("TRUNCATE TABLE `{$table}`");
    }
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

$commonPassword = 'test@test.fr';
$passwordHash = password_hash($commonPassword, PASSWORD_BCRYPT, ['cost' => 12]);
$insertDemoUser = static function (string $email, string $fullName, string $role) use ($db, $passwordHash): int {
    $data = [
        'email' => $email,
        'full_name' => $fullName,
        'role' => $role,
        'is_active' => 1,
        'phone' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'preferred_language' => 'fr',
        'total_points' => $role === 'citizen' ? 180 : 0,
        'notification_status_change' => 1,
        'notification_new_comment' => 1,
        'notification_vote_milestone' => 0,
    ];

    if (has_column($db, 'users', 'password_hash')) {
        $data['password_hash'] = $passwordHash;
    }
    if (has_column($db, 'users', 'password')) {
        $data['password'] = $passwordHash;
    }

    return insert_row($db, 'users', $data);
};

$adminId = $insertDemoUser('admin@test.fr', 'Admin Demo Ma Commune', 'admin');
$agentId = $insertDemoUser('agent@test.fr', 'Agent Demo Ma Commune', 'agent');
$citizenId = $insertDemoUser('citoyen@test.fr', 'Citoyen Demo Kourou', 'citizen');

$agentPrimaryServiceId = $serviceByName['eclairage public'] ?? ($services[0]['id'] ?? null);
if ($agentPrimaryServiceId !== null && table_exists($db, 'user_service_memberships')) {
    insert_row($db, 'user_service_memberships', [
        'user_id' => $agentId,
        'service_id' => (int)$agentPrimaryServiceId,
        'role_in_service' => 'agent',
        'is_primary' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    insert_row($db, 'user_service_memberships', [
        'user_id' => $adminId,
        'service_id' => (int)$agentPrimaryServiceId,
        'role_in_service' => 'manager',
        'is_primary' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

if (table_exists($db, 'user_gamification')) {
    insert_row($db, 'user_gamification', [
        'user_id' => $citizenId,
        'points' => 180,
        'last_action_at' => date('Y-m-d H:i:s'),
    ]);
} elseif (has_column($db, 'users', 'total_points')) {
    update_row($db, 'users', ['total_points' => 180], 'id = ?', [$citizenId]);
}

$pollId = insert_row($db, 'polls', [
    'title' => 'Priorite terrain a Kourou',
    'description' => 'Quelle action visible la commune doit-elle traiter en priorite dans les prochains jours ?',
    'type' => 'single',
    'status' => 'active',
    'is_active' => 1,
    'created_by' => $adminId,
    'ends_at' => date('Y-m-d H:i:s', strtotime('+10 days')),
    'created_at' => date('Y-m-d H:i:s'),
]);

foreach (['Eclairage public', 'Proprete urbaine', 'Mobilier urbain'] as $index => $optionText) {
    $optionIds[$index] = insert_row($db, 'poll_options', [
        'poll_id' => $pollId,
        'label' => $optionText,
        'text' => $optionText,
        'sort_order' => $index,
        'votes' => 0,
    ]);
}

$pollVoteTime = date('Y-m-d H:i:s');
insert_row($db, 'poll_votes', [
    'poll_id' => $pollId,
    'option_id' => $optionIds[0],
    'user_id' => $citizenId,
    'voted_at' => $pollVoteTime,
    'created_at' => $pollVoteTime,
]);
insert_row($db, 'poll_votes', [
    'poll_id' => $pollId,
    'option_id' => $optionIds[1],
    'user_id' => $agentId,
    'voted_at' => $pollVoteTime,
    'created_at' => $pollVoteTime,
]);
insert_row($db, 'poll_votes', [
    'poll_id' => $pollId,
    'option_id' => $optionIds[0],
    'user_id' => $adminId,
    'voted_at' => $pollVoteTime,
    'created_at' => $pollVoteTime,
]);

$eventStart = date('Y-m-d H:i:s', strtotime('+7 days 18:30'));
$eventId = insert_row($db, 'events', [
    'title' => 'Point quartier demo',
    'description' => 'Temps d echange court sur le suivi des interventions et les priorites visibles dans la ville.',
    'location' => 'Maison de quartier de Kourou',
    'event_date' => $eventStart,
    'starts_at' => $eventStart,
    'ends_at' => date('Y-m-d H:i:s', strtotime($eventStart . ' +90 minutes')),
    'is_published' => 1,
    'created_by' => $adminId,
    'created_at' => date('Y-m-d H:i:s'),
]);

insert_row($db, 'event_rsvps', [
    'event_id' => $eventId,
    'user_id' => $citizenId,
    'status' => 'attending',
    'created_at' => date('Y-m-d H:i:s'),
]);
insert_row($db, 'event_rsvps', [
    'event_id' => $eventId,
    'user_id' => $agentId,
    'status' => 'maybe',
    'created_at' => date('Y-m-d H:i:s'),
]);

$locations = [
    ['address' => 'Kourou centre - avenue des Roches', 'latitude' => 5.16218, 'longitude' => -52.64227],
    ['address' => 'Kourou - quartier Pariacabo', 'latitude' => 5.15967, 'longitude' => -52.64983],
    ['address' => 'Kourou - carrefour du Bourg', 'latitude' => 5.16491, 'longitude' => -52.64698],
    ['address' => 'Kourou - boulevard des Iles', 'latitude' => 5.16742, 'longitude' => -52.64061],
    ['address' => 'Kourou - abords du stade', 'latitude' => 5.15584, 'longitude' => -52.65192],
    ['address' => 'Kourou - avenue de la Liberte', 'latitude' => 5.16114, 'longitude' => -52.64531],
    ['address' => 'Kourou - secteur Savane', 'latitude' => 5.17024, 'longitude' => -52.63888],
    ['address' => 'Kourou - entree de quartier', 'latitude' => 5.15803, 'longitude' => -52.64855],
];

$categoryScenarios = [
    1 => [
        'title' => ['Nid de poule qui s agrandit', 'Chainee abimee apres les pluies', 'Affaissement de voirie a traiter'],
        'description' => 'Un habitant signale une deterioration de la chausssee qui gene les vehicules et les pietons.',
        'photo_prompt' => 'Photo citoyenne realiste prise au smartphone d un nid de poule sur une voirie de Kourou en Guyane, lumiere naturelle, angle de rue, aucun texte, aucun visage, scene documentaire municipale.',
    ],
    2 => [
        'title' => ['Lampadaire en panne au crepuscule', 'Zone mal eclairee en sortie de quartier', 'Point lumineux hors service'],
        'description' => 'Le point lumineux ne fonctionne plus et la zone devient peu lisible le soir.',
        'photo_prompt' => 'Photo citoyenne realiste prise au smartphone d un lampadaire en panne dans une rue de Kourou en Guyane, ambiance fin de journee, scene urbaine documentaire, aucun texte, aucun visage.',
    ],
    3 => [
        'title' => ['Vegetation qui deborde sur le passage', 'Entretien des abords a reprendre', 'Massif non taille sur le trottoir'],
        'description' => 'La vegetation reduit la lisibilite du passage et commence a gener les deplacements.',
        'photo_prompt' => 'Photo citoyenne realiste prise au smartphone d une vegetation envahissante sur un trottoir a Kourou en Guyane, scene municipale documentaire, aucun visage, aucun texte.',
    ],
    4 => [
        'title' => ['Depot sauvage a evacuer', 'Bacs debordants sur l accotement', 'Zone de dechets a nettoyer'],
        'description' => 'Un amas de dechets reste visible plusieurs jours et donne une impression de laisser aller.',
        'photo_prompt' => 'Photo citoyenne realiste prise au smartphone d un depot sauvage de dechets dans une rue de Kourou en Guyane, scene municipale documentaire, aucun visage, aucun texte.',
    ],
    5 => [
        'title' => ['Banc communal descelle', 'Equipement urbain degrade', 'Assise publique a remettre en etat'],
        'description' => 'Le mobilier urbain montre des signes d usure et peut devenir dangereux pour les familles.',
        'photo_prompt' => 'Photo citoyenne realiste prise au smartphone d un banc public degrade dans un espace communal de Kourou en Guyane, scene documentaire, aucun visage, aucun texte.',
    ],
    6 => [
        'title' => ['Avaloir bouche apres averse', 'Ecoulement difficile a la voirie', 'Risque d eau stagnante sur reseau'],
        'description' => 'L eau stagne autour du reseau pluvial et la zone reste sensible apres les pluies.',
        'photo_prompt' => 'Photo citoyenne realiste prise au smartphone d un avaloir bouche avec eau stagnante dans une rue de Kourou en Guyane, scene documentaire, aucun texte, aucun visage.',
    ],
    7 => [
        'title' => ['Panneau de signalisation endommage', 'Signal routier penche', 'Signalisation peu lisible'],
        'description' => 'La signalisation devient difficile a lire et peut perturber les usages sur la voie.',
        'photo_prompt' => 'Photo citoyenne realiste prise au smartphone d un panneau de signalisation endommage dans Kourou en Guyane, scene urbaine documentaire, aucun visage, aucun texte.',
    ],
    8 => [
        'title' => ['Facade communale a reprendre', 'Porte de batiment degradee', 'Abord de batiment communal a securiser'],
        'description' => 'Le batiment communal montre un desordre visible qui donne une impression de degradation.',
        'photo_prompt' => 'Photo citoyenne realiste prise au smartphone d un petit batiment communal degrade a Kourou en Guyane, scene documentaire, aucun visage, aucun texte.',
    ],
];

$statusSequence = array_merge(
    array_fill(0, 5, 'submitted'),
    array_fill(0, 5, 'acknowledged'),
    array_fill(0, 5, 'in_progress'),
    array_fill(0, 5, 'resolved'),
    array_fill(0, 5, 'acknowledged')
);

$priorities = ['low', 'medium', 'high', 'medium', 'critical'];
$incidentCategoryIds = [2, 4, 1, 6, 5, 7, 3, 8, 2, 4, 1, 6, 5, 7, 3, 8, 2, 4, 1, 6, 5, 7, 3, 8, 2];

$now = time();
$incidentsSummary = [];
$usedCategoryIds = [];

foreach ($incidentCategoryIds as $index => $categoryId) {
    $category = $categoryById[$categoryId] ?? null;
    if (!$category) {
        continue;
    }

    $usedCategoryIds[$categoryId] = true;
    $location = $locations[$index % count($locations)];
    $scenario = $categoryScenarios[$categoryId];
    $titleVariants = $scenario['title'];
    $title = $titleVariants[$index % count($titleVariants)] . ' - Demo ' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
    $description = $scenario['description'] . ' Point de demo ' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '.';
    $status = $statusSequence[$index];
    $priority = $priorities[$index % count($priorities)];
    $createdAt = date('Y-m-d H:i:s', $now - ((25 - $index) * 7200));
    $updatedAt = $createdAt;
    $resolvedAt = null;
    $assignedTo = $status === 'submitted' ? null : $agentId;

    $incidentId = insert_row($db, 'incidents', [
        'reference' => 'TMP-' . $label . '-' . ($index + 1),
        'user_id' => $citizenId,
        'category_id' => $categoryId,
        'title' => $title,
        'description' => $description,
        'latitude' => $location['latitude'],
        'longitude' => $location['longitude'],
        'address' => $location['address'],
        'status' => $status,
        'priority' => $priority,
        'assigned_to' => $assignedTo,
        'resolved_at' => $resolvedAt,
        'votes_count' => 0,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ]);
    $reference = generate_reference_seed($incidentId);
    update_row($db, 'incidents', ['reference' => $reference], 'id = ?', [$incidentId]);

    insert_row($db, 'status_history', [
        'incident_id' => $incidentId,
        'user_id' => $citizenId,
        'old_status' => null,
        'new_status' => 'submitted',
        'note' => 'Signalement depose par le citoyen.',
        'changed_at' => $createdAt,
    ]);

    $serviceId = $serviceByName[mb_strtolower(trim((string)($category['service'] ?? '')))] ?? null;
    $planId = null;

    if ($status !== 'submitted') {
        $ackAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +2 hours'));
        insert_row($db, 'status_history', [
            'incident_id' => $incidentId,
            'user_id' => $adminId,
            'old_status' => 'submitted',
            'new_status' => 'acknowledged',
            'note' => 'Signalement pris en compte par la commune.',
            'changed_at' => $ackAt,
        ]);
        $updatedAt = $ackAt;

        if ($serviceId !== null && table_exists($db, 'incident_service_history')) {
            insert_row($db, 'incident_service_history', [
                'incident_id' => $incidentId,
                'service_id' => $serviceId,
                'plan_id' => null,
                'actor_user_id' => $adminId,
                'event_type' => 'service_assigned',
                'event_label' => 'Affecte au service ' . $category['service'],
                'citizen_label' => 'Attribue au service ' . $category['service'],
                'payload_json' => json_encode(['service' => $category['service']], JSON_UNESCAPED_UNICODE),
                'created_at' => $ackAt,
            ]);
        }

        $scheduledDate = date('Y-m-d', strtotime($createdAt . ' +1 day'));
        $windowStart = sprintf('%02d:00:00', 8 + ($index % 4));
        $windowEnd = sprintf('%02d:30:00', 11 + ($index % 4));
        $planStatus = match ($status) {
            'acknowledged' => $index >= 20 ? 'rescheduled' : 'scheduled',
            'in_progress' => 'in_progress',
            'resolved' => 'completed',
            default => 'scheduled',
        };
        $sourceType = ($index % 3 === 0) ? 'provider' : 'internal';
        $providerName = $sourceType === 'provider' ? 'Guyane Services Urbains' : null;
        $planCreatedAt = date('Y-m-d H:i:s', strtotime($ackAt . ' +30 minutes'));
        $citizenMessage = match ($planStatus) {
            'rescheduled' => 'Le passage est reprogramme sur un nouveau creneau.',
            'in_progress' => 'Une equipe est en intervention sur le terrain.',
            'completed' => 'L intervention terrain est terminee.',
            default => 'Une intervention est planifiee sur ce dossier.',
        };

        if ($serviceId !== null && table_exists($db, 'intervention_plans')) {
            $planId = insert_row($db, 'intervention_plans', [
                'incident_id' => $incidentId,
                'service_id' => $serviceId,
                'planned_by_user_id' => $adminId,
                'assigned_user_id' => $agentId,
                'status' => $planStatus,
                'scheduled_date' => $scheduledDate,
                'time_window_start' => $windowStart,
                'time_window_end' => $windowEnd,
                'internal_note' => 'Plan demo ' . $reference,
                'citizen_message' => $citizenMessage,
                'source_type' => $sourceType,
                'provider_name' => $providerName,
                'created_at' => $planCreatedAt,
                'updated_at' => $planCreatedAt,
            ]);

            if (table_exists($db, 'incident_service_history')) {
                insert_row($db, 'incident_service_history', [
                    'incident_id' => $incidentId,
                    'service_id' => $serviceId,
                    'plan_id' => $planId,
                    'actor_user_id' => $adminId,
                    'event_type' => $planStatus === 'rescheduled' ? 'intervention_rescheduled' : 'intervention_planned',
                    'event_label' => $planStatus === 'rescheduled' ? 'Intervention reprogrammee' : 'Intervention planifiee',
                    'citizen_label' => $planStatus === 'rescheduled' ? 'Intervention reprogrammee' : 'Intervention planifiee',
                    'payload_json' => json_encode([
                        'scheduled_date' => $scheduledDate,
                        'time_window_start' => substr($windowStart, 0, 5),
                        'time_window_end' => substr($windowEnd, 0, 5),
                        'source_type' => $sourceType,
                        'provider_name' => $providerName,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $planCreatedAt,
                ]);
            }
        }

        insert_row($db, 'notifications', [
            'user_id' => $citizenId,
            'incident_id' => $incidentId,
            'type' => 'intervention_plan',
            'title' => $planStatus === 'rescheduled' ? 'Intervention reprogrammee' : 'Intervention planifiee',
            'body' => $citizenMessage,
            'data' => json_encode(['incident_id' => $incidentId, 'reference' => $reference], JSON_UNESCAPED_UNICODE),
            'is_read' => 0,
            'sent_at' => $planCreatedAt,
            'created_at' => $planCreatedAt,
        ]);
    }

    if ($status === 'in_progress' || $status === 'resolved') {
        $progressAt = date('Y-m-d H:i:s', strtotime($updatedAt . ' +4 hours'));
        insert_row($db, 'status_history', [
            'incident_id' => $incidentId,
            'user_id' => $agentId,
            'old_status' => 'acknowledged',
            'new_status' => 'in_progress',
            'note' => 'Equipe terrain mobilisee.',
            'changed_at' => $progressAt,
        ]);
        insert_row($db, 'comments', [
            'incident_id' => $incidentId,
            'parent_id' => null,
            'user_id' => $agentId,
            'comment' => 'Une equipe est passee sur place pour qualifier puis traiter la situation.',
            'is_internal' => 0,
            'created_at' => $progressAt,
            'is_edited' => 0,
            'is_flagged' => 0,
            'updated_at' => null,
        ]);

        if ($serviceId !== null && table_exists($db, 'incident_service_history')) {
            insert_row($db, 'incident_service_history', [
                'incident_id' => $incidentId,
                'service_id' => $serviceId,
                'plan_id' => $planId,
                'actor_user_id' => $agentId,
                'event_type' => 'intervention_started',
                'event_label' => 'Intervention en cours',
                'citizen_label' => 'Intervention en cours',
                'payload_json' => json_encode(['mode' => 'terrain'], JSON_UNESCAPED_UNICODE),
                'created_at' => $progressAt,
            ]);
        }

        insert_row($db, 'notifications', [
            'user_id' => $citizenId,
            'incident_id' => $incidentId,
            'type' => 'intervention_update',
            'title' => 'Intervention en cours',
            'body' => 'Le dossier est maintenant en cours de traitement sur le terrain.',
            'data' => json_encode(['incident_id' => $incidentId, 'reference' => $reference], JSON_UNESCAPED_UNICODE),
            'is_read' => 0,
            'sent_at' => $progressAt,
            'created_at' => $progressAt,
        ]);
        $updatedAt = $progressAt;
    }

    if ($status === 'resolved') {
        $resolvedAt = date('Y-m-d H:i:s', strtotime($updatedAt . ' +5 hours'));
        insert_row($db, 'status_history', [
            'incident_id' => $incidentId,
            'user_id' => $agentId,
            'old_status' => 'in_progress',
            'new_status' => 'resolved',
            'note' => 'Traitement termine.',
            'changed_at' => $resolvedAt,
        ]);
        insert_row($db, 'comments', [
            'incident_id' => $incidentId,
            'parent_id' => null,
            'user_id' => $agentId,
            'comment' => 'Le probleme est traite. La zone a ete remise en etat ou securisee.',
            'is_internal' => 0,
            'created_at' => $resolvedAt,
            'is_edited' => 0,
            'is_flagged' => 0,
            'updated_at' => null,
        ]);

        if ($serviceId !== null && table_exists($db, 'incident_service_history')) {
            insert_row($db, 'incident_service_history', [
                'incident_id' => $incidentId,
                'service_id' => $serviceId,
                'plan_id' => $planId,
                'actor_user_id' => $agentId,
                'event_type' => 'intervention_completed',
                'event_label' => 'Intervention terminee',
                'citizen_label' => 'Intervention terminee',
                'payload_json' => json_encode(['result' => 'done'], JSON_UNESCAPED_UNICODE),
                'created_at' => $resolvedAt,
            ]);
        }

        insert_row($db, 'notifications', [
            'user_id' => $citizenId,
            'incident_id' => $incidentId,
            'type' => 'status_change',
            'title' => 'Signalement resolu',
            'body' => 'Le signalement a ete clos avec une action visible.',
            'data' => json_encode(['incident_id' => $incidentId, 'reference' => $reference], JSON_UNESCAPED_UNICODE),
            'is_read' => 0,
            'sent_at' => $resolvedAt,
            'created_at' => $resolvedAt,
        ]);

        update_row($db, 'incidents', ['resolved_at' => $resolvedAt, 'updated_at' => $resolvedAt], 'id = ?', [$incidentId]);
        $updatedAt = $resolvedAt;
    } else {
        update_row($db, 'incidents', ['updated_at' => $updatedAt], 'id = ?', [$incidentId]);
    }

    $incidentsSummary[] = [
        'id' => $incidentId,
        'reference' => $reference,
        'status' => $status,
        'category_id' => $categoryId,
        'category_name' => $category['name'],
        'service_id' => $serviceId,
    ];
}

$photoWarnings = [];
$photoCount = 0;

if ($withPhotos) {
    if ($apiKey === '') {
        $photoWarnings[] = 'OPENAI_API_KEY absent, generation photo ignoree.';
    } else {
        $photoRoot = rtrim(UPLOAD_DIR, '/\\') . '/demo-seed';
        rrmdir($photoRoot);
        ensure_dir($photoRoot);

        $masterFiles = [];
        foreach (array_keys($usedCategoryIds) as $categoryId) {
            $prompt = $categoryScenarios[$categoryId]['photo_prompt'] ?? null;
            if (!$prompt) {
                continue;
            }

            try {
                cli_log('Generation photo categorie ' . $categoryId);
                $bytes = create_openai_image($apiKey, $prompt);
                $masterPath = $photoRoot . '/category-' . $categoryId . '-master.png';
                file_put_contents($masterPath, $bytes);
                $masterFiles[$categoryId] = $masterPath;
            } catch (Throwable $e) {
                $photoWarnings[] = 'Categorie ' . $categoryId . ' : ' . $e->getMessage();
            }
        }

        foreach ($incidentsSummary as $incident) {
            $masterPath = $masterFiles[$incident['category_id']] ?? null;
            if ($masterPath === null || !is_file($masterPath)) {
                continue;
            }

            $relativePath = 'demo-seed/' . slugify_ascii($incident['reference']) . '-citizen-photo.png';
            $finalPath = rtrim(UPLOAD_DIR, '/\\') . '/' . $relativePath;
            if (!copy($masterPath, $finalPath)) {
                $photoWarnings[] = 'Impossible de copier la photo pour ' . $incident['reference'];
                continue;
            }

            insert_row($db, 'photos', [
                'incident_id' => $incident['id'],
                'file_path' => $relativePath,
                'file_name' => basename($finalPath),
                'mime_type' => 'image/png',
                'file_size' => filesize($finalPath) ?: 0,
                'uploaded_at' => date('Y-m-d H:i:s'),
                'is_primary' => 1,
                'sort_order' => 0,
            ]);
            $photoCount++;
        }
    }
}

$counts = [
    'users' => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'incidents' => (int)$db->query('SELECT COUNT(*) FROM incidents')->fetchColumn(),
    'comments' => (int)$db->query('SELECT COUNT(*) FROM comments')->fetchColumn(),
    'notifications' => (int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),
    'photos' => (int)$db->query('SELECT COUNT(*) FROM photos')->fetchColumn(),
    'events' => (int)$db->query('SELECT COUNT(*) FROM events')->fetchColumn(),
    'polls' => (int)$db->query('SELECT COUNT(*) FROM polls')->fetchColumn(),
];

$summary = [
    'label' => $label,
    'generated_at' => date(DATE_ATOM),
    'credentials' => [
        'admin' => ['email' => 'admin@test.fr', 'password' => $commonPassword],
        'agent' => ['email' => 'agent@test.fr', 'password' => $commonPassword],
        'citizen' => ['email' => 'citoyen@test.fr', 'password' => $commonPassword],
    ],
    'counts' => $counts,
    'photos_enabled' => $withPhotos,
    'photo_count' => $photoCount,
    'photo_warnings' => $photoWarnings,
    'incidents' => $incidentsSummary,
];

$summaryJson = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($summaryJson === false) {
    throw new RuntimeException('Impossible d encoder le resume.');
}

if (is_string($summaryPath) && $summaryPath !== '') {
    ensure_dir(dirname($summaryPath));
    file_put_contents($summaryPath, $summaryJson);
    cli_log('Resume ecrit dans ' . $summaryPath);
}

echo $summaryJson . PHP_EOL;
