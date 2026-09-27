<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use PDO;
use RuntimeException;
use InvalidArgumentException;
final class CompanyProfile {
 public const FIELDS=['services','hours','prices','contact','policies'];
 public function __construct(private Core $core){}
 public function read(int $tenant,int $actor):array {
  $this->core->authorize($tenant,$actor);
  $r=$this->core->query('SELECT data_json,version,updated_at FROM ai_company_profiles WHERE tenant_id=?',[$tenant])->fetch(PDO::FETCH_ASSOC);
  return ['fields'=>$r?json_decode($r['data_json'],true,16,JSON_THROW_ON_ERROR):array_fill_keys(self::FIELDS,''),'version'=>$r?(int)$r['version']:0,'updated_at'=>$r?(int)$r['updated_at']:null];
 }
 public function save(int $tenant,int $actor,array $fields,int $version,int $now):array {
  if($version<0||count($fields)!==count(self::FIELDS)||array_diff(array_keys($fields),self::FIELDS))throw new InvalidArgumentException('Ficha inválida');
  foreach(self::FIELDS as $k){if(!is_string($fields[$k])||strlen($fields[$k])>1800||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$fields[$k]))throw new InvalidArgumentException('Campo inválido');$fields[$k]=trim($fields[$k]);}
  $json=Core::json($fields,4000);
  return $this->core->transaction(function()use($tenant,$actor,$fields,$version,$now,$json){
   $this->core->lock($tenant);$this->core->authorize($tenant,$actor,'configure');$old=$this->read($tenant,$actor);
   if($old['version']!==$version)throw new RuntimeException('Ficha modificada',409);
   if($version===0)$this->core->query('INSERT INTO ai_company_profiles(tenant_id,data_json,version,updated_at) VALUES(?,?,1,?)',[$tenant,$json,$now]);
   else $this->core->query('UPDATE ai_company_profiles SET data_json=?,version=version+1,updated_at=? WHERE tenant_id=?',[$json,$now,$tenant]);
   $this->core->audit($tenant,$actor,'company.profile_saved',(string)$tenant,['version'=>$version+1],$now);
   return $this->read($tenant,$actor);
  });
 }
}
