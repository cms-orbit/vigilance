<?php

declare(strict_types=1);

namespace CmsOrbit\Vigilance\Console\Commands;

use CmsOrbit\Vigilance\Services\ReportService;
use Illuminate\Console\Command;

class ReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vigilance:report';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sentinel-Hub로 서버 상태를 전송합니다';

    /**
     * Execute the console command.
     */
    public function handle(ReportService $reportService): int
    {
        if (!$reportService->validateUuid()) {
            $this->error('Server UUID가 설정되지 않았습니다. .env 파일에 VIGILANCE_SERVER_ID 값을 설정하거나, php artisan config:clear 후 다시 실행하세요.');
            return self::FAILURE;
        }

        // OS 정보 가져오기
        $osInfo = $reportService->getSystemInfo();
        $osDisplay = $osInfo['os_name'] . ' ' . $osInfo['os_version'];

        $this->info("서버 상태를 수집하는 중... (OS: {$osDisplay})");

        $success = $reportService->report();

        if ($success) {
            $this->info('✓ 서버 상태가 성공적으로 전송되었습니다.');
            return self::SUCCESS;
        }

        $this->error('✗ 서버 상태 전송에 실패했습니다. 로그를 확인하세요.');
        return self::FAILURE;
    }
}

