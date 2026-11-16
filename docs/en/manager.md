# Journal manager and organizing committee guide

This guide is for the OJS journal manager and the members of the organizing committee who prepare and run events: conferences, seminars, webinars, schools, round tables and contests. It covers the whole path: the institution profile, the first setup, editions, fees, payment, decisions on submissions, mailings, the program, attendance, certificates, personal data and reports.

The site administrator has to install and enable the plugin first, see the [site administrator guide](site-admin.md).

## Where things are

- **Institution profile:** **Settings → Website → Plugins**, the **Organizing institution profile** button next to "Conference Manager".
- **The "Conferences" page:** the **Conferences** item in the journal's side menu (address `/{journal}/manageConference`).

At the top of the "Conferences" page there are buttons for the whole journal: **Reminders**, **Certificate templates**, **Audit log**, **Personal data**, **Setup wizard** and **New edition**. Above the list of editions there is a **Status** filter.

Each edition in the list has the buttons **Summary**, **Edit**, **Fees**, **Participants**, **Payment**, **Invoices**, **Receipts**, **Mailings**, **Certificates**, **Decisions** (only if the edition accepts submissions), **Copy**, **Public page** and **Delete**. **← Back to events** returns to the list.

## Suggested order of work

1. Fill in the institution profile: legal details, signers, accounts, privacy texts.
2. Create the first edition with the "First setup" wizard or by hand.
3. Check the participant categories, tariffs and payment rules.
4. Assign a registration manager.
5. Open the call.
6. After the deadline, record decisions, follow payments, send mailings.
7. Publish the program, mark attendance, issue certificates.
8. Export data for accounting and look at the summary.

## How to fill in the institution profile

Open **Settings → Website → Plugins** and click **Organizing institution profile** next to "Conference Manager". These details go into invoices, certificates and the public pages of events. Each journal has its own profile.

### Institution

1. Choose the **Jurisdiction profile**: "Ukraine" or "Generic (no national rules)". "Ukraine" adds the EDRPOU code, treasury accounts, professional development certificates and the export for "Vector". After changing the profile, save the form and open it again to see the fields of the new profile.
2. Choose the **Institution type** (higher education institution, college, in-service teacher training institute, research institute, junior academy of sciences, non-governmental organization or other) and **Ownership**.
3. Fill in **Full name** and **Short name** in all languages of the journal.
4. Enter the **EDRPOU code** (for "Ukraine": 8 digits, the check digit is verified) or the **Legal entity identifier**. If needed, enter the **EDEBO institution code**.
5. Fill in **Address**, **Contact email** and **Website**.

### Signers

Under **Signers**, click **New signer** and enter **Full name**, **Position** and **Signs documents**: the invoice, certificates of the kinds you need, the professional development certificate. Each document prints the first signer assigned to it.

### Payment accounts

Under **Payment accounts**, click **New account** and fill in:

- **Account type:** bank account, treasury account or co-organizer account;
- **Internal label:** to tell accounts apart in the settings;
- **Recipient**, **IBAN**, **Bank**, and **SWIFT (BIC)** if needed;
- for a treasury account: **Budget income classification code**, **Budget programme code (KPKV)** and **Note for the payer**;
- **Payment purpose template**. You can insert variables: `{invoiceNumber}`, `{paymentReference}`, `{payerName}`, `{editionTitle}`, `{editionPath}`, `{year}`, `{amount}`, `{currency}`;
- **Active**: only active accounts can be chosen in payment rules.

The IBAN checksum is verified. A Ukrainian IBAN starts with UA and has 29 characters.

### Personal data

- **Privacy notice:** the text participants see and agree to when they submit or register. If it is empty, the plugin's general text is shown.
- **Data protection contact**.
- **Data retention periods**.

With the "Ukraine" profile, an expandable block **Example text for the lawyer to adapt** under these fields gives a sample based on the Law of Ukraine "On Personal Data Protection". Copy it into the field, replace the parts in square brackets with your institution's details, and have your institution's lawyer review it. The sample is not legal advice.

When you change the consent text, participants who have not yet sent their submission must agree again. All versions of the text are kept, see "Audit log".

### Submissions

The option **The journal also accepts regular articles not related to conferences** lets authors choose "Not for a conference (regular article)" in the submission wizard. If it is off, then while any event accepts submissions, every submission must be for an event.

### Profile import and export

**Download the profile as JSON** saves the profile to a file. In another journal, paste this JSON into **Import a profile (JSON)** and save the form. Signers and accounts from the file replace the current ones.

## How to go through the first setup

When you open **Conferences** for the first time in a journal that has no editions yet, the page shows **First setup**. The wizard creates an edition ready to use from a typical setup (a preset).

1. **Institution type.** Check **Type of your institution**: it is pre-filled from the institution profile. Click **Next**.
2. **Preset.** Pick a preset from the list with **Choose**. Presets for your institution type and jurisdiction profile come first. Presets on a grey background are meant for other types of institutions, but you can choose them too. The preset card shows what the wizard will create: the event type, submission and publication modes, participant categories, tariffs, payment rules and certificate settings. Presets added by the site administrator are marked as such.
3. **First edition.** Enter the name, the page path and the dates. Dates are in the time zone the wizard shows; you can change it later in the edition form.
4. Click **Create the edition**.

