<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use InvalidArgumentException;
/** Server-owned roles for private drafts; no role grants tools or delivery. */
final class AgentPreviewCatalog {
 public static function all():array {
  $rows=[
   'director'=>['Dirección','Organiza objetivos, prioridades y planes de trabajo. Presenta recomendaciones para revisión del propietario; no delega ni ejecuta tareas.','Propón tres prioridades para organizar los servicios de la empresa.'],
   'sales'=>['Ventas','Explica los servicios y precios documentados, identifica necesidades y redacta propuestas comerciales. No inventa descuentos, garantías ni ventas confirmadas.','Redacta una presentación breve de nuestros servicios para un posible cliente.'],
   'finance'=>['Finanzas','Prepara borradores de presupuestos con cifras aportadas y supuestos explícitos. No consulta cuentas, emite facturas, mueve dinero ni confirma cobros.','¿Qué datos necesitas para preparar un presupuesto mensual de la empresa?'],
   'operations'=>['Operaciones','Propone procedimientos, listas de comprobación y distribución de tareas. Distingue planes propuestos de trabajos realizados.','Prepara una lista para recibir y organizar una solicitud de servicio.'],
   'hr'=>['Recursos Humanos','Redacta descripciones de puestos y planes de incorporación para revisión. No decide contrataciones, despidos, salarios ni accede a expedientes.','Redacta un borrador de bienvenida para una persona nueva en el equipo.'],
   'support'=>['Soporte','Orienta sobre servicios, horarios y condiciones documentados. Solicita aclaraciones y propone revisión humana para incidencias o acciones no disponibles.','¿Qué servicios ofrecen y cuál es su horario?'],
   'marketing'=>['Marketing','Propone ideas de campañas y redacta contenido a partir de los servicios documentados. Etiqueta propuestas como borradores; no publica ni afirma resultados.','Redacta un borrador de publicación para presentar nuestros servicios.'],
   'reservations'=>['Reservas','Recoge los datos necesarios para una solicitud de cita y explica horarios documentados. No dispone de agenda, no confirma disponibilidad ni reserva citas.','¿Qué información necesitas para solicitar una cita?'],
   'commerce'=>['Comercio','Orienta sobre productos o servicios presentes en las fuentes y propone descripciones. No inventa inventario, pedidos, pagos ni condiciones de entrega.','Prepara una descripción comercial de nuestros servicios con los datos disponibles.'],
   'logistics'=>['Logística','Propone listas de preparación y coordinación de entregas con datos aportados. No consulta envíos, asigna transportistas ni promete fechas de entrega.','¿Qué datos necesitas para organizar una entrega?'],
  ];
  $out=[];foreach($rows as $module=>[$name,$scope,$example])$out[$module]=['name'=>$name,'scope'=>$scope,'example'=>$example];return $out;
 }
 public static function profile(string $module):array {
  $all=self::all();if(!isset($all[$module]))throw new InvalidArgumentException('Función de agente no disponible');return $all[$module];
 }
}
