<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
final class SupportCompanyBinding {
 public function __construct(private PDO $db){}
 public function resolve(int $agent,int $chat,int $owner,int $actor,int $message):?array {
  $core=new Core($this->db);
  $b=$core->query('SELECT * FROM support_company_bindings WHERE chat_agent_id=?',[$agent])->fetch(PDO::FETCH_ASSOC);
  if(!$b)return null;
  $client=null;
  if((int)$b['chat_id']!==$chat){
   $client=$core->query('SELECT * FROM support_company_clients WHERE chat_id=? AND tenant_id=? AND agent_id=? AND user_id=? AND enabled=1',[$chat,$b['tenant_id'],$agent,$actor])->fetch(PDO::FETCH_ASSOC);
   if(!$client||$actor===(int)$client['bot_id']||$message<=(int)$client['start_id'])throw new RuntimeException('client_unavailable',403);
   $members=array_map('intval',$core->query('SELECT user_id FROM chat_usuarios WHERE chat_id=? ORDER BY user_id',[$chat])->fetchAll(PDO::FETCH_COLUMN));$want=[$actor,(int)$client['bot_id']];sort($want);
   if($members!==$want)throw new RuntimeException('private_chat_required',403);
  }
  if(!(int)$b['enabled']||(int)$b['owner_id']!==$owner||(!$client&&($actor!==$owner||$message<=(int)$b['start_id'])))throw new RuntimeException('company_pilot_unavailable',403);
  $tenant=(int)$b['tenant_id'];if($this->db->inTransaction())$core->lock($tenant);if($core->authorize($tenant,$owner)!=='owner')throw new RuntimeException('company_owner_required',403);
  $a=$core->query("SELECT a.name,a.instructions,t.name AS company FROM ai_agents a JOIN ai_tenants t ON t.id=a.tenant_id WHERE a.id=? AND a.tenant_id=? AND a.module='support'",[$b['enterprise_agent_id'],$tenant])->fetch(PDO::FETCH_ASSOC);
  if(!$a||strlen($a['instructions'])>3000)throw new RuntimeException('company_agent_unavailable',403);
  $profile=(new CompanyProfile($core))->read($tenant,$owner);
  if(trim($profile['fields']['services'])==='')throw new RuntimeException('company_profile_required',403);
  $data=['company'=>$a['company'],'agent'=>$a['name'],'agent-configuration'=>$a['instructions'],'company-profile'=>$profile['fields'],'scope'=>$client?'Atención al cliente de esta empresa. No ejecuta acciones externas.':'Atención de esta empresa. Piloto de pruebas solo con su propietario. No dispone de herramientas para ejecutar acciones ni contactar operadores.'];
  return ['client'=>(bool)$client,'bot_id'=>$client?(int)$client['bot_id']:null,'tenant_id'=>$tenant,'agent_id'=>(int)$b['enterprise_agent_id'],'digest'=>hash('sha256',Core::json($client?[$b,$a,$profile,$client]:[$b,$a,$profile],14000)),'knowledge'=>[['id'=>'scope-approved','text'=>Core::json($data,8000)]]];
 }
}
