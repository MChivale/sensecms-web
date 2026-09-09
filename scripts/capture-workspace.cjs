// Build the public product image from the real isolated QA interface, never production data.
const { chromium } = require('playwright');
const path = require('node:path');
(async () => {
  const browser = await chromium.launch({headless: true, channel: 'chrome'});
  try {
    const page = await browser.newPage({viewport: {width: 1440, height: 940}, deviceScaleFactor: 1});
    const response = await page.goto('http://127.0.0.1:8875/dashboard');
    if (response.status() !== 200) throw new Error('QA unavailable');
    await page.getByRole('heading', {name: 'Good morning, Sense.', exact: true}).waitFor();
    await page.evaluate(() => document.fonts.ready);
    const text = await page.locator('body').innerText();
    if (/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i.test(text)) throw new Error('Private contact data in capture');
    await page.screenshot({path: path.resolve(__dirname, '../.themes/sensecms/assets/workspace.png'), fullPage: false});
    console.log('Captured actual QA workspace; example data, no contact details.');
  } finally { await browser.close(); }
})().catch(error => {console.error(error.message); process.exitCode = 1;});
