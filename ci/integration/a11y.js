/**
 * NFR-07: axe-core (WCAG 2.1 A and AA) on the plugin's public pages and
 * the manager's screens of a real OJS, after reports.sh. Fails on any
 * serious or critical violation inside the plugin's part of the page.
 *
 * node ci/integration/a11y.js http://localhost:8080 path/to/axe.min.js
 */
const { chromium } = require('playwright');
const fs = require('fs');

const B = process.argv[2] || 'http://localhost:8080';
const AXE = fs.readFileSync(process.argv[3], 'utf8');
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const EDITION = 'dec-2027';
// Buttons that change data are never pressed.
const UNSAFE = /Видалити|Скасувати|Копіювати|Публічна сторінка/;
// Buttons inside a screen that only open a form.
const FORMS = ['Запросити учасника', 'Додати категорію', 'Додати тариф', 'Додати правило', 'Додати сесію', 'Журнал платежів', 'Експорт для бухгалтерії', 'Версії згоди', 'Додати партнера'];
let failures = 0;

async function audit(page, name, scope) {
  await page.mouse.move(1, 1);
  await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
  await page.waitForTimeout(300);
  await page.addScriptTag({ content: AXE });
  const found = await page.evaluate(async ([scope, tags]) => {
    const ctx = document.querySelector(scope) ? { include: [scope] } : document;
    const res = await axe.run(ctx, { runOnly: { type: 'tag', values: tags } });
    return res.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.slice(0, 3).map((n) => n.target.join(' ') + ' :: ' + n.html.slice(0, 160)) }));
  }, [scope, TAGS]);
  const bad = found.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  console.log((bad.length ? 'FAIL ' : 'ok   ') + name + (found.length ? ' (' + found.map((v) => v.id + ':' + v.impact).join(', ') + ')' : ''));
  for (const v of bad) {
    failures++;
    console.log('     ' + v.id + ': ' + v.help);
    v.nodes.forEach((n) => console.log('       ' + n));
  }
}

async function signIn(context, user, password) {
  const p = await context.newPage();
  await p.goto(B + '/index/uk/login');
  await p.fill('input[name=username]', user);
  await p.fill('input[name=password]', password);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.close();
}

(async () => {
  const browser = await chromium.launch();
  try {
    const guest = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    for (const url of ['/index/uk/conference/upcoming', '/confa/uk/conference/' + EDITION, '/confa/uk/conference/' + EDITION + '/program', '/confa/uk/conference/pay-2027']) {
      await guest.goto(B + url);
      await audit(guest, url, '.pkp_structure_main');
    }
    const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    await phone.goto(B + '/confa/uk/conference/' + EDITION + '/program');
    await audit(phone, 'phone: program', '.pkp_structure_main');

    const readerCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await signIn(readerCtx, 'group1', 'reader12345');
    const reader = await readerCtx.newPage();
    for (const url of ['/confa/uk/conference/my', '/confa/uk/conference/documents', '/confa/uk/conference/pay-2027/register']) {
      await reader.goto(B + url);
      await audit(reader, url, '.pkp_structure_main');
    }

    // ATT-04, PRG-09: the cabinet with the ticket open, the signed-in program, the ticket page.
    const ownerCtx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await signIn(ownerCtx, 'owner1', 'reader12345');
    const owner = await ownerCtx.newPage();
    let ticketUrl = null;
    await owner.goto(B + '/confa/uk/conference/my');
    if (await owner.locator('.cmTicket__box summary').count()) {
      await owner.locator('.cmTicket__box summary').first().click();
      await audit(owner, 'cabinet with the ticket', '.pkp_structure_main');
      ticketUrl = await owner.locator('a[href*="/conference/checkin/"]').first().getAttribute('href');
      await owner.goto(ticketUrl);
      await audit(owner, 'ticket: owner', '.pkp_structure_main');
    } else {
      console.log('SKIP ticket: owner1 has none');
    }
    await owner.goto(B + '/confa/uk/conference/' + EDITION + '/program');
    await audit(owner, 'program: signed in', '.pkp_structure_main');

    const adminCtx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
    await signIn(adminCtx, 'admin', 'admin12345');
    const a = await adminCtx.newPage();
    a.on('dialog', (d) => d.dismiss());
    const home = async () => {
      await a.goto(B + '/confa/uk/manageConference');
      await a.waitForSelector('.cmEditions__table');
      await a.waitForTimeout(300);
    };
    await home();
    await audit(a, 'manager: events', '.app__main');
    const toolbar = (await a.locator('.app__main button:not(table button)').allInnerTexts()).map((s) => s.trim()).filter((s) => s && !UNSAFE.test(s));
    const row = (await a.locator('tr', { hasText: EDITION }).locator('button').allInnerTexts()).map((s) => s.trim()).filter((s) => s && !UNSAFE.test(s));
    for (const [label, inRow] of [...toolbar.map((t) => [t, false]), ...row.map((t) => [t, true])]) {
      await home();
      const scope = inRow ? a.locator('tr', { hasText: EDITION }) : a.locator('.app__main');
      await scope.getByRole('button', { name: label, exact: true }).first().click();
      await a.waitForTimeout(2000);
      await audit(a, 'manager: ' + label, '.app__main');
      for (const f of FORMS) {
        const b = a.locator('.app__main').getByRole('button', { name: f, exact: true }).first();
        if ((await b.count()) && (await b.isVisible())) {
          await b.click();
          await a.waitForTimeout(1000);
          await audit(a, 'manager: ' + label + ' → ' + f, '.app__main');
        }
      }
    }
    await a.goto(B + '/index/uk/conferenceSite');
    await audit(a, 'site overview', '.app__main');

    // ATT-01: the attendance page of a session, as the manager sees it.
    const api = B + '/confa/api/v1/conference/editions';
    const editions = await (await a.request.get(api + '?count=50')).json();
    const ed = editions.items.find((e) => e.path === EDITION);
    const program = ed ? await (await a.request.get(api + '/' + ed.id + '/program-builder')).json() : { sessions: [] };
    const session = (program.sessions || []).find((s) => s.type !== 'break');
    if (session) {
      await a.goto(B + '/confa/uk/conference/sessionAttendance/' + ed.id + '/' + session.id);
      await audit(a, 'session attendance', '.pkp_structure_main');
    } else {
      console.log('SKIP session attendance: no session in ' + EDITION);
    }
    if (ticketUrl) {
      await a.goto(ticketUrl);
      await audit(a, 'ticket: manager', '.pkp_structure_main');
    }
  } finally {
    await browser.close();
  }
  console.log(failures ? 'FAIL: NFR-07 ' + failures + ' serious or critical accessibility violations' : 'OK NFR-07 WCAG 2.1 AA: no serious or critical violations');
  process.exit(failures ? 1 : 0);
})();
