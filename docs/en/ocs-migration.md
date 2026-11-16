# Migrating an OCS archive and old addresses

A guide for institutions moving from Open Conference Systems (OCS 2.x) to OJS 3.5 with Conference Manager. It covers migrating published papers and making old links lead to the new pages.

## What is migrated

- **Migrated:** published papers with their metadata and PDFs.
- **Not migrated:** registrations, schedules, reviews and unpublished submissions. Keep them in a read-only static copy of the old site.
- **DOIs.** If some papers are already in the institution's repository (DSpace or another), decide beforehand where DOIs are registered. One work should have a DOI in one system only.

## Steps

Work with a **copy** of the OCS database and files, not with the live server.

1. **Move the papers with the script.** See "Migration script" below. It restores the OCS copy, exports the papers, imports them into OJS 3.4, upgrades to OJS 3.5 and compares the result edition by edition.
2. **Review the result.** Open the test OJS 3.5 from the script and check a few papers of every edition: titles, authors, abstracts, PDFs.
3. **Move them into the live journal.** Export the issues from the test OJS 3.5 as Native XML and import them into the live journal one issue at a time, at quiet hours. Do it first on a test copy of the live site with the plugin.
4. **Match the old addresses.** See "Old addresses" below. The script puts ready lists of papers into `legacy/`.
5. **Set up redirects on the old domain's server.** See "Redirect map".
6. **Turn OCS off.** Then watch the server log for 404 errors for a month.

## Migration script

The script `tools/ocs-migration/migrate.sh` is in the plugin's repository (it is not part of the OJS package). Run it on a separate machine with Docker, not on the live server.

```
tools/ocs-migration/migrate.sh ocs-dump.sql.gz /path/to/ocs-files
```

- First argument: the OCS database dump (`.sql` or `.sql.gz`).
- Second argument: the OCS files folder (`files_dir` in OCS `config.inc.php`). Without it the papers move without PDFs: empty placeholders take the files' place, and the comparison shows it.
- The internet is only needed in the first step, to download OCS 2.3.6 and the [ocs-to-ojs](https://github.com/jonasraoni/ocs-to-ojs) tool at tested versions. Every container with data runs on an internal Docker network without internet access, so the personal data in the dump stays on the machine.

What the script does:
1. Restores the dump into MariaDB.
2. Exports the published papers with ocs-to-ojs on PHP 5.6. A conference becomes a journal, an OCS edition becomes an issue, a track becomes a section.
3. Fixes the issue year: it is taken from the edition's **start** date. ocs-to-ojs itself takes the end date, which OCS sites often set years ahead so that the edition's site stays open. Set `YEAR_FROM=end` to use the end date.
4. Imports the papers into OJS 3.4. The script fixes a known ocs-to-ojs bug: in the official OJS images Cyrillic text read from the OJS database became an empty string, and no paper was imported.
5. Upgrades OJS to 3.5.
6. Compares every edition with OCS: papers, files, and how many files have content. The table is saved in `verify.txt`. On any difference the script ends with an error.
7. Writes, for every conference, the list of papers for the old addresses (`legacy/{conference}.tsv`).

Result: the container `ocsmig-ojs35` with the test OJS 3.5 (sign in as admin / admin12345) and the database `ojs` in the container `ocsmig-db`. The work folder is `./ocs-migration-work` by default; set `WORK` to change it. After the migration remove the containers and the work folder: they hold personal data.

## Old addresses

A typical OCS 2.x paper address looks like `/{conference}/{edition}/paper/view/{id}`, for example `/SRE/SRE-2025/paper/view/123`. The plugin needs to know which OJS article each OCS paper id became.

### 1. List of papers from the OCS database

Run this query on the **copy** of the OCS database:

```sql
SELECT p.paper_id AS ocs_paper_id,
       c.path     AS conference,
       sc.path    AS sched_conf,
       (SELECT ps.setting_value FROM paper_settings ps
         WHERE ps.paper_id = p.paper_id AND ps.setting_name = 'title'
         ORDER BY ps.locale = 'en_US' DESC, ps.locale LIMIT 1) AS title
  FROM published_papers pp
  JOIN papers p       ON p.paper_id = pp.paper_id
  JOIN sched_confs sc ON sc.sched_conf_id = p.sched_conf_id
  JOIN conferences c  ON c.conference_id = sc.conference_id
 WHERE p.status = 3  -- published only: published_papers also keeps papers declined later
 ORDER BY p.paper_id;
```

Save the result as a tab-separated file, for example:
`mysql --batch -u ocs -p ocs_copy -e "…query…" > papers.tsv`.

The plugin accepts CSV with commas, semicolons or tabs. The first row must name the columns:
- required: `ocs_paper_id`, `conference`, `sched_conf`;
- for matching by title: `title`;
- for exact matching: `submission_id`, the OJS article id.

Institutions sometimes changed the database schema. Check the query on the copy and adjust the table names if needed.

### 2. Matching

1. In **Conferences**, click **Old OCS addresses**.
2. Choose the file under **CSV file**, or paste its content.
3. Click **Upload and match**.

How the plugin matches papers:
- with `submission_id`, that article is used (it must be published in this journal);
- without it, the published article with the same title in any language is used (case and punctuation do not matter);
- if several articles share the title, the one published in the year from the edition path (`SRE-2025` gives 2025), or in the next year, wins.

The report lists:
- skipped rows with the reason;
- papers that match several articles;
- papers with no article.

For papers in the report, add `submission_id` and upload the file again: existing rows are updated.

**Check an address** shows where any old address leads.

### 3. Address templates

The default templates cover `paper/view`, `paper/viewPaper`, `paper/download` and `paper/viewFile`, with or without an `index.php/` prefix. If the institution's OCS used other addresses, change them under **Old address templates**.

Template parts:

| Part | Meaning |
|---|---|
| `{conference}` | the conference path |
| `{schedConf}` | the edition path |
| `{id}` | the paper id (required) |
| `{fileId}` | a file id |
| `{any}` | any single part of the address |

A template needs at least one fixed part, such as `paper`.

### 4. Redirect map

The buttons under **Redirect map for the web server** give ready files for the administrator of the old domain's server:
- **nginx.** Put the `map` block into `http { }` and the `location` blocks into the `server { }` of the old domain. Then run `nginx -t` and reload nginx.
- **Apache.** Put the "map" file where the `RewriteMap` line in the "rules" file points (change the path if needed). Put the rules into the `<VirtualHost>` of the old domain: `RewriteMap` does not work in `.htaccess`. `mod_rewrite` is required. Then run `apachectl -t` and reload Apache.

Addresses that are not in the map are served as before, for example from a static copy of OCS. Download the map again after each new import of papers.

### 5. Without a map

If the old domain points to this OJS site and the journal path equals the first part of the old addresses (journal `SRE` for `/SRE/SRE-2025/…`), the plugin itself answers with a 301 redirect to the article. The map is then optional, but the server answers faster with it.

## Checking

- Take 200 random old addresses (for example, from the old server's access log) and check that each leads to an article page that answers 200.
- After switching OCS off, watch the old domain for 404 errors for a month.
