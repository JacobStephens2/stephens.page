// Browser-visible profile contract, run by check_theme_toggle.js with its shared browser/server.
module.exports = async function checkDossier(context, baseUrl, check, webfonts = false) {
    const page = await context.newPage();
    try {
        await page.goto(baseUrl + '/');
        await page.evaluate(() => document.fonts.ready);
        if (webfonts) {
            check('dossier', 'production webfonts loaded before layout measurements', await page.evaluate(() =>
                [...new Set([...document.fonts].filter((font) => font.status === 'loaded').map((font) => font.family))].sort()
            ), ['JetBrains Mono', 'Source Sans 3', 'Source Serif 4']);
        }
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
        check('dossier', 'telemetry tracking and elevated ledger', await page.evaluate(() => {
            const metric = getComputedStyle(document.querySelector('.metric-value'));
            const pill = getComputedStyle(document.querySelector('.impact-pill'));
            return {
                metricTracking: Math.abs(parseFloat(metric.letterSpacing) / parseFloat(metric.fontSize) + 0.02) < 0.00001,
                pillTracking: Math.abs(parseFloat(pill.letterSpacing) / parseFloat(pill.fontSize) + 0.01) < 0.00001,
                ledgerShadow: getComputedStyle(document.querySelector('.hero-metrics')).boxShadow,
            };
        }), {
            metricTracking: true, pillTracking: true,
            ledgerShadow: 'rgba(17, 23, 20, 0.04) 0px 4px 18px 0px',
        });
        await page.locator('.navigation .nav-item').first().hover();
        check('dossier', 'navigation card lifts on hover', await page.locator('.navigation .nav-item').first()
            .evaluate((card) => getComputedStyle(card).transform), 'matrix(1, 0, 0, 1, 0, -1)');
        await page.mouse.move(0, 0);
        await page.evaluate(() => scrollTo(0, 0));
        for (const width of [320, 390, 739, 740, 800, 900, 1060, 1280]) {
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(() => scrollTo(0, 0));
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
            if (width >= 740) {
                const fold = await page.evaluate(() => {
                    const current = document.querySelector('#current-work-title').getBoundingClientRect();
                    const outcomes = document.querySelector('.metrics-title').getBoundingClientRect();
                    return {
                        currentBottom: current.bottom,
                        visible: current.top >= 0 && current.bottom <= innerHeight
                            && outcomes.top >= 0 && outcomes.bottom <= innerHeight,
                    };
                });
                check('dossier', `Outcomes and Current work visible at ${width}x900 (title bottom ${fold.currentBottom})`,
                    fold.visible, true);
            }
        }
        for (const file of ['index.html', 'about.html', 'contact.html']) {
            await page.goto(`${baseUrl}/${file}`);
            check(file, 'candidate footer role targets', await page.locator('.footer p').allTextContents(), [
                'Jacob Stephens · Head of Engineering & Staff Systems Engineer · Philadelphia, PA',
                'Open to conversations about Senior/Staff Platform, Infrastructure & AI Agent Systems roles, as well as Engineering Leadership positions.',
                'Resume · Portfolio · Architecture Decisions · GitHub · LinkedIn · Contact · jacob@stephens.page',
                `© ${new Date().getFullYear()} Jacob Stephens · Independent apps published via Stephens Page LLC`,
            ]);
            check(file, 'candidate footer apps publisher link', await page.locator('.footer a[href="/apps.html"]')
                .allTextContents(), ['Stephens Page LLC']);
        }
    } finally {
        await page.close();
    }
};
