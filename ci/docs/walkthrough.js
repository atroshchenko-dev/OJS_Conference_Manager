/**
 * Screenshots for the step-by-step guide (docs/uk/walkthrough.md).
 *
 * Walks the whole cycle of one event through the real interface of a fresh
 * journal: institution profile, first setup wizard, fees, payment rules,
 * promo code, a submission, a listener with a receipt, reviewer bids, a
 * review, decisions, payments, the program, attendance, certificates, the
 * abstract book and the reports. Every screen of the guide is saved under
 * the name the guide uses. Run it through ci/docs/walkthrough.sh.
 *
 * node ci/docs/walkthrough.js <base-url> <journal-path> <out-dir> <work-dir>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const B = process.argv[2] || 'http://localhost:8080';
const P = process.argv[3] || 'guide';
const OUT = process.argv[4] || 'docs/uk/img/walkthrough';
const WORK = process.argv[5] || fs.mkdtempSync('/tmp/cm-guide-');
const J = `${B}/${P}/uk`;
const ADMIN = ['admin', process.env.ADMIN_PASSWORD || 'admin12345'];
const PASS = 'guide-Test-2027';
const W = 1366;
const H = 900;
fs.mkdirSync(OUT, { recursive: true });

const day = 86400000;
const now = Date.now();
const local = (ms, time = '00:00') => new Date(ms).toISOString().slice(0, 10) + 'T' + time;
const D = {
  start: local(now + 160 * day, '09:00'),
  end: local(now + 161 * day, '17:00'),
  cfpOpen: local(now - 15 * day),
  abstracts: local(now + 90 * day),
  papers: local(now + 120 * day),
  registration: local(now + 150 * day),
  earlyBird: local(now + 60 * day),
  bids: local(now + 75 * day).slice(0, 10),
  promoUntil: local(now + 150 * day).slice(0, 10),
  session: local(now + 160 * day, '10:00'),
  sessionEnd: local(now + 160 * day, '11:30'),
};
const EDITION = 'Наука й освіта: виклики сьогодення';
const EPATH = `science-${new Date(now + 160 * day).getFullYear()}`;
const USERS = {
  author: { given: 'Олена', family: 'Коваль', aff: 'Університет «Приклад», кафедра педагогіки' },
  listener: { given: 'Петро', family: 'Савчук', aff: 'Ліцей № 5, м. Житомир' },
  reviewer: { given: 'Марія', family: 'Бондар', aff: 'Інститут педагогіки', reviewer: true },
};
for (const [k, u] of Object.entries(USERS)) {
  u.username = `${P}-${k}`;
  u.email = `${P}-${k}@example.org`;
}

async function pause(page, ms = 1200) {
  await page.waitForLoadState('load').catch(() => {});
  await page.waitForTimeout(ms);
}

/** Saves the viewport, or the whole page with fullPage (layout of OJS keeps the side menu fixed, so the viewport grows instead). */
async function shot(page, name, { full = false } = {}) {
  if (full) {
    const h = await page.evaluate(() => Math.max(document.documentElement.scrollHeight, document.body.scrollHeight));
    await page.setViewportSize({ width: W, height: Math.min(h, 4000) });
    await page.waitForTimeout(400);
  }
  await page.mouse.move(1, 1);
  await page.screenshot({ path: path.join(OUT, name + '.png') });
  if (full) await page.setViewportSize({ width: W, height: H });
  console.log('shot', name);
}

async function scrollTo(page, locator, offset = 120) {
  await locator.first().evaluate((e, off) => {
    e.scrollIntoView({ block: 'start' });
    let p = e.parentElement;
    while (p && p !== document.body && p.scrollHeight <= p.clientHeight + 5) p = p.parentElement;
    if (p && p !== document.body) p.scrollTop -= off; else window.scrollBy(0, -off);
  }, offset);
  await page.waitForTimeout(300);
}

async function newUserPage(browser, state) {
  const ctx = await browser.newContext({ viewport: { width: W, height: H }, storageState: state, acceptDownloads: true });
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.accept());
  return page;
}

async function login(page, user, pass) {
  await page.goto(`${J}/login`);
  await page.fill('#username', user);
  await page.fill('#password', pass);
  await page.click('form button[type=submit]');
  await pause(page, 800);
}

