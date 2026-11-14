# Як долучитися

Дякуємо, що допомагаєте! Плагін розробляється як спільний для всіх закладів, тому будь-яка зміна має працювати для різних типів організаторів і не містити прив'язок до одного закладу.

## Гілки й коміти
- `main` — лише релізи з тегами `vX.Y.Z.W`; `develop` — інтеграційна гілка; робочі гілки `feature/<ID>-коротко` (наприклад, `feature/FEE-08-reissue`).
- Коміти за [Conventional Commits](https://www.conventionalcommits.org/): `feat(fees): FEE-08 reissue invoice`.
- PR у `develop`: ID вимог з плану, скриншоти для змін інтерфейсу, зелений CI, рев'ю другого розробника, рядок в `AI_USE.md`, якщо допомагав ШІ.

## Готовність історії (DoD)
1. Юніт-тести для доменної логіки; права перевірено позитивним і негативним тестом.
2. Рядки інтерфейсу в `locale/uk` і `locale/en` (`php ci/check-locales.php`).
3. Нова зміна схеми — **новий файл** `classes/Migrations/Steps/StepNNNN*.php`, ідемпотентний (`hasTable` / `hasColumn`). Випущені кроки не перейменовуються й не змінюються.
4. Номер `release` у `version.xml` збільшено в кожному релізі; `upgrade.xml` не видаляти.
5. Правила шарів (`php ci/check-layers.php`): `Domain` і `Jurisdiction` — чистий PHP; виклики `Repo::`, `DAORegistry::`, `Hook::` — лише в `classes/Core` і головному класі.

## Новий профіль юрисдикції
Реалізуйте `classes\Jurisdiction\JurisdictionProfile` (можна успадкувати `AbstractJurisdictionProfile`) і зареєструйте його з власного плагіна:

```php
Hook::add('ConferenceManager::jurisdictions', function (string $hookName, $registry) {
    $registry->register(new MyCountryProfile());
    return Hook::CONTINUE;
});
```
