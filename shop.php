<?php
if (!isset($_ENV['APP_KEY'])) require 'common.php';

$cli = php_sapi_name() === 'cli' || PHP_SAPI === 'cli';

if($cli) {
    require 'WebScrapper.php';

    if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
        try {
            echo "=== 개선된 웹 스크래핑 도구 ===\n\n";

            $scraper = new WebScraper('./scraped_files/', true); // 디버그 모드 ON

            // 시스템 환경 체크
            if (!$scraper->checkEnvironment()) {
                echo "❌ 시스템 환경 문제로 중단됩니다.\n";
                exit(1);
            }

            // 테스트 URL
            $url = $_ENV['SHOP_URL'];
            echo "📋 테스트 URL: $url\n";

            $html = $scraper->scrapeUrl($url, 10, 60);

            if ($html) {
                // 원본 파일 저장
                echo "\n💾 원본 파일 저장\n";
                $originalFile = $scraper->saveToFile($html, $url, null, false);

                // 원본 파일이 성공적으로 저장된 경우에만 변환 프로세스 실행
                if ($originalFile) {
                    echo "\n🔄 상대 경로 변환 후 저장 (2번째 프로세스)\n";
                    $convertedFile = $scraper->saveToFile($html, $url, 'converted.html', true, true);

                    if ($convertedFile) {
                        echo "\n✅ 모든 처리 완료!\n";
                        echo "📁 원본 파일: $originalFile\n";
                        echo "📁 변환 파일: $convertedFile\n";
                    } else {
                        echo "\n⚠️  원본 파일은 저장되었으나 변환 파일 저장에 실패했습니다.\n";
                        echo "📁 원본 파일: $originalFile\n";
                    }
                } else {
                    echo "\n❌ 원본 파일 저장에 실패하여 변환 프로세스를 건너뜁니다.\n";
                }
            } else {
                echo "\n❌ 모든 시도 실패\n";
                echo "💡 다음 사항을 확인해보세요:\n";
                echo "   1. 웹사이트가 봇 접근을 차단하는지\n";
                echo "   2. VPN이나 프록시 필요한지\n";
                echo "   3. 특별한 헤더나 쿠키 필요한지\n";
                echo "   4. 방화벽 설정\n";
            }

        } catch (Exception $e) {
            echo "❌ 에러: " . $e->getMessage() . "\n";
        } finally {
            if (isset($scraper)) {
                $scraper->cleanup();
            }
        }
    }
}else{
    if(file_exists('./scraped_files/converted.html')){
        readfile('./scraped_files/converted.html');
    }else{
        echo 'WebScrapper를 실행해주세요.';
    }
}