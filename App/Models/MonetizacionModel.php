<?php
namespace App\Models;
use App\Configs\Database;
use App\Services\MonetizacionException;
use App\Services\MonetizacionInput;
use PDO;

final class MonetizacionModel
{
    private PDO $db;
    public function __construct(?PDO $db=null) { $this->db=$db??Database::getInstance()->getConnection(); }
    public function lockGroup(int $id): array
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Transaction required');
        $s=$this->db->prepare('SELECT * FROM `groups` WHERE id=? FOR UPDATE');$s->execute([$id]);$g=$s->fetch(PDO::FETCH_ASSOC);
        if (!$g || !empty($g['deleted_at'])) throw new MonetizacionException('monetizacion.errors.group',404);
        return $g;
    }
    private function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();try{$r=$work();$this->db->commit();return $r;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function getByGroup(int $groupId): ?array
    {
        $s=$this->db->prepare('SELECT * FROM monetizacion_grupos_solicitudes WHERE group_id=? ORDER BY id DESC LIMIT 1');$s->execute([$groupId]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function find(int $id): ?array
    {
        $s=$this->db->prepare('SELECT * FROM monetizacion_grupos_solicitudes WHERE id=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function getForCreator(int $groupId,int $userId): array
    {
        return $this->transaction(function()use($groupId,$userId){$g=$this->lockGroup($groupId);if((int)$g['created_by']!==$userId)throw new MonetizacionException('monetizacion.errors.creator',403);$row=$this->getByGroup($groupId);return ['monetizado'=>(bool)$g['monetizado'],'solicitud'=>$row && (int)$row['usuario_id']===$userId?$row:null];});
    }
    public function create(int $groupId,int $userId,array $input): array
    {
        $fields=MonetizacionInput::application($input);
        return $this->transaction(function()use($groupId,$userId,$fields){
            $g=$this->lockGroup($groupId);
            if((int)$g['created_by']!==$userId)throw new MonetizacionException('monetizacion.errors.creator',403);
            if(!empty($g['monetizado']))throw new MonetizacionException('monetizacion.errors.alreadyApproved',409);
            $country=$this->db->prepare('SELECT id FROM countries WHERE code=? AND is_active=1 LIMIT 1');$country->execute([$fields['pais']]);if(!$country->fetchColumn())throw new \InvalidArgumentException('monetizacion.errors.country');
            $old=$this->getByGroup($groupId);
            if($old && $old['estado']!=='rechazado'){
                $same=(int)$old['usuario_id']===$userId;
                foreach($fields as$k=>$v)$same=$same && (string)$old[$k]===(string)$v;
                if($old['estado']==='pendiente' && $same)return $old+['replayed'=>true];
                throw new MonetizacionException('monetizacion.errors.pending',409);
            }
            $values=[$userId,$fields['categoria_contenido'],$fields['nombre_completo'],$fields['pais'],$fields['contacto'],$fields['monto_deseado']];
            $s=$this->db->prepare('INSERT INTO monetizacion_grupos_solicitudes(usuario_id,categoria_contenido,nombre_completo,pais,contacto,monto_deseado,group_id)VALUES(?,?,?,?,?,?,?)');$s->execute([...$values,$groupId]);
            return $this->getByGroup($groupId)+['replayed'=>false];
        });
    }
    public function getPendientes(int $page=1): array
    {
        $s=$this->db->prepare("SELECT s.*,g.name AS group_name,g.created_by AS current_creator FROM monetizacion_grupos_solicitudes s JOIN `groups` g ON g.id=s.group_id WHERE s.estado='pendiente' ORDER BY s.id ASC LIMIT 25 OFFSET ?");$s->bindValue(1,($page-1)*25,PDO::PARAM_INT);$s->execute();return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function aprobar(int $id,int $adminId): array { return $this->review($id,$adminId,['estado'=>'aprobado']); }
    public function rechazar(int $id,int $adminId,?string $reason=null): array { return $this->review($id,$adminId,['estado'=>'rechazado','motivo_rechazo'=>$reason]); }
    private function review(int $id,int $adminId,array $input): array
    {
        $decision=MonetizacionInput::review($input);$initial=$this->find($id);if(!$initial)throw new MonetizacionException('monetizacion.errors.notFound',404);
        return $this->transaction(function()use($id,$adminId,$decision,$initial){
            $g=$this->lockGroup((int)$initial['group_id']);$row=$this->getByGroup((int)$g['id']);
            if(!$row || (int)$row['id']!==$id)throw new MonetizacionException('monetizacion.errors.notFound',404);
            if($decision['estado']==='aprobado' && (int)$g['created_by']!==(int)$row['usuario_id'])throw new MonetizacionException('monetizacion.errors.creatorChanged',409);
            if($row['estado']!=='pendiente'){
                if($row['estado']===$decision['estado'])return $row+['replayed'=>true];
                throw new MonetizacionException('monetizacion.errors.reviewed',409);
            }
            $s=$this->db->prepare('UPDATE monetizacion_grupos_solicitudes SET estado=?,motivo_rechazo=?,reviewed_at=UTC_TIMESTAMP(),reviewed_by=? WHERE id=?');$s->execute([$decision['estado'],$decision['motivo_rechazo'],$adminId,$id]);
            $s=$this->db->prepare('UPDATE `groups` SET monetizado=? WHERE id=?');$s->execute([$decision['estado']==='aprobado'?1:0,$g['id']]);
            return $this->find($id)+['replayed'=>false];
        });
    }
}
