# Migrating an OCS archive and old addresses

A guide for institutions moving from Open Conference Systems (OCS 2.x) to OJS 3.5 with Conference Manager. It covers migrating published papers and making old links lead to the new pages.

## What is migrated

- **Migrated:** published papers with their metadata and PDFs.
- **Not migrated:** registrations, schedules, reviews and unpublished submissions. Keep them in a read-only static copy of the old site.
- **DOIs.** If some papers are already in the institution's repository (DSpace or another), decide beforehand where DOIs are registered. One work should have a DOI in one system only.

## Steps

Work on a **copy** of the OCS database and files, never on the live server.

1. **Restore an OCS copy.** Load the MySQL dump and the OCS files folder on a separate machine or container with PHP 5.6 and MySQL. Check that the number of papers per edition matches the public site.
2. **Export the papers.** Use [ocs-to-ojs](https://github.com/pkp/ocs-to-ojs) to get Native XML: the conference becomes a journal, an OCS edition becomes an issue, a track becomes a section.
3. **Import into a test OJS 3.4.** Use the Native XML command line tool and check that the number of articles and files matches step 1.
4. **Upgrade the test OJS to 3.5.** Run the standard OJS upgrade.
5. **Export from 3.5 and import into the live journal.** Do it one issue at a time in quiet hours, on a test installation with the plugin first.
6. **Match the old addresses.** See "Old addresses" below.
7. **Set up redirects on the old domain.** See "Redirect map".
8. **Switch OCS off.** Then watch the server's log for 404 errors for a month.

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
