<?php
require "common.php";
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'fail', 'reason' => 'method_not_allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$isIOS = !empty($input['isIOS']) && $input['isIOS'] === true;

if ($isIOS) {
    // iOS iframe 전용 처리 (세션 없이)
    if (!isset($input['token'])) {
        $newToken = bin2hex(random_bytes(16));
        echo json_encode(['status'=>'retry','token'=>$newToken]);
        exit;
    }

    if (isBot() || isLikelyBot(getClientIp())) {
        echo json_encode(['status'=>'fail','reason'=>'bot_detected']);
        exit;
    }

    echo json_encode(['status'=>'ok']);
    exit;
}

// 그 외 환경: 기존 서버 세션 기반 JS 토큰 검증
verifyJsToken();
