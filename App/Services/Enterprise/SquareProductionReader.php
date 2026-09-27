<?php
declare(strict_types=1);
namespace App\Services\Enterprise;
use RuntimeException;
use Closure;
final class SquareProductionReader
{
    public function __construct(private string $token) {
        if ($token === '' || preg_match('/[\r\n]/', $token)) throw new RuntimeException('production_key_missing');
    }
    public function request(string $method, string $path, ?array $payload = null): array {
        self::allowed($method,$path);
        $body = '';
        $ch = curl_init('https://connect.squareup.com'.$path);
        $opts = [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>40, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->token, 'Square-Version: 2025-03-19', 'Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION=>static function($c, string $part) use (&$body): int {
                if (strlen($body)+strlen($part)>2097152) return 0;
                $body.=$part; return strlen($part);
            }];
        if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        curl_setopt_array($ch, $opts); curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error=curl_errno($ch); curl_close($ch);
        if ($error) throw new RuntimeException('square_network_'.$error);
        $data=json_decode($body,true);
        if ($http<200 || $http>=300 || !is_array($data) || !empty($data['errors'])) {
            $code=$data['errors'][0]['code']??'';
            $safe=in_array($code,['UNAUTHORIZED','FORBIDDEN','BAD_REQUEST','INVALID_VALUE','NOT_FOUND','RATE_LIMITED','INSUFFICIENT_SCOPES','CARD_DECLINED','IDEMPOTENCY_KEY_REUSED'],true)?$code:'provider_error';
            throw new RuntimeException('square_http_'.$http.'_'.$safe);
        }
        return $data;
    }
    public static function validLocation(array $item, string $id): bool {
        return ($item['id']??'')===$id && ($item['status']??'')==='ACTIVE' && ($item['currency']??'')==='USD'
            && ($item['country']??'')==='US' && in_array('CREDIT_CARD_PROCESSING',(array)($item['capabilities']??[]),true);
    }
    public static function allowed(string $method,string $path):void {
        $get=$method==='GET'&&preg_match('~^/v2/(?:locations|catalog/object/[A-Za-z0-9_-]+|orders/[A-Za-z0-9_-]+|payments/[A-Za-z0-9_-]+|invoices/[A-Za-z0-9_.:-]+|subscriptions/[A-Za-z0-9_-]+)$~D',$path);
        if(!$get&&!($method==='POST'&&$path==='/v2/subscriptions/search'))throw new RuntimeException('read_only_request_required');
    }
}