The wizard creates the edition with the preset's event type and modes, the participant categories, tariffs, payment rules and certificate settings.

- The edition is created as a draft. Check it, its fees and payment rules, then change the status to "Call open" in the edition form. **Back to editions** returns to the list.
- Payment rules that need a recipient account are created only if the institution profile has an active account. Otherwise the wizard tells you to add the account to the profile and create the rule later on the **Payment** page.
- The wizard does not create a "Payment on an external page" rule, because it needs the address of the payment page. Add it on the **Payment** page.
- The "Vector" export is turned on only if the institution's jurisdiction profile has it.
- Amounts in presets are examples. Be sure to replace them with your own.
- Everything the wizard creates can be edited afterwards in the usual way.
- To create another edition from a preset, click **Setup wizard** on the "Conferences" page.

How presets work and how to add your own is in the [presets guide](presets.md).

## How to create and set up an edition

Click **New edition** (or **Edit** next to an existing one). The form has language tabs for all journal languages and the sections **General**, **Submissions and publication**, **Dates and windows**, **Texts for participants**, **Proceedings and certificates** and **Program of the event**.

### General

- **Title** (required in the primary language of the journal).
- **Page path:** part of the URL, for example `conf-2027`. Lowercase Latin letters, digits, hyphens and underscores, unique in the journal.
- **Event type:** conference, seminar, webinar, school / course, round table, contest, other event. Changing the type fills in the default modes: a conference takes abstracts and has an issue in the journal; a webinar, school and round table have no submissions and no proceedings; a contest takes abstracts, then full papers.
- **Format:** in person, online or hybrid.
- **Time zone** and **Venue**.
- **Status** (see below).

### Submissions and publication

- **Submissions:** "No submissions (attendees only)", "Abstracts" or "Abstracts, then full papers".
- **Proceedings:** "No proceedings", "Issue in this journal" or "External proceedings (link)". For external proceedings, enter the **Proceedings link**.
- **Review:** light (decision at submission stage), two-phase or standard OJS review.

With "Issue in this journal" the plugin creates one unpublished OJS issue for the edition. Accepted papers go into it automatically. The plugin never deletes the issue.

### Dates and windows

**Starts**, **Ends**, **Call opens**, **Abstract deadline**, **Full paper deadline**, **Registration closes**, **Payment deadline**.

- Dates are in the local time of the event, as `YYYY-MM-DD HH:MM`. Daylight saving time is taken into account.
- At the deadline the window is already closed. A date without a time means 00:00 that day.
- Dates must be in order. A full paper deadline applies only in the "Abstracts, then full papers" mode.

### Texts, proceedings and certificates

- **Description**, **Call for papers**, **Learning outcomes**.
- **Proceedings ISBN** (ISBN-13 with check digit), **UDC / classification**.
- **Hours** and **ECTS credits**. With hours set, listeners in the "Ukraine" profile receive a professional development certificate. Without credits, they are counted as hours divided by 30.
- **Partial qualifications (if awarded)** and **Vector programme ID**.

### Edition statuses

The usual order: "Draft" → "Call open" → "Call closed" → "Held" → "Proceedings published" → "Archived". The **Status** field offers only allowed changes.

- **The call does not open automatically.** Set "Call open" in advance. Submissions are accepted only between the call opening and the deadline.
- The call closes automatically: every 15 minutes the OJS scheduler moves an edition to "Call closed" once the abstract deadline has passed.
- A draft's public page is visible to managers only, marked "Draft".

### Copying and deleting

- **Copy** opens the form filled in from an existing edition, without dates and ISBN. At the bottom of the form, **What else to copy** lets you take from the previous event: **Participant categories and tariffs**, **Payment rules**, **Program rooms**, **Review and abstract book settings**, **Co-organisers and partners with logos**. All are ticked by default. Tariff validity dates (an early-bird price, for example) move by as many days as the event's start date moved; check them after copying. Participants, invoices, submissions and program sessions are never copied.
- **Delete** works only for an edition without participants, invoices and submissions. Otherwise the edition can only be archived.

### Public page

**Public page** opens `/{journal}/conference/{path}`: type, format, dates, venue, submission windows, call for papers, workload, ISBN. The **Submit** button appears only while the call is actually open. **Register as a listener** appears while registration is open. The page carries schema.org/Event markup for search engines.

### Together with the TIB Conference plugin

