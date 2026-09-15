<?php
// save_state.php
$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    $status   = $data['status'];
    $nextTurn = $data['nextTurn'];
    
    // Flatten 2D board matrix into a single comma-delimited line
    // Example output: "0,0,0,1,2,0,...,1|RED|0"
    $flattenedBoard = array();
    foreach ($data['board'] as $row) {
        $flattenedBoard = array_merge($flattenedBoard, $row);
    }
    
    $boardString = implode(",", $flattenedBoard);
    
    // Single line payload: BOARD_CSV|NEXT_TURN|STATUS
    $singleLinePayload = $boardString . "|" . $nextTurn . "|" . $status . "\n";

    file_put_contents('connect4_state.txt', $singleLinePayload);
    echo "OK";
}
?>