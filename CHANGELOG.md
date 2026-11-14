# Журнал змін

Формат — [Keep a Changelog](https://keepachangelog.com/uk/1.1.0/), версії — `major.minor.revision.build`, як вимагає OJS.

## [0.1.0.0] — 2026-11-14

### Додано
- Каркас плагіна `conferenceManager` для OJS 3.5 (`GenericPlugin`, lazy-load), локалі `uk` і `en`.
- Міграції кроками (`classes/Migrations/Steps`), що знаходяться з файлової системи й ідемпотентні; таблиця `conf_schema_steps`; `upgrade.xml`, щоб міграції виконувалися під час оновлення пакета.
- Таблиці профілю закладу: `conf_institutions`, `conf_institution_settings`, `conf_signers`, `conf_signer_settings`, `conf_payment_accounts`, `conf_audit_log`.
- Профіль закладу (INS-01…03, 05…07): форма в налаштуваннях плагіна, REST API, імпорт / експорт JSON, журнал аудиту.
- Профілі юрисдикції `generic` і `UA` та хук `ConferenceManager::jurisdictions` для сторонніх профілів.
- CI: lint, перевірка локалей і шарів, PHPStan, PHPUnit (PHP 8.2–8.4), пакування, встановлення й оновлення в OJS 3.5.0-5/3.5.0-6 на MariaDB і PostgreSQL.
