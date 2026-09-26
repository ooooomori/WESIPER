<?php
// KMA credentials are only read by PHP; no upstream URLs or keys are returned.
function kmaServiceKey(): string {
    $key = getenv('KMA_SERVICE_KEY');
    if ($key) return trim($key);
    $path = getenv('WESIPER_WEATHER_CONFIG') ?: '/opt/bitnami/apache/conf/wesiper-weather.php';
    if (!is_readable($path)) return '';
    $config = require $path;
    return is_array($config) ? trim((string)($config['KMA_SERVICE_KEY'] ?? '')) : '';
}

function kmaStadiumGrid(string $stadium): ?array {
    // Observed names: local 2026 schedule + official September Futures schedule.
    // All observed names match; do not guess aliases for ambiguous stadiums.
    $grid = [
        '잠실'=>[62,127], '고척'=>[59,126], '문학'=>[56,125], '수원'=>[61,122],
        '대전'=>[69,101], '대구'=>[91,91], '광주'=>[60,76], '창원'=>[90,77], '사직'=>[99,77],
        '고양'=>[57,130], '강화'=>[52,129], '이천(LG)'=>[70,120], '이천(두산)'=>[70,122],
        '서산'=>[52,112], '문경'=>[82,107], '상동'=>[94,79], '함평'=>[54,74],
        '익산'=>[61,93], '경산'=>[94,91], '마산'=>[90,77], '울산'=>[102,85],
        '포항'=>[103,95], '청주'=>[70,108],
    ];
    return $grid[trim($stadium)] ?? null;
}

function kmaForecastBases(DateTimeImmutable $now): array {
    $now = $now->setTimezone(new DateTimeZone('Asia/Seoul'));
    $bases = [];
    for ($day=0; $day<2; $day++) {
        foreach ([23,20,17,14,11,8,5,2] as $hour) {
            $base = $now->modify("-$day days")->setTime($hour,0);
            if ($base <= $now) $bases[] = $base;
        }
    }
    return array_slice($bases,0,2);
}

function kmaParseForecast(array $items, string $stadium, string $date, string $hour, DateTimeImmutable $base): ?array {
    $values = [];
    foreach ($items as $item) {
        if (($item['fcstDate'] ?? '') === $date && ($item['fcstTime'] ?? '') === $hour) {
            $values[$item['category']] = $item['fcstValue'] ?? null;
        }
    }
    foreach (['TMP','POP','PCP','SKY','PTY','WSD','REH'] as $category) {
        if (!isset($values[$category])) return null;
        if ($category !== 'PCP' && !is_numeric($values[$category])) return null;
    }
    $sky = (int)$values['SKY']; $pty = (int)$values['PTY'];
    $condition = $pty !== 0 ? ([1=>'비',2=>'비/눈',3=>'눈',4=>'소나기'][$pty] ?? null)
        : ([1=>'맑음',3=>'구름많음',4=>'흐림'][$sky] ?? null);
    if ($condition === null || $values['POP'] < 0 || $values['POP'] > 100 || $values['REH'] < 0 || $values['REH'] > 100 || $values['WSD'] < 0) return null;
    return ['stadium'=>$stadium, 'forecastTime'=>$date . 'T' . $hour,
        'forecastBaseTime'=>$base->format('Ymd\THi'), 'temperature'=>(float)$values['TMP'],
        'precipitationProbability'=>(int)$values['POP'], 'precipitation'=>(string)$values['PCP'],
        'sky'=>$sky, 'precipitationType'=>$pty, 'windSpeed'=>(float)$values['WSD'],
        'humidity'=>(int)$values['REH'], 'condition'=>$condition, 'source'=>'기상청 단기예보'];
}

class KmaGameWeather {
    private $key;
    private $directory;
    private $now;
    private $transport;
    private $memory = [];
    private $deadline;

    public function __construct(string $key, ?string $directory=null, ?DateTimeImmutable $now=null, ?callable $transport=null) {
        $this->key = $key;
        $this->directory = $directory ?? (getenv('WESIPER_WEATHER_CACHE_DIR') ?: sys_get_temp_dir().'/wesiper-kma-'.(function_exists('posix_geteuid') ? posix_geteuid() : 'php'));
        $this->now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Asia/Seoul'));
        $this->transport = $transport;
        $this->deadline = microtime(true)+10; // Weather never monopolizes the game request.
    }

