# Conference Manager для OJS 3.5

Відкритий плагін для проведення конференцій, шкіл, семінарів та інших наукових і освітніх заходів на базі **Open Journal Systems 3.5 LTS**. Призначений для будь-якого закладу-організатора: ЗВО (державних, комунальних, приватних), фахових коледжів, ІППО, наукових установ, МАН, громадських організацій і хостинг-провайдерів, зокрема для багатозакладних інсталяцій, де кожен заклад має свій контекст.

Нічого в коді не прив'язано до конкретного закладу. Усе, що стосується організатора, налаштовується в **профілі закладу** кожного контексту, а національні норми (ЄДРПОУ, казначейські рахунки, сертифікати підвищення кваліфікації, «Вектор») вмикаються **профілем юрисдикції** `UA`. Профіль `generic` вимикає їх для організаторів з інших країн.

> Статус: **0.3 (спринт 3)**. Готові каркас плагіна, міграції з перевіркою оновлення, профіль закладу, профілі юрисдикції, видання (сторінка «Конференції» для менеджера, публічна сторінка зі schema.org/Event, «Майбутні заходи» сайту, автоматичне закриття прийому), схема даних усіх модулів, групи користувачів, REST API та CI. Реєстрація, оргвнески, програма й сертифікати — у наступних спринтах (див. план розробки v2, розділ 18).
>
> «Майбутні заходи» (`/index/conference/upcoming`) вмикає адміністратор сайту: «Адміністрування → Налаштування сайту → Плагіни» → увімкнути «Conference Manager» на рівні сайту. Автоматичне закриття прийому працює через планувальник OJS (cron `php lib/pkp/tools/scheduler.php run` або веб-запуск завдань).

## Встановлення

1. Завантажте пакет `conferenceManager-<версія>.tar.gz` з релізів.
2. OJS: **Налаштування → Вебсайт → Плагіни → Завантажити новий плагін**.
3. Увімкніть «Менеджер конференцій» у потрібному контексті й відкрийте **Профіль закладу-організатора** (кнопка біля плагіна).

Оновлення — так само, через «Оновити» в списку плагінів. Пакет містить `upgrade.xml`, тому міграції виконуються й під час оновлення, без доступу до shell. Перед оновленням зробіть резервну копію бази й `files_dir`.

Вимоги: OJS 3.5.0-5 або новіший у гілці 3.5, PHP 8.2+, MySQL/MariaDB або PostgreSQL.

## Що вміє версія 0.1

- **Профіль закладу** для кожного контексту (INS-01, 05, 06): повна й скорочена назва кількома мовами, тип закладу, форма власності, ідентифікатор юрособи, адреса, контакти, тексти про персональні дані й строки зберігання.
- **Підписанти** (INS-02) з призначенням на документи: рахунок і сертифікати різних типів.
- **Платіжні реквізити** (INS-03): кілька рахунків (банківський, казначейський, співорганізатора), перевірка IBAN, шаблон призначення платежу зі змінними.
- **Профілі юрисдикції** (INS-06, розд. 7.8): `generic` і `UA` (ЄДРПОУ з контрольним розрядом, український IBAN, казначейство, код ЄДЕБО). Інші плагіни можуть додати свій профіль через хук `ConferenceManager::jurisdictions`.
- **Імпорт і експорт профілю** у JSON (INS-07) для швидкого налаштування нових контекстів.
- **Журнал аудиту** змін профілю.
- **REST API**: `/{context}/api/v1/conference/institution` (GET, PUT), `…/institution/export`, `…/institution/import`, `…/jurisdictions`; `…/editions` (GET, POST), `…/editions/{id}` (GET, PUT, DELETE), `…/editions/{id}/copy`, `…/editions/options`. Дати видань приймаються як місцевий час видання (`2027-05-20 09:00`) або з зсувом (`…Z`, `+02:00`), повертаються в UTC і окремо в місцевому часі (`local`). Доступ: менеджер контексту й адміністратор сайту.

## Розробка

```bash
composer install
vendor/bin/phpunit                 # юніт- і інтеграційні тести (SQLite)
php ci/check-locales.php           # ключі локалей uk/en збігаються
php ci/check-layers.php            # правила шарів (Domain без залежностей від OJS)
vendor/bin/phpstan analyse         # рівень 8 для доменного шару

# Справжній OJS 3.5 у Docker: встановлення → оновлення з новою міграцією → API
ci/integration/start-ojs.sh mariadb   # або postgres
ci/integration/run.sh
ci/integration/api-smoke.sh
```

Структура й правила шарів описано в [docs/architecture.md](docs/architecture.md). Що перевірено на коді OJS 3.5.0-6 і чим реальність відрізняється від ескізів плану — у [docs/ojs-3.5-verification.md](docs/ojs-3.5-verification.md).

## Ліцензія

GNU GPL v3, див. [LICENSE](LICENSE).

---

## English

**Conference Manager** turns OJS 3.5 into a platform for conferences, schools and seminars for any organizing institution. Organizer details live in a per-context **institution profile**; national rules are a switchable **jurisdiction profile** (`UA` built in, `generic` for everyone else, more via the `ConferenceManager::jurisdictions` hook). Version 0.2 adds editions (event types, submission and publication modes, time-zone aware windows, optional OJS issue), the schema of all modules, the Participant and Registration manager user groups and the editions REST API. Version 0.1 contained the foundation: plugin skeleton, idempotent migrations that also run on upgrade (`upgrade.xml`), the institution profile with signers and payment accounts, REST API, CI with install/upgrade tests against real OJS 3.5 on MariaDB and PostgreSQL.
