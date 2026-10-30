// Headless parity check for the theme controller (theme.js) and theme toggle slots.
// Run with: npx playwright install chromium && node check_theme_toggle.js [BASE_URL]
// Without BASE_URL it serves the repo root with `python3 -m http.server` on a free port.

const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const net = require('net');
const path = require('path');

const repoRoot = path.join(__dirname, '..');

// Palette P2 contract from issue #53, independent of the implementation.
const META_LIGHT = '#215239';
const META_DARK = '#0e1311';
const palettes = {
    light: {
        surface: '#fcfdfc', soft: '#eef2ef', 'code-bg': '#eef2ef', 'bar-bg': '#e6ece8',
        rule: '#cfd8d3', line: '#cfd8d3', ink: '#111714', muted: '#4a5750',
        brand: '#215239', 'brand-hover': '#173d2a', 'brand-accent': '#488264', 'brand-ink': '#ffffff',
        'brand-blue': '#215239', 'accent-orange': '#215239', accent: '#215239', 'syntax-keyword': '#215239',
    },
    dark: {
        surface: '#0e1311', soft: '#161e1a', 'code-bg': '#1b2520', 'bar-bg': '#212d27',
        rule: '#28362f', line: '#28362f', ink: '#e8efec', muted: '#95a69e',
        brand: '#7ec49b', 'brand-hover': '#96d3af', 'brand-accent': '#488264', 'brand-ink': '#0e1311',
        'brand-blue': '#7ec49b', 'accent-orange': '#7ec49b', accent: '#7ec49b', 'syntax-keyword': '#7ec49b',
    },
};

