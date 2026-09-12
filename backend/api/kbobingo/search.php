<?php
include_once 'common.php';
ini_set('display_errors', 1);
    function getPosition($posNo) {
        switch($posNo) {
            case 1:
            case "P":
                return "투수"; break;
            case 2: 
            case "C":
                return "포수"; break;
            case 3: 
            case "1B":
                return "1루수"; break;
            case 4:
            case "2B":
                return "2루수"; break;
            case 5: 
            case "3B":
                return "3루수"; break;
            case 7: 
            case "LF":
                return "좌익수"; break;
            case 8: 
            case "CF":
                return "중견수"; break;
            case 9: 
            case "RF":
                return "우익수"; break;
            case 6: 
            case "SS":
                return "유격수"; break;
            default: return "지명타자";

        }
    }

    
    function getTeamCode($code) {
        $map = [
            '삼성' => 'sam',
            '해태' => 'kia', 'KIA' => 'kia',
            '롯데' => 'lot',
            '삼미' => 'hyd', '청보' => 'hyd', '태평양' => 'hyd', '현대' => 'hyd',
            'MBC' => 'lg', 'LG' => 'lg',
            'OB' => 'doo', '두산' => 'doo',
            '빙그레' => 'han', '한화' => 'han',
            '쌍방울' => 'sbw',
            'SK' => 'ssg', 'SSG' => 'ssg',
            '우리' => 'kiw', '히어로즈' => 'kiw', '넥센' => 'kiw', '키움' => 'kiw',
            'NC' => 'nc',
            'KT' => 'kt'
        ];

        return $map[$code] ?? null;
    }

    function getPosCode($korPos) {
        $map = [
            '포수'   => 'C',
            '1루수' => '1B',
            '2루수' => '2B',
            '3루수' => '3B',
            '유격수' => 'SS',
            '좌익수' => 'LF',
            '중견수' => 'CF',
            '우익수' => 'RF',
            '지명타자' => 'DH',
            '투수'   => 'P'
        ];

        return $map[$korPos] ?? null;
}
    
    /*
    function searchTeam($career) {
        if (strpos($text, '삼성') !== false)
    }
        */

    // https://statiz.sporki.com/stats/?m=main&m2=fielding&m3=default&so=&ob=&sy=1982&ey=2024&te=&po=&lt=10100&reg=&pe=&ds=&de=&we=&hr=&ha=&ct=&st=&vp=&bo=&pt=&pp=&ii=&vc=&um=&oo=&rr=&sc=&bc=&ba=&li=&as=&ae=&pl=&gc=&lr=&pr=50&ph=10119&hs=&us=&na=&ls=0&sf1=G&sk1=&sv1=&sf2=G&sk2=&sv2=-25
    function searchYear($p_no, $p_team) {
        $snoopy = new Snoopy;
        $type = is_array($p_team) ? "main" : "total";
        $snoopy->fetch("https://statiz.sporki.com/stats/?m=$type&m2=batting&m3=default&so=WAR&ob=DESC&year=&sy=1982&ey=2024&te=&po=&lt=10100&reg=A&pe=&ds=&de=&we=&hr=&ha=&ct=&st=&vp=&bo=&pt=&pp=&ii=&vc=&um=&oo=&rr=&sc=&bc=&ba=&li=&as=&ae=&pl=&gc=&lr=&pr=50&ph=$p_no&hs=&us=&na=&ls=0&sf1=G&sk1=&sv1=&sf2=G&sk2=&sv2=-25");
        $html = $snoopy->results;

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $length_b = (int)$xpath->evaluate('count(/html/body/div[2]/div[4]/section/div[8]/table/tbody/tr)');
        $start = $type === 'total' ? 1 : 3;

        $detail = array();
        if($length_b) {
            
            for($i = $start; $i<=$length_b; $i++) {
                if($i === 13 || $i === 14 || $i === 25 || $i === 26) continue;
                for($j = 3; $j < 31; $j ++) {
                    if($j === 3 && $type === 'main') {
                        
                        $td = $xpath->query("/html/body/div[2]/div[4]/section/div[8]/table/tbody/tr[$i]/td[3]/div/span[2]/img");
                        
                        $td = $td->item(0);
                        $src = $td->getAttribute('src');
                        preg_match("/\/(\d+)\.svg$/", $src, $matches);
                        $team = getTeamCode((int)$matches[1]);

                        preg_match("/\/(\d+)\/(\d+)\.svg$/", $src, $matches);
                        $year = $matches[1];
                        if(!in_array($team, $p_team)) {
                            $p_team[] = $team;
                      }
                        $detail[$team]["year"][] = $year;
                        continue;
                    }

                    $td = $xpath->query("/html/body/div[2]/div[4]/section/div[8]/table/tbody/tr[$i]/td");
                    $td = $td->item($j-1);

                    if($j === 4) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 5) {
                                $detail[$team]["war_5_season"] = true;
                            }        
                        } else {
                            if((int)$td->nodeValue >= 30) {
                                $detail["war_30_total"] = true;
                            }
                        }
                        continue;
                    } else if($j === 8) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 446) {
                                $detail[$team]["pa_446_season"] = true;
                            }        
                        } else {
                        }
                        continue;
                    } else if($j === 12) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 150) {
                                $detail[$team]["h_150_season"] = true;
                            }        
                        } else {
                            if((int)$td->nodeValue >= 1500) {
                                $detail["h_1500_total"] = true;
                            }   
                        }
                        continue;
                    } else if($j === 13) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 30) {
                                $detail[$team]["2b_30_season"] = true;
                            }        
                        } else { 
                        }
                        continue;
                    } else if($j === 14) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 5) {
                                $detail[$team]["3b_5_season"] = true;
                            }        
                        } else {
                        }
                        continue;
                    } else if($j === 15) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 20) {
                                $detail[$team]["hr_20_season"] = true;
                            }        
                        } else {
                            if((int)$td->nodeValue >= 150) {
                                $detail["hr_150_total"] = true;
                            } 
                        }
                        continue;
                    } else if($j === 17) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 80) {
                                $detail[$team]["rbi_80_season"] = true;
                            }        
                        } else {
                            if((int)$td->nodeValue >= 800) {
                                $detail["rbi_800_total"] = true;
                            }
                        }
                        continue;
                    } else if($j === 18) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 20) {
                                $detail[$team]["sb_20_season"] = true;
                            }        
                        } else {
                            if((int)$td->nodeValue >= 150) {
                                $detail["sb_150_total"] = true;
                            }  
                        }
                        continue;
                    } else if($j === 27) {
                        if($type === 'main') {
                            if((float)$td->nodeValue >= 0.3) {
                                $detail[$team]["avg_0.300_season"] =
                                true;
                            }
                        } else {
                            if((float)$td->nodeValue >= 0.3) {
                                $detail["avg_0.300_total"] =
                                true;
                            }
                        }
                        continue;
                    } else if($j === 28) {
                        if($type === 'main') {
                            if((float)$td->nodeValue >= 0.4) {
                                $detail[$team]["obp_0.400_season"] = true;
                            }
                        } else {
                            if((float)$td->nodeValue >= 0.4) {
                                $detail["obp_0.400_total"] = true;
                            }
                        }
                        continue;
                    } else if($j === 29) {
                        if($type === 'main') {
                            if((float)$td->nodeValue >= 0.5) {
                                $detail[$team]["slg_0.500_season"] =
                                true;
                            }
                        } else {
                            if((float)$td->nodeValue >= 0.5) {
                                $detail["slg_0.500_total"] =
                                true;
                            }
                        }
                        continue;
                    } else if($j === 30) {
                        if($type === 'main') {
                            if((float)$td->nodeValue >= 0.8) {
                                $detail[$team]["ops_0.800_season"] = true;
                            }
                            if((float)$td->nodeValue >= 0.9) {
                                $detail[$team]["ops_0.900_season"] = true;
                            }
                        } else {
                            if((float)$td->nodeValue >= 0.8) {
                                $detail["ops_0.800_total"] = true;
                            }
                            if((float)$td->nodeValue >= 0.9) {
                                $detail["ops_0.900_total"] = true;
                            }
                        }
                        continue;
                    }
                }
                
            }   
        }

        $snoopy->fetch("https://statiz.sporki.com/stats/?m=$type&m2=pitching&m3=default&so=WAR&ob=DESC&year=&sy=1982&ey=2024&te=&po=&lt=10100&reg=A&pe=&ds=&de=&we=&hr=&ha=&ct=&st=&vp=&bo=&pt=&pp=&ii=&vc=&um=&oo=&rr=&sc=&bc=&ba=&li=&as=&ae=&pl=&gc=&lr=&pr=50&ph=$p_no&hs=&us=&na=&ls=0&sf1=G&sk1=&sv1=&sf2=G&sk2=&sv2=-25");
        $html = $snoopy->results;

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $length_p = (int)$xpath->evaluate('count(/html/body/div[2]/div[4]/section/div[8]/table/tbody/tr)');

        if($length_p) {
            
            for($i = $start; $i<=$length_p; $i++) {
                if($i === 13 || $i === 14 || $i === 25 || $i === 26) continue;
                for($j = 3; $j < 32; $j ++) {
                    if($j === 3 && $type === 'main') {
                        
                        $td = $xpath->query("/html/body/div[2]/div[4]/section/div[8]/table/tbody/tr[$i]/td[3]/div/span[2]/img");
                        
                        $td = $td->item(0);
                        $src = $td->getAttribute('src');
                        preg_match("/\/(\d+)\.svg$/", $src, $matches);
                        $team = getTeamCode((int)$matches[1]);

                        preg_match("/\/(\d+)\/(\d+)\.svg$/", $src, $matches);
                        $year = $matches[1];
                        if(!in_array($team, $p_team)) {
                            $p_team[] = $team;
                      }
                        $detail[$team]["year"][] = $year;
                        continue;
                    }

                    $td = $xpath->query("/html/body/div[2]/div[4]/section/div[8]/table/tbody/tr[$i]/td");
                    $td = $td->item($j-1);

                    if($td->nodeValue === '') continue;
                    if($j === 4) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 5) {
                                $detail[$team]["war_5_season"] = true;
                            }        
                        } else {
                            if((int)$td->nodeValue >= 30) {
                                $detail["war_30_total"] = true;
                            }
                        }
                        continue;
                    } else if($j === 11) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 10) {
                                $detail[$team]["win_10_season"] = true;
                            }
                            if((int)$td->nodeValue >= 15) {
                                $detail[$team]["win_15_season"] = true;
                            }
                        } else {
                            if((int)$td->nodeValue >= 100) {
                                $detail["win_100_total"] = true;
                            }
                            if((int)$td->nodeValue >= 150) {
                                $detail["win_150_total"] = true;
                            }
                        }
                        continue;
                    } else if($j === 13) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 20) {
                                $detail[$team]["sv_20_season"] = true;
                            }        
                        } else {
                            if((int)$td->nodeValue >= 100) {
                                $detail["sv_100_total"] = true;
                            }
                            if((int)$td->nodeValue >= 50) {
                                $detail["sv_50_total"] = true;
                            }  
                        }
                        continue;
                    } else if($j === 14) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 20) {
                                $detail[$team]["hld_20_season"] = true;
                            }
                            if((int)$td->nodeValue >= 10) {
                                $detail[$team]["hld_10_season"] = true;
                            }
                        } else {
                            if((int)$td->nodeValue >= 50) {
                                $detail["hld_50_total"] = true;
                            }
                        }
                        continue;
                    } else if($j === 15) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 144) {
                                $detail[$team]["ip_144_season"] = true;
                            }        
                        } else {
                        }
                        continue;
                    } else if($j === 27) {
                        if($type === 'main') {
                            if((int)$td->nodeValue >= 100) {
                                $detail[$team]["so_100_season"] = true;
                            }
                            if((int)$td->nodeValue >= 150) {
                                $detail[$team]["so_150_season"] = true;
                            }
                        } else {
                            if((int)$td->nodeValue >= 1000) {
                                $detail["so_1000_total"] = true;
                            }
                            if((int)$td->nodeValue >= 800) {
                                $detail["so_800_total"] = true;
                            }
                        }
                        continue;
                    } else if($j === 31) {
                        if($type === 'main') {
                            if((int)$td->nodeValue <= 3.0) {
                                $detail[$team]["era_3.00_season"] =
                                true;
                            }
                        } else {
                            if((int)$td->nodeValue <= 3.0) {
                                $detail["era_3.00_total"] =
                                true;
                            }
                        }
                        continue;
                    }
                }
                
            }   
        }
        
        /** 연도별 선수 페이지 - 수비 */
        if($length_b && $type === 'main') {
            $snoopy->fetch("https://statiz.sporki.com/player/?m=year&m2=fielding&m3=default&p_no=$p_no&lt=10100&gc=");
            $html = $snoopy->results;

            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            $trs = $xpath->query('/html/body/div[2]/div[4]/section/div[7]/table/tbody[1]/tr');
            foreach($trs as $tr) {
                $tds = $tr->getElementsByTagName('td');
                if(is_numeric($tds[0]->nodeValue)) {
                    $team = getTeamCode(trim($tds[1]->nodeValue));
                    $pos = $tds[3]->nodeValue;
                    
                } else {
                    $pos = $tds[2]->nodeValue;;
                }
                if(!isset($detail[$team]["pos"]) || !in_array($pos, $detail[$team]["pos"])) {
                    $detail[$team]["pos"][] = $pos;
                    if(!in_array("OF", $detail[$team]["pos"]) && ($pos === "LF" || $pos === "CF" || $pos === "RF")) $detail[$team]["pos"][] = "OF";
                }
                    
            }
        }

        return (!$length_b && !$length_p) ? array("code" => 404) : array("code" => 200, "detail" => $detail);
    }
    
    function getPlayerData($p_no, $p_img) {
        include_once '../Snoopy.class.php';
        
        $playerTotal = searchYear($p_no, "total");
        
        if($playerTotal['code'] != 200) {
            return array();
        }

        $snoopy = new Snoopy;  // Corrected capitalization

        $snoopy->fetch("https://statiz.sporki.com/player/?m=playerinfo&p_no=$p_no");
        $html = $snoopy->results;
        return $p_no;
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);        

        $elements = $xpath->query("//ul[@class='profile']//li//em");

        $playerBirth = trim($elements[0]->nodeValue);
        $playerBirth = str_replace(array("년 ", "월 ", "일"), "", $playerBirth);
        $playerHand = $elements[1]->nodeValue;
        $playerYear = trim($elements[3]->nodeValue);
        $playerDraft = $elements[5]->nodeValue;
        $teamName = trim($elements[4]->nodeValue);

        $playerPos = $elements[7]->nodeValue;
        if (strpos($teamName, '-') !== false) {
            // "-"가 포함된 경우
            $playerTeams = explode('-', $teamName);
            foreach ($playerTeams as $team) {
                $team !== "" && $playerTeam[] = getTeamCode($team);
            }
        } else {
            // "-"가 포함되지 않은 경우
            $playerTeam = array(getTeamCode($teamName));
        }

        switch($p_no) {
            case 14108: $playerHand = "우투우타"; break;
            case 10339: $playerDraft = "93 삼성 1차지명"; break;
        }

        

        $deYear = explode(' ', $playerYear)[0];
        $peYear = explode(' ', $playerYear)[2];
        $backNo = $xpath->query("(//span[@class='number'])[last()]");
        $backNo = @$backNo->item(0)->nodeValue;


        $draft = str_replace('라운드', 'R', $playerDraft);
        $draft = trim($draft);

        if($playerTeam === "") $playerTeam = array(explode(" ", $draft)[1]);
        $playerTeam = array_values(array_unique($playerTeam));
        $playerTeam = array_filter($playerTeam, function($value) {
            return $value !== null;
        });
        

        if(strpos($draft, "1차") !== false) {
            $draft = "1차 지명";
        } else if (strpos($draft, "2차") !== false) {
            $draft = "2차 ".@explode(" ", $draft)[3];
        } else {
            $draft = @explode(" ", $draft)[2];
        }
        
        
        if(!is_numeric($p_img)) {
            if($playerPos === "투수") {
                if(mb_substr($playerHand, 0, 2, 'utf-8') === "좌투") {
                    $img = end($playerTeam)."_p_l";
                } else {
                    $img = end($playerTeam)."_p_r";
                }
            } else {
                if(mb_substr($playerHand, 2, 2, 'utf-8') === "좌타") {
                    $img = end($playerTeam)."_b_l";
                } else {
                    $img = end($playerTeam)."_b_r";
                }
            }
            if($img !== $p_img) {
                global $con, $playerlist;
                $con->query("UPDATE $playerlist SET `p_img` = '$img' WHERE `p_no` = $p_no");
            }
        } else {
            $img = $p_img;
        }
        

        
        

        

        $playerSeason = searchYear($p_no, $playerTeam);
        
        $playerAward = array();
        $awardText = $xpath->query('//div[@class="box_cont"]')[3];
        $awardPattern = '/(\d{4}[^\d]+)/';
        preg_match_all($awardPattern, $awardText->textContent, $awardMatches);
        $awards = $awardMatches[0];
        
        foreach($awards as $a) {
            /*
            preg_match('/^(\d+)(.*)$/', $a, $matches);
            $year = $matches[1];
            $type = $matches[2];
            $awardTest[] = $type;
            */
            $year = substr($a, 0, 4);
            $type = substr($a,4);
            foreach($playerSeason['detail'] as &$team) {
                
                if(isset($team['year']) && in_array($year, $team['year'])) {
                    if(strpos($type, "골든글러브") !== false) {
                        $team['gg'] = true;
                    } else if(strpos($type, '정규시즌 MVP') !== false || strpos($type, '한국시리즈 MVP') !== false){
                        $team['mvp'] = true;
                    } else if(mb_substr($type, 0, 3, 'utf-8') === "올스타") {
                        $team['as'] = true;
                    } else if(strpos($type, "신인왕") !== false) {
                        $team['roy'] = true;
                    }
                }
            }
        }

        switch($p_no) {
            case 10109: $playerSeason['detail']['ssg']['mvp'] = true;
        }
        
        $searchResult = array(
            "SporkId" => $p_no,
            "BackNo" => $backNo,
            "Img" => image_exists($p_no) ? $p_no : $img,
            "Profile" => array(
                "draft_1r" => $draft === "1차 지명" || $draft === "1R",
                "one_club" => count($playerTeam) === 1,
            ),
            "Team" => $playerTeam,
            "Debut" => $deYear,
            "End" => $peYear,
            "Season" => $playerSeason['detail'],
            "Total" => $playerTotal['detail'],
            "Pos" => $playerPos === "투수" ? "투수" : "타자",
            
        );

     return $searchResult;
        
    }
    
