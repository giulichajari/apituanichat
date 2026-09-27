<?php
namespace App\Services;
use PDO;

/** Durable post-purchase documents. Never debits, credits or sends external email. */
final class RideDocuments
{
    public function __construct(private PDO $db) {}
    public function enqueue(int $rideId):void
    {
        if(!$this->db->inTransaction())throw new \LogicException('El trabajo debe guardarse con la compra');
        $this->db->prepare('INSERT INTO ride_documents(ride_id) VALUES(?)')->execute([$rideId]);
    }
    public function process(int $rideId):bool
    {
        if($rideId<1||$this->db->inTransaction())throw new \InvalidArgumentException('Trabajo inválido');
        $engines=$this->db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach(['ride_documents','chats','chat_usuarios','mensajes','purchase_receipts'] as $table)
            if(($engines[$table]??'')!=='InnoDB')throw new \RuntimeException('Documentos requieren tablas transaccionales');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT * FROM ride_documents WHERE ride_id=? FOR UPDATE');$q->execute([$rideId]);$job=$q->fetch(PDO::FETCH_ASSOC);
            if(!$job)throw new \RuntimeException('Trabajo no encontrado');
            if($job['status']==='completed'){$this->db->commit();return false;}
            if($job['status']!=='pending')throw new \RuntimeException('Estado de documentos inválido');
            $q=$this->db->prepare("SELECT r.*,p.id AS payment_id,p.amount,p.currency FROM ride_requests r JOIN payments p ON p.ride_request_id=r.id WHERE r.id=? AND p.payment_method='wallet' AND p.status IN ('completed','refunded')");
            $q->execute([$rideId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1||$rows[0]['currency']!=='USD')throw new \RuntimeException('Pago del viaje incompatible');
            $ride=$rows[0];$amount=UsdMoney::decimal(UsdMoney::cents($ride['amount']));
            if($amount!==UsdMoney::decimal(UsdMoney::cents($ride['estimated_fare'])))throw new \RuntimeException('Importe incompatible');
            // Always a dedicated two-person conversation; never reuse a shared group.
            $this->db->prepare('INSERT INTO chats(name,created_at,last_message_at,bot_active) VALUES(?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,0)')->execute(['Viaje #'.$rideId]);
            $chatId=(int)$this->db->lastInsertId();
            $q=$this->db->prepare('INSERT INTO chat_usuarios(chat_id,user_id,leido) VALUES(?,?,0)');
            $q->execute([$chatId,$ride['user_id']]);$q->execute([$chatId,$ride['driver_id']]);
            // Historical purchase note, not an actionable pending request that could be stale.
            $text='Viaje #'.$rideId.' · Pago original: USD '.$amount.'. Consulta el estado actual y las devoluciones en Gestionar mis viajes.';
            $this->db->prepare("INSERT INTO mensajes(chat_id,user_id,contenido,tipo,enviado_en) VALUES(?,?,?,'texto',CURRENT_TIMESTAMP)")->execute([$chatId,$ride['user_id'],$text]);
            $messageId=(int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO purchase_receipts(user_id,type,reference_id,amount,currency,payment_method,description,square_payment_id) VALUES(?,'ride',?,?,'USD','wallet',?,NULL)")->execute([$ride['user_id'],$rideId,$amount,'Pago original del viaje #'.$rideId.'; consultar devoluciones en Wallet']);
            $receiptId=(int)$this->db->lastInsertId();
            $this->db->prepare("UPDATE ride_documents SET status='completed',chat_id=?,message_id=?,receipt_id=?,completed_at=CURRENT_TIMESTAMP WHERE ride_id=?")->execute([$chatId,$messageId,$receiptId,$rideId]);
            $this->db->commit();return true;
        } catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
