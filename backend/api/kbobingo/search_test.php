<?php
include_once 'common.php';

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
        if(($code > 1000 && $code < 2000) || $code === "삼성") return "sam";
        else if(($code > 2000 && $code < 3000) || $code === "해태" || $code === "KIA") return "kia";
        else if(($code > 3000 && $code < 4000) || $code === "롯데") return "lot";
        else if(($code > 4000 && $code < 5000) || $code === "삼미" || $code === "청보" || $code === "태평양" || $code === "현대") return "hyd";
        else if(($code > 5000 && $code < 6000) || $code === "MBC" || $code === "LG") return "lg";
        else if(($code > 6000 && $code < 7000) || $code === "OB" || $code === "두산") return "doo";
        else if(($code > 7000 && $code < 8000) || $code === "빙그레" || $code === "한화") return "han";
        else if(($code > 8000 && $code < 9000) || $code === "쌍방울") return "sbw";
        else if(($code > 9000 && $code < 10000) || $code === "SK" || $code === "SSG") return "ssg";
        else if(($code > 10000 && $code < 11000) || $code === "우리" || $code === "히어로즈" || $code === "넥센" || $code === "키움") return "kiw";
        else if(($code > 11000 && $code < 12000) || $code === "NC") return "nc";
        else if(($code > 12000 && $code < 13000) || $code === "KT") return "kt";
    }
    
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

        return (!$length_b && !$length_p) ? array("code" => 404, "html" => $html) : array("code" => 200, "detail" => $detail);
    }

    function getPlayerData($p_no, $p_img) {
        include_once '../Snoopy.class.php';
        
        $playerTotal = searchYear($p_no, "total");
        if($playerTotal['code'] != 200) {
            return $playerTotal['html'];
        }

        $snoopy = new Snoopy;  // Corrected capitalization

        $snoopy->fetch("https://statiz.sporki.com/player/?m=playerinfo&p_no=$p_no");
        $html = $snoopy->results;
        
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

    
    $data = json_decode(file_get_contents('php://input'), true);
    $searchName = $data["keyword"];
    
    // SQL 인젝션 방지를 위해 prepared statement 사용
    $sql = "SELECT `p_no`, `p_name`, `p_img` 
            FROM $playerlist 
            WHERE (`p_name` LIKE ? OR `p_oldname` LIKE ?)
            ORDER BY LENGTH(p_name) ASC, `p_name` ASC, `p_career_war` DESC LIMIT 15";
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
            
            $sporki = getPlayerData($row['p_no'], $row['p_img']);
            $searchResult['list'][] = $sporki;
            /*if(!empty($sporki) && !isset($sporki['error'])) {
                $sporki["Name"] = $row["p_name"];
                $searchResult['list'][] = $sporki;
                if(count($searchResult['list']) >= 10) break;
            }*/
            
            
        }
    } else {

    }
    
    $searchResult['success'] = true;

    echo json_encode($searchResult);
?>