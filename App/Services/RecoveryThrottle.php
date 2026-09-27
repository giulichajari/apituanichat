<?php
namespace App\Services;
final class RecoveryThrottle
{
    public static function reserve(string $action,string $identity,string $ip): int
    {
        if (!in_array($action,['recovery','reset','pin'],true)) throw new \InvalidArgumentException('Action');
        $ip=filter_var($ip,FILTER_VALIDATE_IP) ? bin2hex(inet_pton($ip)) : 'unknown';
        $prefix='tuani:security:v1:'.$action.':';
        $keys=[$prefix.'ip:'.hash_hmac('sha256',$ip,JwtSecret::get()),$prefix.'account:'.hash_hmac('sha256',strtolower(trim($identity)),JwtSecret::get())];
        $r=LoginThrottle::connect();
        try {
            $result=$r->eval(LoginThrottle::SCRIPT,[...$keys,60,5,900],2);
            if(!is_int($result)||$result<0)throw new \RuntimeException('Limiter unavailable');
            return $result;
        } finally { $r->close(); }
    }
}
