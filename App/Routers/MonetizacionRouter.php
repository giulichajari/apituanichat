<?php
namespace App\Routers;
use App\Controllers\MonetizacionController;
use App\Middlewares\TokenMiddleware;
use EasyProjects\SimpleRouter\Router;
final class MonetizacionRouter
{
    public function __construct(Router $router)
    {
        $auth=new TokenMiddleware();
        $router->post('/group/{idGroup}/monetizacion',fn()=>$auth->strict(),fn()=>(new MonetizacionController())->crearSolicitud());
        $router->get('/group/{idGroup}/monetizacion',fn()=>$auth->strict(),fn()=>(new MonetizacionController())->getSolicitud());
        $router->get('/admin/monetizacion',fn()=>$auth->strict(),fn()=>(new MonetizacionController())->getSolicitudesAdmin());
        $router->patch('/admin/monetizacion/{id}',fn()=>$auth->strict(),fn()=>(new MonetizacionController())->revisarSolicitud());
        $router->post('/admin/monetizacion/{id}/ganancias',fn()=>$auth->strict(),fn()=>(new MonetizacionController())->acreditarGanancias());
    }
}