// toggleParents: one selector per expected toggle, matched against each toggle's parent in DOM order.
const pages = [
    {
        name: 'home',
        path: '/',
        toggleParents: ['.header-utility[data-theme-toggle]'],
        hasThemeColor: true,
    },
    {
        name: 'about',
        path: '/about.html',
        toggleParents: ['.topbar > .theme-toggle-cluster'],
        hasThemeColor: true,
    },
    {
        name: 'contact',
        path: '/contact.html',
        toggleParents: ['.topbar > .theme-toggle-cluster'],
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

async function checkPalette(page, name, theme) {
    const actual = await page.evaluate((tokens) => {
        const style = getComputedStyle(document.documentElement);
        return Object.fromEntries(tokens.map((token) => [token, style.getPropertyValue(`--${token}`).trim()]));
    }, Object.keys(palettes[theme]));
    check(name, `${theme} palette and aliases`, actual, palettes[theme]);
}

async function checkPage(context, baseUrl, spec, systemTheme) {
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
    await checkPalette(page, spec.name, systemTheme);
    check(spec.name, `system ${systemTheme}`, await readThemeState(page), {
        dataTheme: null, stored: null, themeColor: spec.hasThemeColor ? (systemTheme === 'dark' ? META_DARK : META_LIGHT) : null,
    });
    const steps = [
        { dataTheme: 'light', stored: 'light', themeColor: spec.hasThemeColor ? META_LIGHT : null },
        { dataTheme: 'dark', stored: 'dark', themeColor: spec.hasThemeColor ? META_DARK : null },
        { dataTheme: null, stored: null, themeColor: spec.hasThemeColor ? (systemTheme === 'dark' ? META_DARK : META_LIGHT) : null },
    ];
    for (const expected of steps) {
        await visibleToggle.click();
        check(spec.name, `click → ${expected.dataTheme || 'system'}`, await readThemeState(page), expected);
        await checkPalette(page, spec.name, expected.dataTheme || systemTheme);
        await page.reload({ waitUntil: 'load' });
        check(spec.name, 'preference survives reload', await readThemeState(page), expected);
    }

    const otherTheme = systemTheme === 'light' ? 'dark' : 'light';
    await page.emulateMedia({ colorScheme: otherTheme });
    await page.waitForFunction((color) => {
        const meta = document.querySelector('meta[name="theme-color"]');
        return !meta || meta.content === color;
    }, otherTheme === 'dark' ? META_DARK : META_LIGHT);
    await checkPalette(page, spec.name, otherTheme);
    if (spec.name === 'resume') {
        await page.emulateMedia({ media: 'print' });
        for (const preference of [null, 'light', 'dark']) {
            await page.evaluate((theme) => {
                if (theme) document.documentElement.setAttribute('data-theme', theme);
                else document.documentElement.removeAttribute('data-theme');
            }, preference);
            const print = await page.evaluate(() => ({
                ink: getComputedStyle(document.body).color,
                paper: getComputedStyle(document.body).backgroundColor,
                heading: getComputedStyle(document.querySelector('h2')).color,
                link: getComputedStyle(document.querySelector('main a:not([download])')).color,
                visibleToggles: [...document.querySelectorAll('.theme-toggle')].filter((toggle) => toggle.getClientRects().length > 0).length,
            }));
            check(spec.name, `print from ${preference || 'system'} on ${otherTheme} OS`, print, {
                ink: 'rgb(17, 23, 20)', paper: 'rgb(255, 255, 255)',
                heading: 'rgb(33, 82, 57)', link: 'rgb(33, 82, 57)', visibleToggles: 0,
            });
        }
    }
    await page.close();
}

async function checkSharedStyles(context, baseUrl) {
    const page = await context.newPage();
    await page.goto(baseUrl);
    await page.setContent(`<link rel="stylesheet" href="${baseUrl}/theme.css">
        <span class="chip plat">Platform</span>
        <div class="row"><div class="fill alt">Alternate</div></div>
        <div class="stack-row"><div class="stack-track"><div class="fill">Stack</div></div></div>`);
    await page.locator('link').evaluate((link) => link.sheet || new Promise((resolve) => link.onload = resolve));
    await page.emulateMedia({ colorScheme: 'light' });
    await checkPalette(page, 'shared stylesheet alone', 'light');
    await page.emulateMedia({ colorScheme: 'dark' });
    await checkPalette(page, 'shared stylesheet alone', 'dark');
    for (const preference of [null, 'dark']) {
        await page.evaluate((theme) => {
            if (theme) document.documentElement.setAttribute('data-theme', theme);
            else document.documentElement.removeAttribute('data-theme');
        }, preference);
        const colors = await page.evaluate(() => {
            const chip = getComputedStyle(document.querySelector('.chip.plat'));
            return [chip.backgroundColor, chip.borderColor, chip.color,
                getComputedStyle(document.querySelector('.row .fill.alt')).backgroundColor,
                getComputedStyle(document.querySelector('.stack-row .stack-track .fill')).backgroundColor];
        });
        check('shared stylesheet', `${preference || 'system'} dark chip and bars`, colors,
            ['rgb(19, 34, 26)', 'rgb(38, 66, 52)', 'rgb(126, 196, 155)', 'rgb(72, 130, 100)', 'rgb(72, 130, 100)']);
    }
    await page.close();
}

async function checkDossier(context, baseUrl) {
    const page = await context.newPage();
    await page.goto(baseUrl + '/');
    const layout = await page.evaluate(() => {
        const panel = document.querySelector('.hero-panel').getBoundingClientRect();
        const metrics = document.querySelector('.hero-metrics').getBoundingClientRect();
        const banner = document.querySelector('.hero > .links-panel').getBoundingClientRect();
        return {
            containerWidth: document.querySelector('.container').getBoundingClientRect().width,
            columns: metrics.left > panel.right && Math.abs(metrics.top - panel.top) < 1,
            bannerSpansColumns: Math.abs(banner.left - panel.left) < 1 && Math.abs(banner.right - metrics.right) < 1,
        };
    });
    check('dossier', 'desktop executive split with full-width current work', layout, {
        containerWidth: 1060, columns: true, bannerSpansColumns: true,
    });
    check('dossier', 'six icon-equipped quick links', await page.locator('.hero-panel .cta').evaluateAll((links) =>
        links.map((link) => [link.textContent.trim(), link.getAttribute('href'), !!link.querySelector('svg')])
    ), [
        ['Resume', '/resume/', true],
        ['Portfolio', '/portfolio.html', true],
        ['ETA Platform case study', '/blog/one-engineer-platform-production-systems/', true],
        ['Decisions', 'https://adr.stephens.page/', true],
        ['GitHub', 'https://github.com/JacobStephens2', true],
        ['LinkedIn', 'https://www.linkedin.com/in/jacob-c-stephens/', true],
    ]);
    check('dossier', 'categorized Start Here cards', await page.locator('.navigation > *').evaluateAll((items) =>
        items.map((item) => (item.querySelector('.nav-label') || item).textContent.trim())
    ), [
        'Production Systems & Architecture', 'Portfolio', 'ETA Platform case study', 'Architecture decisions', 'Tech stack',
        'Writing, Background & Code', 'Blog', 'Resume', 'About', 'GitHub',
    ]);
    for (const width of [320, 390, 739, 740, 1060]) {
        await page.setViewportSize({ width, height: 900 });
        const responsive = await page.evaluate(() => {
            const hero = document.querySelector('.hero-panel').getBoundingClientRect();
            const metrics = document.querySelector('.hero-metrics').getBoundingClientRect();
            const cards = [...document.querySelectorAll('.navigation .nav-item')].map((item) => item.getBoundingClientRect());
            return {
                noOverflow: document.documentElement.scrollWidth <= innerWidth,
                splitHero: metrics.left > hero.right,
                twoCardsPerRow: cards.length === 8 && Math.abs(cards[0].top - cards[1].top) < 1 && cards[2].top > cards[0].bottom,
            };
        });
        check('dossier', `responsive layout at ${width}px`, responsive, {
            noOverflow: true, splitHero: width >= 740, twoCardsPerRow: width >= 740,
        });
    }
    await page.close();
}

async function checkAllPagePalettes(context, baseUrl) {
    const paths = execFileSync('git', ['ls-files', '*.html'], { cwd: repoRoot, encoding: 'utf8' })
        .trim().split('\n')
        .filter((file) => !file.startsWith('skills/') && fs.readFileSync(path.join(repoRoot, file), 'utf8').includes('href="/theme.css"'));
    const page = await context.newPage();
    for (const file of paths) {
        await page.goto(`${baseUrl}/${file}`, { waitUntil: 'load' });
        for (const [system, preference] of [['light', null], ['light', 'dark'], ['dark', 'light'], ['dark', null]]) {
            await page.evaluate((theme) => {
                if (theme) localStorage.setItem('theme', theme);
                else localStorage.removeItem('theme');
            }, preference);
            await page.emulateMedia({ colorScheme: system });
            await page.reload({ waitUntil: 'load' });
            const theme = preference || system;
            await checkPalette(page, `${file} (${preference || 'system'} on ${system})`, theme);
            const state = await readThemeState(page);
            if (state.themeColor !== null) {
                check(file, `${theme} theme-color`, state.themeColor, theme === 'light' ? META_LIGHT : META_DARK);
            }
            const group = page.locator('.cs-diagram .grp').first();
            if (await group.count()) {
                const colors = await group.evaluate((node) => {
                    const style = getComputedStyle(node);
                    return [style.fill, style.stroke];
                });
                check(file, `${theme} SVG group tint`, colors, theme === 'light'
                    ? ['rgba(33, 82, 57, 0.07)', 'rgba(33, 82, 57, 0.38)']
                    : ['rgba(126, 196, 155, 0.08)', 'rgba(126, 196, 155, 0.4)']);
                check(file, `${theme} SVG box stroke`, await page.locator('.cs-diagram .box').first()
                    .evaluate((node) => getComputedStyle(node).stroke), theme === 'light' ? 'rgb(33, 82, 57)' : 'rgb(126, 196, 155)');
            }
        }
        // Shared CSS must not conceal stale page-local fallback tokens.
        await page.emulateMedia({ colorScheme: 'light' });
        const inline = await page.evaluate((expected) => {
            document.documentElement.removeAttribute('data-theme');
            document.querySelector('link[href="/theme.css"]').sheet.disabled = true;
            const declared = [...document.querySelectorAll('style')].flatMap((style) => [...style.sheet.cssRules])
                .filter((rule) => rule.selectorText === ':root')
                .flatMap((rule) => [...rule.style]);
            const tokens = Object.keys(expected).filter((token) => declared.includes(`--${token}`));
            const computed = getComputedStyle(document.documentElement);
            return {
                actual: Object.fromEntries(tokens.map((token) => [token, computed.getPropertyValue(`--${token}`).trim()])),
                expected: Object.fromEntries(tokens.map((token) => [token, expected[token]])),
            };
        }, { ...palettes.light, bg: '#fcfdfc', panel: '#fcfdfc', text: '#111714' });
        check(file, 'inline light fallback palette', inline.actual, inline.expected);
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

    let browser;
    try {
        browser = await chromium.launch();
        for (const systemTheme of ['light', 'dark']) {
            const context = await browser.newContext({
                viewport: { width: 1280, height: 720 },
                colorScheme: systemTheme,
                reducedMotion: 'reduce',
            });
            // Keep the check offline: third-party analytics and fonts are not under test.
            await context.route((url) => !url.href.startsWith(baseUrl), (route) => route.abort());
            for (const spec of pages) {
                await checkPage(context, baseUrl, spec, systemTheme);
            }
            if (systemTheme === 'light') {
                await checkAllPagePalettes(context, baseUrl);
                await checkSharedStyles(context, baseUrl);
                await checkDossier(context, baseUrl);
                const manifest = await (await context.request.get(baseUrl + '/manifest.webmanifest')).json();
                check('manifest', 'install colors', [manifest.theme_color, manifest.background_color], ['#215239', '#fcfdfc']);
            }
            await context.close();
        }
    } finally {
        if (browser) await browser.close();
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
