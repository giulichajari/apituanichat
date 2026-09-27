<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Configs\Database;
use App\Services\Enterprise\{OpenAiSupport,SupportPilot};
use EasyProjects\SimpleRouter\Router;

final class SupportPilotController
{
    private function service():SupportPilot{
        return new SupportPilot(Database::getInstance()->getConnection(),(int)($_ENV['BOT_USER_ID']??0),
            static function(array $event,string $message,?array $company=null):array{
                $provider=new OpenAiSupport(trim((string)($_ENV['OPENAI_API_KEY']??'')),trim((string)($_ENV['OPENAI_MODEL']??'')));
                if($company){$event['tenant_id']=$company['tenant_id'];$event['agent_id']=$company['agent_id'];return $provider->propose($event,$message,$company['knowledge'],[],'general',true);}
                return $provider->propose($event,$message,[['id'=>'scope-approved',
                    'text'=>'El soporte de TuaniChat orienta sobre cuenta, chats, mensajes, llamadas, Eats, Shop, Ride y Wallet. No dispone todavía de herramientas para consultar operaciones, cambiar saldos, cancelar pedidos o realizar reembolsos.']],[],'general');
            });
    }
    private function output(callable $action):mixed{
        try{return Router::$response->status(200)->send(['success'=>true]+$action());}
        catch(\Throwable $e){
            $code=(int)$e->getCode();if(!in_array($code,[400,401,403,409,422,429],true))$code=503;
            return Router::$response->status($code)->send(['success'=>false,'message'=>match($code){
                401=>'Conexión no autorizada.',403=>'Agente, chat o piloto no disponible.',
                409=>'La operación cambió; vuelve a consultar su estado.',422=>'Este mensaje no puede procesarse.',
                429=>'Se alcanzó el límite diario del piloto.',400=>'Referencias inválidas.',default=>'Soporte temporalmente no disponible.'}]);
        }
    }
    public function receive():mixed{
        return $this->output(function(){
            $body=Router::$request->body;
            $chat=filter_var($body->chat_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $message=filter_var($body->message_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $agent=filter_var(Router::$request->params->agent_id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$chat||!$message||!$agent)throw new \RuntimeException('invalid_reference',400);
            $secret=(string)($_SERVER['HTTP_X_N8N_SECRET']??'');
            $job=$this->service()->receive($agent,$chat,$message,$secret,time());
            $delivery=\App\Services\Enterprise\SupportRuntime::delivery(Database::getInstance()->getConnection());
            $result=$delivery->automatic($agent,$job['id'],$secret,time());
            try{$delivery->flush(\Closure::fromCallable([\App\Services\Enterprise\SupportRuntime::class,'broadcast']));}
            catch(\Throwable $e){/* Durable notice remains pending for worker. */}
            return ['job'=>$job,'delivery'=>$result];
        });
    }
    public function inbox():mixed{
        return $this->output(fn()=>['jobs'=>$this->service()->inbox((int)(Router::$request->user->id??0),(int)(Router::$request->params->agent_id??0))]);
    }
    public function settings():mixed{
        return $this->output(fn()=>['settings'=>$this->service()->settings((int)(Router::$request->user->id??0),(int)(Router::$request->params->agent_id??0))]);
    }
    public function updateSettings():mixed{
        return $this->output(function(){
            $body=Router::$request->body;
            if(!is_bool($body->enabled??null)||!is_int($body->daily_limit??null)||!is_bool($body->auto_delivery??null))throw new \RuntimeException('invalid_settings',400);
            return ['settings'=>$this->service()->updateSettings((int)(Router::$request->user->id??0),(int)(Router::$request->params->agent_id??0),$body->enabled,$body->daily_limit,$body->auto_delivery)];
        });
    }
}
