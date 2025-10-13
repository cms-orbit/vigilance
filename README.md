# Vigilance - Laravel 서버 모니터링 에이전트

Vigilance는 Laravel 프로젝트에 설치되어 시스템 상태를 수집하고 1분마다 Sentinel-Hub로 전송하는 경량 모니터링 에이전트입니다.

## 특징

- 🔍 **실시간 시스템 모니터링**: CPU, 메모리, 디스크 사용량 자동 수집
- 📊 **프로세스 모니터링**: 메모리를 많이 사용하는 프로세스 추적
- 🚨 **오류 로그 수집**: Laravel 로그 파일에서 오류를 자동으로 감지하고 중복 제거
- 🔄 **자동 재시도**: 전송 실패 시 지수 백오프 기반 재시도
- 🖥️ **크로스 플랫폼**: Linux, Windows, macOS 지원
- ⚡ **경량 설계**: 시스템 리소스 최소 사용

## 요구사항

- PHP 8.0 이상
- Laravel 9.0 이상 (Laravel 9, 10, 11 지원)
- Guzzle HTTP Client 7.0 이상

### 호환성 매트릭스

| Laravel 버전 | PHP 버전 | Vigilance 지원 |
|-------------|---------|---------------|
| Laravel 11  | PHP 8.2+ | ✅ 완벽 지원 |
| Laravel 10  | PHP 8.1+ | ✅ 완벽 지원 |
| Laravel 9   | PHP 8.0+ | ✅ 완벽 지원 |
| Laravel 8 이하 | - | ❌ 미지원 |

## 설치

### 1. Composer를 통한 패키지 설치

```bash
composer require cms-orbit/vigilance
```

> **업그레이드 노트:** v1.0.1에서 v1.1.0으로 업그레이드하는 경우, 별도의 설정 변경이 필요하지 않습니다. 모든 기능이 하위 호환성을 유지합니다.

### 2. 환경 설정

`.env` 파일에 Sentinel-Hub URL을 추가하세요:

```env
SENTINEL_HUB_URL=https://sentinel-hub.amuz.co.kr
```

**참고:** 
- 서버 UUID(`VIGILANCE_SERVER_ID`)는 자동으로 생성됩니다.
- 스케줄러는 자동으로 등록됩니다.
- 1분마다 자동으로 서버 상태를 전송합니다.

### 3. 크론 작업 설정 (서버에서 한 번만 설정)

Laravel 스케줄러가 작동하려면 서버에 크론 작업을 추가해야 합니다:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## 설정

`config/vigilance.php` 파일에서 다양한 설정을 조정할 수 있습니다:

```php
return [
    // Sentinel-Hub 도메인 (API 엔드포인트 경로는 자동 추가됨)
    'base_url' => env('SENTINEL_HUB_URL', 'http://localhost'),
    
    // 서버 UUID
    'server_uuid' => env('VIGILANCE_SERVER_ID', ''),
    
    // 모니터링할 디스크 경로
    'disk_paths' => [
        '/',
    ],
    
    // 모니터링할 로그 파일 prefix
    // 데일리 로그 (laravel-2025-10-11.log) 지원
    'log_monitor_prefixes' => [
        storage_path('logs/laravel'),
    ],
    
    // 재시도 설정
    'retry' => [
        'max_attempts' => 3,
        'initial_delay' => 1,
        'max_delay' => 60,
    ],
    
    // HTTP 타임아웃 (초)
    'timeout' => 10,
];
```

## 사용법

### 자동 모니터링

패키지 설치 후 아무 작업 없이 자동으로 1분마다 서버 상태가 전송됩니다.

### 수동 보고서 전송 (테스트용)

```bash
php artisan vigilance:report
```

### 전송 데이터 구조

Vigilance는 다음 형식의 JSON 데이터를 Sentinel-Hub로 전송합니다:

```json
{
  "uuid": "서버 고유 UUID",
  "reported_at": "2025-10-10T12:00:00+00:00",
  "referer": "http://example.com",
  "system_info": {
    "os_name": "Linux",
    "os_version": "Ubuntu 22.04 LTS",
    "cpu_cores": 8,
    "php_version": "8.3.0",
    "laravel_version": "11.0.0"
  },
  "metrics": {
    "cpu": {
      "load_avg": [0.5, 0.6, 0.7],
      "total_usage_percent": 25.5,
      "core_details": []
    },
    "memory": {
      "total_mb": 16384,
      "used_mb": 8192,
      "usage_percent": 50.0,
      "process_details": [...]
    },
    "disks": [...],
    "health_check": {
      "db_connection": "ok",
      "queue_status": "ok",
      "message": "All systems operational"
    }
  },
  "errors": [...]
}
```

## 아키텍처

### StatusGetter 상속 구조

```
AbstractStatusGetter (추상 클래스)
├── LinuxStatusGetter
│   ├── Ubuntu24StatusGetter
│   ├── Ubuntu22StatusGetter
│   ├── Ubuntu20StatusGetter
│   ├── CentosStatusGetter
│   ├── RockyStatusGetter
│   └── DebianStatusGetter
├── WindowsStatusGetter
│   ├── Windows11StatusGetter
│   ├── Windows10StatusGetter
│   └── WindowsServerStatusGetter
└── MacStatusGetter
```

**자동 OS 감지:**
- Linux 배포판 자동 식별 (Ubuntu 버전별, CentOS, Rocky Linux, Debian)
- Windows 버전 자동 식별 (Windows 10, 11, Server)
- macOS 지원

각 OS별 StatusGetter는 다음 메소드를 구현합니다:
- `getCpuData()`: CPU 사용률 및 로드 에버리지
- `getMemoryData()`: 메모리 사용량 및 프로세스 정보
- `getDiskData()`: 디스크 사용량
- `getSystemInfo()`: 시스템 환경 정보
- `getHealthCheck()`: DB 연결 및 큐 상태 확인

### LogMonitor

로그 파일을 모니터링하고 오류를 수집합니다:
- **Prefix 기반 로그 파일 매칭**: 데일리 로그 자동 지원 (laravel.log, laravel-2025-10-11.log 등)
- **중복 제거**: SHA256 해시 생성으로 동일 오류 식별
- **카운팅**: 1분 간격으로 발생한 동일 오류 횟수 집계
- **메시지 요약**: 200자 제한으로 DB 최적화
- **파일 경로 추출**: Exception 스택에서 정확한 파일과 라인 번호 추출

## 버전 히스토리

자세한 변경 사항은 [CHANGELOG.md](CHANGELOG.md)를 참조하세요.

### v1.1.2 (현재)
- **문서 및 설정 개선**: `.gitignore`, `.gitattributes` 추가
- README 문서 보완 (호환성 매트릭스, 업그레이드 가이드)
- CHANGELOG 문서 개선

### v1.1.0
- **하위 호환성 개선**: PHP 8.0+, Laravel 9.0+ 지원
- 모든 기존 기능 유지
- 추가 설정 변경 불필요

### v1.0.1
- 초기 릴리스

## 라이선스

MIT License

## 지원

문제가 발생하거나 기능 제안이 있으시면 GitHub Issues를 통해 알려주세요.

