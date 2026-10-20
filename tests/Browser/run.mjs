// Browser-Prüfungen gegen die laufende Umgebung (Aufruf: make test-browser).
// 1. Barrierefreiheit aller Gast- und Verwaltungsseiten mit axe-core (WCAG 2.1 A/AA + Best Practices), Handygröße.
// 2. Upload: Verbindungsabbruch mitten im Stück, Serverfehler mit „Erneut versuchen“, kaputte Datei.
// 3. Figur „Zu viel am Handy?“: erst nach Zeit (Uhr vorgespult) bzw. vielen Beiträgen sichtbar, Hinweis auf Tippen.
// Erwartet: JOIN_PATH (Beitrittslink ohne Host), ADMIN_EMAIL, ADMIN_PASSWORD; Testdateien unter /data.
import { chromium, devices } from 'playwright';
import fs from 'node:fs';

const base = process.env.BASE_URL ?? 'http://web';
const joinPath = process.env.JOIN_PATH;
const slugPath = joinPath.replace(/\/join\?.*$/, '');
const axe = fs.readFileSync(new URL('../node_modules/axe-core/axe.min.js', import.meta.url), 'utf8');
const failures = [];
const browser = await chromium.launch();

async function audit(page, name) {
    await page.addScriptTag({ content: axe });
    const violations = await page.evaluate(async () => {
        // Die Symfony-Entwicklerleiste gibt es nur lokal – nicht mitprüfen.
        const context = { exclude: [['.sf-toolbar'], ['.sf-minitoolbar'], ['[id^="sfwdt"]'], ['[id^="sfToolbar"]']] };
        const result = await window.axe.run(context, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] });
        return result.violations.map((v) => `${v.impact} ${v.id}: ${v.help} – ${v.nodes.slice(0, 2).map((n) => n.target.join(' ')).join(', ')}`);
    });
    violations.forEach((v) => failures.push(`[Barrierefreiheit] ${name}: ${v}`));
    console.log(`${violations.length ? '✗' : '✓'} ${name}${violations.length ? ` (${violations.length})` : ''}`);
}

async function joinAsGuest(name, device = 'iPhone 14') {
    const context = await browser.newContext({ ...devices[device] });
    const page = await context.newPage();
    await page.goto(base + joinPath);
    await page.fill('input[name="display_name[displayName]"]', name);
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
    return { context, page };
}

// --- 1. Barrierefreiheit: Gast ---
{
    const context = await browser.newContext({ ...devices['iPhone 14'] });
    const page = await context.newPage();
    await page.goto(base + joinPath);
    await audit(page, 'Beitritt: Name');
    await context.close();

    const { context: guest, page: p } = await joinAsGuest('Prüfgast');
    for (const [name, path] of [['Start', ''], ['Galerie', '/galerie'], ['Aufgaben', '/aufgaben'], ['Wer von beiden?', '/wer-von-beiden'], ['Gästebuch', '/gaestebuch'], ['Mein Album', '/album'], ['Upload', '/upload'], ['Porträt', '/portraet'], ['Privatsphäre', '/privatsphaere'], ['Willkommen', '/willkommen'], ['Vorher – nachher', '/aufgaben/vorher-nachher']]) {
        const response = await p.goto(base + slugPath + path, { waitUntil: 'networkidle' });
        if (response.url().endsWith(slugPath) && path !== '') {
            console.log(`– ${name} (nicht verfügbar, übersprungen)`);
            continue;
        }
        await audit(p, name);
    }
    await p.goto(base + slugPath + '/aufgaben');
    const challenge = await p.locator('.challenge-item h2 a').first().getAttribute('href').catch(() => null);
    if (challenge) { await p.goto(base + challenge, { waitUntil: 'networkidle' }); await audit(p, 'Aufgabe (Detail)'); }
    await p.goto(base + slugPath + '/galerie', { waitUntil: 'networkidle' });
    const media = await p.locator('.tile').first().getAttribute('href').catch(() => null);
    if (media) {
        await p.goto(base + media, { waitUntil: 'networkidle' }); await audit(p, 'Foto (Detail)');
        await p.goto(base + slugPath + '/galerie', { waitUntil: 'networkidle' });
        await p.locator('.tile').first().click(); await p.waitForTimeout(700); await audit(p, 'Vollbild-Ansicht');
    }
    await guest.close();
}

