<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use InvalidArgumentException;

/** Support scope only: these profiles grant no access to product records or actions. */
final class SupportCatalog
{
    public static function profile(string $service): array
    {
        $profiles = [
            'general'=>['Atención general',['Identificar el servicio y el problema principal.']],
            'account'=>['Cuenta y acceso',['Solicitar el mensaje de error y el paso donde ocurre, sin pedir contraseñas, códigos de acceso ni documentos.']],
            'chats'=>['Chats, mensajes y llamadas',['Distinguir fallo con la app abierta de notificaciones con la app cerrada.','Solicitar plataforma, estado Conectado/Desconectado y pasos del fallo.']],
            'eats'=>['Eats',['Identificar si la consulta trata de un pedido, entrega o cobro.','Pedir referencia del pedido si el usuario la tiene; no pedir datos completos de pago.']],
            'shop'=>['Shop',['Identificar consulta de producto, compra, entrega o devolución.','Pedir referencia de la compra cuando sea necesaria.']],
            'ride'=>['Ride',['Distinguir solicitud de viaje, viaje en curso, cobro o incidencia.','Pedir referencia del viaje; evitar solicitar ubicación exacta salvo que una herramienta autorizada la necesite.']],
            'wallet'=>['Wallet',['Distinguir saldo, movimiento, cobro o retiro.','Pedir referencia de la operación; nunca PIN, códigos de verificación ni datos completos de tarjetas.']],
        ];
        if (!isset($profiles[$service])) throw new InvalidArgumentException('Servicio de soporte desconocido');
        [$name,$intake]=$profiles[$service];
        return [
            'service'=>$service,'name'=>$name,'intake'=>$intake,
            'execution_policy'=>[
                'Las referencias aportadas por el cliente no demuestran propiedad. El backend debe comprobar acceso antes de consultar registros.',
                'No inventar horarios, tarifas, plazos, políticas de devolución ni estados de pedidos o saldos.',
                'Si falta una fuente empresarial aprobada o una consulta autorizada, aclarar la limitación y proponer atención humana.',
                'Reembolsos, cancelaciones, cambios de saldo, retiros, bloqueos y modificaciones de cuentas no están habilitados en este contrato.',
                'No afirmar que existe un ticket, transferencia o contacto con un operador sin confirmación del backend.',
            ],
            'enabled_actions'=>[],
        ];
    }
}
