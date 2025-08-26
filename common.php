<?php
require_once 'vendor/autoload.php';
use Detection\MobileDetect;
// Mobile Detect 인스턴스
$detect = new MobileDetect;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$approved = filter_var($_ENV['APPROVED'], FILTER_VALIDATE_BOOLEAN);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '', // 필요 시 지정
    'secure' => true,   // HTTPS 필수
    'httponly' => true,
    'samesite' => 'None'
]);

session_start();

// 클라이언트 IP
function getClientIp() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// IPv6 -> IPv4 변환
function normalizeIp($ip) {
    // IPv6 중 IPv4로 매핑된 경우 (::ffff:1.2.3.4) 추출
    if (strpos($ip, '::ffff:') === 0) {
        return substr($ip, 7);
    }
    return $ip;
}

// 봇 감지 (UA 기반)
function isBot() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ua = strtolower($ua);
    $result = false;

    if (empty($ua)) {
        // 명백한 봇
        $result = true;
    } elseif (preg_match('/(googlebot|bingbot|slurp|yahoo|baiduspider)/i', $ua)) {
        // Googlebot, Bingbot 등 공식 검색엔진은 허용
        $result = true;
    } elseif (preg_match('/(curl|wget|httpclient|python|scrapy|spider|crawler|bot)/i', $ua)) {
        // 기타 봇
        $result = true;
    }

    return $result;
}

// 모바일/PC 분리 및 헤더 체크
function isLikelyBot($ip, $returnScore = false) {
    global $detect;
    $score = 0;
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);

    if(isBot()) return true;

    // (1) rDNS 체크
    $hostname = gethostbyaddr($ip);
    // rDNS 없는 경우 의심
    if ($hostname === $ip) $score += 0.5;

    // (2) 헤더 기반 점수
    if (!$detect->isMobile() && !$detect->isTablet()) {
        if (empty($headers['accept-language']) && empty($headers['sec-ch-ua'])) {
            $score += 2;
        }
    } else {
        // 모바일
        if (!$detect->isiOS()) {
            // Android 등
            if (empty($headers['accept-language'])) $score += 0.5;
        }
    }

    return $returnScore ? $score : $score >= 2;
}

function issueJsToken() {
    $token = bin2hex(random_bytes(16));
    $_SESSION['js_token'] = $token;
    return $token;
}

// JS 토큰 검증 (API)
function verifyJsToken() {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $isIOS = !empty($input['isIOS']) && $input['isIOS'] === true;

    // 3️⃣ 클라이언트/서버 IP 추출
    $clientIp = $input['ip'] ?? null;                       // JS에서 전달받은 IP
    $serverIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';       // 서버에서 본 IP
    $serverIpNorm = normalizeIp($serverIp);                 // IPv6 → IPv4 변환 처리

    $response = [
        'clientIp' => $clientIp,
        'serverIp' => $serverIp,
        'clientIpScore' => isLikelyBot($clientIp, true),
        'serverIpScore' => isLikelyBot($serverIp, true),
    ];

    if ($isIOS) {
        // iOS iframe 전용 처리 (세션 없이)
        if (!isset($input['token'])) {
            $newToken = bin2hex(random_bytes(16));
            echo json_encode(array_merge(['status'=>'retry','token'=>$newToken], $response));
            exit;
        }

        if (isBot() || isLikelyBot(getClientIp())) {
            echo json_encode(array_merge(['status'=>'fail','reason'=>'bot_detected'], $response));
            exit;
        }

        echo json_encode(array_merge(['status'=>'ok'], $response));
        exit;
    }else{
        // 1️⃣ 세션이 없으면 새 토큰 발급 → retry
        if (!isset($_SESSION['js_token'])) {
            $_SESSION['js_token'] = bin2hex(random_bytes(16));
            echo json_encode(array_merge(['status'=>'retry','token'=>$_SESSION['js_token']], $response));
            exit;
        }

        // 2️⃣ 클라이언트에서 토큰을 못 받으면 실패
        if (!isset($input['token'])) {
            echo json_encode(array_merge(['status'=>'fail','reason'=>'missing'], $response));
            exit;
        }

        // if ($clientIp && $clientIp !== $serverIpNorm) {
        //     // IP 불일치 → VPN/Proxy/의심 가능성
        //     echo json_encode([
        //         'status' => 'fail',
        //         'reason' => 'ip_mismatch',
        //         'clientIp' => $clientIp,
        //         'serverIp' => $serverIpNorm
        //     ]);
        //     exit;
        // }

        // 3️⃣ UA/헤더 기반 봇 체크
        // common.php에 있는 isBot() / isLikelyBot() 사용
        if (isLikelyBot($clientIp) || isLikelyBot($serverIp)) {
            echo json_encode(array_merge(['status'=>'fail','reason'=>'bot_detected'], $response));
            exit;
        }

        // 4️⃣ JS 토큰 검증
        if (hash_equals($_SESSION['js_token'], $input['token'])) {
            echo json_encode(array_merge(['status'=>'ok'], $response));
        } else {
            echo json_encode(array_merge(['status'=>'fail','reason'=>'invalid'], $response));
        }
        exit;
    }
}