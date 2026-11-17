# Маршрути REST API плагіна

Створено скриптом `ci/routes-table.php` — не редагуйте вручну. Усі маршрути мають префікс `/{журнал}/api/v1/conference/` і вимагають входу (`has.user`) та журналу (`has.context`). «Ролі» — ролі OJS у журналі, які пропускає проміжний шар; «Перевірка в дії» — що дія перевіряє сама.

| Модуль | Метод | Шлях | Ролі | Перевірка в дії |
|---|---|---|---|---|
| Bidding | GET | `editions/{editionId}/bids` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Bidding | PUT | `editions/{editionId}/bids/settings` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Bidding | POST | `editions/{editionId}/bids/assign` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Bidding | POST | `editions/{editionId}/bids/invite` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Book | GET | `editions/{editionId}/abstract-book` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Book | PUT | `editions/{editionId}/abstract-book` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Book | GET | `editions/{editionId}/abstract-book/pdf` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Certificate | PUT | `editions/{editionId}/attendance` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | POST | `editions/{editionId}/attendance/import` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | GET | `editions/{editionId}/sessions/{sessionId}/attendance` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | PUT | `editions/{editionId}/sessions/{sessionId}/attendance` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | POST | `editions/{editionId}/sessions/{sessionId}/attendance/import` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | GET | `editions/{editionId}/certificate-candidates` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | POST | `editions/{editionId}/certificates` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | GET | `editions/{editionId}/certificates` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | POST | `editions/{editionId}/certificates/batch` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | POST | `editions/{editionId}/reviewers` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | POST | `certificates/{certificateId}/revoke` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | POST | `certificates/{certificateId}/reissue` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | GET | `editions/{editionId}/vector-export` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Certificate | GET | `certificate-templates` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Certificate | PUT | `certificate-templates/{type}` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Certificate | POST | `certificate-templates/{type}/preview` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Certificate | GET | `certificate-settings` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Certificate | PUT | `certificate-settings` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Communication | GET | `editions/{editionId}/dashboard` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Communication | GET | `editions/{editionId}/reports/{dimension}` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Communication | GET | `health` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Communication | GET | `editions/{editionId}/campaigns` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Communication | POST | `editions/{editionId}/campaigns/preview` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Communication | POST | `editions/{editionId}/campaigns/test` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Communication | POST | `editions/{editionId}/campaigns` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Communication | GET | `campaigns/{campaignId}` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Communication | GET | `reminders` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Communication | PUT | `reminders` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Communication | GET | `audit-log` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Communication | GET | `consent-versions` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Decision | GET | `editions/{editionId}/decision-candidates` | адмін сайту, менеджер, редактор розділу | менеджер або адмін |
| Decision | POST | `editions/{editionId}/decisions/bulk` | адмін сайту, менеджер, редактор розділу | менеджер або адмін |
| Decision | GET | `decisions/bulk/{batchId}` | адмін сайту, менеджер, редактор розділу | менеджер або адмін |
| Decision | GET | `submissions/{submissionId}/conference` | адмін сайту, менеджер, редактор розділу | менеджер або призначений редактор подання; подання (OJS) |
| Decision | PUT | `submissions/{submissionId}/vote` | адмін сайту, менеджер, редактор розділу | менеджер або призначений редактор подання |
| Decision | GET | `editions/{editionId}/review-settings` | адмін сайту, менеджер, редактор розділу | менеджер або адмін |
| Decision | PUT | `editions/{editionId}/review-settings` | адмін сайту, менеджер, редактор розділу | менеджер або адмін |
| Decision | PUT | `submissions/{submissionId}/presentation-type` | адмін сайту, менеджер, редактор розділу | менеджер або призначений редактор подання; подання (OJS) |
| Edition | GET | `editions` | адмін сайту, менеджер | достатньо ролі |
| Edition | POST | `editions` | адмін сайту, менеджер | достатньо ролі |
| Edition | GET | `editions/options` | адмін сайту, менеджер | достатньо ролі |
| Edition | GET | `editions/{editionId}` | адмін сайту, менеджер | достатньо ролі |
| Edition | PUT | `editions/{editionId}` | адмін сайту, менеджер | достатньо ролі |
| Edition | DELETE | `editions/{editionId}` | адмін сайту, менеджер | достатньо ролі |
| Edition | POST | `editions/{editionId}/copy` | адмін сайту, менеджер | достатньо ролі |
| Fee | GET | `editions/{editionId}/fees` | адмін сайту, менеджер | достатньо ролі |
| Fee | POST | `editions/{editionId}/fees/categories` | адмін сайту, менеджер | достатньо ролі |
| Fee | PUT | `editions/{editionId}/fees/categories/{categoryId}` | адмін сайту, менеджер | достатньо ролі |
| Fee | DELETE | `editions/{editionId}/fees/categories/{categoryId}` | адмін сайту, менеджер | достатньо ролі |
| Fee | POST | `editions/{editionId}/fees/presets` | адмін сайту, менеджер | достатньо ролі |
| Fee | POST | `editions/{editionId}/fees/tariffs` | адмін сайту, менеджер | достатньо ролі |
| Fee | PUT | `editions/{editionId}/fees/tariffs/{tariffId}` | адмін сайту, менеджер | достатньо ролі |
| Fee | DELETE | `editions/{editionId}/fees/tariffs/{tariffId}` | адмін сайту, менеджер | достатньо ролі |
| Fee | GET | `editions/{editionId}/fees/quote` | адмін сайту, менеджер | достатньо ролі |
| Gateway | GET | `payment-gateway` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Gateway | PUT | `payment-gateway` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Gateway | GET | `invoices/{invoiceId}/gateway-payments` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Institution | GET | `institution` | адмін сайту, менеджер | достатньо ролі |
| Institution | PUT | `institution` | адмін сайту, менеджер | достатньо ролі |
| Institution | GET | `institution/export` | адмін сайту, менеджер | достатньо ролі |
| Institution | POST | `institution/import` | адмін сайту, менеджер | достатньо ролі |
| Institution | GET | `jurisdictions` | адмін сайту, менеджер | достатньо ролі |
| Invoice | GET | `editions/{editionId}/payment-rules` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Invoice | POST | `editions/{editionId}/payment-rules` | адмін сайту, менеджер, асистент | у ruleResult(): менеджер або адмін |
| Invoice | PUT | `editions/{editionId}/payment-rules/{ruleId}` | адмін сайту, менеджер, асистент | у ruleResult(): менеджер або адмін |
| Invoice | DELETE | `editions/{editionId}/payment-rules/{ruleId}` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Invoice | GET | `invoice-templates` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Invoice | PUT | `invoice-settings` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Invoice | PUT | `invoice-templates/{kind}` | адмін сайту, менеджер, асистент | менеджер або адмін |
| Invoice | GET | `editions/{editionId}/invoices` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `participations/{participationId}/invoice` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | GET | `invoices/{invoiceId}` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | GET | `invoices/{invoiceId}/pdf` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `invoices/{invoiceId}/paid` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `invoices/{invoiceId}/waive` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `invoices/{invoiceId}/reissue` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `invoices/{invoiceId}/cancel` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `invoices/{invoiceId}/refund-request` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `invoices/{invoiceId}/refund` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `editions/{editionId}/group-invoices` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | GET | `editions/{editionId}/payment-events` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | GET | `exports/accounting/columns` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | PUT | `exports/accounting/columns` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | GET | `exports/accounting` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | GET | `proofs` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | GET | `proofs/{proofId}/file` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `proofs/{proofId}/accept` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Invoice | POST | `proofs/{proofId}/reject` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Legacy | GET | `legacy-urls` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Legacy | PUT | `legacy-urls/templates` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Legacy | POST | `legacy-urls/import` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Legacy | DELETE | `legacy-urls` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Legacy | GET | `legacy-urls/check` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Legacy | GET | `legacy-urls/map` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| MyParticipation | POST | `editions/{editionId}/listeners` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | власні записи |
| MyParticipation | GET | `me/participations` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | власні записи |
| MyParticipation | GET | `me/invoices` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | власні записи |
| MyParticipation | GET | `me/invoices/{invoiceId}/pdf` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | власні записи |
| MyParticipation | POST | `me/invoices/{invoiceId}/proofs` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | власні записи |
| MyParticipation | GET | `me/proofs/{proofId}/file` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | власні записи |
| MyParticipation | POST | `me/participations/{participationId}/cancel` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | власні записи |
| Participant | GET | `editions/{editionId}/participants` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Participant | GET | `editions/{editionId}/participants/export` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Participant | PUT | `participations/{participationId}/category` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Participant | POST | `participations/{participationId}/cancel` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Participant | POST | `editions/{editionId}/invitations` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Participant | GET | `participations/{participationId}/invitation-letter` | адмін сайту, менеджер, асистент | менеджер реєстрації |
| Partner | GET | `editions/{editionId}/partners` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Partner | POST | `editions/{editionId}/partners` | адмін сайту, менеджер | у save(): менеджер або менеджер реєстрації |
| Partner | PUT | `$one` | адмін сайту, менеджер | у save(): менеджер або менеджер реєстрації |
| Partner | DELETE | `$one` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Partner | POST | `$one/move` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Partner | POST | `$one/logo` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Partner | DELETE | `$one/logo` | адмін сайту, менеджер | менеджер або менеджер реєстрації |
| Privacy | GET | `retention` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | менеджер або адмін |
| Privacy | PUT | `retention` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | менеджер або адмін |
| Privacy | GET | `personal-data` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | менеджер або адмін |
| Privacy | POST | `personal-data/anonymize` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | менеджер або адмін |
| Privacy | GET | `me/personal-data` | адмін сайту, менеджер, редактор розділу, асистент, автор, рецензент, читач | власні записи |
| Program | GET | `editions/{editionId}/program-builder` | адмін сайту, менеджер | достатньо ролі |
| Program | POST | `editions/{editionId}/rooms` | адмін сайту, менеджер | достатньо ролі |
| Program | PUT | `editions/{editionId}/rooms/{roomId}` | адмін сайту, менеджер | достатньо ролі |
| Program | DELETE | `editions/{editionId}/rooms/{roomId}` | адмін сайту, менеджер | достатньо ролі |
| Program | POST | `editions/{editionId}/sessions` | адмін сайту, менеджер | достатньо ролі |
| Program | PUT | `editions/{editionId}/sessions/{sessionId}` | адмін сайту, менеджер | достатньо ролі |
| Program | DELETE | `editions/{editionId}/sessions/{sessionId}` | адмін сайту, менеджер | достатньо ролі |
| Program | POST | `editions/{editionId}/program-moves` | адмін сайту, менеджер | достатньо ролі |
| Promo | GET | `editions/{editionId}/promo-codes` | адмін сайту, менеджер, асистент | менеджер або менеджер реєстрації |
| Promo | POST | `editions/{editionId}/promo-codes` | адмін сайту, менеджер, асистент | у save(): менеджер або менеджер реєстрації |
| Promo | PUT | `editions/{editionId}/promo-codes/{codeId}` | адмін сайту, менеджер, асистент | у save(): менеджер або менеджер реєстрації |
| Promo | DELETE | `editions/{editionId}/promo-codes/{codeId}` | адмін сайту, менеджер, асистент | менеджер або менеджер реєстрації |
| Promo | PUT | `editions/{editionId}/seats` | адмін сайту, менеджер, асистент | менеджер або менеджер реєстрації |
| Registration | GET | `submissions/{submissionId}/registration` | адмін сайту, менеджер, редактор розділу, асистент, автор | подання (OJS) |
| Registration | PUT | `submissions/{submissionId}/registration` | адмін сайту, менеджер, редактор розділу, асистент, автор | подання (OJS) |
| Setup | GET | `setup` | адмін сайту, менеджер | достатньо ролі |
| Setup | POST | `setup` | адмін сайту, менеджер | достатньо ролі |
| Setup | GET | `siteMode` | адмін сайту, менеджер | достатньо ролі |
| Setup | PUT | `siteMode` | адмін сайту, менеджер | достатньо ролі |

Усього маршрутів: 145. Публічний маршрут без входу — лише `GET /index/api/v1/conference-site/upcoming` (`ConferenceSiteApiController`, майбутні заходи всіх журналів).
