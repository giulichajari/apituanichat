<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: no-store');
try {
    require '/opt/tuanichat-voz-turnos/bootstrap.php';
    $method=$_SERVER['REQUEST_METHOD']??'';
    $event=$method==='POST'?$_POST:$_GET;

    // VOICE_AUTH_CHECK_V1
    try {
        $sig=$_SERVER['HTTP_X_TWILIO_SIGNATURE']??'';
        $q=$_SERVER['QUERY_STRING']??'';
        $url=$voiceConfig['url'].($q!==''?'?'.$q:'');
        $valid=false;
        try {
            $expected=\TuaniVoice\Pilot::signature($url,$method==='POST'?$_POST:[],$voiceConfig['auth_token']);
            $valid=$sig!=='' && hash_equals($expected,$sig);
        } catch(\Throwable $ignored) {}
        $check=[
            'fecha_utc'=>gmdate('c'),
            'firma_presente'=>$sig!=='',
            'firma_valida'=>$valid,
            'url_correcta'=>$voiceConfig['url']==='https://tuanichat.com/apituanichat/voz-turnos',
            'cuenta_coincide'=>($event['AccountSid']??'')===$voiceConfig['account'],
            'origen_coincide'=>($event['From']??'')===$voiceConfig['from'],
            'destino_coincide'=>($event['To']??'')===$voiceConfig['to'],
            'id_llamada_valido'=>is_string($event['CallSid']??null) && preg_match('/^CA[a-f0-9]{32}$/D',$event['CallSid'])===1
        ];
        if($method==='POST')file_put_contents('/var/lib/tuanichat-voz-turnos/auth-check.json',json_encode($check,JSON_PRETTY_PRINT),LOCK_EX);
    } catch(\Throwable $ignored) {}

    if((int)($_SERVER['CONTENT_LENGTH']??0)>16000 || !$voicePilot->authenticate($method,$_SERVER['QUERY_STRING']??'',$_POST,$event,$_SERVER['HTTP_X_TWILIO_SIGNATURE']??'')) {
        http_response_code(403);echo '<Response><Hangup/></Response>';exit;
    }
    $step=$_GET['step']??'start';$turn=$_GET['turn']??'1';$poll=$_GET['poll']??'0';
    if(!is_string($step)||!is_string($turn)||!is_string($poll)||!ctype_digit($turn)||!ctype_digit($poll))throw new RuntimeException();
    echo $voicePilot->handle($event,$step,(int)$turn,(int)$poll,time());
} catch(Throwable $e) {
    http_response_code(503);echo '<Response><Hangup/></Response>';
}
