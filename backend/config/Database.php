<?php
/**
 * CCDS — Classe de connexion à la base de données
 * Utilise PDO avec un pattern Singleton pour éviter les connexions multiples.
 */

class Database
{
    private static ?PDO $instance = null;

    /**
     * Retourne l'instance unique de la connexion PDO.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            } catch (PDOException $e) {
                // En production, ne pas exposer le message d'erreur
                if (APP_DEBUG) {
                    http_response_code(500);
                    echo json_encode(['error' => 'Erreur de connexion BDD : ' . $e->getMessage()]);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Erreur interne du serveur.']);
                }
                exit;
            }
        }

        return self::$instance;
    }

    // Empêcher l'instanciation directe et la copie
    private function __construct() {}
    private function __clone() {}
}