function searchKBOField($p_no, $p_season) {
    $fieldingTable = 'kbo_fielding_2001_2025';
    global $con;
    $sql = "SELECT p_team, p_pos FROM $fieldingTable WHERE p_no = $p_no";
    $result = $con->query($sql); 

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $team = getTeamCode($row['p_team']);    
            $position = getPosCode($row['p_pos']);            

            if (!$position) continue;

            if (!isset($p_season[$team]['pos'])) {
                $p_season[$team]['pos'] = [];
            }

            // 중복 확인 후 추가
            if (!in_array($position, $p_season[$team]['pos'])) {
                $p_season[$team]['pos'][] = $position;
            }

            // OF 처리도 중복 확인 후 추가
            if (in_array($position, ["LF", "CF", "RF"]) && !in_array("OF", $p_season[$team]['pos'])) {
                $p_season[$team]['pos'][] = "OF";
            }
        }
    }

    return $p_season;
}

function fetchKBOWithOption($playerId, $type) {

    $url = "https://www.koreabaseball.com/Record/Player/{$type}Detail/Total.aspx?playerId={$playerId}";
    $cookie = "/tmp/kbo_cookie.txt";

    /****************************************
     * 1) GET 요청 (VIEWSTATE 추출)
     ****************************************/
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_USERAGENT => "Mozilla/5.0",
    ]);

    $html = curl_exec($ch);

    if (!$html || strlen($html) < 1000) {
        curl_close($ch);
        return ['error' => 'GET failed or invalid response'];
    }

    // VIEWSTATE 등 추출
    preg_match('/id="__VIEWSTATE" value="([^"]+)"/', $html, $viewstate);
    preg_match('/id="__EVENTVALIDATION" value="([^"]+)"/', $html, $eventValidation);
    preg_match('/id="__VIEWSTATEGENERATOR" value="([^"]+)"/', $html, $viewstateGen);

    $vs  = $viewstate[1] ?? null;
    $ev  = $eventValidation[1] ?? null;
    $vsg = $viewstateGen[1] ?? null;

    curl_close($ch);

    if (!$vs || !$ev || !$vsg) {
        return ['error' => 'Failed to extract VIEWSTATE'];
    }


    /****************************************
     * 2) POST 요청 (옵션 적용)
     ****************************************/

    $postFields = [
        "__EVENTTARGET"   => 'ctl00$ctl00$ctl00$cphContents$cphContents$cphContents$ddlSeries',
        "__EVENTARGUMENT" => "",
        "__LASTFOCUS"     => "",
        "__VIEWSTATE"     => $vs,
        "__VIEWSTATEGENERATOR" => $vsg,
        "__EVENTVALIDATION" => $ev,
        'ctl00$ctl00$ctl00$cphContents$cphContents$cphContents$ddlSeries' => 0
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_USERAGENT => "Mozilla/5.0",
        CURLOPT_REFERER => $url,
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    // 응답이 정상인지 검사
    if (!$response || strlen($response) < 1000 || $status !== 200) {
        return ['error' => 'POST failed or invalid response'];
    }

    return $response;
}

    function searchKBO($p_no, $p_pos, $p_img) {

        $url_b = "https://www.koreabaseball.com/Record/Player/HitterDetail/Total.aspx?playerId=".$p_no;
        $url_p = "https://www.koreabaseball.com/Record/Player/PitcherDetail/Total.aspx?playerId=".$p_no;
        // HTML 가져오기
        $html = fetchKBOWithOption($p_no, "Hitter");
        if (!$html) {
            return ['error' => 'Failed to fetch page'];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // ✅ 기본 정보 파싱 (div.player_basic 안의 <ul><li>)
        $basicInfoList = $xpath->query("//div[@class='player_basic']//ul/li");
        $basicInfo = [];
        foreach ($basicInfoList as $li) {
            $text = trim($li->textContent);
            if (strpos($text, '등번호') !== false) $basicInfo['등번호'] = trim(str_replace('등번호: No.', '', $text));
            elseif (strpos($text, '포지션') !== false) $basicInfo['포지션'] = trim(str_replace('포지션: ', '', $text));
            elseif (strpos($text, '경력') !== false) $basicInfo['경력'] = trim(str_replace('경력: ', '', $text));
            elseif (strpos($text, '신장/체중') !== false) $basicInfo['신장/체중'] = trim(str_replace('신장/체중: ', '', $text));
            elseif (strpos($text, '연봉') !== false) $basicInfo['연봉'] = trim(str_replace('연봉: ', '', $text));
            elseif (strpos($text, '지명순위') !== false)
                $basicInfo['지명순위'] = trim(str_replace('지명순위: ', '', $text));
        }
        
        $playerCareer = explode('-', $basicInfo['경력']);
        $playerTeam = array_map('getTeamCode', $playerCareer);
        $playerTeam = array_filter($playerTeam, function($code) {
            return !is_null($code);
        });

        $currentTeamText = $xpath->query("//h4[@id='h4Team']");
        $currentTeamNode = $currentTeamText->item(0);
$currentTeam = $currentTeamNode ? trim($currentTeamNode->textContent) : '';
        if($basicInfo['연봉'] !== "연봉:") $playerTeam[] = getTeamCode(explode(' ', $currentTeam)[0]);

        if($basicInfo['지명순위'] !== "지명순위:") $playerTeam[] = getTeamCode(explode(' ', $basicInfo['지명순위'])[1]);

        $draft = explode(' ', $basicInfo['지명순위']);
        $deYear = 0;
        $peYear = 0;
        $lastTeam = '';
        // ✅ 성적 테이블 파싱 - 타자
        $playerSeason = [];
        $playerTotal = [];
        $seasonRows = $xpath->query("//div[@class='player_records']//tbody/tr");
        $careerRows = $xpath->query("//div[@class='player_records']//tfoot/tr/th");
        //$seasonBatting = [];
        //$careerBatting = [];

        foreach ($seasonRows as $tr) {
            $cols = $xpath->query("./td", $tr);
            $i = 0;
            $team = '';
            $ops = 0;
            $year = 0;
            foreach ($cols as $td) {
                $stat = trim($td->textContent);
                if($i === 0) {
                    $year = (int)$stat;
                    if($deYear === 0) $deYear = $year;
                    if($peYear < $year) $peYear = $year;
                }
                if($i === 1) {
                    $team = getTeamCode($stat);
                    if(!in_array($team, $playerTeam)) $playerTeam[] = $team;
                    if($peYear === $year) $lastTeam = $team;
                    $playerSeason[$team]['pos'] = [];
                }
                else if($i === 2 && (float)$stat >= 0.3) $playerSeason[$team]['avg_0.300_season'] = true;
                else if($i === 4 && (int)$stat >= 446) $playerSeason[$team]['pa_446_season'] = true;
                else if($i === 7 && (int)$stat >= 150) $playerSeason[$team]['h_150_season'] = true;
                else if($i === 8 && (int)$stat >= 30) $playerSeason[$team]['2b_30_season'] = true;
                else if($i === 9 && (int)$stat >= 5) $playerSeason[$team]['3b_5_season'] = true;
                else if($i === 10 && (int)$stat >= 20) $playerSeason[$team]['hr_20_season'] = true;
                else if($i === 12 && (int)$stat >= 80) $playerSeason[$team]['rbi_80_season'] = true;
                else if($i === 13 && (int)$stat >= 20) $playerSeason[$team]['sb_20_season'] = true;
                else if($i === 19) {
                    $ops += (float)$stat;
                    if((float)$stat >= 0.5) $playerSeason[$team]['slg_0.500_season'] = true;
                }
                else if($i === 20) {
                    $ops += (float)$stat;
                    if((float)$stat >= 0.4) $playerSeason[$team]['obp_0.400_season'] = true;
                    if($ops >= 0.8) $playerSeason[$team]['ops_0.800_season'] = true;
                    if($ops >= 0.9) $playerSeason[$team]['ops_0.900_season'] = true;
                    break;
                }
                //$seasonBatting[] = trim($td->textContent);
                $i++;
            }
        }

        $i = 1;
        $ops = 0.0;
        foreach ($careerRows as $td) {
            
            $stat = trim($td->textContent);
            if($i === 2 && (float)$stat >= 0.3) $playerTotal['avg_0.300_total'] = true;
            else if($i === 7){
if((int)$stat >= 1500) $playerTotal['h_1500_total'] = true;
if((int)$stat >=1000) $playerTotal['h_1000_total'] = true;
            }
            else if($i === 10 && (int)$stat >= 150) $playerTotal['hr_150_total'] = true;
            else if($i === 12 && (int)$stat >= 800) $playerTotal['rbi_800_total'] = true;
            else if($i === 13 && (int)$stat >= 150) $playerTotal['sb_150_total'] = true;
            else if($i === 19) {
                $ops += (float)$stat;
                if((float)$stat >= 0.5) $playerTotal['slg_0.500_total'] = true;
            }
            else if($i === 20) {
                $ops += (float)$stat;
                if((float)$stat >= 0.4) $playerTotal['obp_0.400_total'] = true;
                if($ops >= 0.8) $playerTotal['ops_0.800_total'] = true;
                break;
            }
            //$careerBatting[] = trim($td->textContent);
            $i++;
        }

        // HTML 가져오기
        $html = fetchKBOWithOption($p_no, "Pitcher");
        if (!$html) {
            return ['error' => 'Failed to fetch page'];
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        //성적 테이블 파싱 - 투수
        $seasonRows = $xpath->query("//div[@class='player_records']//tbody/tr");
        $careerRows = $xpath->query("//div[@class='player_records']//tfoot/tr/th");

        foreach ($seasonRows as $tr) {
            $cols = $xpath->query("./td", $tr);
            $i = 0;
            $team = '';
            $year = 0;
            foreach ($cols as $td) {
                $stat = trim($td->textContent);
                if($i === 0) {
                    $year = (int)$stat;
                    if($deYear > $year || $deYear === 0) $deYear = $year;
                    if($peYear < $year) $peYear = $year;
                }
                if($i === 1) {
                    $team = getTeamCode($stat);
                    if(!in_array($team, $playerTeam)) $playerTeam[] = $team;
                    if($peYear === $year) $lastTeam = $team;
                    $playerSeason[$team]['pos'] = [];
                }
                else if($i === 2 && (float)$stat <= 3) $playerSeason[$team]['era_3.00_season'] = true;
                else if($i === 6) {
                    if((int)$stat >= 10) $playerSeason[$team]['win_10_season'] = true;
                    if((int)$stat >= 15) $playerSeason[$team]['win_15_season'] = true;
                }
                else if($i === 8 && (int)$stat >= 20) $playerSeason[$team]['sv_20_season'] = true;
                else if($i === 9 && (int)$stat >= 10) $playerSeason[$team]['hld_10_season'] = true;
                else if($i === 12 && (int)$stat >= 144) $playerSeason[$team]['ip_144_season'] = true;
                else if($i === 17) {
                    if((int)$stat >= 100) $playerSeason[$team]['so_100_season'] = true;
                    if((int)$stat >= 150) $playerSeason[$team]['so_150_season'] = true;
                    break;
                }
                $i++;
            }
        }

        $i = 1;
        foreach ($careerRows as $td) {
            $ops = 0;
            $stat = trim($td->textContent);
            if($i === 2 && (float)$stat <= 3) $playerTotal['era_3.00_total'] = true;
            else if($i === 6 && (int)$stat >= 100) $playerTotal['win_100_total'] = true;
            else if($i === 8) {
                    if((int)$stat >= 50) $playerTotal['sv_50_total'] = true;
                    if((int)$stat >= 100) $playerTotal['sv_100_total'] = true;
                }
            else if($i === 17 && (int)$stat >= 800) $playerTotal['so_800_total'] = true;
            $i++;
        }

        $playerSeason = searchKBOField($p_no, $playerSeason);
        $playerTeam = array_values(array_unique($playerTeam));

        //선수 대체 이미지 (예: ssg_b_r)
        $img = $lastTeam;
        if (preg_match('/\((.*?)\)/', $basicInfo['포지션'], $matches)) {
            $handInfo = $matches[1]; // 예: "우투우타" 또는 "우투양타"

            // 투수이면 앞쪽 (던지는 손), 아니면 뒤쪽 (치는 손)
            if ($p_pos === '투수') {
                $handInfo = mb_substr($handInfo, 0, 1) === '우' ? 'r' : 'l';
                $img = $img.'_p_'.$handInfo;
            } else {
                $handInfo = mb_substr($handInfo, 2, 1) === '우' ? 'r': 'l';
                $img = $img.'_b_'.$handInfo;
            }
        }
        /*
        return [
            '기본정보' => $basicInfo,
            '시즌별성적' => $seasonBatting,
            '통산성적' => $careerBatting
        ];
        */
        
        return $deYear ? array(
            "SporkId" => $p_no,
            "BackNo" => $basicInfo['등번호'],
            "Img" => $peYear.'_'.$img,
            "Pos" => $p_pos,
            "Profile" => array(
                "draft_1r" => isset($draft[2]) && ($draft[2] === '1라운드' || $draft[2] === '1차'),
                "one_club" => count($playerTeam) === 1,
                "active_2025" => $peYear >= 2025,
            ),
            "Team" => $playerTeam,
            "Debut" => $deYear,
            "End" => $peYear,
            "Season" => $playerSeason,
            "Total" => $playerTotal,
        ) : null;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $searchName = $data["keyword"];
    
    // SQL 인젝션 방지를 위해 prepared statement 사용
    $sql = "SELECT `p_no`, `p_name`, `p_img`, `p_pos`, `is_WBC`, `is_GG`, `is_MLB`, `is_AS`
            FROM $playerlist 
            WHERE (`p_name` LIKE ? OR `p_oldname` LIKE ?)
            ORDER BY LENGTH(p_name) ASC, `p_name` ASC LIMIT 15";
    $stmt = $con->prepare($sql);
    $searchName = "%$searchName%"; // 와일드카드 추가
    $stmt->bind_param("ss", $searchName, $searchName);
    $stmt->execute();
    $result = $stmt->get_result();
    

    $searchResult = array();
    $searchResult['list'] = array();
    // 결과 처리
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            
            //$sporki = getPlayerData($row['p_no'], $row['p_img']);
            
            /*
            if(!empty($sporki) && !isset($sporki['error'])) {
                $sporki["Name"] = $row["p_name"];
                $searchResult['list'][] = $sporki;
                if(count($searchResult['list']) >= 10) break;
            }
            */
            $kbodata = searchKBO($row['p_no'], $row['p_pos'], $row['p_img']);
            if(!empty($kbodata) && !isset($kbodata['error'])) {
                $goldGloveTeams = preg_split(
                    '/\s*,\s*/',
                    strtolower(trim((string)$row['is_GG'])),
                    -1,
                    PREG_SPLIT_NO_EMPTY
                );

                foreach($goldGloveTeams as $teamCode) {
                    if(isset($kbodata['Season'][$teamCode]) && is_array($kbodata['Season'][$teamCode])) {
                        $kbodata['Season'][$teamCode]['gg'] = true;
                    }
                }

                $allStarTeams = preg_split(
                    '/\s*,\s*/',
                    strtolower(trim((string)$row['is_AS'])),
                    -1,
                    PREG_SPLIT_NO_EMPTY
                );

                foreach($allStarTeams as $teamCode) {
                    if(isset($kbodata['Season'][$teamCode]) && is_array($kbodata['Season'][$teamCode])) {
                        $kbodata['Season'][$teamCode]['as'] = true;
                    }
                }

                $kbodata["Name"] = $row["p_name"];
                $searchResult['list'][] = $kbodata;
                $searchResult['list'][count($searchResult['list'])-1]['Profile']['is_WBC'] = $row['is_WBC'] == 1;
                $searchResult['list'][count($searchResult['list'])-1]['Profile']['is_MLB'] = $row['is_MLB'] == 1;

                if($kbodata['Img'] !== $row['p_img']) {
                    $stmt = $con->prepare("UPDATE $playerlist SET p_img = ? WHERE p_no = ?");
                    $stmt->bind_param("si", $kbodata['Img'], $row['p_no']); // 문자열, 정수
                    $stmt->execute();
                }

                if(count($searchResult['list']) >= 10) break;
            }
            
        }
    } else {

    }
    
    $searchResult['success'] = true;
    $searchResult['rows'] = $result->num_rows;

    echo json_encode($searchResult);
?>
