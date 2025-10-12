<?php

declare(strict_types=1);

namespace CmsOrbit\Vigilance\StatusGetters;

use CmsOrbit\Vigilance\StatusGetters\Linux;
use CmsOrbit\Vigilance\StatusGetters\Windows;
use Illuminate\Support\Facades\DB;

abstract class AbstractStatusGetter
{
    /**
     * CPU 데이터를 수집합니다.
     *
     * @return array{
     *     load_avg: array<float>,
     *     total_usage_percent: float,
     *     core_details: array<array{core: int, usage_percent: float}>
     * }
     */
    abstract public function getCpuData(): array;

    /**
     * 메모리 데이터를 수집합니다.
     *
     * @return array{
     *     total_mb: int,
     *     used_mb: int,
     *     usage_percent: float,
     *     process_details: array<array{pid: int, command: string, memory_mb: int}>
     * }
     */
    abstract public function getMemoryData(): array;

    /**
     * 디스크 데이터를 수집합니다.
     *
     * @param array<string> $paths
     * @return array<array{
     *     mount: string,
     *     total_mb: int,
     *     used_mb: int,
     *     used_percent: float
     * }>
     */
    abstract public function getDiskData(array $paths): array;

    /**
     * 네트워크 데이터를 수집합니다.
     *
     * @return array{
     *     rx_bytes: int,
     *     tx_bytes: int,
     *     rx_rate_mbps: float,
     *     tx_rate_mbps: float
     * }
     */
    abstract public function getNetworkData(): array;

    /**
     * HTTP 헬스 체크를 수행합니다.
     *
     * @param string $url
     * @return array{
     *     url: string,
     *     status: string,
     *     response_time_ms: int,
     *     status_code: int|null
     * }
     */
    public function getHttpHealthCheck(string $url): array
    {
        $startTime = microtime(true);
        $result = [
            'url' => $url,
            'status' => 'fail',
            'response_time_ms' => 0,
            'status_code' => null,
        ];

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $endTime = microtime(true);
            
            $result['response_time_ms'] = (int) (($endTime - $startTime) * 1000);
            $result['status_code'] = (int) $statusCode;
            
            if ($response !== false && $statusCode >= 200 && $statusCode < 400) {
                $result['status'] = 'ok';
            }
            
            curl_close($ch);
        } catch (\Exception $e) {
            $result['status'] = 'error';
        }

        return $result;
    }

    /**
     * 시스템 정보를 수집합니다.
     *
     * @return array{
     *     os_name: string,
     *     os_version: string,
     *     cpu_cores: int,
     *     php_version: string,
     *     laravel_version: string
     * }
     */
    abstract public function getSystemInfo(): array;

    /**
     * 헬스 체크를 수행합니다.
     *
     * @return array{
     *     db_connection: string,
     *     queue_status: string,
     *     message: string
     * }
     */
    public function getHealthCheck(): array
    {
        $health = [
            'db_connection' => 'ok',
            'queue_status' => 'ok',
            'message' => 'All systems operational',
        ];

        // DB 연결 체크
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $health['db_connection'] = 'fail';
            $health['message'] = 'Database connection failed: ' . $e->getMessage();
        }

        // 큐 상태 체크 (선택적)
        try {
            // 큐 실행 여부를 체크하는 로직 추가 가능
            // 예: 최근 job 처리 시간 확인
        } catch (\Exception $e) {
            $health['queue_status'] = 'fail';
        }

        return $health;
    }

    /**
     * 현재 운영체제에 맞는 StatusGetter 인스턴스를 생성합니다.
     */
    public static function create(): self
    {
        $os = PHP_OS_FAMILY;

        return match ($os) {
            'Linux', 'BSD' => self::createLinuxGetter(),
            'Darwin' => new MacStatusGetter(),
            'Windows' => self::createWindowsGetter(),
            default => throw new \RuntimeException("Unsupported OS: {$os}"),
        };
    }

    /**
     * Linux 배포판별 StatusGetter를 생성합니다.
     */
    private static function createLinuxGetter(): self
    {
        // /etc/os-release 파일 읽기
        if (file_exists('/etc/os-release')) {
            $osRelease = @file_get_contents('/etc/os-release');
            if ($osRelease !== false) {
                // ID와 VERSION_ID 추출
                preg_match('/^ID=(.+)$/m', $osRelease, $idMatch);
                preg_match('/^VERSION_ID="?([^"\n]+)"?$/m', $osRelease, $versionMatch);

                $distro = isset($idMatch[1]) ? strtolower(trim($idMatch[1], '"')) : '';
                $version = isset($versionMatch[1]) ? trim($versionMatch[1], '"') : '';

                // Ubuntu
                if ($distro === 'ubuntu') {
                    $majorVersion = (int) explode('.', $version)[0];
                    return match (true) {
                        $majorVersion >= 24 => new Linux\Ubuntu24StatusGetter(),
                        $majorVersion >= 22 => new Linux\Ubuntu22StatusGetter(),
                        $majorVersion >= 20 => new Linux\Ubuntu20StatusGetter(),
                        default => new Linux\Ubuntu20StatusGetter(),
                    };
                }

                // CentOS
                if ($distro === 'centos') {
                    return new Linux\CentosStatusGetter();
                }

                // Rocky Linux
                if ($distro === 'rocky') {
                    return new Linux\RockyStatusGetter();
                }

                // Debian
                if ($distro === 'debian') {
                    return new Linux\DebianStatusGetter();
                }
            }
        }

        // 기본 Linux Getter 반환
        return new LinuxStatusGetter();
    }

    /**
     * Windows 버전별 StatusGetter를 생성합니다.
     */
    private static function createWindowsGetter(): self
    {
        // Windows 버전 확인
        $output = shell_exec('ver 2>nul');
        if ($output !== null) {
            $output = trim($output);

            // Windows 11 (Build 22000 이상)
            if (preg_match('/Version 10\.0\.(\d+)/', $output, $matches)) {
                $build = (int) $matches[1];
                if ($build >= 22000) {
                    return new Windows\Windows11StatusGetter();
                }
                return new Windows\Windows10StatusGetter();
            }

            // Windows Server 감지
            if (stripos($output, 'Server') !== false) {
                return new Windows\WindowsServerStatusGetter();
            }
        }

        // 기본 Windows Getter 반환
        return new WindowsStatusGetter();
    }

    /**
     * 쉘 명령어를 실행하고 결과를 반환합니다.
     */
    protected function executeCommand(string $command): string
    {
        $output = shell_exec($command);
        return $output !== null ? trim($output) : '';
    }

    /**
     * 바이트를 MB로 변환합니다.
     */
    protected function bytesToMb(int $bytes): int
    {
        return (int) round($bytes / 1024 / 1024);
    }

    /**
     * 바이트를 GB로 변환합니다.
     */
    protected function bytesToMb(int $bytes): float
    {
        return round($bytes / 1024 / 1024 / 1024, 2);
    }
}

