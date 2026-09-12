<?php
    include_once "common.php";


    // 연결 확인
    if ($con->connect_error) {
        die("Connection failed: " . $con->connect_error);
    }
    
    $sql = "SELECT MAX(PK) FROM kbobingo_grid WHERE `grid_date` != DATE(NOW())";
    $getLatest = $con->query($sql);
    $latest = $getLatest->fetch_assoc()['MAX(PK)'];

    $data = json_decode(file_get_contents('php://input'), true);
    if(isset($data['index'])) {
        if($data['index'] > $latest + 1 || !is_int($data['index']) || $data['index'] < 1){
            echo json_encode(array(
                "code" => 404,
                "error" => "index 형식이 잘못되었습니다."
            ));
            return;
        }
        $index = $data['index']; 
    } else {
        $index = $latest + 1;
    }

    $sql = "SELECT * FROM kbobingo_grid WHERE PK = $index";

    $result = $con->query($sql);
    

    // 결과 처리
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $res = array(
            "grid" => array(
                "row" => array(
                    $row["row-1"], $row["row-2"], $row["row-3"]
                ),
                "col" => array(
                    $row["col-1"], $row["col-2"], $row["col-3"]
                ),
                "index" => $row["PK"]
                ),
            "date" => $row["grid_date"],
            "code" => 200
        );
    } else {

        $list = [
            "team" => ['ssg', 'lg', 'kt', 'nc', 'doo', 'sam', 'kia', 'lot', 'han', 'kiw'],
            "stat" => [
                "batting" => ['avg_0.300_season', 'avg_0.300_total', 'obp_0.400_season', 'obp_0.400_total','slg_0.500_season', 'slg_0.500_total', 'ops_0.800_season', 'ops_0.900_season', 'ops_0.800_total', 'pa_446_season', 'h_150_season','h_1000_total', 'h_1500_total', '2b_30_season', '3b_5_season', 'hr_20_season', 'hr_150_total', 'rbi_80_season', 'rbi_800_total', 'sb_20_season', 'sb_150_total'],
                "pitching" => ['win_10_season', 'win_15_season', 'win_100_total', 'sv_20_season', 'hld_10_season', 'sv_100_total', 'sv_50_total','ip_144_season', 'so_100_season', 'so_150_season', 'so_800_total', 'era_3.00_season', 'era_3.00_total'],
            ],
            "award" => ["gg", "as"],
            "profile" => ["one_club", "draft_1r", "is_WBC", "is_MLB"], //active_2025 삭제 (260320)
            "pos" => ["c", "1b", "2b", "3b", "ss", "lf", "rf", "cf", "of"],
        ];

        $resultArray = [];
        $subCategory = mt_rand(1, 100) <= 60 ? "batting" : "pitching"; 
        /*
        if($subCategory === "pitching") {
            $list["award"] = array("as");
        }
        */
        while(count($resultArray) < 6) {
            
            $rand = mt_rand(1, 100);
            if ($rand <= 60) {
                $category = 'team';
            } else if ($rand <= 75) {
                if($subCategory === "batting") $category = 'pos';
                else $category = 'team';
                //$category = 'team';
            } else if ($rand <= 80) {
                $category = 'award';
            } else if ($rand <= 90) {
                $category = 'stat';
            } else {
                $category = 'profile';
            }

            if ($category == 'stat') {
                $item = $category.'-'.$list[$category][$subCategory][array_rand($list[$category][$subCategory])];
            } else {
                $item = $category.'-'.$list[$category][array_rand($list[$category])];
            }

            if (!in_array($item, $resultArray)) {
                $resultArray[] = $item;
            }
        }

        $rowArray = array_slice($resultArray, 0, 3);
        $colArray = array_slice($resultArray, 3, 3);

        usort($rowArray, function($a, $b) {
            if (strpos($a, 'team') === 0 && strpos($b, 'team') !== 0) {
                return -1;
            } elseif (strpos($a, 'team') !== 0 && strpos($b, 'team') === 0) {
                return 1;
            } else {
                return strcmp($a, $b);
            }
        });
        usort($colArray, function($a, $b) {
            if (strpos($a, 'team') === 0 && strpos($b, 'team') !== 0) {
                return -1;
            } elseif (strpos($a, 'team') !== 0 && strpos($b, 'team') === 0) {
                return 1;
            } else {
                return strcmp($a, $b);
            }
        });

        $con->query("INSERT INTO `kbobingo_grid` (`grid_date`, `row-1`, `col-1`, `row-2`, `col-2`, `row-3`, `col-3`) VALUES (DATE(NOW()), '$rowArray[0]','$colArray[0]','$rowArray[1]','$colArray[1]','$rowArray[2]','$colArray[2]')");

        $res = array(
            "grid" => array(
                "row" => array(
                    $rowArray[0], $rowArray[1], $rowArray[2]
                ),
                "col" => array(
                    $colArray[0], $colArray[1], $colArray[2]
                ),
                "index" => $index,
                "date" => Date('Y-m-d'),
                ),
            "date" => Date('Y-m-d'),
            "code" => 200
        );

    }

    echo json_encode($res);
    $con->close();
?>
