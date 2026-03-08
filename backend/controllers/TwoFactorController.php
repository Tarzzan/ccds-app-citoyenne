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
    // ─────────────────────────────────────────────────────────────────────────
    // GET /auth/2fa/status
    // ─────────────────────────────────────────────────────────────────────────

    public function getStatus(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int) $auth['sub'];
        $stmt = $this->db->prepare(
            'SELECT two_factor_method, two_factor_secret FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $this->success([
            'two_factor_enabled' => $user['two_factor_method'] !== 'none',
            'two_factor_method'  => $user['two_factor_method'] ?? 'none',
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
        $stmt = $this->db->prepare(
            'UPDATE users SET two_factor_secret = ?, two_factor_method = "pending_totp" WHERE id = ?'
        );
        $stmt->execute([$secret, $userId]);

        // Générer les codes de récupération
        $backupCodes = $this->generateBackupCodes();
        $stmt = $this->db->prepare(
                'UPDATE users SET two_factor_recovery_codes = ? WHERE id = ?'
            );
            $stmt->execute([json_encode(array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $backupCodes)), $userId]);

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

        $stmt = $this->db->prepare(
            'SELECT two_factor_secret, two_factor_method FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user['two_factor_method'] !== 'pending_totp') {
            $this->error('Aucune configuration 2FA en attente.', 400);
            return;
        }

        if (!$this->verifyTotp($user['two_factor_secret'], $code)) {
            $this->error('Code incorrect. Vérifiez l\'heure de votre appareil.', 401);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE users SET two_factor_method = "totp" WHERE id = ?'
        );
        $stmt->execute([$userId]);

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

        $stmt = $this->db->prepare(
            'SELECT password FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!password_verify($body['password'] ?? '', $user['password'])) {
            $this->error('Mot de passe incorrect.', 401);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE users SET two_factor_method = "none", two_factor_secret = NULL, two_factor_recovery_codes = NULL WHERE id = ?'
        );
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
        $stmt = $this->db->prepare(
            'UPDATE users SET two_factor_secret = ?, two_factor_method = "email" WHERE id = ?'
        );
        $stmt->execute([password_hash($code, PASSWORD_BCRYPT) . '|' . $expires, $userId]);

        // Envoyer l'email
        $subject = '[' . (defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'MaCommune') . '] Votre code de vérification';
        $message = "Bonjour {$user['full_name']},\n\nVotre code de vérification est : {$code}\n\nCe code expire dans 10 minutes.\n\nSi vous n'avez pas demandé ce code, ignorez cet email.";
        mail($user['email'], $subject, $message, 'From: ' . (defined('APP_EMAIL_FROM') ? APP_EMAIL_FROM : 'noreply@votre-domaine.com'));

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

        $stmt = $this->db->prepare(
            'SELECT two_factor_method, two_factor_secret, two_factor_recovery_codes FROM users WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

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
                    $stmt = $this->db->prepare(
                'UPDATE users SET two_factor_recovery_codes = ? WHERE id = ?'
            );
            $stmt->execute([json_encode(array_values($backupCodes)), $userId]);
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
