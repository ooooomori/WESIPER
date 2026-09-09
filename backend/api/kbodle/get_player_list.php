<?php


    include_once "common.php";

    // 연결 확인
    if ($con->connect_error) {
        die("Connection failed: " . $con->connect_error);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $searchName = $data["keyword"];

    // SQL 인젝션 방지를 위해 prepared statement 사용
    $sql = "SELECT `playerId`, `name`, `hs`, `hsLoc`, `birth`, `throw`, `bat`, `mainPos`, `subPos`,`draft`, `team`, `backNo` FROM $playerlist WHERE `name` LIKE ? OR `oldname` LIKE ? ORDER BY `name` ASC";
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
            if(!$row['draft']) continue;
            $age = 20;
            if($row['birth']) {
                $currentDay = new DateTime();
                $birthDay = new Datetime($row['birth']);
                $age = $birthDay->diff($currentDay)->y;
            }

            $temp = array(
                "SporkId" => $row['playerId'],
                "Name" => $row['name'],
                "Pos" => $row['mainPos'],
                "SubPos" => explode(",", $row['subPos']),
                "Age" => $age,
                "Pit" => $row['throw'],
                "Bat" => $row['bat'],
                "Draft" => shortenDraft($row['draft']),
                "Team" => $row['team'],
                "HS" => $row['hs'],
                "HSLoc" => $row['hsLoc'],
                "BackNo" => $row['backNo']
            );
            $searchResult['list'][] = $temp;
            /*
            $sporki = getPlayerData($row['p_no'], "search");
            if(!isset($sporki['error'])) {
                
                if(!$row['p_hs']) $row['p_hs'] = "국외";

                $updateFields = [];
                $updateValues = [];
                $types = '';
                
                $sporki["Debut"] = (int)filter_var($sporki["Debut"], FILTER_SANITIZE_NUMBER_INT);
                $sporki["Retire"] = (int)filter_var($sporki["Retire"], FILTER_SANITIZE_NUMBER_INT);
                if((empty($row['p_debut']) || $row['p_debut'] !== $sporki["Debut"]) && !empty($sporki["Debut"])) {
                    $updateFields[] = "`p_debut` = ?";
                    $updateValues[] = $sporki["Debut"];
                    $types .= 's';
                }
        
                if((empty($row['p_retire']) || $row['p_retire'] !== $sporki["Retire"]) && !empty($sporki["Retire"])) {
                    $updateFields[] = "`p_retire` = ?";
                    $updateValues[] = $sporki["Retire"];
                    $types .= 's';
                }
        
                if((empty($row['p_backno']) || $row['p_backno'] !== $sporki["BackNo"]) && !empty($sporki["BackNo"])) {
                    $updateFields[] = "`p_backno` = ?";
                    $updateValues[] = $sporki["BackNo"];
                    $types .= 's';
                }
        
                if((empty($row['p_throw'])) && !empty($sporki["Pit"])) {
                    $updateFields[] = "`p_throw` = ?";
                    $updateValues[] = $sporki["Pit"];
                    $types .= 's';
                }
        
                if((empty($row['p_bat'])) && !empty($sporki["Bat"])) {
                    $updateFields[] = "`p_bat` = ?";
                    $updateValues[] = $sporki["Bat"];
                    $types .= 's';
                }
        
                if((empty($row['p_pos'])) && !empty($sporki["Pos"])) {
                    $updateFields[] = "`p_pos` = ?";
                    $updateValues[] = $sporki["Pos"];
                    $types .= 's';
                }
        
                // SubPos는 배열 → 문자열 변환
                if (!empty($sporki["SubPos"])) {
                    $subPosStr = implode(", ", $sporki["SubPos"]);
                    if(empty($row['p_subpos'])) {
                        $updateFields[] = "`p_subpos` = ?";
                        $updateValues[] = $subPosStr;
                        $types .= 's';
                    }
                }
        
                if((empty($row['p_draft']) || $row['p_draft'] !== $sporki["Draft"]) && !empty($sporki["Draft"])) {
                    $updateFields[] = "`p_draft` = ?";
                    $updateValues[] = $sporki["Draft"];
                    $types .= 's';
                }
        
                // 실제 업데이트 실행
                if (!empty($updateFields)) {
                    $updateSql = "UPDATE $playerlist SET " . implode(", ", $updateFields) . " WHERE `p_no` = ?";
                    $types .= 'i';
                    $updateValues[] = $row['p_no'];
        
                    $updateStmt = $con->prepare($updateSql);
                    $updateStmt->bind_param($types, ...$updateValues);
                    $updateStmt->execute();
                }

                $sporki["HS"] = $row['p_hs'];
                $sporki["HSLoc"] = $row['p_hsLoc'];
                $sporki["Name"] = $row["p_name"];
                
                $searchResult['list'][] = $sporki;
            }*/
            
        } 
    } else {

    }
    
    $searchResult['success'] = true;

    echo json_encode($searchResult);
?>
