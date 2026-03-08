#!/usr/bin/env php
<?php
/**
 * CCDS v1.3 — Serveur WebSocket (RT-01)
 * Diffuse les nouveaux signalements en temps réel aux clients connectés.
 *
 * Démarrage :
 *   php backend/websocket/server.php
 *
 * Dépendance :
 *   composer require cboden/ratchet
 *
 * Architecture :
 *   - Les clients se connectent via ws://server/ws?token=JWT
 *   - Le backend PHP (IncidentController) publie les nouveaux incidents
 *     via un appel interne à /ws/broadcast (HTTP POST local)
 *   - Le serveur WebSocket diffuse le message à tous les clients connectés
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/helpers.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class MaCommuneRealtimeServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, array> */
    protected \SplObjectStorage $clients;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        echo "[MaCommune WebSocket] Serveur démarré — en attente de connexions...\n";
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        // Vérifier le token JWT dans la query string
        $query = $conn->httpRequest->getUri()->getQuery();
        parse_str($query, $params);
        $token = $params['token'] ?? '';

        $payload = jwt_decode($token);
        if (!$payload) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Token invalide']));
            $conn->close();
            return;
        }

        $this->clients->attach($conn, [
            'user_id' => $payload['user_id'],
            'role'    => $payload['role'],
        ]);

        $conn->send(json_encode([
            'type'    => 'connected',
            'message' => 'Connexion temps réel établie',
            'clients' => count($this->clients),
        ]));

        echo "[MaCommune WS] Nouvelle connexion : user_id={$payload['user_id']} (total: " . count($this->clients) . ")\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        try {
            $data = json_decode($msg, true);

            if (($data['type'] ?? '') === 'ping') {
                $from->send(json_encode(['type' => 'pong']));
                return;
            }

            // Broadcast interne (depuis le backend PHP via HTTP local)
            if (($data['type'] ?? '') === 'broadcast' && ($data['secret'] ?? '') === WS_INTERNAL_SECRET) {
                $this->broadcast($data['event'], $data['payload'] ?? []);
                return;
            }

        } catch (\Exception $e) {
            // Ignorer les messages malformés
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
        echo "[MaCommune WS] Déconnexion (total: " . count($this->clients) . ")\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[MaCommune WS] Erreur : " . $e->getMessage() . "\n";
        $conn->close();
    }

    /**
     * Diffuser un événement à tous les clients connectés
     */
    public function broadcast(string $event, array $data): void
    {
        $message = json_encode(['type' => $event, 'data' => $data], JSON_UNESCAPED_UNICODE);
        $count   = 0;
        foreach ($this->clients as $client) {
            $client->send($message);
            $count++;
        }
        echo "[MaCommune WS] Broadcast '$event' → $count clients\n";
    }
}

// Démarrer le serveur
$port   = defined('WS_PORT') ? WS_PORT : 8080;
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new MaCommuneRealtimeServer()
        )
    ),
    $port
);

echo "[MaCommune WebSocket] Écoute sur le port $port\n";
$server->run();
