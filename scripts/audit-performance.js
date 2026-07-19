import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';

const SITE_URL = process.argv[2] || 'http://localhost:4321';
const SCREENSHOT_DIR = process.argv[3] || './dist/audit-screenshots';

async function runAudit() {
  console.log(`Starting automated performance and visual audit for: ${SITE_URL}`);
  
  if (!fs.existsSync(SCREENSHOT_DIR)) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  }

  const browser = await puppeteer.launch({ headless: true });
  const page = await browser.newPage();

  // Capture console errors
  const consoleErrors = [];
  page.on('console', msg => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text());
    }
  });

  // Capture failed requests
  const failedRequests = [];
  page.on('requestfailed', request => {
    failedRequests.push(`${request.url()} - ${request.failure().errorText}`);
  });

  try {
    // 1. Audit Desktop version
    await page.setViewport({ width: 1280, height: 800 });
    const startTime = Date.now();
    await page.goto(SITE_URL, { waitUntil: 'networkidle0' });
    const loadTime = Date.now() - startTime;

    // Capture Performance Timing
    const performanceTiming = await page.evaluate(() => {
      const t = window.performance.timing;
      return {
        dnsLookup: t.domainLookupEnd - t.domainLookupStart,
        tcpConnect: t.connectEnd - t.connectStart,
        ttfb: t.responseStart - t.requestStart,
        domInteractive: t.domInteractive - t.navigationStart,
        domComplete: t.domComplete - t.navigationStart,
      };
    });

    const desktopScreenshotPath = path.join(SCREENSHOT_DIR, 'desktop-home.png');
    await page.screenshot({ path: desktopScreenshotPath, fullPage: true });

    // 2. Audit Mobile version
    await page.setViewport({ width: 375, height: 667, isMobile: true, hasTouch: true });
    await page.reload({ waitUntil: 'networkidle0' });
    const mobileScreenshotPath = path.join(SCREENSHOT_DIR, 'mobile-home.png');
    await page.screenshot({ path: mobileScreenshotPath, fullPage: true });

    // 3. Generate Markdown Report
    const reportPath = path.join(SCREENSHOT_DIR, 'audit-report.md');
    const reportContent = `# Automated Web Audit Report
Generated on: ${new Date().toISOString()}
Target URL: ${SITE_URL}

## 📊 Loading Performance
- **Full Load Time (networkidle0):** ${loadTime} ms
- **Time to First Byte (TTFB):** ${performanceTiming.ttfb} ms
- **DNS Lookup Time:** ${performanceTiming.dnsLookup} ms
- **TCP Connection Time:** ${performanceTiming.tcpConnect} ms
- **DOM Interactive:** ${performanceTiming.domInteractive} ms
- **DOM Complete:** ${performanceTiming.domComplete} ms

## 🚨 Errors & Logs
- **Console Errors Detected:** ${consoleErrors.length}
${consoleErrors.map(err => `  - \`ERROR:\` ${err}`).join('\n') || '  - None'}

- **Failed Network Requests:** ${failedRequests.length}
${failedRequests.map(req => `  - \`FAILED:\` ${req}`).join('\n') || '  - None'}

## 📸 Screenshots Saved
- **Desktop View:** [desktop-home.png](file:///${path.resolve(desktopScreenshotPath).replace(/\\/g, '/')})
- **Mobile View:** [mobile-home.png](file:///${path.resolve(mobileScreenshotPath).replace(/\\/g, '/')})
`;

    fs.writeFileSync(reportPath, reportContent);
    console.log(`Audit complete! Report saved to: ${path.resolve(reportPath)}`);

  } catch (error) {
    console.error('Audit failed with error:', error);
  } finally {
    await browser.close();
  }
}

runAudit();
