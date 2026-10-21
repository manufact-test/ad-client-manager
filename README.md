# Ad Client Manager

Внутренняя PHP 8.3 / MySQL система учёта рекламных клиентов.

- [MASTER.md](MASTER.md) — согласованная бизнес-логика, этапы и состояние разработки.
- [NEXT-CHAT.md](NEXT-CHAT.md) — рабочая контрольная точка и дальнейшие действия.
- `main` — проверенный фундамент и GitHub Actions; `deploy` — приложение для Hostinger (публикуется только после успешного CI из `main`).
- `feature/integrated-client-workflow` — изолированная разработка связанного маршрута клиентов, периодов, финансов, PDF, задач, архива и аналитики; [draft PR #2](https://github.com/manufact-test/ad-client-manager/pull/2).
- Секреты, личные документы и данные реальных клиентов запрещено отправлять в репозиторий. **Перед вводом реальных данных обязательно перевести репозиторий в Private** и повторно проверить CI/CD, безопасность и восстановление.

Локальные проверки: `php tests/smoke.php`, `php tests/document_smoke.php`. Для интеграционных проверок требуется изолированная MySQL 8 и переменные `TEST_DB_HOST`, `TEST_DB_NAME`, `TEST_DB_USER`, `TEST_DB_PASSWORD` (GitHub Actions запускает их самостоятельно).
