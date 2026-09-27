<?php
namespace App\Services;
use App\Models\MonetizacionModel;
use App\Models\WalletModel;
use PDO;
final class MonetizacionEarnings
{
    public function __construct(private PDO $db) {}
    // Only called by the ADMIN controller. Approval itself never invokes this.
    public function credit(int $requestId,int $adminId,array $input): array
    {
        $key=$input['request_key']??null;
        if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new \InvalidArgumentException('monetizacion.errors.requestKey');
        $cents=UsdMoney::cents($input['monto']??null);if($cents>10000000)throw new \InvalidArgumentException('monetizacion.errors.amount');
        $reason=$input['concepto']??null;if(!is_string($reason)||!preg_match('//u',$reason)||trim($reason)===''||strlen($reason)>300||preg_match('/[\x00-\x1F\x7F]/',$reason))throw new \InvalidArgumentException('monetizacion.errors.concept');
        $model=new MonetizacionModel($this->db);$initial=$model->find($requestId);if(!$initial)throw new MonetizacionException('monetizacion.errors.notFound',404);
        $recipient=(int)$initial['usuario_id'];
        $intent=['solicitud_id'=>$requestId,'monto_centavos'=>$cents,'concepto'=>trim($reason),'admin_id'=>$adminId];
        return (new WalletCheckout($this->db))->run($recipient,'group_earnings',$key,$intent,function()use($model,$initial,$recipient,$requestId,$cents,$adminId,$key){
            $g=$model->lockGroup((int)$initial['group_id']);$row=$model->find($requestId);
            if(!$row||$row['estado']!=='aprobado'||empty($g['monetizado'])||(int)$g['created_by']!==$recipient||(int)$row['usuario_id']!==$recipient)throw new MonetizacionException('monetizacion.errors.notApproved',409);
            $reference='group_earnings:'.$g['id'].':request:'.$requestId.':admin:'.$adminId.':'.hash('sha256',$key);
            $credit=(new WalletModel($this->db))->adjustBalance($recipient,$cents/100,$reference);
            if(empty($credit['success']))throw new MonetizacionException('monetizacion.errors.credit',503);
            return ['solicitud_id'=>$requestId,'group_id'=>(int)$g['id'],'usuario_id'=>$recipient,'monto_centavos'=>$cents,'currency'=>'USD','new_balance'=>$credit['new_balance']];
        });
    }
}
