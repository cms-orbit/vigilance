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
     * 로그 파일을 파싱하여 오류 엔트리를 추출합니다.
     */
    private function parseLogFile(string $logPath): array
    {
        $entries = [];
        $content = @file_get_contents($logPath);

        if ($content === false) {
            return $entries;
        }

        // 마지막 수정 시간 확인 (1분 이내의 로그만 처리)
        $lastModified = filemtime($logPath);
        if ($lastModified === false || time() - $lastModified > $this->checkInterval) {
            // 파일이 최근에 수정되지 않았으면 최근 N줄만 읽기
            $lines = $this->getLastLines($logPath, 100);
        } else {
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
     * 파일의 마지막 N줄을 읽습니다.
     */
    private function getLastLines(string $file, int $lines): array
    {
        $handle = @fopen($file, 'r');
        if (!$handle) {
            return [];
        }

        $buffer = [];
        $linecounter = 0;

        fseek($handle, -1, SEEK_END);
        $pos = ftell($handle);

        while ($linecounter < $lines && $pos > 0) {
            $char = fgetc($handle);
            if ($char === "\n") {
                $linecounter++;
                if ($linecounter < $lines) {
                    $line = stream_get_line($handle, 0, "\n");
                    if ($line !== false) {
                        $buffer[] = strrev($line);
                    }
                }
            }
            $pos--;
            fseek($handle, $pos, SEEK_SET);
        }

        fclose($handle);
        return array_reverse($buffer);
    }

    /**
     * 로그 라인이 오류인지 확인합니다.
     */
    private function isErrorLine(string $line): bool
    {
        return preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY)\]/', $line) === 1;
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

            if (preg_match('/in\s+(.+\.php)\s+on\s+line\s+(\d+)/', $message, $fileMatches)) {
                $file = $fileMatches[1];
                $lineNum = (int) $fileMatches[2];
            } elseif (preg_match('/(.+\.php):(\d+)/', $message, $fileMatches)) {
                $file = $fileMatches[1];
                $lineNum = (int) $fileMatches[2];
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

