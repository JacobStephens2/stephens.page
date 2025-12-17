// Script to capture screenshots of portfolio projects
// Run with: npx playwright install chromium && node capture_screenshots.js

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const screenshotsDir = path.join(__dirname, '..', 'screenshots');
if (!fs.existsSync(screenshotsDir)) {
    fs.mkdirSync(screenshotsDir, { recursive: true });
}

const projects = [
    { url: 'file:///var/www/resume.jacobstephens.net/public/index.html', filename: 'resume.jacobstephens.net.png' },
    { url: 'https://wedding.stephens.page', filename: 'wedding.stephens.page.png' },
    { url: 'https://dailydozen.jacobstephens.net/', filename: 'dailydozen.jacobstephens.net.png' },
    { url: 'https://wadadli.stephens.page', filename: 'wadadli.stephens.page.png' },
    { url: 'https://chestercounty-life.com/', filename: 'chestercounty-life.com.png' },
    { url: 'https://artifact.stewardgoods.com/', filename: 'artifact.stewardgoods.com.png' },
];

async function captureScreenshots() {
    const browser = await chromium.launch({
        ignoreHTTPSErrors: true
    });
    
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        viewport: { width: 1280, height: 720 }
    });
    
    for (const project of projects) {
        const page = await context.newPage();
        try {
            console.log(`Capturing ${project.filename}...`);
            await page.goto(project.url, { 
                waitUntil: 'domcontentloaded', 
                timeout: 30000 
            });
            await page.waitForTimeout(3000); // Wait for any animations/loads
            const screenshotPath = path.join(screenshotsDir, project.filename);
            await page.screenshot({ path: screenshotPath, fullPage: false });
            console.log(`✓ Saved ${project.filename}`);
        } catch (error) {
            console.error(`✗ Failed to capture ${project.filename}: ${error.message}`);
        } finally {
            await page.close();
        }
    }
    
    await browser.close();
    console.log('Screenshot capture complete!');
}

captureScreenshots().catch(console.error);