The plugin works without [TIB Conference](https://github.com/withanage/conference). When TIB Conference is installed and enabled in the journal and the edition has its own OJS issue (the "Issue in this journal" publication mode), the dates, format and venue are entered once:

- Saving the edition fills the conference fields of the issue: **start date** and **end date** (the day in the edition's time zone), **street / place** (the venue in the journal's primary language) and **online conference** (only for the "online" format).
- When these fields are changed in the OJS issue form (**Issues → Future Issues → Edit**), the edition takes the new days with the same start and end times, the new venue in the primary language and the format ("online" when the box is ticked; "in person" when it is unticked on an online event). A hybrid event stays hybrid while the box is unticked.
- The **city** and **country** are set in the issue form only: the edition has no such fields and the plugin does not change them.
- A change from the issue form that the edition cannot take (an end before the start, say) is not applied. The edition stays as it was, and the next save of the edition puts the issue fields back in step with it.
- The plugin adds Ukrainian labels for the TIB fields of the issue form, since TIB ships no Ukrainian translation.
- Changes from the issue form go to the audit log as "tib_sync". The edition form shows a note above the fields that the sync is on.

### Co-organisers and partners

**Partners** in the edition's row opens the organisations that hold the event together with the journal or support it (up to 30 per event).

- **Add a partner**: choose the **Role** (co-organiser, partner, sponsor, media partner), enter the **Name** in the journal's languages (the primary one is required) and, if needed, the **Website**.
- **Add logo**: PNG, JPEG or WebP up to 1 MB. SVG is refused because it can carry scripts. The file goes to the OJS public files folder, so the page shows it without extra requests.
- The **↑** and **↓** arrows change the order. On the public page partners are grouped by role, co-organisers first, and keep this order within a group.
- **Account for the fees** can be set only for a co-organiser. The list holds the active accounts of the "co-organizer account" kind from the institution profile. The account used in participants' invoices is still chosen in the payment rules; the mark on the partner only shows on the public page who receives the fees.

The public page of the event shows an **Organisers and partners** block with logos and links. In the schema.org markup co-organisers join the organizers and sponsors are listed as sponsor.

## How to set up participant categories and tariffs

Click **Fees** next to the edition.

### Participant categories

- Choose an institution type in the list next to it and click **Add typical categories** to get the set for that type. For a higher education institution, for example: staff of the institution, staff of partner institutions, faculty of other institutions, students, foreign participants. Then adjust the set.
- Or click **Add a category** and enter the **Name** (in all languages), the **Code** (for reports, e.g. `students`) and, if needed, **Proof required (certificate, student card, etc.)**.
- A category that participants already have cannot be deleted.

An author chooses the category in the speaker questionnaire, a listener when registering.

### Tariffs

Click **Add a tariff** and fill in:

- **Category** and **Material**: abstract, paper (full text), listener participation or any material;
- **Presentation type**: any or a specific one;
- **Pricing**: flat amount or per page; for papers you can limit **Pages from** and **Pages to**;
- **Amount** (e.g. `150` or `150.50`) and **Currency** (ISO 4217 code: UAH, USD, EUR);
- **Valid from** and **Valid until (exclusive)**, for example for an early-bird discount. Without dates a tariff always applies.

An amount of 0 means free participation. When several tariffs fit, the most specific one wins: a tariff for a specific presentation type beats a general one, a page range beats a tariff without pages, a tariff with dates beats an open-ended one.

To check your setup, use **Check the calculation** and click **Calculate**.

## How to limit seats and give out promo codes

Click **Seats and promo codes** next to the edition. Registration managers see the same page.

### In-person listener seats

Enter how many listeners can attend in person in **Number of seats** and click **Save**. An empty field means "no limit". Above the field you can see how many seats are taken and how many people are on the waiting list.

- When the seats run out, new in-person listeners go to the **waiting list**. They get no invoice.
- As soon as a seat frees up (a participant cancelled, or you raised the number of seats), the first person in the queue gets it automatically: the participation becomes active, an invoice is issued and the person gets the "A seat is free for you" email. You can change its text in OJS **Email templates**.
- The limit applies only to in-person listeners. It does not limit presenters, co-authors or online listeners.
- The queue is shown under the field: name, email and the date the person joined it.

### Promo codes

Click **Add a promo code** and fill in:

- **Code:** 3 to 32 Latin letters, digits, dashes or underscores. The participant may type it in lower case and with spaces, it does not matter.
- **Discount:** a percentage from 1 to 100 or a fixed amount in the currency of the tariffs. The percentage is rounded in favour of the payer. An amount never takes the fee below zero; if the fee after the discount is zero, the participant gets a waiver with the reason "promo code".
- **How many times it can be used:** an empty field means no limit.
- **Valid from** and **Valid until (included)**: dates in the time zone of the edition.
- **Note for managers**, for example who the code was given to. Participants do not see it.
- **The code is on:** a code that is off is not accepted.

The participant enters the code when registering as a listener or later in the cabinet, while the invoice is not paid. One code applies to a participation. The invoice shows the code and the discount next to the fee.

A code that was used cannot be deleted, and its name and discount cannot change, so invoices stay clear. You can switch it off or change its limit, period or note. A cancelled participation gives the use back. All changes to codes go to the audit log.

## How to set up payment rules

Click **Payment** next to the edition. In **How participant categories pay**, click **Add a rule**.

1. Choose a category or "All other categories". A category rule wins over the general one. There can be only one general rule.
2. Choose the **Payment mode**: "Free", "Bank transfer by invoice", "Treasury transfer", "Paid by a co-organizer", "Payment on an external page" or "Online card payment". "Online card payment" can be saved only after a payment service is set up (see below).
3. For a transfer, choose the **Recipient account** from the institution profile. Bank transfer accepts only a bank account, treasury transfer only a treasury account.
4. For an external page, enter the **Payment page link** (`https://` only).
5. If needed, write **Instructions for the payer**. If empty, the participant sees the default instructions for the mode. For Ukraine the instructions always warn that a transfer to a card number is not a payment to the institution's account.

Without rules, with "Free", or with a zero tariff no invoices are issued.

### Online card payment (LiqPay)

A participant can pay an invoice by card, Apple Pay or Google Pay on the payment service's page. The money goes to your own merchant account; the plugin never sees or stores card data. LiqPay (PrivatBank) is supported now. Other services can be added by separate plugins.

Before you turn it on:
- sign an agreement with LiqPay for the institution and get the shop keys in the LiqPay account ("Settings" → "API");
- agree fiscalisation with the accounting office: card payments need a fiscal receipt (PRRO in Ukraine). LiqPay can issue it (its PRRO service) or you use your own. The plugin does not issue fiscal receipts;
- a budget institution should check with the treasury whether it may take acquiring payments to its account.

To set it up:
1. On the **Payment** page of any edition, find **Online card payment**. The setting is shared by the whole journal.
2. Choose **Payment service** "LiqPay" and paste the **LiqPay public key** and the **LiqPay private key**. The private key is never shown again after saving. Leave the field empty to keep it.
3. To try it out, tick **Test mode (sandbox)** and use the test keys (they start with `sandbox_`). No money is taken, and test payments mark invoices paid. Before taking real payments, untick it and paste the live keys. Without test mode, test payments do not count.
4. Click **Save**.
5. In the edition's **payment rules**, choose "Online card payment" for the categories you want.

How it works:
- In the participant's cabinet an open invoice gets a **Pay by card** button with the amount due. It appears only while the invoice is open, the amount is above zero and the service supports the currency (LiqPay: UAH, USD, EUR).
- An invoice becomes paid only from a report signed with your LiqPay private key. Repeated reports do not count twice; forged reports are refused.
- If a report is lost, the plugin asks LiqPay itself: right after the participant comes back from LiqPay, and hourly for attempts from 10 minutes to 7 days old. The OJS scheduler must be running for this.
- Each attempt has its own order number, `{journal}-{invoice number}-{attempt}`, which you can find in the LiqPay account. The attempts of an invoice are available through the API: `GET …/invoices/{id}/gateway-payments`.
- If a participant pays an invoice that is already closed (for instance twice in two tabs) or in another currency, the invoice does not change. The audit log gets a `gateway_paid_unmatched` entry and you refund in the LiqPay account. A refund made in LiqPay is logged as `gateway_reversed` but does not change the invoice: change it by hand if needed.
- To turn card payment off, choose the service "Off". Rules with this mode stay, but the button disappears.

The address for reports (`server_url`) is sent to LiqPay with each payment: `…/conference/payment/callback/liqpay`. The site must be reachable from the internet over HTTPS.

### Invoice templates

The same page has **Invoice templates**: "Invoice (bank)" and "Invoice (treasury)". The PDF is built from an HTML template. You can adapt the template to your institution using only the variables listed under **Variables:**. **Restore built-in** undoes your changes.

### Payer's tax number

With the "Ukraine" profile there is the option **Require the personal tax number from individuals who pay**. It is off by default. Turn it on only when your accounting department requires it: it is sensitive personal data.

## How invoices work

- A listener is invoiced right after registering. A presenter is invoiced after the acceptance decision.
- The number looks like `2027-00001` and has no gaps within the journal and year. The payment reference has 8 characters including a check character.
- Payer and recipient details are fixed at the moment of issue.
- The payer gets a letter and sees the invoice, the PDF and the instructions in the cabinet.

Click **Invoices** next to the edition to see all invoices. Search by number, payment reference or email. For an invoice you can:

- **Mark paid**: enter the amount received. A smaller amount is recorded as a part payment and the invoice stays open with the balance.
- **Waive**: enter the reason. A zero invoice with the reason is created and the old one becomes "superseded".
- **Reissue**: a new invoice by the current tariff, category and presentation type. The amount paid is carried over. If more has been paid than the new amount, the invoice is not reissued.
- Cancel the invoice, download the PDF, see **History**.

A paid invoice can be neither waived nor cancelled.

### Refunds
Accounting returns the money; the plugin records the refund so that it is in the books and in the export.
- **Refund request**: mark that the payer asks for the money back and, if needed, write why. When a participant cancels after paying, the invoice gets this state by itself.
- **Record a refund**: enter the amount and how the money was returned (for example the date and number of the payment order). Without an amount, all that was paid is refunded and the invoice becomes "refunded". A smaller amount is a part refund, for example when one member of a group did not come.

The export for accounting has the columns **Refunded**, **Refund date** and **Refund note**.

### Group invoice
When one organisation pays for several participants (for example a school for its teachers), issue one invoice for them.
1. In the **Participants** register select the participants and click **Group invoice**.
2. Enter the name of the organisation, its code (the legal id of the jurisdiction profile) and the contact person. The contact person gets the invoice letter and sees the invoice in the cabinet.
3. Click **Issue the group invoice**.

Each participant has a line of their own by their category and tariff. Unpaid invoices of these participants are replaced by the group invoice. Participants who already paid something, are waived, are invited or already have a group invoice are not added; the page lists them with the reason. A group invoice is not reissued: to change the group, cancel it and issue a new one.

### Invited participants
An invited participant (keynote speaker, chair, guest) pays no fee.
1. In the **Participants** register click **Invite a participant**.
2. Enter the email, given name, family name, affiliation and role. Add a text for the letter if needed.
3. Click **Invite**.

The person gets a letter with links to the event page and to the cabinet, where they sign in with this email. Their unpaid invoices in this event are waived, and new invoices, for example after a paper is accepted, are issued at zero with the reason "Invited participant". The register shows an **Invited** mark next to the name. The letter can be changed in the journal's email templates (**Participant invitation**).

To issue an invoice by hand (for example when a paper is paid per page), use the **Invoice** button in the **Participants** register.

**Publishing waits for payment.** OJS will not publish an event paper until the presenter's invoice is paid or waived.

Receipt review, the payment journal and the register of participants are described in detail in the [registration manager guide](registration-manager.md).

## How to assign a registration manager

Open **Users & Roles**, find the user and give them the **Registration manager** role. They see the **Conferences** item but cannot edit editions, tariffs, payment rules or templates.

## How to record decisions on submissions

### On the submission card

At the "Submission" stage, next to the standard OJS buttons, there are four buttons:

- **Accept: oral presentation**;
- **Accept: poster presentation**;
- **Accept: online presentation**;
- **Accept: publication only**.

They appear only for submissions linked to an event. Each opens the standard OJS decision page with a letter to the authors that you can edit.

After the decision:

- the presentation type is recorded in the submission;
- the submission moves to copyediting and into the event's issue;
- the presenter is invoiced by the tariff of the accepted type;
- the authors get a letter.

The submission card has an **Event participation** panel: the event, the presentation type, participants and their confirmation, the invoice and the receipt. At the copyediting and production stages you can click **Change the presentation type** here. The authors get a letter, and an unpaid invoice is reissued with the paid part carried over.

### Bulk decisions

1. Click **Decisions** next to the edition. The page **Decisions on abstracts** lists submissions waiting for the first decision.
2. Find the ones you need: **Search by title, author or number**, the filters **Requested presentation type** and **Section**, then **Find**.
3. Tick submissions or click **Select all**.
4. In **Decision**, choose one of the four "Accept" decisions or "Decline".
5. Click **Record the decision** and confirm. This cannot be undone.

Up to 200 submissions at once. Each submission is decided by a separate OJS queue job, so every author gets their own letter, and a failure in one submission does not stop the others. The page shows progress, any failed submissions and **Recent runs**. A section editor sees only submissions assigned to them. Sending to review or requesting revisions is done one by one from the submission card.

### Letter texts

All plugin letters are journal email templates: a decision for each presentation type, the presentation type change, co-authorship confirmation, invoice issued, receipt accepted or rejected, and three reminders. Edit them in **Settings → Workflow → Emails**. Plugin upgrades do not overwrite texts you changed.

## How to send a mailing

1. Click **Mailings** next to the edition.
2. In **Recipients**, choose the conditions: role, category, payment, **Section**, **Only authors of accepted materials**, **Full text** (uploaded or not).
3. Click **Preview recipients**.
4. Fill in **Subject** and **Text of the letter**. Variables (recipient's name, event name, cabinet link, journal name) are inserted by clicking them under **Variables (click to insert)**.
5. Click **Send a test letter to myself** and check the letter.
6. Click **Send the mailing** and confirm.

Each person gets one letter even with several roles. Up to 5,000 recipients per mailing. Letters go in portions through the OJS queue within the letters-per-hour limit. A started mailing cannot be cancelled. Progress and failed addresses are under **Previous mailings**.

## How to turn on reminders

Click **Reminders** at the top of the "Conferences" page. There are three kinds:

- **Payment:** to participants with an unpaid invoice and no receipt waiting for review;
- **Full text:** to authors of accepted abstracts without a full text;
- **Event start:** to listeners and presenters of accepted papers.

For each, tick **Send**, set **Days before** (1 to 60) and save. Reminders are off by default. They are sent hourly for events whose call is open or closed, and each person gets each reminder only once. Without the OJS scheduler (cron) no reminders are sent.

"Full text uploaded" means the author uploaded a file after the acceptance decision. If an editor uploads the file, the plugin does not count it.

## How to build and publish the program

### Schedule in the builder

1. In **Conferences**, click **Program** next to the edition.
2. Open **Rooms** and add rooms: a name and, if you like, capacity and a link to an online room (Zoom, Teams, Meet). The link of an online room becomes the stream link of all its sessions.
3. Click **Add session** (or **Add session on this day**) and fill in the type, title, start and end, room, chairs and the default length of one paper. A seminar can have hours, ECTS credits and learning outcomes. A session can have its own stream link and the time from which participants see it.
4. Drag accepted papers from **Unassigned papers** into a session, or pick a session in **Move to**. The arrows change the order; the "min" field sets the length of one paper.

Times are in the time zone of the edition. The builder computes the time of each paper and warns when:
- the room is already taken by another session (such a session is not saved);
- the papers do not fit into the session;
- the same presenter speaks in two sessions at the same time.

Breaks cannot hold papers. A paper declined or withdrawn after it was placed is shown struck through and can only be taken out.

### Publishing

1. Click **Edit** next to the edition and go to **Program of the event**.
2. If needed, fill in **Introduction to the program (schedule, rules, links)**.
3. Click **View the program** to see the draft. Visitors cannot see it yet.
4. Tick **Publish the program on the event page** and save.

The address is `/{journal}/conference/{path}/program`. Without sessions, the program lists accepted papers by journal section, then by presentation type and alphabetically. With sessions, it goes by days and time, and a visitor in another time zone can switch it to their own time.

A published program also gives:
- **Add to calendar (iCal)** and **Download PDF**, and **Add this session to calendar** in each session;
- stream links only for registered participants of the event and managers, and only from the time set in the session or, by default, from the start of the session's day. iCal and PDF files never contain stream links;
- an **In the conference program** block with the date, time, session and room on the page of a published article.

## How review works in an event

The review mode is chosen in the event settings (the **Review** field).

### Committee voting (light mode)
In the light mode decisions are made at the submission stage. Every committee member, that is an editor or section editor assigned to the submission, sees a **Committee votes** block in the submission card:
- the votes of the other members and the totals for, against and abstained;
- their own vote with a comment, which they can change or withdraw.

The chair also sees the totals on the **Decisions on submissions** page in the votes column, so they can decide on many submissions at once. A vote only helps the decision: the editor still makes it. Every vote is written to the audit log.

### Conflict of interest
The plugin compares the affiliation of the submission's editors (from the user profile) with the affiliation of the authors (from the submission, in any language, with the ROR id). If the institution or department matches, the submission card shows a **Possible conflict of interest** warning. The editor concerned sees a separate warning for themselves. The plugin blocks nothing: a person decides whether to take part.

### Two-phase review (abstract, then full text)
1. In the event settings choose the **Two-phase (abstracts, then papers)** mode and, if needed, the full text deadline.
2. Next to the event on the **Conferences** page click **Review** and choose the review form for abstracts (round 1) and for full texts (round 2 and later). Forms are created in the OJS journal settings.
3. The editor sends the abstract to review and assigns reviewers: they get the abstract form.
4. After the reviews the editor clicks **Abstract accepted — send the full text**. The author gets a letter with the full text deadline. The letter can be changed in the journal's email templates.
5. The author uploads the paper as a new file version and the editor creates a new review round with that file. The reviewers of the new round get the full text form. Further decisions are made as usual.

If the editor chose another form when assigning a reviewer, the plugin keeps it.

### Reviewer bids
In the standard and two-phase modes, reviewers of the journal can mark which submissions of the event they would like to review. The plugin proposes a distribution and you confirm it with one click.

1. Click **Reviewer bids** next to the event in **Conferences**.
2. Set **Choice open until (inclusive)** and **Reviewers per submission** (1 to 10, 2 by default), and click **Save**. An empty date turns the choice off.
3. Click **Invite reviewers by email**: every user with the reviewer role in the journal gets an email with a link to the choice page. The link is also shown on the screen, so you can send it another way. Reviewers see it in their cabinet too.
4. A reviewer sees the titles, sections, keywords and abstracts of the submissions, but no author names, and marks each one: **very keen**, **willing**, **rather not** or **conflict of interest**. Reviewers do not see their own submissions.
5. Send submissions to review with the usual OJS decision. The **Proposed distribution** block shows "submission — reviewer" pairs only for submissions already in review:
   - only those who chose "very keen" or "willing" are proposed; "very keen" first, and among them those with fewer open reviews;
   - submissions with the fewest willing reviewers get reviewers first;
   - authors of the submission and reviewers already assigned are never proposed, nor those who chose "rather not" or "conflict of interest".
6. Untick the pairs you do not want and click **Assign the selected**. The plugin assigns reviewers in the current round the same way the OJS form does: with the response and review due dates from the journal settings, all review files of the round and the task in the reviewer's dashboard. The reviewer gets a "Review request" email with a link to the review page, and then accepts or declines as usual.

The **Choices by submission** table shows who chose what and who is assigned already, and the **Reviewers of the journal** table shows how many submissions each reviewer wants and how many open reviews they have. You can still assign a reviewer by hand in OJS. The texts of both emails can be changed in the journal's email templates.

## How to make the book of abstracts

The book of abstracts is one PDF with the abstracts of the accepted papers of the event: submissions that passed review and were not declined. The text of each abstract, its keywords and the authors with their affiliations come from the submission. To change a text, edit the abstract of the submission and download the book again.

1. Click **Book of abstracts** next to the edition on the **Conferences** page.
2. Check **What goes into the book**: how many papers and in which groups. **Papers without an abstract** lists those that have no text yet.
3. Choose the page size (A5 or A4) and the order of papers:
   - **by the program**: groups are the sessions of the schedule, papers in the order they are presented, papers outside the schedule at the end under "Other papers";
   - **by journal sections**;
   - **by title**.
4. Fill in the title page and the imprint: the lines above the title (ministry, institution), the title, the subtitle, the city, the imprint text (bibliographic description, editorial board, approval of the academic council and so on) and, if needed, a preface. ISBN and UDC come from the edition settings.
5. Click **Download PDF**. The settings are saved with the download.

The PDF has a title page, an imprint page, contents with page numbers, the abstracts numbered through the book and an author index with the numbers of the abstracts. The margins are mirrored with a wider inner margin for binding, so the file can go to print.

## How to mark attendance

Click **Participants** next to the edition.

- **By hand:** tick the **Attendance** column, or click **Mark everyone on the page** or **Unmark everyone on the page**.
- **From an online meeting report:** click **Import meeting report**, choose a **Report file** (CSV from Microsoft Teams, Zoom or Google Meet, up to 5 MB), set **Threshold, minutes** (30 by default) and click **Preview**. Check who was found, who counts as present and who is not among the participants, then click **Apply**.

Participants are matched by email. Minutes of several joins are added up. An import never removes attendance marked by hand. Next to each mark you can see how it was set: by hand or from a report, with the minutes.

### Attendance at single sessions of the programme

If the event has a programme, attendance can be marked for each session. In the programme builder, click **Attendance** next to a session. The session page opens:

- **Who was present:** tick the people and click **Save attendance**. Presenters and chairs of the session are at the top. **Search** narrows the list; **Tick the people shown** and **Untick the people shown** act on the visible rows.
- **Import meeting report:** a Teams, Zoom or Google Meet report of this session only. The default threshold is three quarters of the session's length. Click **Preview**, then **Apply**. Marks made by hand are never changed by an import.

A session chair (set in the **Chairs** field of the session) sees their sessions in the participant cabinet under **Sessions I chair** and marks attendance there without access to management. The chair must be a participant of the event with an account in the journal.

Attendance at any session counts as attendance at the event.

At in-person events, attendance is easiest to mark by the participants' QR entry tickets: see the [registration manager guide](registration-manager.md#how-to-check-participants-in-by-the-qr-ticket). A chair who scans a ticket marks their own sessions only.

Signed-in participants can build their own programme with **Add to my programme** on the program page and download their sessions to a calendar.

## How to issue certificates

### Templates and numbers

Click **Certificate templates** at the top of the "Conferences" page.

- Document kinds: certificate of participation, speaker certificate, listener certificate, organizer acknowledgement, reviewer acknowledgement. With the "Ukraine" profile there is also the professional development certificate (item 13 of the Procedure approved by Cabinet of Ministers Resolution No. 800).
- The built-in template is A4 landscape with a QR code. To change it, edit **Template HTML (A4 landscape)**: click a variable to insert it at the cursor. Check the result with **Preview**. **Back to built-in** undoes your changes. Scripts, frames and unknown variables are not accepted.
- **Number series:** 1 to 10 letters or digits, `CERT` by default. Numbers look like `CERT-2027-00001`; the counter restarts every year.
- **Export for the "Vector" platform** ("Ukraine" profile only) turns the export buttons on. It is off by default.

The signature on a document comes from the institution profile signer assigned to that kind of document.

### Issuing

Click **Certificates** next to the edition. For each participant you can see which document they are entitled to, or why none.

- A listener gets a certificate when attendance is marked (or, in the "Ukraine" profile, a professional development certificate when the event has hours).
- If the programme has "Seminar" sessions with hours, the professional development certificate goes to those present at one or more of them. Its workload is the sum of the hours of the seminars attended (the hours of the event are then not used); ECTS credits come from each seminar or are hours / 30. The certificate has a **Programme components** line listing the seminars; learning outcomes come from the event or, when it has none, from the seminars. A listener who attended only sections gets a listener certificate.
- A speaker gets a certificate for an accepted paper and attendance.
- A co-author gets a certificate of participation.
- An absentee author gets a certificate after the paper is published.
- Reviewers and organizers get an acknowledgement. Click **Add the reviewers of the event** to add everyone who completed a review of the event's papers. This version has no separate button to add organizers to the register.

The **Show** filter has "all", "eligible", "issued" and "not eligible". Tick people or click **Select everyone eligible**, then **Issue to the selected**. **Issue to everyone eligible** issues to all at once: up to 25 documents immediately, more through the OJS queue in portions. Each person gets a document of each kind only once.

A document is fixed when it is issued: later changes to the template or data do not change it. A document issued in Ukrainian also has an English version.

### Revoking and reissuing

Below the table is the list **Issued documents** with the state "Valid", "Revoked" or "Replaced by …".

- **Revoke:** enter the reason. It is shown on the verification page.
- **Reissue:** the old document is revoked and a new one is issued with a new number from the current data. Use this to fix a mistake in a name. The old document's verification page shows the new number. If the person is no longer entitled to the document, reissue does nothing.

### Public verification and register

- The QR code on a document leads to the verification page `/{journal}/conference/verify/{code}`.
- With the "Ukraine" profile, `/{journal}/conference/documents` is the public register of issued professional development certificates. Revoked documents and email addresses are not shown there.

### Export for "Vector"

**Export for "Vector" (new)** and **Export for "Vector" (all)** download a CSV with number, date, full name, email, programme ID, topic, kind, form, dates, hours, credits and verification link. Exported rows are marked, so "new" next time gives only new rows. The platform does not publish an official import template, so the columns follow the fields of item 13 of the Procedure.

## How to handle personal data

Click **Personal data** at the top of the "Conferences" page.

### Retention periods

Under **Retention periods**, enter months (0 to 120):

- **Receipt files, months after upload**;
- **Participant data, months after the event ended**.

0 means keep. Both are off by default: your institution sets the period by its accounting and archive rules. After the period, a daily OJS task removes receipt files and anonymises participants of ended events. Invoice records and issued documents stay.

### A person's request

Under **A person's request**, enter the **Email address of the person** and:

- click **Download the data (JSON)** to get everything the plugin stores about that person in the journal;
- click **Anonymise** to remove their data. The name becomes "Anonymized"; email, affiliation, position, country, ORCID and the link to the account are removed. Invoices and issued documents keep their copies of the data. **This cannot be undone.**

Participants can also download their own data in the cabinet.

## Audit log and consent versions

Click **Audit log** at the top of the "Conferences" page (journal managers only).

- **Actions:** who changed events, fees, invoices, participants, mailings and settings, and when, with **Before** and **After** details.
- **Consent versions:** every version of the personal data consent text that participants saw, with the number of agreements.

## Event summary

Click **Summary** next to the edition. It shows submissions by state (waiting for a decision, in review, accepted, declined, published, full text uploaded), accepted papers by presentation type, participants (active, cancelled), payments by currency (paid, unpaid, partly paid, waived, replaced or cancelled), receipts waiting for review and reminders sent. Click **Refresh** for fresh numbers.

## Export for accounting

In the **Invoices** list, open **Export for accounting**.

1. Under **What to export**, choose "This event only" or "All events of the journal".
2. Set the period **From** and **To** and the **Date**: of issue or of full payment.
3. If needed, tick **Include cancelled and superseded invoices**.
4. Set the **Columns**: **Add a column**, **Up**, **Down**, **Remove**. Click **Save columns** so the journal remembers your choice.
5. Click **Download CSV** or **Download XLSX**.

The CSV is made for Excel: UTF-8, ";" as separator, decimal comma. **XLSX needs the PHP `zip` extension on the server**; without it only CSV is available.

### Reports

The **Reports** card at the bottom of **Summary** groups the edition by one dimension:
- **Journal sections** and **Presentation types**: submitted, accepted, declined;
- **Participant roles**, **Participant categories**, **Institutions**, **Countries**, **Forms of participation**: registered, attended, cancelled.

Rows are sorted largest first. Institution names that differ only in spaces or case are counted together. The last row is **Total**. **Download CSV** gives the table for Excel (UTF-8, ";" separator).

### Is the scheduler running

Above the cards you see when the OJS scheduler last ran the plugin's tasks. If it has not run for more than two hours or never, a warning appears: reminders, submission windows, mailings and batch documents will not work. Send it to the server administrator; see the [site administrator guide](site-admin.md), "How to set up cron and the OJS queue".

### Letter of invitation

A participant with an active participation can download a letter of invitation as a PDF from the cabinet, in Ukrainian or English. Presenters and co-authors get it only once the submission is accepted (copyediting or later) and not declined. The letter has the institution's letterhead from the profile, an outgoing number `{edition path}/{participation number}`, the event's name, dates and venue, the role, the accepted paper and the form of participation. It is signed by the signer with the document type **Letter of invitation**, or the first signer of the profile. A manager downloads the letter with **Invitation letter** in the register of participants.

## Google Scholar

If the Google Scholar plugin is enabled in the journal, the article page of an event paper shows the event name (`citation_conference_title`) instead of the journal name, plus the proceedings ISBN and the publisher (the institution's name).