async function saveDownload(page, trigger, file) {
  const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), trigger()]);
  await dl.saveAs(path.join(WORK, file));
}

/** Saves a document the signed-in user can open (a link in the page). */
async function fetchTo(page, url, file) {
  const r = await page.context().request.get(new URL(url, page.url()).href);
  if (r.ok()) fs.writeFileSync(path.join(WORK, file), await r.body());
  else console.log('not saved', file, r.status());
}

/** The manager's screens: opens an edition section from the panel of edition sections. */
async function section(page, name) {
  await page.getByRole('button', { name, exact: true }).first().click();
  await pause(page, 1800);
}

async function openEdition(page) {
  await page.goto(`${J}/manageConference`);
  await pause(page, 1500);
  await page.locator('tr', { hasText: EPATH }).getByText('Відкрити').click();
  await pause(page, 1500);
}

(async () => {
  const browser = await chromium.launch(process.env.CHROME ? { executablePath: process.env.CHROME } : {});
  const admin = await browser.newContext({ viewport: { width: W, height: H }, acceptDownloads: true });
  const m = await admin.newPage();
  // Prompts of the manager's screens (amount paid, reason of a waiver).
  m.on('dialog', (d) => d.accept(/Причина/.test(d.message()) ? 'Друга доповідь того самого автора, рішення оргкомітету' : undefined));
  await login(m, ...ADMIN);

  // ---- 1. Institution profile ----
  await m.goto(`${J}/management/settings/website`);
  await pause(m);
  await m.getByRole('tab', { name: /Плагіни/ }).click();
  await pause(m, 1500);
  const row = m.locator('tr', { hasText: 'Менеджер конференцій' }).first();
  await row.locator('a,button').first().click();
  await m.getByText('Профіль закладу-організатора', { exact: true }).first().click();
  await pause(m, 2500);
  const f = m.locator('form:has(select[name=jurisdiction])');
  await f.locator('select[name=jurisdiction]').selectOption('UA');
  await f.locator('select[name=institutionType]').selectOption('university');
  await f.locator('select[name=ownership]').selectOption('state');
  await f.locator('input[name="name[uk]"]').fill('Державний університет «Приклад»');
  await f.locator('input[name="shortName[uk]"]').fill('ДУ «Приклад»');
  await f.locator('input[name=legalId]').fill('40123454');
  await f.locator('textarea[name="address[uk]"]').fill('вул. Наукова, 1, м. Київ, 01001');
  await f.locator('input[name=contactEmail]').fill('conf@example.org');
  await f.locator('input[name=website]').fill('https://example.org');
  await f.locator('input[name="privacyContact[uk]"]').fill('Відповідальна особа: dpo@example.org');
  await f.locator('textarea[name="retentionNotice[uk]"]').fill('Дані учасників зберігаються 3 роки після завершення заходу.');
  await f.locator('input[name="signers[0][fullName][uk]"]').fill('Іваненко Іван Іванович');
  await f.locator('input[name="signers[0][position][uk]"]').fill('Ректор');
  await f.locator('input[name="signers[0][fullName][en]"]').fill('Ivan Ivanenko');
  await f.locator('input[name="signers[0][position][en]"]').fill('Rector');
  const types = f.locator('input[name="signers[0][documentTypes][]"]');
  for (let i = 0; i < (await types.count()); i++) await types.nth(i).check();
  await f.locator('input[name="paymentAccounts[0][label]"]').fill('Основний рахунок університету');
  await f.locator('input[name="paymentAccounts[0][recipientName]"]').fill('Державний університет «Приклад»');
  await f.locator('input[name="paymentAccounts[0][iban]"]').fill('UA223052990000026001234567890');
  await f.locator('input[name="paymentAccounts[0][bankName]"]').fill('АТ «Банк для прикладу»');
  await f.locator('input[name="paymentAccounts[0][legalId]"]').fill('40123454');
  await f.locator('input[name="paymentAccounts[0][purposeTemplate]"]').fill('Оргвнесок за участь у {editionTitle}, рахунок {invoiceNumber}, {payerName}');
  await f.locator('input[name="paymentAccounts[0][isActive]"]').check();
  await f.locator('input[name=allowRegularSubmissions]').check();
  await scrollTo(m, f.locator('select[name=jurisdiction]'), 230);
  await shot(m, 'm01-profile-institution');
  await f.locator('input[name="signers[0][fullName][uk]"]').evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await shot(m, 'm02-profile-signers');
  await f.locator('input[name="paymentAccounts[0][iban]"]').evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await shot(m, 'm03-profile-accounts');
  await f.getByRole('button', { name: 'OK' }).click();
  await pause(m, 2500);

  // ---- 2. First setup wizard ----
  await m.goto(`${J}/manageConference`);
  await pause(m, 2000);
  await shot(m, 'm04-wizard-step1');
  await m.getByRole('button', { name: 'Далі' }).click();
  await pause(m, 1500);
  await shot(m, 'm05-wizard-presets', { full: true });
  await m.locator('*:has(> button)', { hasText: 'конференція з тезами й статтями' }).last().getByRole('button', { name: 'Обрати' }).click();
  await pause(m, 1500);
  const t = m.locator('input[type=text]:visible');
  await t.nth(0).fill(`${EDITION} ${EPATH.slice(-4)}`);
  await t.nth(1).fill(`Science and Education: Today's Challenges ${EPATH.slice(-4)}`);
  await t.nth(2).fill(EPATH);
  const dates = m.locator('input[type=datetime-local]:visible');
  const dv = [D.start, D.end, D.cfpOpen, D.abstracts, D.papers, D.registration];
  for (let i = 0; i < dv.length; i++) await dates.nth(i).fill(dv[i]);
  await shot(m, 'm06-wizard-edition', { full: true });
  const site = m.getByText(/сайт конференцій/).first();
  if (await site.count()) {
    await scrollTo(m, site, 200);
    await shot(m, 'm06b-wizard-public-part');
  }
  await m.getByRole('button', { name: 'Створити видання' }).click();
  await pause(m, 3000);
  await shot(m, 'm07-wizard-done');
  await m.getByText('До списку видань').click();
  await pause(m, 1500);
  await shot(m, 'm00-conferences-list');
  await shot(m, 'm08-editions-list');

  // ---- 3. Edition form, open the call ----
  await m.locator('tr', { hasText: EPATH }).getByText('Відкрити').click();
  await pause(m, 1500);
  await section(m, 'Редагувати');
  const L = (label) => m.getByLabel(label, { exact: false }).first();
  await L('Місце проведення').fill('Київ, вул. Наукова, 1, головний корпус; онлайн у Zoom');
  await L('Опис').fill('Щорічна міжнародна науково-практична конференція для науковців, викладачів і здобувачів освіти. Секції: педагогіка, економіка, інформаційні технології.');
  await L('Інформаційний лист').fill('Тези до 2 сторінок, шрифт Times New Roman 14. Оргвнесок сплачується після рішення оргкомітету про прийняття доповіді. Працівники закладу беруть участь безкоштовно.');
  await L('Рецензування').selectOption('standard');
  await L('Статус').selectOption('cfp_open');
  await L('ISBN збірника').fill('9780306406157');
  await L('УДК').fill('378(063)');
  await L('Обсяг, годин').fill('12');
  await shot(m, 'm10-edition-form', { full: true });
  await m.getByRole('button', { name: 'Зберегти' }).click();
  await pause(m, 2500);

  // ---- 4. Fees ----
  await openEdition(m);
  await section(m, 'Оргвнески');
  await shot(m, 'm11-fees', { full: true });
  await m.getByRole('button', { name: 'Додати тариф' }).click();
  await pause(m, 1000);
  let sel = m.locator('select:visible');
  const cat = await sel.nth(1).evaluate((e) => [...e.options].find((o) => /інших закладів/.test(o.text)).value);
  await sel.nth(1).selectOption(cat);
  await sel.nth(2).selectOption('abstract');
  const sum = m.locator('input[type=text]:visible').first();
  await sum.fill('200');
  await m.locator('input[type=datetime-local]:visible').nth(1).fill(D.earlyBird);
  await sum.evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await shot(m, 'm11b-tariff-form');
  await m.getByRole('button', { name: 'Зберегти' }).click();
  await pause(m, 2000);
  sel = m.locator('select:visible');
  const n = await sel.count();
  await sel.nth(n - 3).selectOption(cat);
  await sel.nth(n - 2).selectOption('abstract');
  await m.getByRole('button', { name: 'Розрахувати' }).click();
  await pause(m, 1500);
  await m.getByRole('button', { name: 'Розрахувати' }).evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await shot(m, 'm11c-tariff-check');

  // ---- 5. Payment rules ----
  await section(m, 'Оплата');
  await shot(m, 'm12-payment-rules', { full: true });
  await m.getByRole('button', { name: 'Додати правило' }).click();
  await pause(m, 1000);
  sel = m.locator('select:visible');
  await sel.nth(0).selectOption({ label: 'Здобувачі освіти' });
  await sel.nth(1).selectOption('bank_transfer');
  await pause(m, 500);
  sel = m.locator('select:visible');
  await sel.nth(2).selectOption({ index: 1 });
  await m.locator('textarea:visible').first().fill('Сплатіть за реквізитами рахунку. Студентський квиток надішліть разом із квитанцією.');
  await scrollTo(m, sel.nth(0), 150);
  await shot(m, 'm12b-payment-rule-form');
  await m.getByRole('button', { name: 'Скасувати' }).first().click();

  // ---- 6. Seats and promo codes ----
  await section(m, 'Місця й промокоди');
  await m.getByRole('button', { name: 'Додати промокод' }).click();
  await pause(m, 800);
  await m.getByLabel('Код', { exact: false }).first().fill('PARTNER20');
  await m.getByLabel('Відсоток знижки').fill('20');
  await m.getByLabel('Скільки разів можна використати').fill('30');
  await m.getByLabel('Діє до (включно)').fill(D.promoUntil);
  await m.getByLabel('Примітка для менеджерів').fill('Для кафедр-партнерів');
  await shot(m, 'm13-seats-promo', { full: true });
  await m.getByRole('button', { name: 'Зберегти' }).nth(1).click();
  await pause(m, 1500);

  // ---- 17. Public part ----
  await m.goto(`${J}/manageConference`);
  await pause(m, 1500);
  const pub = m.getByText('Публічна частина', { exact: true }).first();
  if (await pub.count()) {
    await pub.click();
    await pause(m, 2000);
    await shot(m, 'm34-public-part', { full: true });
    const add = m.getByRole('button', { name: /Додати пункт/ });
    if (await add.count()) {
      await add.first().click();
      await pause(m, 1500);
    }
  }

  // ---- Participants register their accounts ----
  const anon = await browser.newContext({ viewport: { width: W, height: H } });
  const a = await anon.newPage();
  for (const [k, u] of Object.entries(USERS)) {
    await anon.clearCookies();
    await a.goto(`${J}/user/register`);
    await a.fill('#givenName', u.given);
    await a.fill('#familyName', u.family);
    await a.fill('#affiliation', u.aff);
    await a.selectOption('#country', 'UA');
    await a.fill('#email', u.email);
    await a.fill('#username', u.username);
    await a.fill('#password', PASS);
    await a.fill('#password2', PASS);
    await a.locator('input[name=privacyConsent]').check();
    if (u.reviewer) await a.locator('input[name^="reviewerGroup"]').first().check();
    if (k === 'author') await shot(a, 'p01-register-account', { full: true });
    await a.locator('form button[type=submit]').first().click();
    await pause(a, 1500);
    await anon.clearCookies();
    await login(a, u.username, PASS);
    u.state = path.join(WORK, `${k}.json`);
    await anon.storageState({ path: u.state });
  }
  await anon.clearCookies();
  await a.goto(`${J}/index`);
  await pause(a);
  await shot(a, 'p00-site-home', { full: true });
  await a.goto(`${J}/conference`);
  await pause(a);
  await shot(a, 'p00b-site-events', { full: true });
  await a.goto(`${J}/conference/${EPATH}`);
  await pause(a);
  await shot(a, 'p02-edition-public', { full: true });

  // ---- Author submits ----
  const sample = path.join(WORK, 'thesis.pdf');
  fs.writeFileSync(sample, '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
  const au = await newUserPage(browser, USERS.author.state);
  const submissions = [];
  for (const [title, abstract, type] of [
    ['Цифрові інструменти в підготовці майбутніх учителів', 'У тезах розглянуто, як цифрові інструменти (LMS, хмарні сервіси, симулятори уроку) допомагають готувати майбутніх учителів. Наведено результати опитування 120 студентів педагогічних спеціальностей.', 'oral'],
    ['Хмарні сервіси для організації проєктної роботи школярів', 'Описано досвід використання хмарних сервісів для спільної проєктної роботи учнів 8–9 класів.', 'poster'],
  ]) {
    const first = submissions.length === 0;
    await au.goto(`${J}/submission`);
    await pause(au, 2500);
    await au.getByLabel('українська').check();
    await au.frameLocator('iframe').first().locator('body').click();
    await au.keyboard.type(title);
    await au.locator('input[name=submissionRequirements]').check();
    await au.locator('input[name=privacyConsent]').check();
    if (first) await shot(au, 'p03-submission-start', { full: true });
    await au.getByRole('button', { name: 'Почати подачу' }).click();
    await pause(au, 3500);
    submissions.push(Number(new URL(au.url()).searchParams.get('id')));
    await au.frameLocator('iframe[id^="titleAbstract-abstract"]').locator('body').click();
    await au.keyboard.type(abstract);
    for (const kw of ['цифрові інструменти', 'підготовка вчителів']) {
      await au.locator('input[id^="titleAbstract-keywords"]').fill(kw);
      await au.keyboard.press('Enter');
    }
    await au.waitForTimeout(1500);
    await au.getByRole('button', { name: 'Продовжити' }).click();
    await pause(au, 2500);
    await au.locator('input[type=file]').first().setInputFiles(sample);
    await au.waitForTimeout(4000);
    await au.getByRole('button', { name: 'Текст статті' }).first().click();
    await au.waitForTimeout(1500);
    if (first) await shot(au, 'p04-submission-files');
    await au.getByRole('button', { name: 'Продовжити' }).click();
    await pause(au, 2500);
    if (first) await shot(au, 'p05-submission-authors');
    await au.getByRole('button', { name: 'Продовжити' }).click();
    await pause(au, 2500);
    await au.getByRole('button', { name: 'Продовжити' }).click();
    await pause(au, 2500);
    const ed = au.locator('select[name=conferenceEditionId]');
    await ed.selectOption({ index: 1 });
    await au.waitForTimeout(1200);
    const catSel = au.locator('select[name=conferenceFeeCategory]');
    await catSel.selectOption(await catSel.evaluate((e) => [...e.options].find((o) => /інших закладів/.test(o.text)).value));
    await au.locator(`input[name=conferencePresentationType][value=${type}]`).check();
    await au.locator('input[name=conferenceParticipationForm][value=in_person]').check();
    await au.locator('input[name=conferenceConsent]').check();
    await au.waitForTimeout(1500);
    if (first) await shot(au, 'p06-submission-conference-step', { full: true });
    await au.getByRole('button', { name: 'Продовжити' }).click();
    await pause(au, 3000);
    if (first) await shot(au, 'p07-submission-review', { full: true });
    await au.getByRole('button', { name: 'Подати', exact: true }).click();
    await au.waitForTimeout(1500);
    await au.locator('[role=dialog]').getByRole('button').filter({ hasText: /Подати/ }).first().click();
    await pause(au, 4000);
    if (first) await shot(au, 'p08-submission-done');
  }

  // ---- Listener registers with a promo code, downloads the invoice, sends a receipt ----
  const li = await newUserPage(browser, USERS.listener.state);
  await li.goto(`${J}/conference/${EPATH}/register`);
  await pause(li, 1500);
  await li.selectOption('#cmCategory', await li.locator('#cmCategory').evaluate((e) => [...e.options].find((o) => /інших закладів/.test(o.text)).value));
  await li.locator('input[name=participationForm][value=in_person]').check();
  await li.fill('#cmPosition', 'Учитель інформатики');
  await li.locator('input[name=consent]').check();
  await li.fill('#cmPromoCode', 'partner20');
  await shot(li, 'p09-listener-register', { full: true });
  await li.locator('form button[type=submit]').first().click();
  await pause(li, 2500);
  await shot(li, 'p10-cabinet-listener', { full: true });
  await fetchTo(li, await li.getByText('Завантажити рахунок (PDF)', { exact: true }).first().getAttribute('href'), 'invoice.pdf');
  await li.goto(`${J}/conference/my`);
  await pause(li);
  await li.getByText('Вхідний квиток (QR-код)').click();
  await scrollTo(li, li.getByText('Вхідний квиток (QR-код)'), 20);
  await shot(li, 'p11-ticket-qr');
  await li.getByText('Реквізити платника').click().catch(() => {});
  const receipt = path.join(WORK, 'platizhka.pdf');
  fs.writeFileSync(receipt, fs.readFileSync(sample).toString() + '% receipt\n');
  await li.locator('input[type=file]').first().setInputFiles(receipt);
  await li.locator('input[type=file]').first().evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await shot(li, 'p12-payer-and-receipt');
  await li.getByRole('button', { name: 'Надіслати', exact: true }).click();
  await pause(li, 2000);

  // ---- Reviewer bids ----
  await openEdition(m);
  await section(m, 'Вибір рецензентами');
  await m.getByLabel('Вибір відкрито до (включно)').fill(D.bids);
  await m.getByLabel('Рецензентів на одне подання').fill('1');
  await m.getByRole('button', { name: 'Зберегти' }).first().click();
  await pause(m, 2000);
  await shot(m, 'm14-review-bidding', { full: true });
  const rv = await newUserPage(browser, USERS.reviewer.state);
  await rv.goto(`${J}/conference/${EPATH}/bids`);
  await pause(rv, 1500);
  await rv.getByText('Анотація').first().click().catch(() => {});
  for (const id of submissions) await rv.locator(`input[name="bid[${id}]"][value=eager]`).check();
  await shot(rv, 'r01-review-bids', { full: true });
  await rv.getByRole('button', { name: 'Зберегти вибір' }).click();
  await pause(rv, 1500);
  await rv.goto(`${J}/conference/my`);
  await pause(rv);
  await shot(rv, 'r02-reviewer-cabinet');

  // ---- Send the first submission to review, assign from the bids ----
  const S1 = submissions[0];
  await m.goto(`${J}/dashboard/editorial?workflowSubmissionId=${S1}`);
  await pause(m, 4500);
  await shot(m, 'm15-submission-card');
  await m.locator('[role=dialog]').getByRole('button', { name: 'Надіслати на рецензування' }).click();
  await pause(m, 4000);
  await m.getByRole('button', { name: 'Пропустити цей електронний лист' }).click();
  await m.waitForTimeout(1500);
  const boxes = m.locator('input[type=checkbox]:visible');
  for (let i = 0; i < (await boxes.count()); i++) await boxes.nth(i).check().catch(() => {});
  await m.getByRole('button', { name: 'Записати рішення' }).click();
  await pause(m, 3000);
  await openEdition(m);
  await section(m, 'Вибір рецензентами');
  await scrollTo(m, m.getByText('Запропонований розподіл'), 100);
  await shot(m, 'm16-bids-proposal');
  await m.getByRole('button', { name: /Призначити вибраних/ }).click();
  await pause(m, 3000);

  // ---- Reviewer reviews ----
  await rv.goto(`${J}/reviewer/submission/${S1}`);
  await pause(rv, 3000);
  await shot(rv, 'r03-review-request');
  await rv.locator('#dataCollectionCheck').check();
  await rv.getByRole('button', { name: /Погодитися на рецензування/ }).click();
  await pause(rv, 3500);
  await rv.getByRole('button', { name: /Перейти до кроку №3/ }).click();
  await pause(rv, 3500);
  await rv.frameLocator('iframe[id^=comments-]').locator('body').click();
  await rv.keyboard.type('Тема актуальна, результати опитування подано чітко. Варто додати порівняння з даними попередніх років.');
  await rv.selectOption('#recommendation', '1');
  await rv.locator('#recommendation').evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await shot(rv, 'r04-review-form');
  await rv.getByRole('button', { name: 'Надіслати рецензію' }).click();
  await rv.waitForTimeout(1500);
  await rv.locator('[role=dialog], .pkp_modal_panel').last().getByRole('button', { name: 'OK' }).click();
  await pause(rv, 3000);

  // ---- Decision from the card, then the mass decision ----
  await m.goto(`${J}/dashboard/editorial?workflowSubmissionId=${S1}`);
  await pause(m, 4500);
  await shot(m, 'm17-review-stage');
  await m.locator('[role=dialog]').getByRole('button', { name: 'Направити на редагування' }).click();
  await pause(m, 4000);
  await shot(m, 'm18-decision-accept');
  for (let i = 0; i < 3; i++) {
    const skip = m.getByRole('button', { name: 'Пропустити цей електронний лист' });
    if ((await skip.count()) && (await skip.first().isVisible())) {
      await skip.first().click();
      await m.waitForTimeout(1000);
    }
    const next = m.getByRole('button', { name: 'Продовжити' });
    if ((await next.count()) && (await next.first().isVisible())) {
      await next.first().click();
      await m.waitForTimeout(1500);
    }
  }
  await m.getByRole('button', { name: 'Записати рішення' }).click();
  await pause(m, 4000);
  await openEdition(m);
  await section(m, 'Рішення');
  await m.locator('tbody input[type=checkbox]:visible').first().check();
  sel = m.locator('select:visible', { has: m.locator('option', { hasText: 'Відхилити' }) });
  await sel.selectOption({ label: 'Прийняти: стендова доповідь' });
  await shot(m, 'm19-mass-decisions');
  await m.getByRole('button', { name: 'Ухвалити рішення' }).click();
  await pause(m, 9000);
  await shot(m, 'm19b-mass-decisions-run');

  // ---- Receipts and invoices ----
  await section(m, 'Квитанції');
  await shot(m, 'm20-receipts', { full: true });
  await m.getByRole('button', { name: 'Прийняти', exact: true }).first().click();
  await pause(m, 2500);
  await section(m, 'Рахунки');
  const invoiceRows = m.locator('tbody tr', { hasText: USERS.author.email });
  await invoiceRows.nth(1).getByRole('button', { name: 'Оплачено' }).click();
  await pause(m, 2500);
  await m.locator('tbody tr', { hasText: USERS.author.email }).filter({ hasText: 'виставлено' }).first().getByRole('button', { name: 'Звільнити' }).click();
  await pause(m, 2500);
  await shot(m, 'm21-invoices', { full: true });

  // ---- Mailing and reminders ----
  await section(m, 'Розсилки');
  await m.getByRole('button', { name: 'Переглянути одержувачів' }).click().catch(() => {});
  await pause(m, 1500);
  await m.getByLabel('Тема').first().fill('Програма конференції опублікована').catch(() => {});
  await m.getByLabel('Текст листа').first().fill('Шановні учасники! Програму конференції опубліковано на сторінці заходу.').catch(() => {});
  await shot(m, 'm28-mailing', { full: true });
  await m.goto(`${J}/manageConference`);
  await pause(m, 1500);
  await m.getByText('Нагадування', { exact: true }).first().click();
  await pause(m, 2000);
  await shot(m, 'm31-reminders', { full: true });

  // ---- Program ----
  await openEdition(m);
  await section(m, 'Програма');
  if (!(await m.getByRole('button', { name: 'Додати зал' }).isVisible())) {
    await m.getByText(/^Зали \(\d+\)$/).click();
    await m.waitForTimeout(600);
  }
  await m.getByRole('button', { name: 'Додати зал' }).click();
  await m.waitForTimeout(1000);
  await m.getByLabel('Назва залу').fill('Актова зала (ауд. 101)');
  await m.getByLabel('Місткість').fill('120');
  await m.getByLabel('Посилання для онлайн-участі').fill('https://zoom.example.org/j/123456789');
  await shot(m, 'm22-program-room');
  await m.getByRole('button', { name: 'Зберегти' }).click();
  await pause(m, 1500);
  await m.getByRole('button', { name: 'Додати сесію' }).first().click();
  await m.waitForTimeout(1200);
  await m.getByLabel('Назва (uk)').first().fill('Секція 1. Цифрова педагогіка');
  await m.getByLabel('Зал').selectOption({ index: 1 });
  await m.getByLabel('Початок').first().fill(D.session);
  await m.getByLabel('Завершення').first().fill(D.sessionEnd);
  await m.locator('input[type=search]:visible').first().fill('Савч');
  await m.waitForTimeout(1500);
  await m.getByText(`${USERS.listener.given} ${USERS.listener.family}`, { exact: true }).last().click();
  await m.waitForTimeout(800);
  await scrollTo(m, m.getByLabel('Тип сесії'), 120);
  await shot(m, 'm23-program-session-form');
  await m.getByRole('button', { name: 'Зберегти' }).first().click();
  await pause(m, 2000);
  for (let i = 0; i < 2; i++) {
    const move = m.locator('select:visible', { has: m.locator('option', { hasText: 'Секція 1' }) }).first();
    if (!(await move.count())) break;
    await move.selectOption({ label: await move.evaluate((e) => [...e.options].find((o) => /Секція 1/.test(o.text)).text) });
    await m.waitForTimeout(1800);
  }
  await shot(m, 'm24-program-builder', { full: true });
  await section(m, 'Редагувати');
  await m.getByLabel('Опублікувати програму на сторінці заходу').check();
  await m.getByLabel('Вступ до програми').first().fill('Реєстрація учасників з 08:30 у фоє головного корпусу. Регламент: доповідь до 15 хвилин, запитання 5 хвилин.');
  await m.getByLabel('Опублікувати програму на сторінці заходу').evaluate((e) => e.scrollIntoView({ block: 'center' }));
  await shot(m, 'm25-program-publish');
  await m.getByRole('button', { name: 'Зберегти' }).click();
  await pause(m, 2500);

  // ---- Attendance ----
  await openEdition(m);
  await section(m, 'Учасники');
  await m.getByRole('button', { name: 'Позначити всіх на сторінці' }).click();
  await pause(m, 2500);
  await shot(m, 'm26-participants', { full: true });
  await m.getByRole('button', { name: 'Імпорт звіту наради' }).click();
  await m.waitForTimeout(1200);
  await shot(m, 'm27-attendance-import');

  // ---- Certificates, abstract book, summary, export ----
  await m.goto(`${J}/manageConference`);
  await pause(m, 1500);
  await m.getByText('Шаблони сертифікатів', { exact: true }).first().click();
  await pause(m, 2000);
  await shot(m, 'm32-cert-templates');
  await openEdition(m);
  await section(m, 'Сертифікати');
  await m.getByRole('button', { name: 'Додати рецензентів заходу' }).click();
  await pause(m, 2500);
  await m.getByRole('button', { name: /Видати всім, кому належить/ }).click();
  await pause(m, 6000);
  await shot(m, 'm29-certificates', { full: true });
  await section(m, 'Збірник тез');
  await m.getByLabel('Над назвою').fill('Міністерство освіти і науки України\nДержавний університет «Приклад»');
  await m.getByLabel('Підзаголовок').fill('Матеріали Міжнародної науково-практичної конференції');
  await m.getByLabel('Місто').fill('Київ');
  await m.getByLabel('Звороток титулу').fill('Рекомендовано до друку вченою радою ДУ «Приклад».\nРедакційна колегія: Іваненко І. І. (голова).');
  await shot(m, 'm30-abstract-book', { full: true });
  await saveDownload(m, () => m.getByRole('button', { name: 'Завантажити PDF' }).click(), 'abstract-book.pdf');
  await section(m, 'Підсумки');
  await shot(m, 'm09-edition-summary', { full: true });
  await section(m, 'Рахунки');
  await m.getByText('Експорт для бухгалтерії').first().click();
  await pause(m, 2000);
  await shot(m, 'm33-accounting-export', { full: true });

  // ---- Participant after the event ----
  await li.goto(`${J}/conference/my`);
  await pause(li, 1500);
  await shot(li, 'p13-cabinet-after', { full: true });
  const href = async (text) => li.getByText(text, { exact: true }).first().getAttribute('href');
  const docs = { 'certificate.pdf': await href('Завантажити PDF'), 'invitation.pdf': await href('Лист-запрошення (PDF)') };
  for (const [file, url] of Object.entries(docs)) await fetchTo(li, url, file);
  const verify = await href('Сторінка перевірки');
  const ticket = await href('Відкрити квиток на весь екран');
  const session = await li.locator('a[href*="sessionAttendance"]').first().getAttribute('href');
  await li.goto(`${J}/conference/${EPATH}/program`);
  await pause(li);
  await shot(li, 'p14-program-public', { full: true });
  await li.goto(ticket);
  await pause(li);
  await shot(li, 'p15-ticket-fullscreen');
  await li.goto(session);
  await pause(li);
  await shot(li, 'p16-moderator-session');
  await anon.clearCookies();
  await a.goto(verify);
  await pause(a);
  await shot(a, 'p17-verify', { full: true });

  await browser.close();
  console.log('done', OUT);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
