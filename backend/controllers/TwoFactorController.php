<?php

declare(strict_types=1);

/**
 * TwoFactorController — Authentification à deux facteurs (SEC-03)
 *
 * Méthodes supportées :
 *   - TOTP (Time-based One-Time Password) — compatible Google Authenticator, Authy
 *   - Email (code à 6 chiffres envoyé par email)
 *
 * Endpoints :
 *   GET    /auth/2fa/status       — état 2FA de l'utilisateur connecté
 *   POST   /auth/2fa/setup        — initialise la 2FA (génère secret + QR code)
 *   POST   /auth/2fa/verify       — vérifie le code et active la 2FA
 *   DELETE /auth/2fa/disable      — désactive la 2FA (requiert le mot de passe)
 *   POST   /auth/2fa/send-email   — envoie un code par email
 *   POST   /auth/2fa/validate     — valide le code lors de la connexion
 */
class TwoFactorController extends BaseController
{
    private function hasTwoFactorMethodColumn(): bool
    {
        return $this->dbHasColumn('users', 'two_factor_method');
    }

    private function hasTwoFactorEnabledColumn(): bool
    {
        return $this->dbHasColumn('users', 'two_factor_enabled');
    }

    private function getTwoFactorRecoveryCodesColumn(): ?string
    {
        if ($this->dbHasColumn('users', 'two_factor_recovery_codes')) {
            return 'two_factor_recovery_codes';
        }

        if ($this->dbHasColumn('users', 'two_factor_backup_codes')) {
            return 'two_factor_backup_codes';
        }

        return null;
    }

    private function twoFactorMethodSelectSql(): string
    {
        if ($this->hasTwoFactorMethodColumn()) {
            return 'two_factor_method';
        }

        if ($this->hasTwoFactorEnabledColumn()) {
            return "CASE
                WHEN COALESCE(two_factor_enabled, 0) = 1 THEN 'totp'
                WHEN COALESCE(two_factor_secret, '') <> '' AND LOCATE('|', two_factor_secret) > 0 THEN 'email'
                WHEN COALESCE(two_factor_secret, '') <> '' THEN 'pending_totp'
                ELSE 'none'
            END";
        }

        return "'none'";
    }

    private function twoFactorRecoveryCodesSelectSql(): string
    {
        $column = $this->getTwoFactorRecoveryCodesColumn();
        if ($column === null) {
            return 'NULL AS two_factor_recovery_codes';
        }

        return "{$column} AS two_factor_recovery_codes";
    }

