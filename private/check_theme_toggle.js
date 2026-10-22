// Headless parity check for the theme controller (theme.js) and theme toggle slots.
// Run with: npx playwright install chromium && node check_theme_toggle.js [BASE_URL]
// Without BASE_URL it serves the repo root with `python3 -m http.server` on a free port.

const { chromium } = require('playwright');
const { spawn } = require('child_process');
const net = require('net');
const path = require('path');

const repoRoot = path.join(__dirname, '..');

// Mirrors META_LIGHT / META_DARK in theme.js; update both together.
const META_LIGHT = '#9b4d24';
const META_DARK = '#141210';

// toggleParents: one selector per expected toggle, matched against each toggle's parent in DOM order.
const pages = [
    {
        name: 'home',
        path: '/',
        toggleParents: ['.site-header > .theme-toggle-cluster'],
        hasThemeColor: true,
    },
    {
        name: 'portfolio',
        path: '/portfolio.html',
        toggleParents: ['.topbar-right'],
        hasThemeColor: false,
    },
    {
        name: 'blog post with topbar',
        path: '/blog/byte-counts-lie-measuring-what-i-actually-wrote/',
        toggleParents: ['.topbar > .theme-toggle-cluster'],
        hasThemeColor: false,
    },
    {
        name: 'resume',
        path: '/resume/',
        toggleParents: [
            '.sticky-mobile-header[data-theme-toggle]',
            'header > .header-actions[data-theme-toggle]',
        ],
        hasThemeColor: true,
    },
];

const failures = [];

function check(pageName, label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    console.log(`${ok ? '✓' : '✗'} ${pageName}: ${label}${ok ? '' : ` (expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)})`}`);
    if (!ok) failures.push(`${pageName}: ${label}`);
}

function freePort() {
    return new Promise((resolve, reject) => {
        const server = net.createServer();
        server.listen(0, '127.0.0.1', () => {
            const { port } = server.address();
            server.close(() => resolve(port));
        });
        server.on('error', reject);
    });
}

async function startStaticServer() {
    const port = await freePort();
    const proc = spawn('python3', ['-m', 'http.server', String(port), '--bind', '127.0.0.1'], {
        cwd: repoRoot,
        stdio: 'ignore',
    });
    const baseUrl = `http://127.0.0.1:${port}`;
    for (let i = 0; i < 50; i++) {
        try {
            await fetch(baseUrl + '/');
            return { baseUrl, stop: () => proc.kill() };
        } catch (e) {
            await new Promise((r) => setTimeout(r, 100));
        }
    }
    proc.kill();
    throw new Error('static server did not start');
}

async function readThemeState(page) {
    return page.evaluate(() => {
        const meta = document.querySelector('meta[name="theme-color"]');
        return {
            dataTheme: document.documentElement.getAttribute('data-theme'),
            stored: localStorage.getItem('theme'),
            themeColor: meta ? meta.getAttribute('content') : null,
        };
    });
}

async function checkPage(context, baseUrl, spec) {
    const page = await context.newPage();

    await page.goto(baseUrl + spec.path, { waitUntil: 'load' });
    await page.evaluate(() => localStorage.removeItem('theme'));
    await page.reload({ waitUntil: 'load' });
    await page.waitForSelector('.theme-toggle', { state: 'attached' });

    const toggleCount = await page.locator('.theme-toggle').count();
    check(spec.name, 'toggle count', toggleCount, spec.toggleParents.length);

    const parentMatches = await page.evaluate((selectors) => {
        const toggles = Array.from(document.querySelectorAll('.theme-toggle'));
        return selectors.map((sel, i) => !!toggles[i] && toggles[i].parentElement.matches(sel));
    }, spec.toggleParents);
    check(spec.name, `toggle parents ${spec.toggleParents.join(', ')}`, parentMatches, spec.toggleParents.map(() => true));

    const visibleToggle = page.locator('.theme-toggle:visible').first();
    const steps = [
        { dataTheme: 'light', stored: 'light', themeColor: spec.hasThemeColor ? META_LIGHT : null },
        { dataTheme: 'dark', stored: 'dark', themeColor: spec.hasThemeColor ? META_DARK : null },
        { dataTheme: null, stored: null, themeColor: spec.hasThemeColor ? META_LIGHT : null },
    ];
    for (const expected of steps) {
        await visibleToggle.click();
        check(spec.name, `click → ${expected.dataTheme || 'system'}`, await readThemeState(page), expected);
    }

    await page.close();
}

async function main() {
    let server = null;
    let baseUrl = process.argv[2];
    if (!baseUrl) {
        server = await startStaticServer();
        baseUrl = server.baseUrl;
    }
    baseUrl = baseUrl.replace(/\/$/, '');

    const browser = await chromium.launch();
    try {
        const context = await browser.newContext({
            viewport: { width: 1280, height: 720 },
            colorScheme: 'light',
        });
        // Keep the check offline: third-party analytics and fonts are not under test.
        await context.route((url) => !url.href.startsWith(baseUrl), (route) => route.abort());
        for (const spec of pages) {
            await checkPage(context, baseUrl, spec);
        }
    } finally {
        await browser.close();
        if (server) server.stop();
    }

    if (failures.length) {
        console.error(`\n${failures.length} check(s) failed.`);
        process.exit(1);
    }
    console.log('\nAll theme toggle checks passed.');
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