// --- 1. Barrierefreiheit: Verwaltung ---
{
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const a = await context.newPage();
    await a.goto(base + '/login');
    await audit(a, 'Verwaltung: Anmeldung');
    await a.fill('input[type=email]', process.env.ADMIN_EMAIL);
    await a.fill('input[type=password]', process.env.ADMIN_PASSWORD);
    await a.click('button[type=submit]');
    await a.waitForLoadState('networkidle');
    const admin = slugPath.replace('/h/', '/admin/h/');
    for (const [name, path] of [['Hochzeiten', '/admin'], ['Übersicht', admin], ['Moderation', `${admin}/moderation`], ['Zugang', `${admin}/zugang`], ['Aufgaben', `${admin}/aufgaben`], ['Umfrage', `${admin}/umfrage`], ['Einstellungen', `${admin}/einstellungen`], ['Export', `${admin}/export`], ['Team', `${admin}/team`]]) {
        const response = await a.goto(base + path, { waitUntil: 'networkidle' });
        if (response.status() >= 400) { failures.push(`[Verwaltung] ${name}: HTTP ${response.status()}`); continue; }
        await audit(a, `Verwaltung: ${name}`);
    }
    await context.close();
}

// --- 2. Upload-Fehlerfälle ---
{
    const { context, page: p } = await joinAsGuest('Prüfgast Upload', 'Pixel 7');
    await p.goto(base + slugPath + '/upload', { waitUntil: 'networkidle' });
    const item = (file) => p.locator('[data-upload-item]', { has: p.locator('[data-role=name]', { hasText: file }) }).first();
    async function settle(file, maxMs = 60000) {
        const t0 = Date.now();
        while (Date.now() - t0 < maxMs) {
            const status = (await item(file).locator('[data-role=status]').textContent().catch(() => '')) ?? '';
            if ((/Gespeichert|nicht unterstützt|nicht erreichbar/.test(status)) && !/Versuch/.test(status)) return status;
            await p.waitForTimeout(150);
        }
        return 'Zeitüberschreitung';
    }
    function expect(label, ok, detail) {
        console.log(`${ok ? '✓' : '✗'} Upload: ${label}${ok ? '' : ` – ${detail}`}`);
        if (!ok) failures.push(`[Upload] ${label}: ${detail}`);
    }

    let patches = 0;
    const chunkUrl = /\/api\/uploads\/[0-9a-f-]{36}$/;
    await p.route(chunkUrl, async (route) => {
        if (route.request().method() === 'PATCH' && ++patches === 1) return route.abort('connectionreset');
        return route.continue();
    });
    await p.setInputFiles('input[type=file]', '/data/gross.jpg');
    let status = await settle('gross.jpg');
    expect('Abbruch im ersten Stück wird automatisch fortgesetzt', status === 'Gespeichert' && patches >= 3, `${status}, ${patches} Teil-Anfragen`);
    await p.unroute(chunkUrl);

    let posts = 0;
    const startUrl = /\/api\/uploads$/;
    await p.route(startUrl, async (route) => {
        if (route.request().method() === 'POST' && ++posts === 1) {
            return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'Server kurz nicht erreichbar.' }) });
        }
        return route.continue();
    });
    await p.setInputFiles('input[type=file]', '/data/klein.jpg');
    status = await settle('klein.jpg', 8000);
    const retry = item('klein.jpg').locator('[data-role=retry]');
    const retryVisible = await retry.isVisible();
    if (retryVisible) await retry.click();
    status = await settle('klein.jpg');
    expect('Serverfehler zeigt „Erneut versuchen“ und klappt danach', retryVisible && status === 'Gespeichert', `Knopf ${retryVisible}, ${status}`);
    await p.unroute(startUrl);

    await p.setInputFiles('input[type=file]', '/data/kaputt.jpg');
    status = await settle('kaputt.jpg', 15000);
    const noRetry = !(await item('kaputt.jpg').locator('[data-role=retry]').isVisible());
    const remove = (await item('kaputt.jpg').locator('[data-role=cancel]').textContent())?.trim();
    expect('Kaputte Datei: klare Meldung, kein Wiederholen, „Entfernen“', /nicht unterstützt/.test(status) && noRetry && remove === 'Entfernen', `${status}, Wiederholen ${!noRetry}, Knopf „${remove}“`);
    await context.close();
}

