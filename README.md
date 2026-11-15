# Conference Manager для OJS 3.5

Відкритий плагін для проведення конференцій, шкіл, семінарів та інших наукових і освітніх заходів на базі **Open Journal Systems 3.5 LTS**. Призначений для будь-якого закладу-організатора: ЗВО (державних, комунальних, приватних), фахових коледжів, ІППО, наукових установ, МАН, громадських організацій і хостинг-провайдерів, зокрема для багатозакладних інсталяцій, де кожен заклад має свій контекст.

Нічого в коді не прив'язано до конкретного закладу. Усе, що стосується організатора, налаштовується в **профілі закладу** кожного контексту, а національні норми (ЄДРПОУ, казначейські рахунки, сертифікати підвищення кваліфікації, «Вектор») вмикаються **профілем юрисдикції** `UA`. Профіль `generic` вимикає їх для організаторів з інших країн.

> Статус: **1.0.0.0-beta4** (збірка 0.12.0.0, спринт 12). Готові профіль закладу й профілі юрисдикції, видання й публічні сторінки, майстер подання з анкетою доповідача, оргвнески, реєстрація слухачів і кабінет учасника, рахунки з PDF, квитанції, рішення щодо подань, розсилки, нагадування, експорт для бухгалтерії, підсумки заходу, журнал дій, облік присутності й сертифікати з QR-перевіркою, свідоцтва про підвищення кваліфікації з публічним реєстром і експортом для «Вектора», строки зберігання, вивантаження й знеособлення персональних даних. Програма заходу — у наступних спринтах (див. план розробки v2, розділ 18).
>
> «Майбутні заходи» (`/index/conference/upcoming`) вмикає адміністратор сайту: «Адміністрування → Налаштування сайту → Плагіни» → увімкнути «Conference Manager» на рівні сайту. Автоматичне закриття прийому працює через планувальник OJS (cron `php lib/pkp/tools/scheduler.php run` або веб-запуск завдань).

## Встановлення

1. Завантажте пакет `conferenceManager-<версія>.tar.gz` з релізів.
2. OJS: **Налаштування → Вебсайт → Плагіни → Завантажити новий плагін**.
3. Увімкніть «Менеджер конференцій» у потрібному контексті й відкрийте **Профіль закладу-організатора** (кнопка біля плагіна).

Оновлення — так само, через «Оновити» в списку плагінів. Пакет містить `upgrade.xml`, тому міграції виконуються й під час оновлення, без доступу до shell. Перед оновленням зробіть резервну копію бази й `files_dir`.

Вимоги: OJS 3.5.0-5 або новіший у гілці 3.5, PHP 8.2+, MySQL/MariaDB або PostgreSQL.

## Що вміє плагін

