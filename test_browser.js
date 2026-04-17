const puppeteer = require('puppeteer');
(async () => {
  const browser = await puppeteer.launch();
  const page = await browser.newPage();
  page.on('console', msg => console.log('BROWSER_LOG:', msg.text()));
  page.on('pageerror', err => console.log('BROWSER_ERR:', err.toString()));
  await page.goto('http://localhost/recantodaserra/admin/login.html');
  await page.evaluate(() => localStorage.setItem('adminToken', '1234'));
  await page.goto('http://localhost/recantodaserra/admin/index.html', { waitUntil: 'networkidle0' });
  
  const content = await page.evaluate(() => document.getElementById('app').innerHTML);
  console.log('APP_INNER_HTML_LENGTH:', content.length);
  
  await browser.close();
})();
