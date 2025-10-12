<?php

declare(strict_types=1);

namespace CmsOrbit\Vigilance\StatusGetters;

class MacStatusGetter extends AbstractStatusGetter
{
    /**
     * CPU 데이터를 수집합니다.
     */
    public function getCpuData(): array
    {
        // Load average 가져오기
        $loadAvg = sys_getloadavg();

        // CPU 사용률 계산
        $cpuUsage = $this->calculateCpuUsage();

        return [
            'load_avg' => $loadAvg ?: [0.0, 0.0, 0.0],
            'total_usage_percent' => $cpuUsage,
            'core_details' => [],
        ];
    }

    /**
     * 메모리 데이터를 수집합니다.
     */
    public function getMemoryData(): array
    {
        // vm_stat을 사용하여 메모리 정보 가져오기
        $vmStat = $this->executeCommand('vm_stat');

        $pageSize = 4096; // macOS 기본 페이지 크기

        // Pages free 추출
        preg_match('/Pages free:\s+(\d+)/', $vmStat, $freeMatch);
        preg_match('/Pages active:\s+(\d+)/', $vmStat, $activeMatch);
        preg_match('/Pages inactive:\s+(\d+)/', $vmStat, $inactiveMatch);
        preg_match('/Pages wired down:\s+(\d+)/', $vmStat, $wiredMatch);

        $free = isset($freeMatch[1]) ? (int)$freeMatch[1] : 0;
        $active = isset($activeMatch[1]) ? (int)$activeMatch[1] : 0;
        $inactive = isset($inactiveMatch[1]) ? (int)$inactiveMatch[1] : 0;
        $wired = isset($wiredMatch[1]) ? (int)$wiredMatch[1] : 0;

        $freeBytes = $free * $pageSize;
        $usedBytes = ($active + $inactive + $wired) * $pageSize;
        $totalBytes = $freeBytes + $usedBytes;

        $usagePercent = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : 0.0;

        // 프로세스별 메모리 사용량 (상위 5개)
        $processDetails = $this->getTopMemoryProcesses(5);

        return [
            'total_mb' => $this->bytesToMb($totalBytes),
            'used_mb' => $this->bytesToMb($usedBytes),
            'used_percent' => $usagePercent,
            'process_details' => $processDetails,
        ];
    }

    /**
     * 디스크 데이터를 수집합니다.
     */
    public function getDiskData(array $paths): array
    {
        $disks = [];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $total = disk_total_space($path);
            $free = disk_free_space($path);

            if ($total === false || $free === false) {
                continue;
            }

            $used = $total - $free;
            $usagePercent = $total > 0 ? round(($used / $total) * 100, 2) : 0.0;

            $disks[] = [
                'mount' => $path,
                'total_mb' => $this->bytesToMb((int) $total),
                'used_mb' => $this->bytesToMb((int) $used),
                'used_percent' => $usagePercent,
            ];
        }

        return $disks;
    }

    /**
     * 네트워크 데이터를 수집합니다.
     */
    public function getNetworkData(): array
    {
        $output = $this->executeCommand('netstat -ib | grep -e "en0" -e "en1"');
        $lines = explode("\n", $output);
        
        $totalRx = 0;
        $totalTx = 0;
        
        foreach ($lines as $line) {
            if (preg_match('/\s+(\d+)\s+\d+\s+\d+\s+(\d+)\s+\d+\s+\d+/', $line, $matches)) {
                $totalRx += (int) $matches[1];
                $totalTx += (int) $matches[2];
            }
        }

        return [
            'rx_bytes' => $totalRx,
            'tx_bytes' => $totalTx,
            'rx_rate_mbps' => round($totalRx / 1024 / 1024, 2),
            'tx_rate_mbps' => round($totalTx / 1024 / 1024, 2),
        ];
    }

    /**
     * 시스템 정보를 수집합니다.
     */
    public function getSystemInfo(): array
    {
        // macOS 버전 정보
        $osVersion = $this->executeCommand('sw_vers -productVersion');
        $osName = $this->executeCommand('sw_vers -productName');

        // CPU 코어 수
        $cpuCores = (int) $this->executeCommand('sysctl -n hw.ncpu');

        return [
            'os_name' => $osName ?: 'macOS',
            'os_version' => $osVersion ?: 'Unknown',
            'cpu_cores' => $cpuCores > 0 ? $cpuCores : 1,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];
    }

    /**
     * CPU 사용률을 계산합니다.
     */
    private function calculateCpuUsage(): float
    {
        // top 명령어를 사용하여 CPU 사용률 가져오기
        $output = $this->executeCommand('top -l 1 -n 0 | grep "CPU usage"');

        if (preg_match('/(\d+\.\d+)% user/', $output, $userMatch) &&
            preg_match('/(\d+\.\d+)% sys/', $output, $sysMatch)) {
            $user = (float) $userMatch[1];
            $sys = (float) $sysMatch[1];
            return round($user + $sys, 2);
        }

        return 0.0;
    }

    /**
     * 메모리를 가장 많이 사용하는 프로세스를 가져옵니다.
     */
    private function getTopMemoryProcesses(int $limit): array
    {
        // top 명령어를 사용하여 메모리 사용량이 높은 프로세스 가져오기
        $command = "top -l 1 -o mem -n " . $limit . " -stats pid,command,mem 2>/dev/null";
        $output = $this->executeCommand($command);

        $processes = [];
        $lines = explode("\n", $output);

        // 헤더 라인 건너뛰기
        $dataStarted = false;

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            // PID로 시작하는 라인부터 데이터 시작
            if (preg_match('/^\d+/', $line)) {
                $dataStarted = true;
            }

            if (!$dataStarted) {
                continue;
            }

            // PID, COMMAND, MEM 파싱
            if (preg_match('/^(\d+)\s+(.+?)\s+([\d\.]+[MKG]?)$/', $line, $matches)) {
                $memStr = $matches[3];
                $memMb = 0;

                // 메모리 문자열을 MB로 변환
                if (preg_match('/([\d\.]+)([MKG])?/', $memStr, $memMatches)) {
                    $value = (float) $memMatches[1];
                    $unit = $memMatches[2] ?? 'M';

                    $memMb = match($unit) {
                        'K' => (int) ($value / 1024),
                        'M' => (int) $value,
                        'G' => (int) ($value * 1024),
                        default => (int) $value
                    };
                }

                $processes[] = [
                    'pid' => (int) $matches[1],
                    'command' => trim($matches[2]),
                    'memory_mb' => $memMb,
                ];

                if (count($processes) >= $limit) {
                    break;
                }
            }
        }

        return $processes;
    }

    /**
     * 전체 메모리를 MB로 가져옵니다.
     */
    private function getTotalMemoryMb(): int
    {
        $bytes = (int) $this->executeCommand('sysctl -n hw.memsize');
        return $this->bytesToMb($bytes);
    }
}

