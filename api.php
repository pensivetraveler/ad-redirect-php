<?php
if(!isset($_ENV['APP_KEY'])) require 'common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'fail', 'reason' => 'method_not_allowed']);
    exit;
}

// 그 외 환경: 기존 서버 세션 기반 JS 토큰 검증
verifyJsToken();