- **Профіль закладу** для кожного контексту (INS-01, 05, 06): повна й скорочена назва кількома мовами, тип закладу, форма власності, ідентифікатор юрособи, адреса, контакти, тексти про персональні дані й строки зберігання.
- **Підписанти** (INS-02) з призначенням на документи: рахунок і сертифікати різних типів.
- **Платіжні реквізити** (INS-03): кілька рахунків (банківський, казначейський, співорганізатора), перевірка IBAN, шаблон призначення платежу зі змінними.
- **Профілі юрисдикції** (INS-06, розд. 7.8): `generic` і `UA` (ЄДРПОУ з контрольним розрядом, український IBAN, казначейство, код ЄДЕБО). Інші плагіни можуть додати свій профіль через хук `ConferenceManager::jurisdictions`.
- **Імпорт і експорт профілю** у JSON (INS-07) для швидкого налаштування нових контекстів.
- **Журнал аудиту** змін профілю.
- **REST API**: `/{context}/api/v1/conference/institution` (GET, PUT), `…/institution/export`, `…/institution/import`, `…/jurisdictions`; `…/editions` (GET, POST), `…/editions/{id}` (GET, PUT, DELETE), `…/editions/{id}/copy`, `…/editions/options`. Дати видань приймаються як місцевий час видання (`2027-05-20 09:00`) або з зсувом (`…Z`, `+02:00`), повертаються в UTC і окремо в місцевому часі (`local`). Доступ: менеджер контексту й адміністратор сайту. `…/submissions/{id}/registration` (GET, PUT) — анкета доповідача; доступ також для автора подання.
- **Видання й публічні сторінки** (0.2–0.3): форма видань для менеджера, сторінка видання з schema.org/Event, «Майбутні заходи» сайту, автоматичне закриття прийому.
- **Майстер подання** (0.4): крок «Участь у конференції» з анкетою доповідача й згодою, блокування подання поза вікном прийому, учасники з авторів, підтвердження участі співавторами за одноразовим посиланням.
- **Оргвнески й учасники** (0.5): категорії учасників із типовими наборами, тарифна матриця з розрахунком у цілих копійках, реєстрація слухачів, кабінет учасника `/{context}/conference/my`, реєстр із фільтрами й CSV, скасування участі з обробкою рахунків.
- **Оплата й рахунки** (0.6): режими оплати й правила «категорія → режим», рахунки слухачам після реєстрації й доповідачам після прийняття, номер без пропусків і код платежу, PDF із банківського чи казначейського шаблону (журнал може змінити шаблон без коду), сума прописом, часткова оплата, звільнення від оплати, рахунки в кабінеті учасника.
- **Рішення щодо подань** (0.8): рішення «Прийняти» з типом виступу (усна, стендова, онлайн, заочна), після яких подання йде на редагування й до випуску заходу, а доповідач отримує рахунок за тарифом типу; масові рішення через чергу OJS з окремим листом кожному автору; зміна типу виступу після рішення з перерахунком рахунку; панель «Участь у заході» на картці подання.
- **Квитанції й журнал платежів** (0.7): квитанції PDF/JPG/PNG у кабінеті з перевіркою вмісту й приватним зберіганням, черга перевірки для реєстраційного менеджера з листом платнику, журнал платежів видання, що лише доповнюється, фільтр реєстру за оплатою, неповнолітні учасники зі згодою батьків або законного представника.
- **Персональні дані** (0.12): строки зберігання квитанцій і даних учасників з щоденним завданням (за замовчуванням вимкнено); вивантаження всіх даних людини в JSON для неї самої й для менеджера; знеособлення, яке зберігає рахунки й видані документи; перенесення записів під час злиття користувачів OJS; теги Google Scholar для матеріалів конференції; зразки текстів повідомлень за законом про захист персональних даних.
- **Український профіль документів** (0.11): свідоцтво про підвищення кваліфікації за п. 13 постанови № 800 (години, кредити ЄКТС, результати навчання, часткові кваліфікації, ЄДРПОУ закладу) з англійською версією; публічний реєстр виданих свідоцтв; відкликання й перевипуск з новим номером; експорт для платформи «Вектор» (за замовчуванням вимкнено); пакетна видача через чергу OJS; подяки рецензентам; реквізити платника-організації з ЄДРПОУ, а РНОКПП лише за вмиканням.
- **Присутність і сертифікати** (0.10): позначки присутності в реєстрі й імпорт звіту Teams, Zoom чи Google Meet з порогом у хвилинах; шаблони сертифікатів п'яти видів зі змінними й попереднім переглядом; номери за серією й роком; видача за правилами (слухач за присутністю, доповідач за прийнятою доповіддю, заочний автор після публікації); PDF A4 альбомний, що не змінюється після видачі; QR-код і публічна сторінка перевірки; сертифікати в кабінеті учасника.
- **Розсилки, нагадування й звіти** (0.9): перевиставлення рахунку з перенесенням сплаченої суми; публікація лише після оплати; експорт для бухгалтерії в CSV і XLSX з власними колонками; розсилки учасникам за умовами через чергу OJS; автоматичні нагадування про оплату, повний текст і початок заходу; підсумки заходу; версії тексту згоди й журнал дій.

