<?php

declare(strict_types=1);

namespace CmsOrbit\Vigilance;

use CmsOrbit\Vigilance\Console\Commands\ReportCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class VigilanceServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/vigilance.php',
            'vigilance'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // UUID 자동 생성 (없는 경우)
        $this->ensureServerUuid();

        if ($this->app->runningInConsole()) {
            // 설정 파일 발행
            $this->publishes([
                __DIR__.'/../config/vigilance.php' => config_path('vigilance.php'),
            ], 'vigilance-config');

            // Artisan 명령어 등록
            $this->commands([
                ReportCommand::class,
            ]);

            // 스케줄러 자동 등록
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('vigilance:report')
                    ->everyMinute()
                    ->withoutOverlapping()
                    ->runInBackground();
            });
        }
    }

    /**
     * 서버 UUID가 없으면 자동 생성합니다.
     */
    private function ensureServerUuid(): void
    {
        $envPath = base_path('.env');

        if (!file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);

        // 이미 UUID가 설정되어 있으면 건너뛰기
        if (preg_match('/^VIGILANCE_SERVER_ID=.+$/m', $envContent)) {
            return;
        }

        // UUID 생성 및 추가
        $uuid = (string) Str::uuid();
        $envContent .= "\n# Vigilance Server ID (auto-generated)\n";
        $envContent .= "VIGILANCE_SERVER_ID={$uuid}\n";

        file_put_contents($envPath, $envContent);
    }
}

