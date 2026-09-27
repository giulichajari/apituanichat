<?php
namespace App\Services;

use App\Models\AnunciosModel;
use App\Models\WalletModel;
use App\Models\ReceiptModel;
use PDO;

final class AnuncioCheckout
{
    public function __construct(private PDO $db) {}
    public static function normalize(array $data): array
    {
        $text=$data['contenido']??null;$country=$data['pais_destino']??null;$count=$data['cantidad_personas']??null;
        if(!is_string($text)||!preg_match('//u',$text)||trim($text)===''||strlen($text)>20000
            ||preg_match_all('/./us',$text)>5000)throw new \InvalidArgumentException('anuncios.errors.content');
        if(!is_string($country)||!preg_match('/^[A-Za-z]{2,3}$/D',$country))throw new \InvalidArgumentException('anuncios.errors.country');
        if((!is_int($count)&&!is_string($count))||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$count)||(int)$count>1000000)throw new \InvalidArgumentException('anuncios.errors.quantity');
        return ['contenido'=>trim($text),'pais_destino'=>strtoupper($country),'cantidad_personas'=>(int)$count,'precio_centavos'=>(int)$count*10];
    }
    public function purchase(int $userId,string $key,array $input,?array $image=null): array
    {
        if($userId<1)throw new AnuncioPurchaseException('auth.required',401);
        if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new \InvalidArgumentException('anuncios.errors.requestKey');
        $intent=self::normalize($input);$intent['imagen_sha256']=$image['sha256']??null;
        $engines=$this->db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach(['anuncios_publicidad','wallets','wallet_transactions','wallet_checkout_requests','purchase_receipts','family_links']as$table){
            if(($engines[$table]??'')!=='InnoDB')throw new \RuntimeException('Transactional storage required');
        }
        $s=$this->db->prepare('SELECT id FROM countries WHERE code=? AND is_active=1 LIMIT 1');$s->execute([$intent['pais_destino']]);
        if(!$s->fetchColumn())throw new \InvalidArgumentException('anuncios.errors.country');
        $receipt=new ReceiptModel($this->db);
        $description='Anuncio publicitario: '.$intent['cantidad_personas'].' personas; '.$intent['pais_destino'];
        $amount=$intent['precio_centavos']/100;
        $storedImage=null;
        try {
            $result=(new WalletCheckout($this->db))->run($userId,'anuncio',$key,$intent,function()use($userId,$key,$intent,$image,$receipt,$description,$amount,&$storedImage){
                $debit=(new WalletModel($this->db))->debitForPurchase($userId,$amount,'anuncio:'.hash('sha256',$userId.':'.$key));
                if(empty($debit['success'])){
                    $message=$debit['message']??'';
                    if(stripos($message,'Saldo insuficiente')!==false)throw new AnuncioPurchaseException('anuncios.errors.insufficientBalance',402);
                    if(stripos($message,'congelada')!==false)throw new AnuncioPurchaseException('anuncios.errors.walletFrozen',403);
                    throw new AnuncioPurchaseException('anuncios.errors.walletUnavailable',503);
                }
                if($image!==null)$storedImage=AnuncioImage::store($image);
                $id=(new AnunciosModel($this->db))->create($userId,$intent,$storedImage,bin2hex(random_bytes(24)));
                // Persist receipt atomically; external email is deliberately deferred until commit.
                if(!$receipt->createAndNotify($userId,'anuncio_publicidad',$id,$amount,'wallet',$description,null,false))throw new \RuntimeException('Receipt persistence failed');
                return ['id'=>$id,'estado'=>'pagado','precio_centavos'=>$intent['precio_centavos'],'currency'=>'USD','newBalance'=>$debit['new_balance']];
            });
        }catch(\Throwable $e){
            if($storedImage!==null){
                // On ambiguous DB failure preserve the private file rather than break a committed ad.
                try{$s=$this->db->prepare('SELECT id FROM anuncios_publicidad WHERE imagen_url=?');$s->execute([$storedImage]);if(!$s->fetchColumn())@unlink(AnuncioImage::path($storedImage));}catch(\Throwable $ignored){}
            }
            throw $e;
        }
        if(empty($result['replayed'])){
            try{$receipt->notifyCreatedPurchase($userId,$amount,$description,'wallet');}catch(\Throwable $e){error_log('Anuncio receipt email failed');}
        }
        return $result;
    }
}
