const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');


puppeteer.use(StealthPlugin());

const args = Object.fromEntries(
    process.argv.slice(2).map(arg => {
        const [key, val] = arg.replace(/^--/, '').split('=');
        return [key, val];
    })
);

const url = args.url;
const proxy = args.proxy;
const proxyUser = args.user;
const proxyPass = args.pass;
const timeout = parseInt(args.timeout ?? '20000', 10);

let headers = {};

try {
    headers = args.headers ? JSON.parse(args.headers) : {};
} catch (e) {
    console.error(JSON.stringify({ error: 'Invalid headers JSON' }));
    process.exit(1);
}

(async () => {

    let browser;

    try {
        const launchArgs = [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-infobars',
            '--disable-blink-features=AutomationControlled'
        ];

        if (proxy) launchArgs.push(`--proxy-server=${proxy}`);

        browser = await puppeteer.launch({ headless: 'new', args: launchArgs });
        const page = await browser.newPage();

        if (proxyUser && proxyPass) {
            await page.authenticate({ username: proxyUser, password: proxyPass });
        }

        if (Object.keys(headers).length > 0) {
            await page.setExtraHTTPHeaders(headers);
        }

        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
            '(KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36'
        );

        const response = await page.goto(url, {
            waitUntil: 'domcontentloaded',
            timeout: timeout,
        });

        const status = response?.status?.() ?? 0;

        if (status >= 400) {
            console.log(JSON.stringify({
                success: false,
                status: status,
                error: `HTTP error: ${status}`
            }));
        } else {
            const content = await page.content();
            console.log(JSON.stringify({
                success: true,
                status: status,
                html: content
            }));
        }
        await browser.close();
    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            status: 500,
            error: error.message
        }));

        await browser.close();
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();
