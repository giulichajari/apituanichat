<?php
namespace App\Models;
use App\Configs\Database;
use PDO;

class WebAuthnModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function saveChallenge(int $userId, string $challengeBinary, string $type): void
    {
        // Solo un challenge activo por usuario y tipo a la vez.
        $stmt = $this->db->prepare("DELETE FROM webauthn_challenges WHERE user_id = ? AND type = ?");
        $stmt->execute([$userId, $type]);

        $stmt = $this->db->prepare("
            INSERT INTO webauthn_challenges (user_id, challenge, type)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, base64_encode($challengeBinary), $type]);
    }

    public function consumeChallenge(int $userId, string $type): ?string
    {
        $stmt = $this->db->prepare("
            SELECT challenge FROM webauthn_challenges
            WHERE user_id = ? AND type = ?
            AND created_at >= (NOW() - INTERVAL 5 MINUTE)
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$userId, $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare("DELETE FROM webauthn_challenges WHERE user_id = ? AND type = ?");
        $stmt->execute([$userId, $type]);

        return $row ? base64_decode($row['challenge']) : null;
    }

    public function saveCredential(int $userId, string $credentialIdBinary, string $publicKeyBinary, ?int $signCount, ?string $aaguid, string $label): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, aaguid, label)
            VALUES (:user_id, :credential_id, :public_key, :sign_count, :aaguid, :label)
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':credential_id' => base64_encode($credentialIdBinary),
            ':public_key' => base64_encode($publicKeyBinary),
            ':sign_count' => $signCount ?? 0,
            ':aaguid' => $aaguid,
            ':label' => $label,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getCredentialsByUser(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT id, credential_id, label, created_at FROM webauthn_credentials WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Devuelve credenciales con los binarios ya decodificados, listas para pasarle a la librería.
    public function getCredentialsForAuth(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT id, credential_id, public_key, sign_count FROM webauthn_credentials WHERE user_id = ?");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['credential_id_bin'] = base64_decode($r['credential_id']);
            $r['public_key_bin'] = base64_decode($r['public_key']);
        }
        return $rows;
    }

    public function findCredentialByRawId(int $userId, string $credentialIdBinary): ?array
    {
        $target = base64_encode($credentialIdBinary);
        $stmt = $this->db->prepare("SELECT * FROM webauthn_credentials WHERE user_id = ? AND credential_id = ?");
        $stmt->execute([$userId, $target]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateSignCount(int $credentialDbId, int $newCount): void
    {
        $stmt = $this->db->prepare("UPDATE webauthn_credentials SET sign_count = ? WHERE id = ?");
        $stmt->execute([$newCount, $credentialDbId]);
    }

    public function hasAnyCredential(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as c FROM webauthn_credentials WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'] > 0;
    }

    public function deleteCredential(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?");
        return $stmt->execute([$id, $userId]);
    }
}
