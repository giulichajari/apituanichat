<?php
namespace App\Routers;
use App\Controllers\AnunciosController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;

final class AnunciosRouter
{
    public function __construct(?Router $router,?TokenMiddleware $auth=new TokenMiddleware())
    {
        $router->post('/anuncios',fn()=>$auth->strict(),fn()=>(new AnunciosController())->createAnuncio());
        $router->get('/anuncios/paises',fn()=>$auth->strict(),fn()=>(new AnunciosController())->paises());
        $router->get('/anuncios/{slug}/imagen',fn()=>(new AnunciosController())->imagenPublicada());
        $router->get('/anuncios/{slug}',fn()=>(new AnunciosController())->getPublicado());
        $router->get('/admin/anuncios',fn()=>$auth->strict(),fn()=>(new AnunciosController())->getAnunciosAdmin());
        $router->patch('/admin/anuncios/{id}/publicado',fn()=>$auth->strict(),fn()=>(new AnunciosController())->marcarPublicado());
        $router->get('/admin/anuncios/{id}/imagen',fn()=>$auth->strict(),fn()=>(new AnunciosController())->imagenAdmin());
    }
}
