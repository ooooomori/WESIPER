<?php
    include_once "common.php";

    $data = json_decode(file_get_contents('php://input'), true);
    $gridId = $data['index'];
    $row = $data['row'];
    $col = $data['col'];

    $pickTable = "kbobingo_pick";

    $query = "SELECT 
                pick.picked, 
                pl.p_no, 
                pl.p_name, 
                pl.p_img,
                (SELECT SUM(picked) 
                FROM $pickTable 
                WHERE grid_index = pick.grid_index 
                AND row_no = pick.row_no 
                AND col_no = pick.col_no) AS total_picked
            FROM $pickTable pick
            INNER JOIN $playerlist pl ON pick.p_no = pl.p_no
            WHERE pick.grid_index = ?
            AND pick.row_no = ?
            AND pick.col_no = ?
            ORDER BY pick.picked DESC
            LIMIT 1;";
    $stmt = $con->prepare($query);
    $stmt->bind_param("iii", $gridId, $row, $col);
    $stmt->execute();

    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $pickPlayer = $result->fetch_assoc();
        $pick = $pickPlayer['picked'];
        $p_no = $pickPlayer['p_no'];
        $p_name = $pickPlayer['p_name'];
        $p_img = image_exists($p_no) ? $p_no : $pickPlayer['p_img'];

        

        $pickSum = $pickPlayer['total_picked'];

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
            "name" => $p_name,
            "img" => $p_img, 
            "no" => $p_no, 
        ));
    } else {
        echo json_encode(array(
            "code" => 200,
        ));
    }

    return;

    

?>