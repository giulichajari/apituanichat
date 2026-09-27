<?php
namespace App\Services;

final class AccountSecurity
{
    public static function validPassword(mixed $value): bool
    {
        return is_string($value) && strlen($value)>=8 && strlen($value)<=72 && !str_contains($value,"\0");
    }

    private static function lock(\PDO $db,int $id): string
    {
        $key='tuani:account:'.$id;
        $s=$db->prepare('SELECT GET_LOCK(?, 5)');$s->execute([$key]);
        if ((int)$s->fetchColumn()!==1) throw new \RuntimeException('Account busy');
        return $key;
    }
    private static function unlock(\PDO $db,string $key): void
    {
        $s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$key]);
    }

    public static function storeSession(\PDO $db,int $id,string $previousHash,array $values): bool
    {
        $key=self::lock($db,$id);
        try {
            $s=$db->prepare('SELECT pass FROM users WHERE id = ?');$s->execute([$id]);
            $current=$s->fetchColumn();
            if (!is_string($current) || !hash_equals($current,$previousHash)) return false;
            $s=$db->prepare('INSERT INTO user_tokens (user_id, token, refresh_token, created_at, expires_at, refresh_expires_at) VALUES (:user_id, :token, :refresh_token, :created_at, :expires_at, :refresh_expires_at)');
            return $s->execute($values);
        } finally { self::unlock($db,$key); }
    }

    public static function reset(\PDO $db,string $token,string $hash): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$token)) return false;
        $sql='SELECT id FROM users WHERE otp = ? AND otp_created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)';
        $s=$db->prepare($sql);$s->execute([$token]);$id=$s->fetchColumn();
        if (!$id) return false;
        $key=self::lock($db,(int)$id);
        try {
            $s=$db->prepare($sql.' AND id = ?');$s->execute([$token,(int)$id]);
            if (!$s->fetchColumn()) return false;
            // Delete first: on a subsequent failure sessions stay revoked, even on MyISAM.
            // Login uses the same per-account lock; refresh updates cannot recreate deleted rows.
            $s=$db->prepare('DELETE FROM user_tokens WHERE user_id = ?');$s->execute([(int)$id]);
            $s=$db->prepare('UPDATE users SET pass = ?, otp = NULL, otp_created_at = NULL WHERE id = ? AND otp = ? AND otp_created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
            $s->execute([$hash,(int)$id,$token]);
            return $s->rowCount()===1;
        } finally { self::unlock($db,$key); }
    }
}
