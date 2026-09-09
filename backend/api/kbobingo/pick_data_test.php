<?php
    include_once "common.php";

    $data = json_decode(file_get_contents('php://input'), true);
    $pickId = $data['p_no'];
    $gridId = $data['index'];
    $type = $data['type'];
    $row = $data['row'];
    $col = $data['col'];

    $pickTable = "kbobingo_pick";
    if(!is_int($pickId)) {
        echo json_encode(array(
            "code" => 400,
            "error" => "잘못된 정답 데이터 형식입니다."
        ));
        return ;
    }
    if($type === 1) {
        $query = "INSERT INTO $pickTable (grid_index, p_no, row_no, col_no, picked)
        VALUES ($gridId, $pickId, $row, $col, 1)
        ON DUPLICATE KEY UPDATE picked = picked + 1";
        $insertPick = $con->query($query);
        if(!$insertPick) {
            echo json_encode(array(
                "code" => 500,
                "error" => "데이터 저장 실패: " . $con->error
            ));
            return;
        } else {
            echo json_encode(array("code" => 200));
        }
    } else {
        $query = "SELECT pick.picked, pl.p_name, pl.p_img
        FROM $pickTable pick
        INNER JOIN $playerlist pl ON pick.p_no = pl.p_no
        WHERE pick.grid_index = ? AND pick.p_no = ? AND pick.row_no = ? AND pick.col_no = ?";
        $stmt = $con->prepare($query);
        $stmt->bind_param("iiii", $gridId, $pickId, $row, $col);
        $stmt->execute();

        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $pickPlayer = $result->fetch_assoc();
            $pick = $pickPlayer['picked'];
            $p_name = $pickPlayer['p_name'];
            $p_img = image_exists($p_no) ? $pickId : $pickPlayer['p_img'];

            $query = "SELECT SUM(picked) FROM $pickTable WHERE grid_index = ? AND row_no = ? AND col_no = ?";
            $stmt = $con->prepare($query);
            $stmt->bind_param("iii", $gridId, $row, $col);
            $stmt->execute();
            $result = $stmt->get_result();
            $pickSum = $result->fetch_assoc()['SUM(picked)'];

            $pickValue = 100 * $pick / $pickSum;
            if ($pickValue > 2) {
                $pickRate = number_format($pickValue, 0); // xx%
            } elseif ($pickValue > 0.1) {
                $pickRate = number_format($pickValue, 1); // x.x%
            } elseif ($pickValue > 0.01) {
                $pickRate = number_format($pickValue, 2); // x.xx%
            } elseif ($pickValue > 0.001) {
                $pickRate = number_format($pickValue, 3); // x.xxx%
            } else {
                $pickRate = 0.001;
            }

            echo json_encode(array(
                "code" => 200,
                "rate" => $pickRate,
                "score" => 100 - floor($pickValue),
                "name" => $p_name,
                "img" => $p_img, 
            ));
        } else {
            echo json_encode(array(
                "code" => 404,
                "error" => "잘못된 정답 데이터 형식입니다."
            ));
        }

        return;
    }
    

?>