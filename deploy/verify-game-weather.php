<?php
require $argv[1];
date_default_timezone_set('Asia/Seoul');
$key = kmaServiceKey();
if (!$key) { fwrite(STDERR,"KEY_MISSING\n"); exit(1); }
echo "KEY_CONFIGURED\n";
$ch = curl_init('https://www.koreabaseball.com/ws/Main.asmx/GetKboGameList');
curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['leId'=>1,'srId'=>'0,1,3,4,5,7,9','date'=>date('Ymd')]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Referer: https://www.koreabaseball.com/','Origin: https://www.koreabaseball.com']]);
$games=json_decode(curl_exec($ch),true)['game'] ?? []; curl_close($ch);
$game=null;
foreach ($games as $candidate) if ((string)$candidate['GAME_STATE_SC']==='1' && kmaStadiumGrid($candidate['S_NM'])) { $game=$candidate; break; }
if (!$game) { echo "NO_UPCOMING_GAME_TODAY\n"; exit(2); }
echo json_encode(array_intersect_key($game,array_flip(['G_ID','G_DT','G_TM','S_NM'])),JSON_UNESCAPED_UNICODE)."\n";
$calls=0;
$transport=function($params) use (&$calls) {
    $calls++;
    $ch=curl_init('https://apis.data.go.kr/1360000/VilageFcstInfoService_2.0/getVilageFcst?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    $raw=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $errno=curl_errno($ch); curl_close($ch);
    $data=json_decode($raw ?: '',true);
    echo json_encode(['http'=>$http,'curlErrorCode'=>$errno,'base'=>$params['base_date'].$params['base_time'],'resultCode'=>$data['response']['header']['resultCode'] ?? null,'itemCount'=>count($data['response']['body']['items']['item'] ?? [])])."\n";
    return $data;
};
$dir=sys_get_temp_dir().'/wesiper-kma-verify-'.bin2hex(random_bytes(6));
$weather=(new KmaGameWeather($key,$dir,null,$transport))->forGame($game);
echo json_encode(['weather'=>$weather,'requests'=>$calls],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
if (!$weather) exit(3);
$again=(new KmaGameWeather($key,$dir,null,$transport))->forGame($game);
if ($again!=$weather) exit(4); // JSON may decode whole-number floats as integers.
echo "SHARED_CACHE_PASS requests=$calls\n";
