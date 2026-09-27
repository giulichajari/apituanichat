<?php
namespace App\Services;

final class LoginThrottle
{
    // Both budgets are reserved in one Redis operation; rejected requests do not extend TTL.
    public const SCRIPT = <<<'LUA'
local wait = 0
for i = 1, #KEYS do
  if tonumber(redis.call('GET', KEYS[i]) or '0') >= tonumber(ARGV[i]) then
    wait = math.max(wait, redis.call('TTL', KEYS[i]), 1)
  end
end
if wait > 0 then return wait end
for i = 1, #KEYS do
  local count = redis.call('INCR', KEYS[i])
  if count == 1 then redis.call('EXPIRE', KEYS[i], tonumber(ARGV[3])) end
end
return 0
LUA;

    public static function connect(): \Redis
    {
        $redis = new \Redis();
        if (!$redis->connect('127.0.0.1', 6379, 1.0)) throw new \RuntimeException('Login limiter unavailable');
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
        return $redis;
    }

    public static function reserve(string $email, string $ip): int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = 'unknown';
        else $ip = bin2hex(inet_pton($ip));
        $secret = JwtSecret::get();
        $keys = [
            'tuani:login:v1:ip:'.hash_hmac('sha256', $ip, $secret),
            'tuani:login:v1:account:'.hash_hmac('sha256', strtolower(trim($email)), $secret),
        ];
        $redis = self::connect();
        try {
            $result = $redis->eval(self::SCRIPT, [...$keys, 60, 20, 900], 2);
            if (!is_int($result) || $result < 0) throw new \RuntimeException('Invalid limiter response');
            return $result;
        } finally { $redis->close(); }
    }
}