## Обмеження бета-версії

- Програма заходу, сертифікати секцій і з годинами/ECTS, відкликання й заміна сертифіката з інтерфейсу ще не готові (спринт 11 і далі).
- Нагадування надсилає планувальник OJS: потрібен `cron` з `php lib/pkp/tools/scheduler.php run` або веб-запуск завдань. Нагадування за замовчуванням вимкнені.
- Масові рішення й розсилки виконує черга OJS (після веб-запитів або `php lib/pkp/tools/jobs.php work`).
- XLSX потребує розширення PHP `zip`; без нього доступний CSV.
- Повернення коштів плагін не робить: переплату менеджер вирішує вручну.
- Картка подання OJS 3.5 на ширині телефона прокручується вбік (поведінка самого OJS).

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

**Conference Manager** turns OJS 3.5 into a platform for conferences, schools and seminars for any organizing institution. Organizer details live in a per-context **institution profile**; national rules are a switchable **jurisdiction profile** (`UA` built in, `generic` for everyone else, more via the `ConferenceManager::jurisdictions` hook). Status: **1.0.0.0-beta4** (build 0.12.0.0). The program comes in the next sprints; reminders need the OJS scheduler (cron), bulk decisions and mailings need the OJS job queue, XLSX needs the PHP `zip` extension. Version 0.12 adds retention periods for receipt scans and participant data with a daily task (off by default), a JSON export of everything about a person for the person and for a manager, anonymisation that keeps invoices and issued documents, records that follow an OJS user merge, Google Scholar conference tags and sample privacy notices from the jurisdiction profile. Version 0.11 added the Ukrainian professional development certificate (hours, ECTS credits, learning outcomes, partial qualifications, the institution's legal id) with an English version, a public register of issued documents, revoke and reissue with a new number, an export for the "Vector" platform (off by default), batch issue through the OJS job queue, reviewer thank-you letters and payer organisations with their legal id (a personal tax number only when turned on). Version 0.10 added attendance marks and the import of a Teams, Zoom or Google Meet attendance report with a minute threshold, certificate templates of five kinds with variables and a preview, yearly numbers by series, issue by rules (listeners by attendance, speakers by an accepted paper, absentee authors after publication), A4 landscape PDFs that do not change after issue, a QR code with a public verification page and certificates in the participant's cabinet. Version 0.9 added invoice reissue with the paid amount carried over, publishing only after payment, an accounting export to CSV and XLSX with configurable columns, mailings to participants through the OJS job queue, automatic payment, full text and event reminders, an edition summary, kept versions of the consent text and an audit log viewer. Version 0.8 added editorial decisions 901–904 (accept as an oral, poster, online or absentee presentation) that move the submission to copyediting, put it in the edition's issue and invoice the presenter by the tariff of the type; bulk decisions through the OJS job queue with a letter per author; a presentation type change after the decision that reissues an unpaid invoice; and a conference panel in the submission card. Version 0.7 added payment receipts in the cabinet (PDF/JPG/PNG checked by content, stored outside the web root), a review queue with letters to the payer, an append-only payment journal per edition, a register filter by payment and minor participants with guardian consent. Version 0.6 added payment modes with "category → mode" rules, invoices for listeners and accepted presenters with gapless numbers and payment references, PDF invoices from bank and treasury templates that a journal can edit, the amount in words, part payments and waivers. Version 0.5 added participant categories, the tariff matrix (integer minor units), listener registration, the participant's cabinet, the register of participants with CSV export and cancellation. Version 0.4 added the speaker questionnaire in the submission wizard. Version 0.2 added editions (event types, submission and publication modes, time-zone aware windows, optional OJS issue), the schema of all modules, the Participant and Registration manager user groups and the editions REST API. Version 0.1 contained the foundation: plugin skeleton, idempotent migrations that also run on upgrade (`upgrade.xml`), the institution profile with signers and payment accounts, REST API, CI with install/upgrade tests against real OJS 3.5 on MariaDB and PostgreSQL.
