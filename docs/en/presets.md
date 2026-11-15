# Presets: typical event setups

This guide is for institutions that want to set up their events quickly and consistently, and for the community that prepares typical setups for others. A preset describes in one file what a new edition will look like: the event type, the modes, participant categories, tariffs, payment rules and certificate settings. The journal manager picks a preset in the "First setup" wizard (see the [manager guide](manager.md#how-to-go-through-the-first-setup)).

## What a preset is

A preset is a JSON file. The wizard shows the manager the presets that match their institution's jurisdiction profile, with those for their institution type first, and creates an edition with all settings from the chosen one. After that everything can be edited by hand, and the preset no longer affects the edition it created.

**Amounts in built-in presets are only examples.** After creating an edition, check them and replace them with your institution's amounts.

## Built-in presets

| Code | Intended for |
|---|---|
| `ua-university-private` | a private higher education institution in Ukraine |
| `ua-university-state` | a state or communal higher education institution in Ukraine |
| `ua-ippo` | an in-service teacher training institute |
| `ua-research-institute` | a research institute |
| `ua-man` | a junior academy of sciences |
| `generic-conference` | a conference of any organizer without national rules |

The card of each preset in the wizard shows what exactly it creates.

## Where presets live

- **In the plugin package:** the `presets/` folder. This holds the built-in presets. They are replaced with each plugin upgrade, so do not put your own files there.
- **On the server, without changing the package:** the folder `{files_dir}/conferenceManager/presets/`, where `{files_dir}` is the OJS files directory from `config.inc.php`. The site administrator puts presets of an institution or the community here. Plugin upgrades do not touch them.

Presets from both folders are available in every journal of the site where the plugin is enabled, if they match the institution type and jurisdiction.

## File format

A UTF-8 file with one JSON object. Fields:

| Field | Meaning |
|---|---|
| `schema` | Format version. Always `1` for now. |
| `code` | Preset code: lowercase Latin letters, digits and dashes. Must be unique among all presets of the site. |
| `name` | Name for the wizard in the interface languages: `{"uk": "…", "en": "…"}`. |
| `description` | Short description for the wizard card, in the same form: `{"uk": "…", "en": "…"}`. |
| `jurisdiction` | `"UA"`, `"generic"` or `null` if the preset fits any jurisdiction. |
| `institutionTypes` | List of institution types the preset is shown for: `university`, `college`, `ippo`, `research_institute`, `man`, `ngo`, `other`. |
| `edition` | Edition settings (see below). |
| `categories` | List of participant categories. |
| `tariffs` | List of tariffs. |
| `paymentRules` | List of payment rules. |
| `certificates` | Certificate settings. |

An empty `institutionTypes` list means the preset fits any type of institution.

### `edition`

| Field | Values |
|---|---|
| `eventType` | `conference`, `seminar`, `webinar`, `school`, `round_table`, `contest`, `other` |
| `submissionMode` | `none` (no submissions, attendees only), `abstracts`, `abstracts_and_papers` (abstracts, then full papers) |
| `publicationMode` | `none` (no proceedings), `ojs_issue` (issue in this journal), `external` (external proceedings) |
| `format` | `in_person`, `online`, `hybrid` |
| `reviewMode` | review mode, as in the edition form (optional) |
| `hours` | hours for the professional development certificate, a whole number (optional) |
| `ectsX100` | ECTS credits times 100: `20` means 0.2 credits (optional) |

The preset does not set the edition's name, path or dates: the manager enters them at the third step of the wizard.

### `categories`

Each category: `{"code": "…", "requiresProof": true|false, "name": {"uk": "…", "en": "…"}}`.

- `code`: the category code that tariffs and payment rules refer to.
- `requiresProof`: whether proof is required (a certificate, a student card). If omitted, no proof is required.
- `name` may be omitted for the built-in codes, then the plugin supplies the name: `own_staff`, `partners`, `other_faculty`, `students`, `foreign`, `teachers_region`, `teachers_other`, `heads`, `researchers`, `young_researchers`, `pupils`, `teachers_supervisors`, `guests`, `members`, `general`. For your own codes, `name` is required.

### `tariffs`

Each tariff: `{"category": "…", "material": "…", "pricing": "…", "amount": "…", "currency": "…"}`.

- `category`: a category code from `categories`.
- `material`: `abstract`, `paper` (full text), `listener` (listener participation), `any` (any material).
- `pricing`: `flat` or `per_page`. Listener participation is always `flat`.
- `amount`: the amount as a string with two decimals, for example `"400.00"`. `"0.00"` means free.
- `currency`: an ISO 4217 currency code, for example `"UAH"`.

### `paymentRules`

Each rule: `{"category": "…" or null, "mode": "…"}`.

- `category`: a category code, or `null` for the "All other categories" rule. There can be only one rule with `null`.
- `mode`: `free`, `bank_transfer`, `treasury_transfer`, `external_organizer`, `external_link`.

The wizard creates rules that need a recipient account only if the institution profile has an active account. Otherwise it tells the manager to add the account and create the rule later on the **Payment** page. The wizard does not create an `external_link` rule, because a preset has no payment page address: the manager adds it on the **Payment** page.

### `certificates`

`{"professionalDevelopment": true|false, "vector": true|false}`

- `professionalDevelopment`: the institution issues professional development certificates (meaningful with the `UA` profile; the manager sets the hours in the edition).
- `vector`: turn on the export for the "Vector" platform. If the institution's jurisdiction profile has no such export, the wizard does not turn it on and says so.

## Example

A preset for a college conference in Ukraine: abstracts, proceedings in the journal, hybrid format, three categories, bank transfer, students free.

```json
{
  "schema": 1,
  "code": "example-college-conference",
  "name": {
    "uk": "Студентська конференція коледжу",
    "en": "College student conference"
  },
  "description": {
    "uk": "Тези, збірник у журналі, змішаний формат. Здобувачі освіти безкоштовно, інші за банківським переказом.",
    "en": "Abstracts, proceedings in the journal, hybrid format. Students free, others by bank transfer."
  },
  "jurisdiction": "UA",
  "institutionTypes": ["college"],
  "edition": {
    "eventType": "conference",
    "submissionMode": "abstracts",
    "publicationMode": "ojs_issue",
    "format": "hybrid"
  },
  "categories": [
    { "code": "students", "requiresProof": true },
    { "code": "own_staff", "requiresProof": true },
    {
      "code": "school_teachers",
      "requiresProof": false,
      "name": { "uk": "Вчителі закладів загальної середньої освіти", "en": "School teachers" }
    }
  ],
  "tariffs": [
    { "category": "students", "material": "abstract", "pricing": "flat", "amount": "0.00", "currency": "UAH" },
    { "category": "own_staff", "material": "abstract", "pricing": "flat", "amount": "200.00", "currency": "UAH" },
    { "category": "school_teachers", "material": "abstract", "pricing": "flat", "amount": "300.00", "currency": "UAH" },
    { "category": "school_teachers", "material": "listener", "pricing": "flat", "amount": "100.00", "currency": "UAH" }
  ],
  "paymentRules": [
    { "category": "students", "mode": "free" },
    { "category": null, "mode": "bank_transfer" }
  ],
  "certificates": {
    "professionalDevelopment": false,
    "vector": false
  }
}
```

## How to add your own preset

1. Start from the example above or from a built-in preset in the package's `presets/` folder.
2. Change `code` to your own unique code. Name the file after the code, for example `example-college-conference.json`.
3. Fill in `name` and `description` in both languages, choose `jurisdiction` and `institutionTypes`.
4. Check the JSON syntax with any validator: one extra comma or quote makes the file invalid.
5. Give the file to the site administrator. They put it in `{files_dir}/conferenceManager/presets/` on the server (the folder can be created with the hosting file manager or FTP).
6. The administrator opens **Conferences: site overview** and, in the **Presets** block, checks how many presets are available and that the file is not in the list of errors.
7. The journal manager opens **Setup wizard** on the "Conferences" page and sees the new preset in the list.

### If the preset is not in the wizard

- A file with errors is not offered in the wizard. The **Presets** block of **Conferences: site overview** names the file and the error: for example a wrong field value, a duplicate `code`, or a tariff with a category code that is not in `categories`.
- Check `institutionTypes`: for the listed institution types the preset comes first; for other types it is shown on a grey background. The institution type is set in the institution profile, and it can be changed at the first step of the wizard.
- Check `jurisdiction`: a preset with `"UA"` is not shown to a journal with the "Generic (no national rules)" profile, and the other way round. `null` fits any.

## How to share a preset with the community

If your preset would help other institutions, propose it for the package through the project repository (see `CONTRIBUTING.md`). Before that:

- remove names, legal details and other data that apply only to your institution;
- keep the amounts as examples and say so in the description;
- make sure names and descriptions are in both Ukrainian and English.
