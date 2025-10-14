<?php

declare(strict_types=1);

namespace CmsOrbit\Vigilance\StatusGetters;

class LinuxStatusGetter extends AbstractStatusGetter
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

        // 코어별 상세 정보
        $coreDetails = $this->getCoreDetails();

        return [
            'load_avg' => $loadAvg ?: [0.0, 0.0, 0.0],
            'total_usage_percent' => $cpuUsage,
            'core_details' => $coreDetails,
        ];
    }

    /**
     * 메모리 데이터를 수집합니다.
     */
    public function getMemoryData(): array
    {
        $meminfo = $this->parseMeminfo();

        $total = $meminfo['MemTotal'] ?? 0;
        $available = $meminfo['MemAvailable'] ?? 0;
        $used = $total - $available;

        $usagePercent = $total > 0 ? round(($used / $total) * 100, 2) : 0.0;

        // 프로세스별 메모리 사용량 (상위 5개)
        $processDetails = $this->getTopMemoryProcesses(5);

        return [
            'total_mb' => $this->bytesToMb($total * 1024),
            'used_mb' => $this->bytesToMb($used * 1024),
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
        $netDev = @file_get_contents('/proc/net/dev');

        if ($netDev === false) {
            return [
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'rx_rate_mbps' => 0.0,
                'tx_rate_mbps' => 0.0,
            ];
        }

        $lines = explode("\n", $netDev);
        $totalRx = 0;
        $totalTx = 0;

        foreach ($lines as $line) {
            // lo (loopback) 제외, eth, ens, enp로 시작하는 인터페이스만
            if (preg_match('/^\s*(eth|ens|enp|wlan)[\d\w]+:\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $matches)) {
                $totalRx += (int) $matches[2];
                $totalTx += (int) $matches[3];
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
        // OS 정보
        $osInfo = $this->executeCommand('cat /etc/os-release | grep PRETTY_NAME | cut -d "=" -f2 | tr -d \'"\' ');
        if (empty($osInfo)) {
            $osInfo = php_uname('s') . ' ' . php_uname('r');
        }

        // CPU 코어 수
        $cpuCores = (int) $this->executeCommand('nproc');

        return [
            'os_name' => 'Linux',
            'os_version' => $osInfo,
            'cpu_cores' => $cpuCores > 0 ? $cpuCores : 1,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];
    }

    /**
     * /proc/meminfo를 파싱합니다.
     */
    private function parseMeminfo(): array
    {
        $meminfo = [];
        $content = @file_get_contents('/proc/meminfo');

        if ($content === false) {
            return $meminfo;
        }

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                $meminfo[$matches[1]] = (int) $matches[2];
            }
        }

        return $meminfo;
    }

    /**
     * CPU 사용률을 계산합니다.
     */
    private function calculateCpuUsage(): float
    {
        $stat1 = $this->getCpuStat();
        usleep(100000); // 0.1초 대기
        $stat2 = $this->getCpuStat();

        $total1 = array_sum($stat1);
        $total2 = array_sum($stat2);
        $idle1 = $stat1[3];
        $idle2 = $stat2[3];

        $totalDiff = $total2 - $total1;
        $idleDiff = $idle2 - $idle1;

        if ($totalDiff <= 0) {
            return 0.0;
        }

        return round((1 - ($idleDiff / $totalDiff)) * 100, 2);
    }

    /**
     * /proc/stat에서 CPU 통계를 가져옵니다.
     */
    private function getCpuStat(): array
    {
        $content = @file_get_contents('/proc/stat');
        if ($content === false) {
            return [0, 0, 0, 0, 0, 0, 0, 0];
        }

        $lines = explode("\n", $content);
        $cpuLine = $lines[0];

        if (preg_match('/^cpu\s+(.+)/', $cpuLine, $matches)) {
            return array_map('intval', explode(' ', trim($matches[1])));
        }

        return [0, 0, 0, 0, 0, 0, 0, 0];
    }

    /**
     * 코어별 상세 정보를 가져옵니다.
     */
    private function getCoreDetails(): array
    {
        // 간단히 구현 (실제로는 더 정교한 구현 필요)
        return [];
    }

    /**
     * 메모리를 가장 많이 사용하는 프로세스를 가져옵니다.
     */
    private function getTopMemoryProcesses(int $limit): array
    {
        $command = "ps aux --sort=-%mem | head -n " . ($limit + 1) . " | tail -n {$limit}";
        $output = $this->executeCommand($command);

        $processes = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            // ps aux 출력 파싱
            $parts = preg_split('/\s+/', $line, 11);
            if (count($parts) < 11) {
                continue;
            }

            $processes[] = [
                'pid' => (int) $parts[1],
                'command' => $parts[10],
                'memory_mb' => $this->bytesToMb((int) ($parts[5] * 1024)),
            ];
        }

        return $processes;
    }
}

