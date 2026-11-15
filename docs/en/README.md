# Conference Manager for OJS 3.5: user guides

These guides are for everyone who works with the Conference Manager plugin in Open Journal Systems 3.5. The plugin suits any organizing institution: a university, a college, an in-service teacher training institute, a research institute, a junior academy of sciences, a non-governmental organization, or a hosting provider that serves several institutions.

Українська версія: [../uk/README.md](../uk/README.md).

## Guides

| Guide | Who it is for |
|---|---|
| [Site administrator](site-admin.md) | Whoever installs and upgrades the plugin, sets up cron and the OJS job queue, and watches all journals of the site. |
| [Journal manager and organizing committee](manager.md) | Whoever sets up the institution profile and creates event editions, fees, payment, decisions, mailings and certificates. |
| [Registration manager](registration-manager.md) | Whoever keeps the register of participants, reviews receipts, records payments and marks attendance. |
| [Participant: author, co-author, listener](participant.md) | Whoever submits an abstract, confirms co-authorship, registers as a listener, pays and receives a certificate. |
| [Presets](presets.md) | Institutions and the community that want to describe a typical event setup in one file and share it. |

## Which guide to read

If you run the server or the whole OJS installation, start with the site administrator guide: without the plugin enabled, cron and the job queue, some features (reminders, mailings, automatic closing of calls) will not work. If you are responsible for an event in your journal, read the manager guide: it covers everything from the first setup to certificates and reports. If you only keep track of participants and payments, the registration manager guide is enough. The participant guide can be handed to authors and listeners as instructions, or used as a source for your call for papers. The presets guide is for those who prepare typical setups for their own institution or for others.

## Key terms

- **Journal (context).** For the plugin, one OJS journal is one organizing institution. Data of different journals on one site never mix.
- **Edition.** One specific event: the 2027 conference, a summer school, a webinar. Each edition has its own dates, participant categories, tariffs, payment rules, participants and documents.
- **Institution profile.** Name, legal details, signers, payment accounts and privacy texts. These details go into invoices, certificates and public pages.
- **Jurisdiction profile.** Switches national rules on. "Ukraine" adds the EDRPOU code, treasury accounts, professional development certificates and the export for the "Vector" platform. "Generic (no national rules)" is for organizers in other countries.

## Current limits

- There is no schedule with rooms, sessions and times yet. There is a static program: accepted papers by section and presentation type.
- There is no online card payment. The plugin issues invoices and shows instructions; a manager records the payment.
- The plugin does not make refunds: a manager handles overpayments by hand.
- XLSX export needs the PHP `zip` extension. Without it, CSV is available.
- Reminders, mailings, bulk decisions and batch certificates need the OJS scheduler and job queue.
