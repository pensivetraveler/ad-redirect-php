<?php
/**
 * 개선된 범용 웹사이트 스크래핑 클래스 - 실패 원인 진단 및 해결
 */

class WebScraper {
    private $userDataDir;
    private $outputDir;
    private $debugMode;

    public function __construct($outputDir = './scraped_files/', $debugMode = true) {
        $this->userDataDir = '/tmp/chrome-scraper-' . uniqid() . '-' . getmypid();
        $this->outputDir = rtrim($outputDir, '/') . '/';
        $this->debugMode = $debugMode;

        // 출력 디렉토리 생성
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0777, true);
        }

        mkdir($this->userDataDir, 0777, true);

        // Chrome 프로세스 정리
        shell_exec('sudo pkill -9 chrome 2>/dev/null');
        shell_exec('sudo pkill -9 chromium 2>/dev/null');
        sleep(2);
    }

    /**
     * 시스템 환경 체크
     */
    public function checkEnvironment() {
        echo "=== 시스템 환경 체크 ===\n";

        // Chrome 설치 확인
        $chromeCheck = shell_exec('which google-chrome 2>/dev/null');
        if (empty($chromeCheck)) {
            $chromeCheck = shell_exec('which chromium-browser 2>/dev/null');
            if (empty($chromeCheck)) {
                echo "❌ Chrome/Chromium이 설치되어 있지 않습니다.\n";
                echo "💡 설치 명령: sudo apt-get install google-chrome-stable\n";
                return false;
            } else {
                echo "✅ Chromium 발견: " . trim($chromeCheck) . "\n";
            }
        } else {
            echo "✅ Google Chrome 발견: " . trim($chromeCheck) . "\n";
        }

        // 네트워크 연결 확인
        $pingResult = shell_exec('ping -c 1 google.com 2>/dev/null');
        if (strpos($pingResult, '1 received') !== false) {
            echo "✅ 인터넷 연결 정상\n";
        } else {
            echo "⚠️  인터넷 연결 확인 필요\n";
        }

        // 메모리 확인
        $memInfo = shell_exec('free -m | grep Mem');
        if (preg_match('/\s+(\d+)\s+(\d+)/', $memInfo, $matches)) {
            $totalMem = $matches[1];
            $usedMem = $matches[2];
            $freeMem = $totalMem - $usedMem;
            echo "💾 메모리: {$freeMem}MB 사용 가능\n";

            if ($freeMem < 500) {
                echo "⚠️  메모리 부족 - 500MB 이상 권장\n";
            }
        }

        echo "\n";
        return true;
    }

    /**
     * URL 접근 가능성 사전 체크
     */
    public function checkUrlAccessibility($url) {
        echo "🔍 URL 접근성 체크: $url\n";

        // cURL로 기본 접근 테스트
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD 요청만

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            echo "❌ cURL 에러: $error\n";
            return false;
        }

        echo "📡 HTTP 상태 코드: $httpCode\n";

        if ($httpCode >= 200 && $httpCode < 400) {
            echo "✅ URL 접근 가능\n";
            return true;
        } else {
            echo "❌ URL 접근 불가\n";
            return false;
        }
    }

    /**
     * 여러 방법으로 스크래핑 시도
     */
    public function scrapeUrl($url, $waitTime = 5, $timeout = 30) {
        if (!$this->checkUrlAccessibility($url)) {
            return false;
        }

        // 방법 1: 기본 스크래핑
        echo "\n📌 방법 1: 기본 Chrome 헤드리스 모드\n";
        $html = $this->tryBasicScraping($url, $waitTime, $timeout);
        if ($html) return $html;

        // 방법 2: User-Agent 변경
        echo "\n📌 방법 2: User-Agent 변경\n";
        $html = $this->tryWithUserAgent($url, $waitTime, $timeout);
        if ($html) return $html;

        // 방법 3: 더 관대한 설정
        echo "\n📌 방법 3: 더 관대한 Chrome 설정\n";
        $html = $this->tryRelaxedSettings($url, $waitTime, $timeout);
        if ($html) return $html;

        // 방법 4: cURL 폴백 (JavaScript 없이)
        echo "\n📌 방법 4: cURL 폴백 (정적 HTML만)\n";
        $html = $this->tryCurlFallback($url);
        if ($html) {
            echo "⚠️  JavaScript가 실행되지 않은 정적 HTML입니다.\n";
            return $html;
        }

        echo "\n❌ 모든 방법 실패\n";
        return false;
    }

    private function tryBasicScraping($url, $waitTime, $timeout) {
        $tempFile = '/tmp/scraper_basic_' . uniqid() . '.html';
        $errorFile = '/tmp/scraper_error_' . uniqid() . '.log';

        $command = sprintf(
            'timeout %d google-chrome --headless --no-sandbox --disable-gpu ' .
            '--user-data-dir=%s --window-size=1920,1080 --disable-web-security ' .
            '--no-first-run --disable-extensions --disable-dev-shm-usage ' .
            '--virtual-time-budget=%d --dump-dom %s > %s 2>%s',
            $timeout,
            escapeshellarg($this->userDataDir),
            $waitTime * 1000,
            escapeshellarg($url),
            escapeshellarg($tempFile),
            escapeshellarg($errorFile)
        );

        return $this->executeCommand($command, $tempFile, $errorFile);
    }

    private function tryWithUserAgent($url, $waitTime, $timeout) {
        $tempFile = '/tmp/scraper_ua_' . uniqid() . '.html';
        $errorFile = '/tmp/scraper_ua_error_' . uniqid() . '.log';

        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $command = sprintf(
            'timeout %d google-chrome --headless --no-sandbox --disable-gpu ' .
            '--user-data-dir=%s --window-size=1920,1080 ' .
            '--user-agent=%s --virtual-time-budget=%d --dump-dom %s > %s 2>%s',
            $timeout,
            escapeshellarg($this->userDataDir . '_ua'),
            escapeshellarg($userAgent),
            $waitTime * 1000,
            escapeshellarg($url),
            escapeshellarg($tempFile),
            escapeshellarg($errorFile)
        );

        return $this->executeCommand($command, $tempFile, $errorFile);
    }

    private function tryRelaxedSettings($url, $waitTime, $timeout) {
        $tempFile = '/tmp/scraper_relaxed_' . uniqid() . '.html';
        $errorFile = '/tmp/scraper_relaxed_error_' . uniqid() . '.log';

        // 더 관대한 설정
        $command = sprintf(
            'timeout %d google-chrome --headless --no-sandbox --disable-gpu ' .
            '--user-data-dir=%s --window-size=1920,1080 --disable-web-security ' .
            '--disable-features=TranslateUI --disable-extensions --disable-plugins ' .
            '--disable-images --disable-background-networking --disable-sync ' .
            '--disable-default-apps --disable-client-side-phishing-detection ' .
            '--allow-running-insecure-content --ignore-certificate-errors ' .
            '--virtual-time-budget=%d --dump-dom %s > %s 2>%s',
            $timeout + 30, // 더 긴 타임아웃
            escapeshellarg($this->userDataDir . '_relaxed'),
            ($waitTime + 5) * 1000, // 더 긴 대기
            escapeshellarg($url),
            escapeshellarg($tempFile),
            escapeshellarg($errorFile)
        );

        return $this->executeCommand($command, $tempFile, $errorFile);
    }

    private function tryCurlFallback($url) {
        echo "🌐 cURL로 정적 HTML 다운로드 시도...\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // gzip 지원

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            echo "❌ cURL 에러: $error\n";
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 400 && $html && strlen($html) > 100) {
            echo "✅ cURL 성공! 크기: " . number_format(strlen($html)) . " bytes\n";
            return $html;
        }

        echo "❌ cURL 실패 - HTTP $httpCode\n";
        return false;
    }

    private function executeCommand($command, $tempFile, $errorFile) {
        if ($this->debugMode) {
            echo "🔧 실행 명령: " . substr($command, 0, 100) . "...\n";
        }

        $startTime = microtime(true);
        shell_exec($command);
        $endTime = microtime(true);

        echo "⏱️  실행 시간: " . round(($endTime - $startTime), 2) . "초\n";

        // 에러 로그 확인
        if (file_exists($errorFile)) {
            $errors = file_get_contents($errorFile);
            if (!empty($errors) && $this->debugMode) {
                echo "⚠️  에러 로그: " . substr($errors, 0, 200) . "...\n";
            }
            unlink($errorFile);
        }

        if (file_exists($tempFile) && filesize($tempFile) > 100) { // 최소 100바이트
            $html = file_get_contents($tempFile);
            unlink($tempFile);

            echo "✅ 성공! 크기: " . number_format(strlen($html)) . " bytes\n";
            return $html;
        }

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        echo "❌ 실패: 유효한 콘텐츠 없음\n";
        return false;
    }

    /**
     * 상대 경로를 절대 경로로 변환 (2번째 프로세스)
     */
    public function convertRelativeToAbsolute($html, $baseUrl) {
        echo "\n=== 2번째 프로세스: 상대 경로 → 절대 경로 변환 ===\n";

        // URL 파싱하여 기본 정보 추출
        $parsedUrl = parse_url($baseUrl);
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';
        $baseUrlFormatted = $scheme . '://' . $host;

        echo "🔗 기준 URL: $baseUrlFormatted\n";

        $originalLength = strlen($html);
        $conversions = 0;

        // 1. src="/ 로 시작하는 것들 (src="// 제외)
        $html = preg_replace_callback(
            '/src=(["\'])\/(?!\/)([^"\']*)\1/i',
            function($matches) use ($baseUrlFormatted, &$conversions) {
                $conversions++;
                $quote = $matches[1];
                $path = $matches[2];
                return "src={$quote}{$baseUrlFormatted}/{$path}{$quote}";
            },
            $html
        );

        // 2. src="../ 로 시작하는 것들 (src="// 제외)
        $html = preg_replace_callback(
            '/src=(["\'])\.\.\/([^"\']*)\1/i',
            function($matches) use ($baseUrlFormatted, &$conversions) {
                $conversions++;
                $quote = $matches[1];
                $path = $matches[2];
                return "src={$quote}{$baseUrlFormatted}/../{$path}{$quote}";
            },
            $html
        );

        // 3. href="/ 로 시작하는 것들 (href="// 제외)
        $html = preg_replace_callback(
            '/href=(["\'])\/(?!\/)([^"\']*)\1/i',
            function($matches) use ($baseUrlFormatted, &$conversions) {
                $conversions++;
                $quote = $matches[1];
                $path = $matches[2];
                return "href={$quote}{$baseUrlFormatted}/{$path}{$quote}";
            },
            $html
        );

        // 4. href="../ 로 시작하는 것들 (href="// 제외)
        $html = preg_replace_callback(
            '/href=(["\'])\.\.\/([^"\']*)\1/i',
            function($matches) use ($baseUrlFormatted, &$conversions) {
                $conversions++;
                $quote = $matches[1];
                $path = $matches[2];
                return "href={$quote}{$baseUrlFormatted}/../{$path}{$quote}";
            },
            $html
        );

        // 5. url('/ 로 시작하는 것들
        $html = preg_replace_callback(
            '/url\((["\'])\.\.\/([^"\']*)\1/i',
            function($matches) use ($baseUrlFormatted, &$conversions) {
                $conversions++;
                $quote = $matches[1];
                $path = $matches[2];
                return "href={$quote}{$baseUrlFormatted}/../{$path}{$quote}";
            },
            $html
        );

        $newLength = strlen($html);
        $sizeChange = $newLength - $originalLength;

        echo "✅ 변환 완료!\n";
        echo "🔄 총 변환 횟수: {$conversions}개\n";
        echo "📊 크기 변화: " . ($sizeChange >= 0 ? '+' : '') . number_format($sizeChange) . " bytes\n";

        // 변환 결과 미리보기 (처음 3개)
        if ($conversions > 0) {
            echo "\n🔍 변환 결과 미리보기:\n";
            $this->showConversionPreview($html, $baseUrlFormatted);
        }

        return $html;
    }

    /**
     * 변환 결과 미리보기 표시
     */
    private function showConversionPreview($html, $baseUrl) {
        $patterns = [
            '/src=["\']' . preg_quote($baseUrl, '/') . '\/[^"\']*["\']/i',
            '/href=["\']' . preg_quote($baseUrl, '/') . '\/[^"\']*["\']/i'
        ];

        $found = 0;
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    if ($found >= 3) break 2; // 최대 3개만 표시

                    $found++;
                    $matchText = $match[0];
                    echo "   {$found}. " . substr($matchText, 0, 80) . "\n";
                }
            }
        }

        if ($found == 0) {
            echo "   (변환된 항목을 찾을 수 없습니다)\n";
        }
    }

    /**
     * 변환 통계 분석
     */
    public function analyzeConversions($html) {
        echo "\n=== 변환 통계 분석 ===\n";

        // 각 패턴별 개수 세기
        $stats = [
            'src_absolute' => 0,
            'href_absolute' => 0,
            'src_protocol_relative' => 0,
            'href_protocol_relative' => 0,
            'src_relative_remaining' => 0,
            'href_relative_remaining' => 0
        ];

        // 절대 경로 (http:// 또는 https://)
        $stats['src_absolute'] = preg_match_all('/src=["\']https?:\/\/[^"\']*["\']/i', $html);
        $stats['href_absolute'] = preg_match_all('/href=["\']https?:\/\/[^"\']*["\']/i', $html);

        // 프로토콜 상대 경로 (//)
        $stats['src_protocol_relative'] = preg_match_all('/src=["\']\/\/[^"\']*["\']/i', $html);
        $stats['href_protocol_relative'] = preg_match_all('/href=["\']\/\/[^"\']*["\']/i', $html);

        // 남은 상대 경로
        $stats['src_relative_remaining'] = preg_match_all('/src=["\'](?!https?:\/\/|\/\/)[^"\']*["\']/i', $html);
        $stats['href_relative_remaining'] = preg_match_all('/href=["\'](?!https?:\/\/|\/\/)[^"\']*["\']/i', $html);

        echo "📊 src 속성:\n";
        echo "   ✅ 절대 경로: {$stats['src_absolute']}개\n";
        echo "   🌐 프로토콜 상대: {$stats['src_protocol_relative']}개\n";
        echo "   ⚠️  남은 상대 경로: {$stats['src_relative_remaining']}개\n";

        echo "📊 href 속성:\n";
        echo "   ✅ 절대 경로: {$stats['href_absolute']}개\n";
        echo "   🌐 프로토콜 상대: {$stats['href_protocol_relative']}개\n";
        echo "   ⚠️  남은 상대 경로: {$stats['href_relative_remaining']}개\n";

        return $stats;
    }

    /**
     * 파일 저장 (변환 옵션 추가)
     */
    public function saveToFile($html, $url, $customFilename = null, $convertPaths = false, $saveToDOC = false) {
        // 상대 경로 변환 처리
        if ($convertPaths) {
            $html = $this->convertRelativeToAbsolute($html, $url);
            $this->analyzeConversions($html);
        }

        if ($customFilename) {
            $filename = $customFilename;
        } else {
            $domain = parse_url($url, PHP_URL_HOST);
            $domain = str_replace('www.', '', $domain);
            $domain = preg_replace('/[^a-zA-Z0-9.-]/', '_', $domain);
            $timestamp = date('Y-m-d_H-i-s');
            $suffix = $convertPaths ? '_converted' : '';
            $filename = "{$domain}_{$timestamp}{$suffix}.html";
        }

        $filepath = $this->outputDir . $filename;
        $saved = file_put_contents($filepath, $html);

        if ($saved) {
            echo "💾 파일 저장: $filepath (" . number_format($saved) . " bytes)\n";
            return $filepath;
        }

        echo "❌ 파일 저장 실패\n";
        return false;
    }

    /**
     * 배치 스크래핑 (실패 재시도 포함)
     */
    public function batchScrape($urls, $waitTime = 5, $timeout = 30, $retries = 1) {
        $results = [];
        $total = count($urls);

        echo "🚀 배치 스크래핑 시작: {$total}개 URL\n";
        echo str_repeat("=", 50) . "\n";

        foreach ($urls as $index => $url) {
            echo "\n📍 진행률: " . ($index + 1) . "/{$total} - $url\n";

            $html = false;
            $attempts = 0;

            while (!$html && $attempts <= $retries) {
                if ($attempts > 0) {
                    echo "🔄 재시도 " . $attempts . "/{$retries}\n";
                    sleep(5); // 재시도 전 대기
                }

                $html = $this->scrapeUrl($url, $waitTime, $timeout);
                $attempts++;
            }

            if ($html) {
                $filepath = $this->saveToFile($html, $url);
                $results[$url] = [
                    'success' => true,
                    'filepath' => $filepath,
                    'size' => strlen($html),
                    'attempts' => $attempts
                ];
            } else {
                $results[$url] = [
                    'success' => false,
                    'filepath' => null,
                    'size' => 0,
                    'attempts' => $attempts
                ];
            }

            // 다음 요청 전 대기
            if ($index < $total - 1) {
                sleep(3);
            }
        }

        echo "\n" . str_repeat("=", 50) . "\n";
        echo "✅ 배치 처리 완료!\n";

        return $results;
    }

    /**
     * 정리 작업
     */
    public function cleanup() {
        if (is_dir($this->userDataDir)) {
            shell_exec('rm -rf ' . escapeshellarg($this->userDataDir) . '*');
        }
        shell_exec('sudo pkill -9 chrome 2>/dev/null');
    }

    public function __destruct() {
        $this->cleanup();
    }
}