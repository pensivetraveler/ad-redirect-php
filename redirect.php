<?php
if(!isset($_ENV['APP_KEY'])) require 'common.php';
$redirect_url = $_ENV['REDIRECT_URL'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>게임 안내 페이지</title>
    <style>
        body { display: flex; justify-content: center; align-items: center; height: 100vh; font-family: sans-serif; background: black; }
        .msg { font-size: 1.5em; color: white; }
    </style>
<body>
<div class="msg">즐거운 시간 되세요</div>
<script>
    const randomNumber = Math.floor(Math.random() * 1000) + 1000;
    setTimeout(function() {
        window.location.href = "<?=$redirect_url?>";
    }, randomNumber);
</script>
</body>
</html>