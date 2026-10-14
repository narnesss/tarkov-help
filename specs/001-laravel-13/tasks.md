# Задачи

- [x] Laravel bootstrap, DI, HTTP и совместимый API.
- [x] Blade, общий layout, экспорт и generated attributes.
- [x] Публичный кэш, инвалидация, cache headers.
- [x] Миграции, карты архитектуры, deployment rule.
- [x] HTTP/кэш/безопасность/экспорт; CI настроен для PHP 8.4.

Фактические результаты: 11 Laravel-тестов / 137 проверок; 23 доменных теста / 222 проверки; 7006 маршрутов / 8642 файла. Браузер: главная, Билет, Развязка. Production optimize прошёл. Приёмка отправленного SHA фиксируется в GitHub Actions.

CI для 5cfdf751c115d40304f6989a82cca9f5fdffa763 прошёл на PHP 8.4.26: 34 теста / 359 проверок, сборка, все маршруты, stale-export gate, Wrangler dry-run и production optimize. Протокол: https://github.com/narnesss/tarkov-help/actions/runs/38651701218.
