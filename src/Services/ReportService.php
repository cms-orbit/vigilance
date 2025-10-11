<?php

declare(strict_types=1);

namespace CmsOrbit\Vigilance\Services;

use CmsOrbit\Vigilance\StatusGetters\AbstractStatusGetter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ReportService
{
    private Client $client;
    private string $baseUrl;
    private string $serverUuid;
    private int $timeout;
    private array $retryConfig;

    const REPORT_PATH = '/api/status/report';

    public function __construct()
    {
        $this->baseUrl = config('vigilance.base_url');
        $this->serverUuid = config('vigilance.server_uuid');
        $this->timeout = config('vigilance.timeout', 10);
        $this->retryConfig = config('vigilance.retry', [
            'max_attempts' => 3,
            'initial_delay' => 1,
            'max_delay' => 60,
        ]);

        $this->client = new Client([
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'verify' => false, // SSL 인증서 검증 비활성화 (로컬 개발 환경용)
            'allow_redirects' => [
                'max' => 5,
                'strict' => true, // POST 메서드를 유지
                'referer' => true,
                'protocols' => ['http', 'https'],
            ],
        ]);
    }

    /**
     * 서버 상태를 수집하고 Sentinel-Hub로 전송합니다.
     */
    public function report(): bool
    {
        try {
            $payload = $this->collectPayload();
            return $this->sendWithRetry($payload);
        } catch (\Exception $e) {
            Log::error('Vigilance report failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * 전송할 페이로드를 수집합니다.
     */
    private function collectPayload(): array
    {
        $statusGetter = AbstractStatusGetter::create();
        $logMonitor = new LogMonitor(config('vigilance.log_monitor_prefixes', []));

        $errors = $logMonitor->collectErrors();

        $payload = [
            'uuid' => $this->serverUuid,
            'reported_at' => now()->toIso8601String(),
            'referer' => request()->header('referer') ?? request()->ip(),
            'system_info' => $statusGetter->getSystemInfo(),
            'metrics' => [
                'cpu' => $statusGetter->getCpuData(),
                'memory' => $statusGetter->getMemoryData(),
                'disks' => $statusGetter->getDiskData(config('vigilance.disk_paths', ['/'])),
                'health_check' => $statusGetter->getHealthCheck(),
            ],
            'errors' => $errors,
        ];

        return $payload;
    }

    /**
     * 재시도 로직과 함께 데이터를 전송합니다.
     */
    private function sendWithRetry(array $payload): bool
    {
        $maxAttempts = $this->retryConfig['max_attempts'];
        $delay = $this->retryConfig['initial_delay'];
        $maxDelay = $this->retryConfig['max_delay'];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->client->post($this->baseUrl . self::REPORT_PATH, [
                    'json' => $payload,
                ]);

                if ($response->getStatusCode() === 200) {
                    Log::info('Vigilance report sent successfully', [
                        'uuid' => $this->serverUuid,
                    ]);
                    return true;
                }
            } catch (GuzzleException $e) {
                Log::warning("Vigilance report attempt {$attempt} failed", [
                    'error' => $e->getMessage(),
                    'uuid' => $this->serverUuid,
                ]);

                if ($attempt < $maxAttempts) {
                    sleep($delay);
                    // 지수 백오프
                    $delay = min($delay * 2, $maxDelay);
                }
            }
        }

        Log::error('Vigilance report failed after all retry attempts', [
            'uuid' => $this->serverUuid,
            'attempts' => $maxAttempts,
        ]);

        return false;
    }

    /**
     * 서버 UUID를 검증합니다.
     */
    public function validateUuid(): bool
    {
        return !empty($this->serverUuid) && strlen($this->serverUuid) === 36;
    }

    /**
     * 시스템 정보를 가져옵니다.
     */
    public function getSystemInfo(): array
    {
        $statusGetter = AbstractStatusGetter::create();
        return $statusGetter->getSystemInfo();
    }
}

