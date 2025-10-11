<?php

declare(strict_types=1);

namespace CmsOrbit\Vigilance\Services;

class LogMonitor
{
    private array $logPrefixes;
    private array $processedHashes = [];
    private int $checkInterval = 60; // 1분

    public function __construct(array $logPrefixes)
    {
        $this->logPrefixes = $logPrefixes;
    }

    /**
     * 로그 파일을 모니터링하고 오류를 수집합니다.
     *
     * @return array<array{
     *     hash: string,
     *     message_summary: string,
     *     file: string,
     *     line: int,
     *     count: int
     * }>
     */
    public function collectErrors(): array
    {
        $errors = [];
        $errorCounts = [];

        // prefix에 매칭되는 모든 로그 파일 찾기
        $logFiles = $this->findLogFiles();

        foreach ($logFiles as $logPath) {
            if (!file_exists($logPath)) {
                continue;
            }

            $entries = $this->parseLogFile($logPath);

            foreach ($entries as $entry) {
                $hash = $this->generateHash($entry);

                if (!isset($errorCounts[$hash])) {
                    $errorCounts[$hash] = [
                        'hash' => $hash,
                        'message_summary' => $this->summarizeMessage($entry['message']),
                        'file' => $entry['file'] ?? 'unknown',
                        'line' => $entry['line'] ?? 0,
                        'count' => 0,
                    ];
                }

                $errorCounts[$hash]['count']++;
            }
        }

        return array_values($errorCounts);
    }

    /**
     * prefix에 매칭되는 로그 파일들을 찾습니다.
     */
    private function findLogFiles(): array
    {
        $files = [];

        foreach ($this->logPrefixes as $prefix) {
            // prefix.log 형태의 파일
            $mainFile = $prefix . '.log';
            if (file_exists($mainFile)) {
                $files[] = $mainFile;
            }

            // prefix-*.log 형태의 파일들 (데일리 로그)
            $pattern = $prefix . '-*.log';
            $matchedFiles = glob($pattern);

            if ($matchedFiles !== false) {
                foreach ($matchedFiles as $file) {
                    $files[] = $file;
                }
            }
        }

        return array_unique($files);
    }

    /**
     * 로그 파일을 파싱하여 오류 엔트리를 추출합니다.
     */
    private function parseLogFile(string $logPath): array
    {
        $entries = [];

        // 파일 크기 확인 (10MB 이상이면 tail 사용)
        $fileSize = @filesize($logPath);
        if ($fileSize === false) {
            return $entries;
        }

        if ($fileSize > 10 * 1024 * 1024) {
            // 큰 파일은 tail로 최근 1000줄만 읽기
            $lines = $this->getTailLines($logPath, 1000);
        } else {
            // 작은 파일은 전체 읽기
            $content = @file_get_contents($logPath);
            if ($content === false) {
                return $entries;
            }
            $lines = explode("\n", $content);
        }

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            // Laravel 로그 형식 파싱
            if ($this->isErrorLine($line)) {
                $entry = $this->parseLogLine($line);
                if ($entry) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * tail 명령어를 사용하여 파일의 마지막 N줄을 읽습니다.
     */
    private function getTailLines(string $file, int $lines): array
    {
        $command = "tail -n {$lines} " . escapeshellarg($file) . " 2>/dev/null";
        $output = shell_exec($command);

        if ($output === null) {
            return [];
        }

        return explode("\n", trim($output));
    }

    /**
     * 로그 라인이 오류인지 확인합니다.
     */
    private function isErrorLine(string $line): bool
    {
        return preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line) === 1;
    }

    /**
     * 로그 라인을 파싱합니다.
     */
    private function parseLogLine(string $line): ?array
    {
        // Laravel 로그 형식: [날짜] env.LEVEL: 메시지 {"exception":"..."}
        $pattern = '/\[(\d{4}-\d{2}-\d{2}[^\]]+)\]\s+(\w+)\.(\w+):\s+(.+)/';

        if (preg_match($pattern, $line, $matches)) {
            $message = $matches[4];

            // 파일과 라인 번호 추출
            $file = 'unknown';
            $lineNum = 0;

            // 패턴 1: "at /path/to/file.php:123)" (exception 메시지)
            if (preg_match('/at\s+([\/\\\\].+?\.php):(\d+)\)?/', $message, $fileMatches)) {
                $file = $fileMatches[1];
                $lineNum = (int) $fileMatches[2];
            }
            // 패턴 2: "in /path/to/file.php on line 123"
            elseif (preg_match('/in\s+([\/\\\\].+?\.php)\s+on\s+line\s+(\d+)/', $message, $fileMatches)) {
                $file = $fileMatches[1];
                $lineNum = (int) $fileMatches[2];
            }
            // 패턴 3: 일반 경로 "/path/to/file.php:123"
            elseif (preg_match('/([\/\\\\].+?\.php):(\d+)/', $message, $fileMatches)) {
                $file = $fileMatches[1];
                $lineNum = (int) $fileMatches[2];
            }

            // file 경로를 항상 상대 경로로 변환
            $basePath = base_path();
            if (str_starts_with($file, $basePath)) {
                $file = substr($file, strlen($basePath) + 1);
            }

            // 최대 길이 제한 (DB 필드 크기)
            if (strlen($file) > 250) {
                $file = '...' . substr($file, -247);
            }

            return [
                'timestamp' => $matches[1],
                'level' => $matches[3],
                'message' => $message,
                'file' => $file,
                'line' => $lineNum,
            ];
        }

        return null;
    }

    /**
     * 오류 엔트리에서 고유 해시를 생성합니다.
     */
    private function generateHash(array $entry): string
    {
        $hashData = $entry['message'] . '|' . $entry['file'] . '|' . $entry['line'];
        return hash('sha256', $hashData);
    }

    /**
     * 메시지를 요약합니다 (최대 200자).
     */
    private function summarizeMessage(string $message): string
    {
        if (mb_strlen($message) > 200) {
            return mb_substr($message, 0, 200) . '...';
        }
        return $message;
    }
}

