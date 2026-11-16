# Site administrator guide

This guide is for the OJS site administrator: the person who installs and upgrades the plugin, sets up the server, cron and the job queue, and watches all journals of the installation. Setting up individual events is covered in the [manager guide](manager.md).

## Requirements

- OJS 3.5.0-5 or newer in the 3.5 branch.
- PHP 8.2 or newer.
- MySQL / MariaDB or PostgreSQL.
- The PHP `zip` extension for XLSX export. Without it, managers can export CSV only.

## How to install the plugin

You do not need command line access to install it.

1. Download the package `conferenceManager-<version>.tar.gz` from the releases page.
2. Log in to the journal that will hold events and open **Settings → Website → Plugins**.
3. Click **Upload A New Plugin**, choose the package file and confirm.
4. Find "Conference Manager" in the list and enable it.
5. A button **Organizing institution profile** appears next to the plugin. Tell the journal manager that the profile is theirs to fill in.

When the plugin is first enabled in a journal, it creates two user groups: **Participant** and **Registration manager**. Enabling it again does not duplicate them.

If the site has several journals, enable the plugin in each journal that holds events. There is one package on the server, but each journal has its own settings and data.

## How to upgrade the plugin

1. **Make a backup first** of the database and the OJS files directory (`files_dir`). Do not start an upgrade without a backup.
2. Open **Settings → Website → Plugins**, find "Conference Manager" and click **Upgrade**.
3. Choose the file of the new version and confirm.

The package contains `upgrade.xml`, so all database changes run automatically during the upgrade, without a shell. Each schema step is applied only once; running it again breaks nothing. Data of earlier versions is kept.

After the upgrade, open **Conferences: site overview** (see below) and check the plugin version and the number of schema steps.

## How to enable the plugin at the site level

Some features work for the whole site rather than one journal. Only the site administrator can turn them on.

1. Open **Administration → Site Settings → Plugins**.
2. Enable "Conference Manager".

This turns on the "Upcoming events" and "Conferences: site overview" pages.

On a site with one journal OJS shows no site-level plugin list. There the plugin turns itself on at site level together with the journal.

## The "Upcoming events" page

The address is `/index/conference/upcoming`. It lists events from all journals of the site where the plugin is enabled.

- It shows editions with the status "Call open" or "Proceedings published", from enabled journals only.
- Visitors can filter by **Journal / institution**, type and format, search in **Title or city** and click **Show**. The city is searched in the "Venue" field of the edition.
- The same data is available from an open API: `/index/api/v1/conference-site/upcoming`.
- Until the plugin is enabled at the site level, the page and the API return 404.

To add the page to the site menu, create an ordinary OJS navigation menu item with a custom link to the address above.

## The "Conferences: site overview" page

The address is `/index/conferenceSite`. The site administrator sees the link **Site overview (administrator)** on the **Conferences** page of any journal, in the **For the whole journal** row. Only the site administrator sees the page, and it works once the plugin is enabled at site level.

What it shows:

- **Plugin version** and **Schema steps**: how many database changes have been applied.
- **Plugin jobs in the queue** and **Failed jobs**.
- A table of journals. For each journal: **Plugin on**, **Active / all events**, **Participants**, **Papers**, **Paid invoices**, **Certificates** (not revoked), **Mailing letters waiting**, **Jobs queued**. Active events are those whose call is open or closed, or whose papers are published. **Download the table as CSV** gives this table for a consortium or provider report (UTF-8, ";" separator).
- **Scheduled tasks**: when each plugin task last ran and whether it ran without errors. A task that never ran shows "has not run yet". A failed run points you to the OJS task log. If no task has run for more than two hours, a warning about cron appears at the top of the advice; event managers see the same warning on the summary page.
- **OJS queue on web requests**: on (and how many jobs per request) or off (jobs run by cron).
- **Server time zone**.
- **Load profile** (see below).
- **Presets**: how many presets are available, which server folder takes your own, and which files have errors (the wizard does not offer them).

Below the overview the page gives advice, for example:

- when the scheduler has not run for a day;
- when a different `job_runner_max_jobs` value in `config.inc.php` suits the chosen profile better;
- when the queue on web requests is off: then make sure cron processes the queue, or mailings and batch issue do not run;
- when plugin jobs have failed: then see **Administration → Jobs → Failed**.

## How to choose a load profile

The profile sets how much background work the plugin does at a time and when. Choose it on the site overview page in the **Load profile** block and save.

| Profile | Portion | Jobs per request | Letters per hour | Mass work |
|---|---|---|---|---|
| Shared hosting without cron | 10 | 5 | 100 | only at night, 22:00–07:00 server time |
| A server of the institution (default) | 30 | 30 | 500 | any time |
| A multi-institution installation with cron (provider) | 30 | separate queue worker | as the mail server allows | any time |

Mass work means mailings, batch certificate issue and bulk decisions on submissions.

