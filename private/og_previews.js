// Check committed social previews: node private/og_previews.js
// Recapture from this checkout: node private/og_previews.js --capture
// Requires the Playwright Chromium installed for private/package.json.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs/promises');
const http = require('node:http');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..');
const origin = 'https://stephens.page';
const imageTag = /<meta\s+(?:property="og:image"|name="twitter:image")\s+content="([^"]+)"\s*>/g;
const htmlFiles = execFileSync('git', ['ls-files', '*.html'], { cwd: root, encoding: 'utf8' })
    .trim().split('\n');

async function previewServer() {
    const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript',
        '.jpg': 'image/jpeg', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
    const server = http.createServer(async (request, response) => {
        try {
            const pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname);
            let file = path.resolve(root, '.' + pathname);
            if (file !== root && !file.startsWith(root + path.sep)) throw new Error('outside document root');
            if ((await fs.stat(file)).isDirectory()) file = path.join(file, 'index.html');
            response.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
            response.end(await fs.readFile(file));
        } catch {
            response.writeHead(404).end();
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    return { server, baseUrl: `http://127.0.0.1:${server.address().port}` };
}

async function checkPreviews(context, baseUrl) {
    const page = await context.newPage();
    await page.goto(baseUrl + '/');
    let count = 0;
    for (const file of htmlFiles) {
        const response = await context.request.get(baseUrl + '/' + file);
        assert.equal(response.status(), 200, file);
        const html = await response.text();
        const tags = [...html.matchAll(imageTag)].map((match) => match[1]);
        if (['index.html', 'about.html', 'contact.html'].includes(file)) {
            assert.equal(tags.length, 2, `${file}: both social images are required`);
        }
        const urls = tags.filter((url) => url.startsWith(origin + '/') && /\/og[^/]*\.jpg$/.test(url));
        if (!urls.length) continue; // Product icons, headshots and resume images are independent assets.
        assert.equal(urls.length, 2, `${file}: both social images are required`);
        assert.equal(urls[0], urls[1], `${file}: OpenGraph and Twitter must agree`);
        const fingerprint = urls[0].match(/\/og(?:-about|-contact)?-([a-f0-9]{12})\.jpg$/);
        assert.ok(fingerprint, `${file}: preview needs a cache-busted filename`);
        const imageUrl = baseUrl + new URL(urls[0]).pathname;
        const imageResponse = await context.request.get(imageUrl);
        assert.equal(imageResponse.status(), 200, urls[0]);
        assert.match(imageResponse.headers()['content-type'], /^image\/jpeg/);
        const bytes = await imageResponse.body();
        assert.equal(crypto.createHash('sha256').update(bytes).digest('hex').slice(0, 12), fingerprint[1],
            `${file}: filename must fingerprint the committed bytes`);
        const pixels = await page.evaluate(async (url) => {
            const img = new Image();
            img.src = url;
            await img.decode();
            const canvas = document.createElement('canvas');
            canvas.width = img.width;
            canvas.height = img.height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            const data = ctx.getImageData(0, 0, img.width, img.height).data;
            // JPEG compression moves channels slightly. Count near-colors, not exact RGB equality.
            const retired = [[155, 77, 36], [239, 233, 223]];
            const fractions = retired.map((rgb) => {
                let matches = 0;
                for (let i = 0; i < data.length; i += 4) {
                    const distance = rgb.reduce((sum, value, channel) => sum + Math.abs(data[i + channel] - value), 0);
                    if (distance <= 10) matches++;
                }
                return matches / (img.width * img.height);
            });
            return { width: img.width, height: img.height, fractions };
        }, imageUrl);
        assert.equal(pixels.width, 1200, file);
        assert.equal(pixels.height, 630, file);
        // Permit incidental photo/antialiasing pixels, never a retired UI surface or accent.
        assert.ok(pixels.fractions.every((fraction) => fraction < 0.005),
            `${file}: retired copper/oatmeal visible (${pixels.fractions})`);
        console.log(`PASS ${file}: paired tags, HTTP 200 JPEG, fingerprint, 1200x630, no retired palette`);
        count++;
    }
    assert.equal(count, 28, '26 existing previews plus About and Contact');
    const oldFiles = execFileSync('git', ['ls-files', '*og.jpg'], { cwd: root, encoding: 'utf8' })
        .trim().split('\n').filter(Boolean);
    for (const file of oldFiles) {
        await assert.rejects(fs.access(path.join(root, file)), { code: 'ENOENT' }, `${file}: remove old copper JPEG`);
    }
    await page.close();
    console.log(`All ${count} social previews passed.`);
}

async function capturePreviews(context, baseUrl) {
    const updates = [];
    for (const file of htmlFiles) {
        const html = await fs.readFile(path.join(root, file), 'utf8');
        const url = [...html.matchAll(imageTag)].map((match) => match[1])
            .find((url) => url.startsWith(origin + '/') && /\/og[^/]*\.jpg$/.test(url));
        if (!url) continue;
        const page = await context.newPage();
        try {
            const response = await page.goto(baseUrl + '/' + file, { waitUntil: 'load' });
            assert.equal(response.status(), 200, file);
            await page.evaluate(async () => {
                await document.fonts.ready;
                await Promise.all([...document.images].filter((image) => {
                    const rect = image.getBoundingClientRect();
                    return rect.top < innerHeight && rect.bottom > 0;
                }).map((image) => image.decode()));
            });
            const palette = await page.evaluate(() => {
                const css = getComputedStyle(document.documentElement);
                return [css.getPropertyValue('--brand').trim(), css.getPropertyValue('--surface').trim()];
            });
            assert.deepEqual(palette, ['#215239', '#fcfdfc'], `${file}: capture Palette P2`);
            const bytes = await page.screenshot({ type: 'jpeg', quality: 90, animations: 'disabled' });
            const hash = crypto.createHash('sha256').update(bytes).digest('hex').slice(0, 12);
            const stem = file === 'about.html' ? 'og-about' : file === 'contact.html' ? 'og-contact' : 'og';
            const nextUrl = new URL(`${stem}-${hash}.jpg`, url).href;
            const nextHtml = html.replace(imageTag, (tag, image) => image === url ? tag.replace(url, nextUrl) : tag);
            updates.push({ file, html: nextHtml, url, nextUrl, bytes });
            console.log(`Captured ${file} → ${new URL(nextUrl).pathname}`);
        } finally {
            await page.close();
        }
    }
    // Finish every render before changing the committed pages or removing any old image.
    for (const update of updates) {
        const nextPath = path.join(root, new URL(update.nextUrl).pathname);
        await fs.mkdir(path.dirname(nextPath), { recursive: true });
        await fs.writeFile(nextPath, update.bytes);
        await fs.writeFile(path.join(root, update.file), update.html);
        if (update.url !== update.nextUrl) await fs.rm(path.join(root, new URL(update.url).pathname), { force: true });
    }
}

async function main() {
    const { server, baseUrl } = await previewServer();
    let browser;
    try {
        browser = await chromium.launch();
        const context = await browser.newContext({
            viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1,
            colorScheme: 'light', reducedMotion: 'reduce',
        });
        const capture = process.argv.includes('--capture');
        // Load real webfonts for captures; analytics, widgets and opt-in runtimes are unnecessary.
        const fontHosts = new Set(['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net']);
        await context.route((url) => url.origin !== baseUrl && !(capture && fontHosts.has(url.hostname)),
            (route) => route.abort());
        if (capture) await capturePreviews(context, baseUrl);
        await checkPreviews(context, baseUrl);
    } finally {
        if (browser) await browser.close();
        await new Promise((resolve) => server.close(resolve));
    }
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
