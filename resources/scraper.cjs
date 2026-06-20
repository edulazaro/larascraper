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

let actions = [];

try {
    actions = args.actions ? JSON.parse(args.actions) : [];
} catch (e) {
    console.error(JSON.stringify({ error: 'Invalid actions JSON' }));
    process.exit(1);
}

/**
 * Run an ordered list of browser actions on the page.
 * Any failure (e.g. a selector that never appears) throws and is reported
 * back to PHP as { success: false, error }.
 */
async function runActions(page, actions, timeout) {
    for (const action of actions) {
        switch (action.type) {
            case 'click':
                await page.waitForSelector(action.selector, { timeout });
                if (action.waitForNavigation) {
                    await Promise.all([
                        page.waitForNavigation({ waitUntil: 'networkidle2', timeout }),
                        page.click(action.selector),
                    ]);
                } else {
                    await page.click(action.selector);
                }
                break;
            case 'type':
                await page.waitForSelector(action.selector, { timeout });
                await page.type(action.selector, action.text ?? '');
                break;
            case 'select':
                await page.waitForSelector(action.selector, { timeout });
                await page.select(action.selector, action.value);
                break;
            case 'hover':
                await page.waitForSelector(action.selector, { timeout });
                await page.hover(action.selector);
                break;
            case 'press':
                if (action.waitForNavigation) {
                    await Promise.all([
                        page.waitForNavigation({ waitUntil: 'networkidle2', timeout }),
                        page.keyboard.press(action.key),
                    ]);
                } else {
                    await page.keyboard.press(action.key);
                }
                break;
            case 'waitForSelector':
                await page.waitForSelector(action.selector, { timeout });
                break;
            case 'waitForNavigation':
                await page.waitForNavigation({ waitUntil: 'networkidle2', timeout });
                break;
            case 'wait':
                await new Promise(resolve => setTimeout(resolve, action.ms ?? 0));
                break;
            case 'scroll':
                await page.evaluate((to) => {
                    window.scrollTo(0, to === 'top' ? 0 : document.body.scrollHeight);
                }, action.to ?? 'bottom');
                break;
            default:
                throw new Error(`Unknown action type: ${action.type}`);
        }
    }
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
            await runActions(page, actions, timeout);
            const content = await page.content();
            console.log(JSON.stringify({
                success: true,
                status: status,
                html: content
            }));
        }
    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            status: 500,
            error: error.message
        }));
    } finally {
        // browser is undefined if launch() itself failed, so guard the close.
        if (browser) {
            await browser.close();
        }
    }
})();
