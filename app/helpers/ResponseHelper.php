<?php

namespace App\Helpers;

class ResponseHelper {
    public static function send($success, $message = "", $data = [], $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data
        ]);
        exit;
    }
}

?>