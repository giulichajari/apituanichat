<?php
namespace App\Controllers;

use App\Configs\Database;
use App\Models\AnunciosModel;
use App\Services\AnuncioCheckout;
use App\Services\AnuncioImage;
use App\Services\AnuncioPurchaseException;
use EasyProjects\SimpleRouter\Router;

final class AnunciosController
{
    private function requireAdmin(): bool
    {
        $u=Router::$request->user??null;
        if(!$u||empty($u->id)){Router::$response->status(401)->json(['error'=>'auth.required']);return false;}
        if(strtoupper((string)($u->rol??''))!=='ADMIN'){Router::$response->status(403)->json(['error'=>'auth.adminRequired']);return false;}
        return true;
    }
    public function createAnuncio(): void
    {
        $uid=(int)(Router::$request->user->id??0);
        if($uid<1){Router::$response->status(401)->json(['error'=>'auth.required']);return;}
        try{
            $multipart=str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'multipart/form-data');
            $body=$multipart?$_POST:(array)(Router::$request->body??[]);
            $key=$body['request_key']??null;
            if(!is_string($key))throw new \InvalidArgumentException('anuncios.errors.requestKey');
            $image=AnuncioImage::inspect($_FILES['imagen']??null);
            $result=(new AnuncioCheckout(Database::getInstance()->getConnection()))->purchase($uid,$key,$body,$image);
            Router::$response->status(empty($result['replayed'])?201:200)->json(['success'=>true,'data'=>$result]);
        }catch(AnuncioPurchaseException $e){
            Router::$response->status($e->httpStatus)->json(['success'=>false,'error'=>$e->reason,'insufficientBalance'=>$e->httpStatus===402]);
        }catch(\InvalidArgumentException $e){
            $key=str_starts_with($e->getMessage(),'anuncios.errors.')?$e->getMessage():'anuncios.errors.invalidRequest';
            Router::$response->status(422)->json(['success'=>false,'error'=>$key]);
        }catch(\Throwable $e){error_log('Anuncios create failed: '.get_class($e));Router::$response->status(503)->json(['success'=>false,'error'=>'anuncios.errors.unavailable']);}
    }
    public function getAnunciosAdmin(): void
    {
        if(!$this->requireAdmin())return;
        $page=filter_var(Router::$request->query->page??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if(!$page){Router::$response->status(422)->json(['error'=>'anuncios.errors.page']);return;}
        try {
            $rows=(new AnunciosModel())->getAllForAdmin($page,25);
            foreach($rows as &$row){$row['imagen_url']=$row['imagen_url']?'/apituanichat/admin/anuncios/'.$row['id'].'/imagen':null;$row['share_url']='https://tuanichat.com/anuncio/'.$row['link_compartir'];}unset($row);
            Router::$response->status(200)->json(['data'=>$rows,'page'=>$page,'per_page'=>25]);
        }catch(\Throwable $e){Router::$response->status(503)->json(['error'=>'anuncios.errors.unavailable']);}
    }
    public function marcarPublicado(): void
    {
        if(!$this->requireAdmin())return;
        $id=filter_var(Router::$request->params->id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id){Router::$response->status(422)->json(['error'=>'anuncios.errors.id']);return;}
        try{
            $ok=(new AnunciosModel())->marcarPublicado($id);
            Router::$response->status($ok?200:404)->json($ok?['success'=>true,'estado'=>'publicado']:['error'=>'anuncios.errors.notFound']);
        }catch(\Throwable $e){Router::$response->status(503)->json(['error'=>'anuncios.errors.unavailable']);}
    }
    public function imagenAdmin(): void
    {
        if(!$this->requireAdmin())return;
        $id=filter_var(Router::$request->params->id??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        try{
            $ad=$id?(new AnunciosModel())->find($id):null;
            if(!$ad||!$ad['imagen_url']){Router::$response->status(404)->json(['error'=>'anuncios.errors.notFound']);return;}
            $path=AnuncioImage::path($ad['imagen_url']);
            if(!is_file($path)||is_link($path))throw new \RuntimeException('Image unavailable');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true))throw new \RuntimeException('Image type invalid');
            header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Content-Length: '.filesize($path));readfile($path);
        }catch(\Throwable $e){Router::$response->status(404)->json(['error'=>'anuncios.errors.notFound']);}
    }
    public function getPublicado(): void
    {
        $slug=Router::$request->params->slug??null;
        if(!is_string($slug)||!preg_match('/^[a-f0-9]{48}$/D',$slug)){Router::$response->status(404)->json(['error'=>'anuncios.errors.notFound']);return;}
        try {
            $ad=(new AnunciosModel())->findPublished($slug);
            if(!$ad){Router::$response->status(404)->json(['error'=>'anuncios.errors.notFound']);return;}
            $ad['imagen_url']=$ad['imagen_url']?'/apituanichat/anuncios/'.$slug.'/imagen':null;
            unset($ad['id']);
            header('Cache-Control: no-store');
            Router::$response->status(200)->json(['data'=>$ad]);
        }catch(\Throwable $e){Router::$response->status(503)->json(['error'=>'anuncios.errors.unavailable']);}
    }
    public function imagenPublicada(): void
    {
        $slug=Router::$request->params->slug??null;
        try {
            $ad=is_string($slug)&&preg_match('/^[a-f0-9]{48}$/D',$slug)?(new AnunciosModel())->findPublished($slug):null;
            if(!$ad||!$ad['imagen_url']){Router::$response->status(404)->json(['error'=>'anuncios.errors.notFound']);return;}
            $path=AnuncioImage::path($ad['imagen_url']);
            if(!is_file($path)||is_link($path))throw new \RuntimeException('Image unavailable');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if(!in_array($mime,['image/jpeg','image/png','image/gif','image/webp'],true))throw new \RuntimeException('Image invalid');
            header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');header('Content-Length: '.filesize($path));readfile($path);
        }catch(\Throwable $e){Router::$response->status(404)->json(['error'=>'anuncios.errors.notFound']);}
    }
    public function paises(): void
    {
        try{$rows=Database::getInstance()->getConnection()->query('SELECT code,name FROM countries WHERE is_active=1 ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);Router::$response->status(200)->json(['data'=>$rows]);}
        catch(\Throwable $e){Router::$response->status(503)->json(['error'=>'anuncios.errors.unavailable']);}
    }
}
