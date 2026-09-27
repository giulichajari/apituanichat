<?php
namespace App\Services;
use PDO;

/** Stored in the same transaction as the trip change. No external network calls. */
final class RidePush
{
    public static function enqueue(PDO $db,int $rideId): int
    {
        if(!$db->inTransaction())throw new \LogicException('Aviso Ride requiere transacción');
        if($db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='push_outbox'")->fetchColumn()!=='InnoDB')throw new \RuntimeException('Cola Ride requiere InnoDB');
        $q=$db->prepare('SELECT id,user_id,driver_id,status FROM ride_requests WHERE id=?');
        $q->execute([$rideId]);$ride=$q->fetch(PDO::FETCH_ASSOC);
        if(!$ride)throw new \RuntimeException('Viaje no encontrado');
        $state=$ride['status'];
        $body=match($state){
            'pending'=>'Tienes una nueva solicitud de viaje.',
            'accepted'=>'Tu viaje fue aceptado.',
            'rejected'=>'El viaje fue cancelado o rechazado. Revisa tu Wallet.',
            'completed'=>'El viaje ha finalizado. Revisa el detalle en Ride.',
            default=>throw new \RuntimeException('Estado de viaje inválido')
        };
        $recipients=$state==='pending'?[(int)$ride['driver_id']]:($state==='accepted'?[(int)$ride['user_id']]:[(int)$ride['user_id'],(int)$ride['driver_id']]);
        $count=0;$queue=new PushOutbox($db);
        $q=$db->prepare('SELECT fcm_token FROM device_tokens WHERE user_id=? AND is_active=1');
        foreach(array_unique($recipients) as $user){
            $q->execute([$user]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $token){
                if($queue->enqueue($user,(string)$token,['type'=>'ride_update','event_id'=>$rideId.':'.$state,'ride_id'=>(string)$rideId,'ride_status'=>$state,'title'=>'TuaniChat Ride','body'=>$body],time()))++$count;
            }
        }
        return $count;
    }

    public static function isCurrent(PDO $db,array $data,int $recipient): bool
    {
        $id=filter_var($data['ride_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)return false;
        $q=$db->prepare('SELECT user_id,driver_id,status FROM ride_requests WHERE id=?');$q->execute([$id]);
        $ride=$q->fetch(PDO::FETCH_ASSOC);
        if(!$ride||$ride['status']!==($data['ride_status']??null))return false;
        return match($ride['status']) {
            'pending'=>$recipient===(int)$ride['driver_id'],
            'accepted'=>$recipient===(int)$ride['user_id'],
            'completed','rejected'=>in_array($recipient,[(int)$ride['user_id'],(int)$ride['driver_id']],true),
            default=>false
        };
    }
}