// --- 3. Hinweis „Zu viel am Handy?“ ---
{
    function expect(label, ok, detail) {
        console.log(`${ok ? '✓' : '✗'} Handy-Hinweis: ${label}${ok ? '' : ` – ${detail}`}`);
        if (!ok) failures.push(`[Handy-Hinweis] ${label}: ${detail}`);
    }
    const adminContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const a = await adminContext.newPage();
    await a.goto(base + '/login');
    await a.fill('input[type=email]', process.env.ADMIN_EMAIL);
    await a.fill('input[type=password]', process.env.ADMIN_PASSWORD);
    await a.click('button[type=submit]');
    await a.waitForLoadState('networkidle');
    async function configure(mode, threshold) {
        await a.goto(base + slugPath.replace('/h/', '/admin/h/') + '/einstellungen', { waitUntil: 'networkidle' });
        await a.check(`input[name=nudge_mode][value=${mode}]`);
        await a.fill('input[name=nudge_threshold]', String(threshold));
        await a.click('text=Handy-Hinweis speichern');
        await a.waitForLoadState('networkidle');
    }
    const isOpen = (page) => page.locator('dialog.nudge__dialog').evaluate((d) => d.open);
    const figureShown = (page) => page.locator('.nudge__trigger').isVisible();

    // Nach Zeit: Figur erst nach 1 Minute sichtbarer Zeit, bleibt über Seitenwechsel, nach dem Antippen eine Stunde Ruhe.
    await configure('time', 1);
    const context = await browser.newContext({ ...devices['iPhone 14'] });
    const p = await context.newPage();
    await p.clock.install();
    await p.goto(base + joinPath);
    await p.fill('input[name="display_name[displayName]"]', 'Prüfgast Handy');
    await Promise.all([p.waitForURL((url) => !url.pathname.includes('/join')), p.click('button[type=submit]')]);
    await p.goto(base + slugPath, { waitUntil: 'networkidle' });
    const hiddenAtStart = !(await figureShown(p));
    await p.clock.runFor(30_000);
    const hiddenAfterHalf = !(await figureShown(p));
    await p.clock.runFor(45_000);
    const shownAfterMinute = await figureShown(p);
    expect('Figur am Anfang unsichtbar, nach 1 Minute in der App da', hiddenAtStart && hiddenAfterHalf && shownAfterMinute, `Anfang versteckt ${hiddenAtStart}, nach 30 s versteckt ${hiddenAfterHalf}, nach 75 s da ${shownAfterMinute}`);
    await p.goto(base + slugPath + '/galerie', { waitUntil: 'networkidle' });
    const staysOnNextPage = await figureShown(p);
    const closedBeforeTap = !(await isOpen(p));
    await p.click('.nudge__trigger');
    const tapOpens = await isOpen(p);
    await p.click('text=Okay, Handy weg!');
    expect('Bleibt beim Seitenwechsel, Antippen zeigt den Hinweis', staysOnNextPage && closedBeforeTap && tapOpens && !(await isOpen(p)), `bleibt ${staysOnNextPage}, vorher zu ${closedBeforeTap}, auf Tippen ${tapOpens}`);
    const goneAfterTap = !(await figureShown(p));
    await p.clock.runFor(150_000);
    expect('Nach dem Lesen weg und eine Stunde Ruhe', goneAfterTap && !(await figureShown(p)), `weg ${goneAfterTap}`);
    await context.close();

    // Nach Beiträgen: ab 1 eigenen Beitrag in der letzten Stunde, auf jeder Seite.
    await configure('activity', 1);
    const { context: c2, page: q } = await joinAsGuest('Prüfgast Fleißig');
    await q.goto(base + slugPath, { waitUntil: 'networkidle' });
    const hiddenBefore = !(await figureShown(q));
    await q.goto(base + slugPath + '/upload', { waitUntil: 'networkidle' });
    await q.setInputFiles('input[type=file]', '/data/klein.jpg');
    await q.waitForSelector('[data-upload-item] [data-role=status]:has-text("Gespeichert")', { timeout: 30000 });
    await q.goto(base + slugPath + '/galerie', { waitUntil: 'networkidle' });
    expect('Figur erst nach vielen Beiträgen', hiddenBefore && (await figureShown(q)) && !(await isOpen(q)), `vorher versteckt ${hiddenBefore}`);
    await c2.close();

    await configure('time', 20);
    await adminContext.close();
}

await browser.close();
if (failures.length) {
    console.log(`\n${failures.length} Befund(e):\n${failures.join('\n')}`);
    process.exit(1);
}
console.log('\nAlle Browser-Prüfungen bestanden.');