    private function fetchTwoFactorState(int $userId, string $extraSelect = ''): array
    {
        $select = array_filter([
            'id',
            'email',
            'full_name',
            'two_factor_secret',
            $this->twoFactorMethodSelectSql() . ' AS two_factor_method',
            $this->twoFactorRecoveryCodesSelectSql(),
            trim($extraSelect),
        ]);

        $stmt = $this->db->prepare(
            'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: [];
    }

    private function persistTwoFactorSecret(int $userId, ?string $secret, string $method): void
    {
        if ($this->hasTwoFactorMethodColumn()) {
            $stmt = $this->db->prepare(
                'UPDATE users SET two_factor_secret = ?, two_factor_method = ? WHERE id = ?'
            );
            $stmt->execute([$secret, $method, $userId]);
            return;
        }

        if ($this->hasTwoFactorEnabledColumn()) {
            $enabled = $method === 'totp' ? 1 : 0;
            $stmt = $this->db->prepare(
                'UPDATE users SET two_factor_secret = ?, two_factor_enabled = ? WHERE id = ?'
            );
            $stmt->execute([$secret, $enabled, $userId]);
            return;
        }

        $stmt = $this->db->prepare('UPDATE users SET two_factor_secret = ? WHERE id = ?');
        $stmt->execute([$secret, $userId]);
    }

    private function persistRecoveryCodes(int $userId, array $backupCodes): void
    {
        $column = $this->getTwoFactorRecoveryCodesColumn();
        if ($column === null) {
            return;
        }

        $stmt = $this->db->prepare(
            "UPDATE users SET {$column} = ? WHERE id = ?"
        );
        $stmt->execute([json_encode($backupCodes), $userId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /auth/2fa/status
    // ─────────────────────────────────────────────────────────────────────────

    public function getStatus(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int) $auth['sub'];
        $user = $this->fetchTwoFactorState($userId);
        $method = $user['two_factor_method'] ?? 'none';

        $this->success([
            'two_factor_enabled' => $method !== 'none',
            'two_factor_method'  => $method,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /auth/2fa/setup
    // Génère un secret TOTP et retourne l'URL du QR code
    // ─────────────────────────────────────────────────────────────────────────

    public function setup(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int) $auth['sub'];
        $stmt = $this->db->prepare(
            'SELECT email FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        // Générer un secret TOTP (Base32, 160 bits)
        $secret = $this->generateTotpSecret();

        // Stocker le secret temporairement (non activé tant que verify() n'est pas appelé)
        $this->persistTwoFactorSecret($userId, $secret, 'pending_totp');

        // Générer les codes de récupération
        $backupCodes = $this->generateBackupCodes();
        $this->persistRecoveryCodes(
            $userId,
            array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $backupCodes)
        );

        // URL otpauth:// pour le QR code
        $issuer   = urlencode(defined('APP_NAME') ? APP_NAME : 'Ma Commune');
        $label    = urlencode($user['email']);
        $otpUrl   = "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
        $qrCodeUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($otpUrl);

        $this->success([
            'secret'       => $secret,
            'qr_code_url'  => $qrCodeUrl,
            'backup_codes' => $backupCodes,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /auth/2fa/verify
    // Vérifie le premier code TOTP et active la 2FA
    // ─────────────────────────────────────────────────────────────────────────

    public function verify(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int) $auth['sub'];
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $code   = trim($body['code'] ?? '');

        if (empty($code) || !ctype_digit($code) || strlen($code) !== 6) {
            $this->error('Code invalide — 6 chiffres requis.', 422);
            return;
        }

        $user = $this->fetchTwoFactorState($userId);

        if ($user['two_factor_method'] !== 'pending_totp') {
            $this->error('Aucune configuration 2FA en attente.', 400);
            return;
        }

        if (!$this->verifyTotp($user['two_factor_secret'], $code)) {
            $this->error('Code incorrect. Vérifiez l\'heure de votre appareil.', 401);
            return;
        }

        $this->persistTwoFactorSecret($userId, $user['two_factor_secret'], 'totp');

        $this->success(['enabled' => true, 'method' => 'totp']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /auth/2fa/disable
    // ─────────────────────────────────────────────────────────────────────────

    public function disable(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int) $auth['sub'];
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];

        $passwordColumn = $this->getUserPasswordColumnName();
        $stmt = $this->db->prepare(
            "SELECT {$passwordColumn} AS password_hash FROM users WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($body['password'] ?? '', $user['password_hash'])) {
            $this->error('Mot de passe incorrect.', 401);
            return;
        }

        $column = $this->getTwoFactorRecoveryCodesColumn();
        if ($this->hasTwoFactorMethodColumn()) {
            $sql = 'UPDATE users SET two_factor_method = "none", two_factor_secret = NULL';
        } elseif ($this->hasTwoFactorEnabledColumn()) {
            $sql = 'UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL';
        } else {
            $sql = 'UPDATE users SET two_factor_secret = NULL';
        }

        if ($column !== null) {
            $sql .= ", {$column} = NULL";
        }

        $sql .= ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);

        $this->success(['disabled' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /auth/2fa/send-email
    // Envoie un code à 6 chiffres par email (méthode email)
    // ─────────────────────────────────────────────────────────────────────────

    public function sendEmailCode(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int) $auth['sub'];
        $stmt = $this->db->prepare(
            'SELECT email, full_name FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        // Stocker le code haché avec expiration
        $this->persistTwoFactorSecret(
            $userId,
            password_hash($code, PASSWORD_BCRYPT) . '|' . $expires,
            'email'
        );

        // Envoyer l'email
        $subject = '[' . (defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'MaCommune') . '] Votre code de vérification';
        $message = "Bonjour {$user['full_name']},\n\nVotre code de vérification est : {$code}\n\nCe code expire dans 10 minutes.\n\nSi vous n'avez pas demandé ce code, ignorez cet email.";
        mail($user['email'], $subject, $message, 'From: ' . (defined('APP_EMAIL_FROM') ? APP_EMAIL_FROM : 'noreply@netetfix.com'));

        $this->success(['sent' => true, 'expires_in' => 600]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /auth/2fa/validate
    // Valide le code lors de la connexion (TOTP ou email)
    // ─────────────────────────────────────────────────────────────────────────

    public function validateCode(): void
    {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int) ($body['user_id'] ?? 0);
        $code   = trim($body['code'] ?? '');

        if (!$userId || empty($code)) {
            $this->error('Paramètres manquants.', 422);
            return;
        }

        $user = $this->fetchTwoFactorState($userId);

        $valid = false;

        switch ($user['two_factor_method']) {
            case 'totp':
                $valid = $this->verifyTotp($user['two_factor_secret'], $code);
                break;

            case 'email':
                [$hashedCode, $expires] = explode('|', $user['two_factor_secret'] . '|');
                if (strtotime($expires) > time() && password_verify($code, $hashedCode)) {
                    $valid = true;
                    // Invalider le code après utilisation
                    $stmt = $this->db->prepare(
                        'UPDATE users SET two_factor_secret = NULL WHERE id = ?'
                    );
                    $stmt->execute([$userId]);
                }
                break;
        }

        // Vérification des codes de récupération
        if (!$valid && !empty($user['two_factor_recovery_codes'])) {
            $backupCodes = json_decode($user['two_factor_recovery_codes'], true) ?? [];
            foreach ($backupCodes as $i => $hashed) {
                if (password_verify($code, $hashed)) {
                    $valid = true;
                    // Supprimer le code utilisé
                    unset($backupCodes[$i]);
                    $this->persistRecoveryCodes($userId, array_values($backupCodes));
                    break;
                }
            }
        }

        if (!$valid) {
            $this->error('Code incorrect ou expiré.', 401);
            return;
        }

        $this->success(['valid' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers privés
    // ─────────────────────────────────────────────────────────────────────────

    private function generateTotpSecret(): string
    {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    private function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }

    /**
     * Vérifie un code TOTP (RFC 6238)
     * Accepte ±1 intervalle de 30s pour compenser le décalage d'horloge.
     */
    private function verifyTotp(string $secret, string $code): bool
    {
        $time = (int) floor(time() / 30);

        for ($offset = -1; $offset <= 1; $offset++) {
            $expected = $this->generateTotp($secret, $time + $offset);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    private function generateTotp(string $secret, int $time): string
    {
        // Décoder le secret Base32
        $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits        = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos($base32Chars, $char)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }

        // HMAC-SHA1
        $msg  = pack('N*', 0) . pack('N*', $time);
        $hash = hash_hmac('sha1', $msg, $key, true);

        // Tronquer
        $offset = ord($hash[19]) & 0x0F;
        $otp    = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
            (ord($hash[$offset + 3])  & 0xFF)
        ) % 1_000_000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }
}
