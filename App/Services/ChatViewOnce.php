<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use RuntimeException;

/** The normal message row contains only a placeholder, never this content. */
final class ChatViewOnce
{
    public function __construct(private PDO $db) {}

    public function store(int $messageId,int $sender,int $recipient,string $body,int $now): void
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('Crear junto al mensaje en una transacción');
        if ($messageId<1 || $sender<1 || $recipient<1 || $sender===$recipient || trim($body)==='' || strlen($body)>16000) throw new \InvalidArgumentException('Mensaje inválido');
        $s=$this->db->prepare('INSERT INTO chat_view_once(message_id,sender_id,recipient_id,body,created_at) VALUES(?,?,?,?,?)');
        $s->execute([$messageId,$sender,$recipient,$body,$now]);
    }

    public function consume(int $viewer,int $messageId,int $now,callable $authorize): string
    {
        if ($this->db->inTransaction()) throw new RuntimeException('La apertura requiere transacción independiente');
        $this->db->beginTransaction();
        try {
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $s=$this->db->prepare('SELECT * FROM chat_view_once WHERE message_id=?'.$lock);$s->execute([$messageId]);
            $row=$s->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['recipient_id']!==$viewer) throw new RuntimeException('Mensaje no disponible',404);
            $authorize($viewer,$messageId);
            if ($row['consumed_at']!==null || $row['body']===null) throw new RuntimeException('El mensaje ya fue abierto',410);
            $body=$row['body'];
            $s=$this->db->prepare('UPDATE chat_view_once SET body=NULL,consumed_at=? WHERE message_id=? AND consumed_at IS NULL');
            $s->execute([$now,$messageId]);
            if ($s->rowCount()!==1) throw new RuntimeException('El mensaje ya fue abierto',410);
            $this->db->commit();
            return $body;
        } catch (\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack();throw $e; }
    }
}
