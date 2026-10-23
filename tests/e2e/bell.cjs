// Browser scenarios of the notification bell, run by tests/e2e/run.sh against its throw-away GLPI:
// several tabs (one poller, exactly one chime, failover, mute) with Web Locks and with the
// localStorage fallback, a navigated tab taking over the chime, a suspended background poller, a
// poll response overtaken by an action, user actions that must not chime, a browser without
// BroadcastChannel, and the muted list.
//
//   node tests/e2e/bell.cjs <base-url> "<command that runs the SQL on stdin against the GLPI DB>"
//
// Headless Chromium lets every tab play audio; fixtures/autoplay-emulation.js restores the real
// rule (audio only after a user gesture in that tab).

const path = require('path');
const { execSync } = require('child_process');
const { chromium } = require('playwright');

const [base, sqlCommand] = process.argv.slice(2);
const AUTOPLAY_EMULATION = path.join(__dirname, 'fixtures', 'autoplay-emulation.js');
const POLL_WAIT_MS = 14000; // poll interval (10 s) plus margin
let failures = 0;
let seq = 0;

const sleep = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });
const check = (label, actual, expected) => {
    if (JSON.stringify(actual) === JSON.stringify(expected)) {
        console.log(`  \x1b[32mok\x1b[0m   ${label}`);
    } else {
        failures += 1;
        console.log(`  \x1b[31mFAIL\x1b[0m ${label} (expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)})`);
    }
};
const sql = (query) => execSync(sqlCommand, { input: query, encoding: 'utf8' }).trim();
const glpiId = () => Number(sql("SELECT id FROM glpi_users WHERE name='glpi';"));
const addAlert = (name, suppressed = 0) => {
    seq += 1;
    sql(`INSERT INTO glpi_plugin_inappnotification_alerts (users_id, alert_name, alert_message, source_group,
        alert_fingerprint, is_read, unread_count, is_suppressed, last_triggered_at, date_creation, date_mod)
        VALUES (${glpiId()}, '${name}', 'e2e', 'other', 'e2e-bell:${Date.now()}:${seq}', 0, 1, ${suppressed}, NOW(), NOW(), NOW());`);
};

async function newContext(browser, { lease = false, noChannel = false } = {}) {
    const context = await browser.newContext();
    await context.addInitScript({ path: AUTOPLAY_EMULATION });
    await context.addInitScript((flags) => {
        if (flags.lease) {
            // Like a page served over plain HTTP (no secure context, no Web Locks).
            Object.defineProperty(Navigator.prototype, 'locks', { get: () => undefined, configurable: true });
        }
        if (flags.noChannel) {
            Object.defineProperty(window, 'BroadcastChannel', { value: undefined, configurable: true });
        }
        window.__chimes = 0;
        window.addEventListener('inappnotification:chime', () => { window.__chimes += 1; });
    }, { lease, noChannel });
    // Log in in a tab of its own: its click must not unlock the audio of the tabs under test.
    const login = await context.newPage();
    await login.goto(`${base}/`);
    await login.fill('#login_name', 'glpi');
    await login.fill('#login_password', 'glpi');
    await Promise.all([login.waitForNavigation(), login.click('button[name="submit"]')]);
    await login.close();
    return context;
}

async function openBell(context, polls = null, index = 0) {
    const page = await context.newPage();
    if (polls) {
        page.on('request', (request) => {
            if (/\/Notifications\?/.test(request.url())) {
                polls[index] += 1;
            }
        });
    }
    await page.goto(`${base}/front/central.php`);
    await page.waitForSelector('.inappnotification-bell-toggle');
    return page;
}

const roleOf = async (page) => {
    const wrap = page.locator('.inappnotification-bell-wrap');
    return {
        poller: (await wrap.getAttribute('data-poller')) === '1',
        sound: (await wrap.getAttribute('data-sound')) === '1',
        mode: await wrap.getAttribute('data-coordination'),
    };
};
// Roles are assigned after the tab's first fetch; under load that can take a while.
const waitForRole = async (page, role, expected, timeoutMs = 5000) => {
    const started = Date.now();
    let current = (await roleOf(page))[role];
    while (current !== expected && Date.now() - started < timeoutMs) {
        await sleep(200);
        current = (await roleOf(page))[role];
    }
    return current;
};
const chimesOf = (pages) => Promise.all(pages.map((page) => page.evaluate(() => window.__chimes)));
const resetChimes = (pages) => Promise.all(pages.map((page) => page.evaluate(() => { window.__chimes = 0; })));
const badgeOf = async (page) => (await page.locator('.inappnotification-badge').innerText().catch(() => '0')).trim();
const unlockAudio = async (page) => {
    await page.bringToFront();
    await page.mouse.click(5, 300);
};

async function setMuted(page, muted) {
    await page.bringToFront();
    await page.locator('.inappnotification-bell-toggle').click();
    const toggle = page.locator('.inappnotification-sound-toggle');
    if ((await toggle.getAttribute('aria-pressed')) !== String(muted)) {
        await toggle.click();
    }
    await page.keyboard.press('Escape');
}

