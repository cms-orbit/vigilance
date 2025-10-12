<?php

declare(strict_types=1);

namespace CmsOrbit\Vigilance\StatusGetters;

class WindowsStatusGetter extends AbstractStatusGetter
{
    /**
     * CPU 데이터를 수집합니다.
     */
    public function getCpuData(): array
    {
        // Windows에서는 load average가 없으므로 기본값 사용
        $loadAvg = [0.0, 0.0, 0.0];

        // WMI를 통한 CPU 사용률 조회
        $cpuUsage = $this->getCpuUsageWindows();

        return [
            'load_avg' => $loadAvg,
            'total_usage_percent' => $cpuUsage,
            'core_details' => [],
        ];
    }

    /**
     * 메모리 데이터를 수집합니다.
     */
    public function getMemoryData(): array
    {
        // PowerShell을 통한 메모리 정보 조회
        $command = 'powershell "Get-WmiObject Win32_OperatingSystem | Select-Object TotalVisibleMemorySize, FreePhysicalMemory | ConvertTo-Json"';
        $output = $this->executeCommand($command);

        $data = json_decode($output, true);
        if (!$data) {
            return [
                'total_mb' => 0,
                'used_mb' => 0,
                'used_percent' => 0.0,
                'process_details' => [],
            ];
        }

        $total = (int) ($data['TotalVisibleMemorySize'] ?? 0);
        $free = (int) ($data['FreePhysicalMemory'] ?? 0);
        $used = $total - $free;

        $usagePercent = $total > 0 ? round(($used / $total) * 100, 2) : 0.0;

        return [
            'total_mb' => $this->bytesToMb($total * 1024),
            'used_mb' => $this->bytesToMb($used * 1024),
            'used_percent' => $usagePercent,
            'process_details' => $this->getTopMemoryProcessesWindows(5),
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
     * 시스템 정보를 수집합니다.
     */
    public function getSystemInfo(): array
    {
        // Windows 버전 정보
        $command = 'powershell "Get-WmiObject Win32_OperatingSystem | Select-Object Caption, Version | ConvertTo-Json"';
        $output = $this->executeCommand($command);
        $osData = json_decode($output, true);

        $osVersion = $osData['Caption'] ?? 'Windows';
        if (isset($osData['Version'])) {
            $osVersion .= ' (' . $osData['Version'] . ')';
        }

        // CPU 코어 수
        $command = 'powershell "(Get-WmiObject Win32_Processor).NumberOfLogicalProcessors"';
        $cpuCores = (int) $this->executeCommand($command);

        return [
            'os_name' => 'Windows',
            'os_version' => $osVersion,
            'cpu_cores' => $cpuCores > 0 ? $cpuCores : 1,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];
    }

    /**
     * Windows에서 CPU 사용률을 가져옵니다.
     */
    private function getCpuUsageWindows(): float
    {
        $command = 'powershell "(Get-WmiObject Win32_Processor).LoadPercentage"';
        $output = $this->executeCommand($command);
        return (float) trim($output);
    }

    /**
     * Windows에서 메모리를 많이 사용하는 프로세스를 가져옵니다.
     */
    private function getTopMemoryProcessesWindows(int $limit): array
    {
        $command = "powershell \"Get-Process | Sort-Object WS -Descending | Select-Object -First {$limit} Id, ProcessName, @{Name='MemoryMB';Expression={[math]::Round(\$_.WS/1MB,2)}} | ConvertTo-Json\"";
        $output = $this->executeCommand($command);

        $data = json_decode($output, true);
        if (!$data) {
            return [];
        }

        // 단일 결과인 경우 배열로 감싸기
        if (isset($data['Id'])) {
            $data = [$data];
        }

        $processes = [];
        foreach ($data as $process) {
            $processes[] = [
                'pid' => (int) ($process['Id'] ?? 0),
                'command' => $process['ProcessName'] ?? 'Unknown',
                'memory_mb' => (int) ($process['MemoryMB'] ?? 0),
            ];
        }

        return $processes;
    }
}

