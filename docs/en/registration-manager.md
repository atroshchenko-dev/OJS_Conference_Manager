# Registration manager guide

This guide is for members of the **Registration manager** user group: the people who keep track of event participants, review payment receipts, record payments and mark attendance. The journal manager assigns the role in **Users & Roles**.

## What you can use

Open the **Conferences** item in the journal's side menu. Next to each edition you will see the buttons you work with:

- **Participants:** the register of participants, attendance, invoicing by hand;
- **Invoices:** the list of invoices, payments, the payment journal, the export for accounting;
- **Receipts:** the receipt review queue;
- **Summary:** the numbers of the event;
- **Mailings** and **Certificates**.

At the top of the page you can view **Reminders** (the journal manager changes them).

What a registration manager does not do: create or edit editions, change fees, payment rules, invoice or certificate templates, record decisions on submissions, see the audit log or the "Personal data" page, or run the export for "Vector". Ask the journal manager for these.

## How to work with the register of participants

Click **Participants** next to the edition.

### Search and filters

- Type a name, email or affiliation in **Search: name, email, affiliation** and click **Search**. Search ignores upper and lower case and works with Cyrillic.
- Narrow the list with the filters:
  - **Role:** presenter, co-author, listener, reviewer and others;
  - **Category**;
  - **Confirmation:** awaiting confirmation, confirmed, declined;
  - **Status:** active or cancelled participation;
  - **Payment:** "Paid or exempt", "Awaiting payment", "Receipt awaiting review", "No invoice";
  - **Attendance:** present or not marked.
- The register shows 50 entries per page. The number found is shown above the list.
- Minor participants are marked "minor".

### How to change a participant's category

Choose the new category in the participant's row. An invoice already issued does not change by itself: to match the new category, reissue the invoice (see below).

### How to cancel a participation

Click **Cancel participation** in the participant's row and confirm. If the person has no other active participation in the event, their unpaid invoices are cancelled and paid ones get the state "refund requested". Accounting returns the money; record the refund on the **Invoices** page with **Record a refund**.

When a presenter's participation is cancelled, the submission is not withdrawn: withdraw it separately in the OJS editorial workflow.

### How to export the register to CSV

Set the filters and click **Export CSV**. The file contains only the entries that match the filters. The separator is a semicolon, so the file opens in Excel directly. Cells that Excel could run as formulas are escaped.

### How to issue an invoice by hand

Invoices are usually issued automatically: to a listener after registration, to a presenter after acceptance. To issue one by hand, for example when a paper is paid per page, click **Invoice** in the participant's row. For a paper, enter the number of pages; for an abstract, leave it empty.

## How to review receipts

Participants send receipts or payment orders from their cabinet (PDF, JPG or PNG up to 5 MB). Click **Receipts** next to the edition.

1. Open the **Awaiting review** tab. There are also **Accepted**, **Rejected** and all.
2. Click **View**: the file opens in a new tab.
3. Compare the amount in the file with the **Due** column. The plugin does not read the amount from the file; you check it.
4. If everything is right, click **Accept**. The invoice is marked paid for the amount due and the payer gets a letter.
5. If something is wrong, click **Reject** and briefly write the reason. The payer sees it in the letter and in the cabinet and can send the right receipt.

Good to know:

- If the same file was already sent for another invoice, the receipt carries the warning "The same file was already sent for another invoice". Check it more carefully.
- A receipt is reviewed only once. If two people click at the same time, only the first click counts.
- If the receipt shows less than the amount due, do not click "Accept". Record a part payment in the list of invoices (see below).

## How to record a payment, a part payment or a waiver

Click **Invoices** next to the edition. Find the invoice with **Number, reference or email**.

- **Full or part payment.** Click **Mark paid** and enter the amount received. If it is less than the amount due, it is recorded as a part payment and the invoice stays open with the balance.
- **Waiver.** Click **Waive** and enter the reason (the participant sees it). A zero invoice with the reason is created and the old invoice becomes "superseded". A paid invoice cannot be waived.
- **Reissue.** Click **Reissue** when the category, presentation type or number of pages has changed. The new invoice uses the current tariff and the paid part is carried over. If more has been paid than the new amount, the invoice is not reissued and the overpayment is handled by hand.
- **Cancel.** An unpaid invoice can be cancelled. A paid one cannot.
- **History** shows all changes of the invoice, and you can download the PDF at any time.

Until a presenter's invoice is paid or waived, OJS will not publish their paper.

## The payment journal

In the list of invoices, click **Payment journal**. It shows all invoice events of the edition, newest first: issue, payment, part payment, waiver, cancellation, receipts sent, accepted and rejected, payer details changed. Each event shows **Time (UTC)**, **Event**, **Amount** and **Note**.

Journal entries are never changed or deleted. If a mistake was made, correct it with a new action (for example reissue the invoice), and the journal shows both events.

## Export for accounting

In the list of invoices, open **Export for accounting**, choose this event or all events of the journal, the period and the columns, then click **Download CSV** or **Download XLSX**. XLSX is available only if the server has the PHP `zip` extension. See the [manager guide](manager.md#export-for-accounting) for details.

## How to mark attendance

Click **Participants** next to the edition.

- **By hand.** Tick the **Attendance** column. To mark or unmark everyone on the current page of the register, click **Mark everyone on the page** or **Unmark everyone on the page**. It helps to narrow the list with a filter first, for example by the role "listener".
- **From an online meeting report.** Click **Import meeting report**.
  1. Choose the **Report file**: a CSV attendance report from Microsoft Teams, Zoom or Google Meet, up to 5 MB.
  2. Set **Threshold, minutes**: how long a person must stay in the meeting to count as present. 30 by default.
  3. Click **Preview** and check the result: how many rows, who was found, who is present and how many are not among the participants.
  4. Click **Apply**.

Participants are matched by email, so a person should join the meeting with the same address as in the registration. If they joined several times, the minutes are added up. An import never removes a mark set by hand. Next to each mark you can see how it was set: by hand or "from the meeting report", with the minutes.

Attendance decides whether listeners and speakers receive a certificate.

### How to check participants in by the QR ticket

In-person participants have an entry ticket with a QR code in their cabinet. Scan it with the camera of a phone where you are signed in to the journal. The participant's page opens with the name, institution, role and form of participation.

1. If needed, choose the **Session**: attendance is then marked at the event and at this session. During a session or 30 minutes before it, the session is chosen by itself. The chosen session is remembered for the next tickets for a day.
2. Press **Mark as present**. The page answers "Marked." or "This person is already marked."

The mark shows in the register as a manual one. The page flags the ticket of a cancelled participation. A session chair who scans a ticket can mark attendance at their own sessions only. Other users cannot open the ticket page.

Attendance at a single session of the programme is marked on the session page: the **Attendance** button in the programme builder opens it, and a session chair reaches it from their cabinet. See the [manager guide](manager.md#attendance-at-single-sessions-of-the-programme).

## Certificates and mailings

You can issue, revoke and reissue certificates on the **Certificates** page and send mailings to participants on the **Mailings** page. How to do this is described in the [manager guide](manager.md): sections "How to issue certificates" and "How to send a mailing". Certificate templates and the export for "Vector" are set up by the journal manager.