async function severalTabs(browser, lease) {
    const label = lease ? 'lease' : 'locks';
    console.log(`\n-- several tabs (${label})`);
    const settle = lease ? 3500 : 800;
    const context = await newContext(browser, { lease });
    const polls = [0, 0, 0];
    const tabs = [];
    for (let i = 0; i < 3; i += 1) {
        tabs.push(await openBell(context, polls, i));
        await sleep(300);
    }
    await sleep(settle);

    let roles = await Promise.all(tabs.map(roleOf));
    check(`[${label}] coordination mode`, roles.map((role) => role.mode), [label, label, label]);
    check(`[${label}] the first tab polls, the others do not`, roles.map((role) => role.poller), [true, false, false]);

    await unlockAudio(tabs[1]);
    await sleep(settle);
    roles = await Promise.all(tabs.map(roleOf));
    check(`[${label}] the clicked tab holds the sound role`, roles.map((role) => role.sound), [false, true, false]);

    polls.fill(0);
    await sleep(12000);
    check(`[${label}] only the poller sends list requests`, polls.map((count) => count > 0), [true, false, false]);

    addAlert(`tabs ${label} 1`);
    await sleep(POLL_WAIT_MS);
    check(`[${label}] one chime, in the sound tab`, await chimesOf(tabs), [0, 1, 0]);
    const badges = await Promise.all(tabs.map(badgeOf));
    check(`[${label}] all tabs show the new badge`, badges.every((badge) => badge === badges[0] && badge !== '0'), true);

    await tabs[0].close();
    const rest = tabs.slice(1);
    await resetChimes(rest);
    await sleep(settle + 500);
    roles = await Promise.all(rest.map(roleOf));
    check(`[${label}] another tab took over polling`, roles.filter((role) => role.poller).length, 1);
    addAlert(`tabs ${label} 2`);
    await sleep(POLL_WAIT_MS);
    check(`[${label}] still exactly one chime after the failover`, await chimesOf(rest), [1, 0]);

    await setMuted(rest[1], true);
    await sleep(800);
    await resetChimes(rest);
    addAlert(`tabs ${label} 3`);
    await sleep(POLL_WAIT_MS);
    check(`[${label}] no chime while muted (shared by all tabs)`, await chimesOf(rest), [0, 0]);
    await setMuted(rest[1], false);
    await context.close();
}

async function navigatedTab(browser) {
    console.log('\n-- navigated tab');
    const context = await newContext(browser);
    const poller = await openBell(context);
    const other = await openBell(context);
    await unlockAudio(other);
    await sleep(800);
    await other.goto(`${base}/front/ticket.php`);
    await other.waitForSelector('.inappnotification-bell-toggle');
    await sleep(1500);
    check('[navigation] the navigated tab takes the sound role without a new click', await waitForRole(other, 'sound', true), true);
    addAlert('navigation');
    await sleep(POLL_WAIT_MS);
    check('[navigation] the chime comes from the navigated tab, not from the poller', await chimesOf([poller, other]), [0, 1]);
    await context.close();
}

async function suspendedPoller(browser) {
    console.log('\n-- poller in a suspended background tab');
    const context = await newContext(browser);
    const polls = [0, 0];
    const poller = await openBell(context, polls, 0);
    const visible = await openBell(context, polls, 1);
    await unlockAudio(visible);
    check('[suspended poller] the first tab polls, the clicked one holds the sound role',
        [await waitForRole(poller, 'poller', true), await waitForRole(visible, 'sound', true)], [true, true]);
    // Like a background tab the browser throttles or suspends: the poller keeps its role but no
    // data arrives from it. (CDP Page.setWebLifecycleState "frozen" has no effect on a headless
    // page, which counts as visible.)
    await poller.route('**/Notifications?*', () => {});
    await visible.bringToFront();
    polls.fill(0);
    addAlert('suspended poller');
    await sleep(30000); // stale after 1.5 poll intervals, checked once per interval
    check('[suspended poller] the visible tab fetched itself', polls[1] > 0, true);
    check('[suspended poller] the visible tab chimed', await visible.evaluate(() => window.__chimes), 1);
    check('[suspended poller] the visible tab shows the new entry', (await badgeOf(visible)) !== '0', true);
    await poller.unroute('**/Notifications?*');
    await sleep(POLL_WAIT_MS);
    check('[suspended poller] no second chime once the poller delivers again', await chimesOf([poller, visible]), [0, 1]);
    await context.close();
}

