<?php
namespace App\Controllers;
use App\Configs\Database;
use App\Models\MonetizacionModel;
use App\Services\MonetizacionException;
use App\Services\MonetizacionInput;
use App\Services\MonetizacionEarnings;
use EasyProjects\SimpleRouter\Router;
final class MonetizacionController
{
    private function userId(): int { return (int)(Router::$request->user->id??0); }
    private function requireUser(): bool {if($this->userId()>0)return true;Router::$response->status(401)->json(['error'=>'auth.required']);return false;}
    private function requireAdmin(): bool {if(!$this->requireUser())return false;if(strtoupper((string)(Router::$request->user->rol??''))==='ADMIN')return true;Router::$response->status(403)->json(['error'=>'auth.adminRequired']);return false;}
    private function requireVerified(): bool {if(!$this->requireUser())return false;if(!empty(Router::$request->user->is_verified??null))return true;Router::$response->status(403)->json(['error'=>'auth.verificationRequired']);return false;}
    private function id(string $name): int {$id=filter_var(Router::$request->params->{$name}??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new \InvalidArgumentException('monetizacion.errors.id');return $id;}
    private function handle(callable $work,int $status=200): void
    {
        header('Cache-Control: private, no-store');
        try{$data=$work();Router::$response->status(!empty($data['replayed'])?200:$status)->json(['success'=>true,'data'=>$data]);}
        catch(MonetizacionException $e){Router::$response->status($e->status)->json(['error'=>$e->reason]);}
        catch(\InvalidArgumentException $e){$key=str_starts_with($e->getMessage(),'monetizacion.errors.')?$e->getMessage():'monetizacion.errors.invalid';Router::$response->status(422)->json(['error'=>$key]);}
        catch(\Throwable $e){error_log('Monetizacion: '.get_class($e));Router::$response->status(503)->json(['error'=>'monetizacion.errors.unavailable']);}
    }
    public function crearSolicitud(): void {if(!$this->requireVerified())return;$this->handle(fn()=>(new MonetizacionModel())->create($this->id('idGroup'),$this->userId(),(array)(Router::$request->body??[])),201);}
    public function getSolicitud(): void {if(!$this->requireUser())return;$this->handle(fn()=>(new MonetizacionModel())->getForCreator($this->id('idGroup'),$this->userId()));}
    public function getSolicitudesAdmin(): void {if(!$this->requireAdmin())return;$this->handle(function(){$page=filter_var(Router::$request->query->page??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);if(!$page)throw new \InvalidArgumentException('monetizacion.errors.page');return ['solicitudes'=>(new MonetizacionModel())->getPendientes($page),'page'=>$page,'per_page'=>25];});}
    public function revisarSolicitud(): void {if(!$this->requireAdmin())return;$this->handle(function(){$d=MonetizacionInput::review((array)(Router::$request->body??[]));$m=new MonetizacionModel();return $d['estado']==='aprobado'?$m->aprobar($this->id('id'),$this->userId()):$m->rechazar($this->id('id'),$this->userId(),$d['motivo_rechazo']);});}
    public function acreditarGanancias(): void {if(!$this->requireAdmin())return;$this->handle(fn()=>(new MonetizacionEarnings(Database::getInstance()->getConnection()))->credit($this->id('id'),$this->userId(),(array)(Router::$request->body??[])),201);}
}
