# Changelog

이 파일은 Vigilance 패키지의 주요 변경 사항을 기록합니다.

형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.0.0/)를 따르며,
이 프로젝트는 [Semantic Versioning](https://semver.org/lang/ko/)를 준수합니다.

## [1.3.0] - 2026-06-26

### Added
- Laravel 12 지원 추가
- Laravel 13 지원 추가
- PHP 8.5 호환 범위 추가

### Changed
- `illuminate/support` 지원 범위를 `^9.0|^10.0|^11.0|^12.0|^13.0`으로 확장
- PHP 지원 범위를 `>=8.0 <8.6`으로 명시
- README 호환성 매트릭스를 Laravel 13까지 업데이트

### Notes
- 패키지 네임스페이스, ServiceProvider, Artisan 명령어 이름은 변경되지 않았습니다 (`CmsOrbit\Vigilance\VigilanceServiceProvider`, `vigilance:report`).
- Laravel 9 / 10 / 11 와의 하위 호환성은 그대로 유지됩니다.

## [1.1.2] - 2025-10-13

### Added
- `.gitignore` 파일 추가 (패키지 개발 환경 개선)
- `.gitattributes` 파일 추가 (패키지 배포 최적화)

### Changed
- README 문서 개선 (호환성 매트릭스, 업그레이드 가이드, 버전 히스토리 추가)
- CHANGELOG 문서 개선

## [1.1.0] - 2025-10-13

### Changed
- PHP 최소 버전 요구사항을 8.2에서 8.0으로 변경하여 하위 호환성 확대
- Laravel 지원 버전을 11.0에서 9.0|10.0|11.0으로 확대
- README 요구사항 섹션 업데이트

### Notes
- 모든 기존 기능은 Laravel 9.0+ 및 PHP 8.0+에서 정상 작동합니다
- match 표현식 및 str_starts_with() 함수는 PHP 8.0에서 지원됩니다

## [1.0.1] - 2025-10-13

### Initial Release
- 실시간 시스템 모니터링 (CPU, 메모리, 디스크)
- 프로세스 모니터링
- 오류 로그 수집 및 중복 제거
- 자동 재시도 메커니즘
- 크로스 플랫폼 지원 (Linux, Windows, macOS)
- OS별 자동 감지 및 최적화된 StatusGetter
- 자동 스케줄러 등록
- 서버 UUID 자동 생성