async function overtakenPoll(browser) {
    console.log('\n-- poll response overtaken by an action');
    addAlert('overtaken');
    const context = await newContext(browser);
    const poller = await openBell(context);
    const other = await openBell(context);
    await unlockAudio(other);
    await sleep(800);
    // Hold the poller's next list response for 4 s after the server produced it ("unread").
    let caught = null;
    const pollInFlight = new Promise((resolve) => { caught = resolve; });
    await poller.route('**/Notifications?*', async (route) => {
        const response = await route.fetch();
        caught();
        await sleep(4000);
        await route.fulfill({ response });
    });
    await pollInFlight;
    await other.bringToFront();
    await other.locator('.inappnotification-bell-toggle').click();
    await other.locator('.inappnotification-mark-all').click();
    await sleep(6000);
    await poller.unroute('**/Notifications?*');
    check('[overtaken poll] the late response does not chime', await chimesOf([poller, other]), [0, 0]);
    check('[overtaken poll] it does not undo "mark all as read"', [await badgeOf(poller), await badgeOf(other)], ['0', '0']);
    await context.close();
}

async function withoutChannel(browser) {
    console.log('\n-- browser without BroadcastChannel');
    const context = await newContext(browser, { noChannel: true });
    const blocked = await openBell(context);
    const unlocked = await openBell(context);
    await unlockAudio(unlocked);
    await sleep(800);
    check('[standalone] mode', [(await roleOf(blocked)).mode, (await roleOf(unlocked)).mode], ['standalone', 'standalone']);
    addAlert('standalone');
    await sleep(POLL_WAIT_MS + 2000);
    check('[standalone] exactly one chime, from the tab that can play audio', await chimesOf([blocked, unlocked]), [0, 1]);
    check('[standalone] both tabs show the new entry', [(await badgeOf(blocked)) !== '0', (await badgeOf(unlocked)) !== '0'], [true, true]);
    await context.close();
}

async function blockedChime(browser) {
    console.log('\n-- chime blocked until the first click');
    const context = await newContext(browser);
    const page = await openBell(context);
    await sleep(1500); // after the first fetch: the entry must arrive as a change, not as the starting state
    addAlert('blocked chime');
    await sleep(POLL_WAIT_MS);
    check('[blocked] no chime before a user gesture on the page', await page.evaluate(() => window.__chimes), 0);
    check('[blocked] the bell shows the hint', await page.locator('.inappnotification-audio-hint').count(), 1);
    await unlockAudio(page);
    await sleep(800);
    check('[blocked] the missed chime plays on the first click', await page.evaluate(() => window.__chimes), 1);
    check('[blocked] the hint is gone', await page.locator('.inappnotification-audio-hint').count(), 0);
    await setMuted(page, true);
    await page.locator('.inappnotification-bell-toggle').click();
    await page.locator('.inappnotification-sound-test').click();
    await sleep(500);
    check('[sound test] plays on click, also while muted', await page.evaluate(() => window.__chimes), 2);
    await page.keyboard.press('Escape');
    await setMuted(page, false);
    await context.close();
}

async function userActions(browser) {
    console.log('\n-- user actions');
    addAlert('actions seed');
    const context = await newContext(browser);
    const page = await openBell(context);
    const other = await openBell(context);
    await unlockAudio(other);
    await sleep(800);
    await page.bringToFront();
    await page.locator('.inappnotification-bell-toggle').click();
    await page.locator('.inappnotification-mark-all').click();
    await sleep(1500);
    await page.locator('.inappnotification-dropdown .inappnotification-item').first()
        .locator('.inappnotification-item-menu-trigger').click();
    await page.locator('.inappnotification-item-actions.is-open .inappnotification-item-menu-action').first().click();
    await sleep(POLL_WAIT_MS);
    check('[actions] "mark all as read" and "mark as unread" do not chime', await chimesOf([page, other]), [0, 0]);
    check('[actions] both tabs show the same badge', [await badgeOf(page), await badgeOf(other)], ['1', '1']);
    await context.close();
}

async function mutedList(browser) {
    console.log('\n-- muted list');
    sql('DELETE FROM glpi_plugin_inappnotification_alerts;');
    addAlert('e2e active');
    addAlert('e2e suppressed', 1);
    const context = await newContext(browser);
    const page = await context.newPage();
    await page.goto(`${base}/plugins/inappnotification/front/muted.php`);
    await page.waitForSelector('[data-glpi-search-container] table', { timeout: 20000 });
    await sleep(1000);
    const text = await page.locator('[data-glpi-search-container]').innerText();
    check('[muted list] shows the suppressed entry', text.includes('e2e suppressed'), true);
    check('[muted list] hides the active entry', text.includes('e2e active'), false);
    const targets = await page.locator('form').evaluateAll((forms) => forms.map((form) => form.getAttribute('action') || ''));
    check('[muted list] the search form stays on muted.php', targets.some((target) => target.includes('/front/muted.php')), true);
    await context.close();
}

(async () => {
    const browser = await chromium.launch();
    try {
        await severalTabs(browser, false);
        await severalTabs(browser, true);
        await navigatedTab(browser);
        await suspendedPoller(browser);
        await overtakenPoll(browser);
        await blockedChime(browser);
        await userActions(browser);
        await withoutChannel(browser);
        await mutedList(browser);
    } finally {
        await browser.close();
    }
    process.exit(failures > 0 ? 1 : 0);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