    private function cached(string $key, callable $load) {
        if (array_key_exists($key,$this->memory)) return $this->memory[$key];
        if (!is_dir($this->directory) && !@mkdir($this->directory,0700,true) && !is_dir($this->directory)) return null;
        $path = $this->directory.'/'.hash('sha256',$key).'.json';
        $read = function () use ($path) {
            $entry = is_file($path) ? json_decode(file_get_contents($path),true) : null;
            return is_array($entry) && ($entry['expires'] ?? 0)>$this->now->getTimestamp() ? $entry : null;
        };
        if (($entry=$read()) !== null) return $this->memory[$key]=$entry['value'];
        $lock = @fopen($path.'.lock','c');
        if (!$lock) return null;
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); return null; }
        try {
            if (($entry=$read()) !== null) return $this->memory[$key]=$entry['value'];
            $value = $load();
            $entry = ['expires'=>$this->now->getTimestamp()+($value === null ? 60 : 1800),'value'=>$value];
            $temp = tempnam($this->directory,'kma-');
            if ($temp !== false) {
                try {
                    if (file_put_contents($temp,json_encode($entry,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)) !== false) rename($temp,$path);
                } finally { if (is_file($temp)) unlink($temp); }
            }
            return $this->memory[$key]=$value;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private function request(array $grid, DateTimeImmutable $base): ?array {
        if (microtime(true)>=$this->deadline) return null;
        $params = ['serviceKey'=>rawurldecode($this->key),'dataType'=>'JSON','pageNo'=>1,'numOfRows'=>2000,
            'base_date'=>$base->format('Ymd'),'base_time'=>$base->format('Hi'),'nx'=>$grid[0],'ny'=>$grid[1]];
        if ($this->transport) $data = ($this->transport)($params);
        else {
            $ch = curl_init('https://apis.data.go.kr/1360000/VilageFcstInfoService_2.0/getVilageFcst?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>1500,
                CURLOPT_TIMEOUT_MS=>max(1,min(3500,(int)(($this->deadline-microtime(true))*1000)))]);
            $raw = curl_exec($ch); $status = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            if ($raw === false || $status !== 200) return null;
            $data = json_decode($raw,true);
        }
        if (($data['response']['header']['resultCode'] ?? '') !== '00') return null;
        $body = $data['response']['body'] ?? [];
        $items = $body['items']['item'] ?? null;
        // Never silently use a truncated page.
        if (!is_array($items) || !$items || (int)($body['totalCount'] ?? count($items))>count($items)) return null;
        return $items;
    }

    public function forGame(array $game): ?array {
        if (!$this->key || (string)($game['GAME_STATE_SC'] ?? '') !== '1') return null;
        $stadium = trim((string)($game['S_NM'] ?? ''));
        $grid = kmaStadiumGrid($stadium);
        $date = str_replace('-','',(string)($game['G_DT'] ?? ''));
        $start = (string)($game['G_TM'] ?? '');
        if (!$grid || $date !== $this->now->format('Ymd') || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$start)) return null;
        $kickoff = DateTimeImmutable::createFromFormat('!Ymd H:i',$date.' '.$start,new DateTimeZone('Asia/Seoul'));
        if (!$kickoff || $kickoff <= $this->now) return null;
        $hour = substr($start,0,2).'00';
        foreach (kmaForecastBases($this->now) as $base) {
            try {
                $value = $this->cached("game-v1:$stadium:$date:$start:".$base->format('YmdHi'),function () use ($grid,$base,$stadium,$date,$hour) {
                    $items = $this->cached('grid-v1:'.implode(':',$grid).':'.$base->format('YmdHi'),fn()=>$this->request($grid,$base));
                    return $items === null ? null : kmaParseForecast($items,$stadium,$date,$hour,$base);
                });
                if ($value !== null) return $value;
            } catch (Throwable $error) {
                // Do not log request URLs, service keys or upstream bodies.
                error_log('KMA forecast unavailable');
            }
        }
        return null;
    }
}