- **Mailing letters per hour.** Leave it empty to use the profile value. If your mail server allows fewer, enter its limit.
- **Fair queue.** The hourly letter limit is shared equally between journals that have letters waiting. A large event in one journal does not take the whole limit.
- **Portions.** Mailings and certificate issue run in portions; each portion queues the next one.
- **Quiet period.** For 48 hours before the submission deadline of any open edition, mass work pauses and resumes after the deadline. Participants' submissions take priority during that time.
- **Reminders** are never held, neither by the quiet period nor by the night window.

## How to set up cron and the OJS job queue

The plugin relies on two OJS mechanisms.

1. **The task scheduler** runs:
   - automatic closing of calls after the deadline (every 15 minutes);
   - reminders to participants (hourly);
   - checking unfinished card payments with the payment service (hourly, only where card payment is on);
   - retention of personal data (daily).
2. **The job queue** runs bulk decisions, mailings and batch certificate issue.

### Scheduler

Add a line like this to the crontab of the user the web server runs as:

```
*/5 * * * * cd /path/to/ojs && php lib/pkp/tools/scheduler.php run
```

If cron is not available on your hosting, turn on the web-based running of OJS scheduled tasks (the `task_runner` setting in `config.inc.php`). Tasks then run during ordinary site visits, with less precise timing.

### Queue

By default OJS runs queued jobs after ordinary web requests (`job_runner = On` in `config.inc.php`). For a small site this is enough. The number of jobs per request is set by `job_runner_max_jobs`; for the shared hosting profile the overview page recommends 5.

On a dedicated server or a multi-institution installation, process the queue separately:

1. Turn the queue on web requests off: `job_runner = Off` in `config.inc.php`.
2. Run a permanent queue worker, for example with systemd or supervisor:

   ```
   php lib/pkp/tools/jobs.php work
   ```

   Or process the queue from cron every minute:

   ```
   * * * * * cd /path/to/ojs && php lib/pkp/tools/jobs.php run
   ```

If a queue worker crashes in the middle of a job, that job may stay "running". Other runs are not affected.

## Jurisdiction profiles

A jurisdiction profile switches national fields and documents on. The journal manager chooses it in the institution profile, so different journals of one site can use different profiles.

- **Ukraine (`UA`).** The EDRPOU code with a check digit, the Ukrainian IBAN, treasury accounts (budget income classification code, KPKV), the EDEBO code, the professional development certificate, a public register of documents, the export for "Vector", sample privacy texts. The default event time zone is Europe/Kyiv and the currency is UAH.
- **Generic (`generic`).** For organizers in other countries: a legal entity identifier without national checks, bank accounts, certificates without national documents.

For a new journal the plugin suggests a profile by the primary language of the journal: Ukrainian gives `UA`, other languages give `generic`. The manager can change it.

Developers can add a profile for another country as a separate plugin through the hook `ConferenceManager::jurisdictions`, without changing Conference Manager itself.

## Several institutions on one site

The plugin is built for installations where several institutions share one OJS site, for example at a hosting provider or in a network of institutions.

- **Each journal is a separate institution.** Each journal has its own institution profile, signers, accounts, jurisdiction, editions, participants, invoices and certificates.
- **Data is isolated.** A manager or registration manager of one journal cannot see or change records of another journal: no participants, receipts, invoices or audit log entries. An automated test checks this isolation after every code change.
- **Roles are assigned per journal.** Add people to the "Registration manager" group and to journal managers in the journals where they actually work.
- **Quick setup of a new institution.** A manager can download the institution profile as JSON in one journal and import it in another. For typical event setups there are presets, see the [presets guide](presets.md). Put your own presets in `{files_dir}/conferenceManager/presets/` on the server, without changing the package.
- **Shared queue.** Letters of all journals go through one OJS queue, but the hourly limit is shared equally between journals.
- **"Upcoming events"** shows events of all journals with the **Journal / institution** filter.
- **The conference-site mode** is turned on per journal (the "Public part" button on the "Conferences" page, see the [manager's guide](manager.md)). It changes OJS texts ("About the Conference", "Current Proceedings", "Proceedings Archive") only in the journals where it is on. Other journals and site-level pages keep the usual OJS texts.

## Other things to know

- **Merging users.** When you merge two OJS accounts, the plugin's records (participants, receipts, journals, mailings) move to the account that remains.
- **Themes.** The plugin's public pages are checked with the Default, Manuscript and Health Sciences themes. The Health Sciences theme does not show the "Upcoming events" block on the journal home page in the conference-site mode, because its home page template lacks the hook. The events page and the "Conferences" menu item work there too. Colours are CSS variables (`--cm-accent`, `--cm-border` and others) that you can change in your theme's own CSS.
- **Receipt and certificate files** are stored in the OJS files directory (`files_dir`), outside the web root. Include this directory in your backups.
- **Deleting a journal** is blocked by invoices and certificates issued in it.
- **The OJS 3.5 submission card** scrolls sideways on a phone screen. This is how OJS itself behaves, not the plugin.
