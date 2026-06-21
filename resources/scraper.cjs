const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');


puppeteer.use(StealthPlugin());

const args = Object.fromEntries(
    process.argv.slice(2).map(arg => {
        // Split on the FIRST '=' only; values (selectors like [name=x], JSON,
        // headers) can contain '=' and must not be truncated.
        const cleaned = arg.replace(/^--/, '');
        const i = cleaned.indexOf('=');
        return i === -1 ? [cleaned, ''] : [cleaned.slice(0, i), cleaned.slice(i + 1)];
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

// Lazily-created tesseract worker, reused across solveCaptcha calls and
// terminated in the final cleanup. Null until the first OCR captcha runs.
let captchaWorker = null;

// Holds a captured file/binary (set by submitAndCapture). `done` flips the
// Condition::captured() check; `file` is base64, returned to PHP as $result->file.
const capture = { done: false, file: null, contentType: null };

/**
 * Submit a form in-page (via fetch) and capture the response if it looks like a
 * file/binary. Returns true when captured. Mirrors how a browser submits the
 * form, but reads the raw bytes so a PDF (etc.) can be returned to PHP.
 */
async function submitAndCapture(page, action) {
    const result = await page.evaluate(async (formSel, expect) => {
        const form = document.querySelector(formSel);
        if (!form) return { ok: false };

        const body = new URLSearchParams();
        for (const el of form.querySelectorAll('input[name], select[name], textarea[name]')) {
            if (el.type === 'submit') continue;
            body.set(el.name, el.value);
        }

        const method = (form.getAttribute('method') || 'get').toUpperCase();
        const action = new URL(form.getAttribute('action') || location.href, location.href).href;

        const resp = method === 'GET'
            ? await fetch(action + (action.includes('?') ? '&' : '?') + body.toString())
            : await fetch(action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });

        const contentType = resp.headers.get('content-type') || '';
        const bytes = new Uint8Array(await resp.arrayBuffer());

        // Decide whether this is the file we wanted: match the expected
        // content-type, and (for PDFs) accept the %PDF magic bytes too.
        const magic = String.fromCharCode(...bytes.slice(0, 4));
        const isExpected = expect
            ? (contentType.includes(expect) || (expect.includes('pdf') && magic === '%PDF'))
            : !contentType.includes('text/html');

        if (!isExpected) return { ok: false, contentType };

        let bin = '';
        for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return { ok: true, contentType, base64: btoa(bin) };
    }, action.formSelector, action.expect);

    if (result && result.ok) {
        capture.done = true;
        capture.file = result.base64;
        capture.contentType = result.contentType || (action.expect ?? null);
        return true;
    }
    return false;
}

/**
 * OCR a captcha image buffer to text. Lazy-requires the optional jimp +
 * tesseract.js packages (installed via `larascraper:install --captcha`) so they
 * are not a dependency for scrapers that never solve captchas.
 */
async function ocrCaptcha(pngBuffer, options) {
    let Jimp, createWorker;
    try {
        ({ Jimp } = require('jimp'));
        ({ createWorker } = require('tesseract.js'));
    } catch (e) {
        throw new Error(
            'solveCaptcha needs the OCR packages. Install them with: ' +
            'php artisan larascraper:install --captcha (or npm install tesseract.js jimp)'
        );
    }

    const crop = options.crop ?? 7;
    const scale = options.scale ?? 5;
    const contrast = options.contrast ?? 0.6;
    const threshold = options.threshold ?? 150;

    const img = await Jimp.read(pngBuffer);
    const w = img.bitmap.width, h = img.bitmap.height;
    if (crop > 0) {
        img.crop({ x: crop, y: crop, w: Math.max(1, w - crop * 2), h: Math.max(1, h - crop * 2) });
    }
    img.scale(scale).greyscale().contrast(contrast);
    img.scan(0, 0, img.bitmap.width, img.bitmap.height, (x, y, idx) => {
        const c = img.bitmap.data[idx] < threshold ? 0 : 255;
        img.bitmap.data[idx] = img.bitmap.data[idx + 1] = img.bitmap.data[idx + 2] = c;
    });

    if (!captchaWorker) {
        captchaWorker = await createWorker(options.lang || 'eng');
    }
    await captchaWorker.setParameters({
        tessedit_char_whitelist: options.whitelist || 'abcdefghijklmnopqrstuvwxyz0123456789',
        tessedit_pageseg_mode: String(options.psm ?? 8),
    });

    const buf = await img.getBuffer('image/png');
    const { data } = await captchaWorker.recognize(buf);
    return data.text.replace(/[^a-z0-9]/gi, '').toLowerCase();
}

/**
 * Evaluate a JS-evaluable condition (from when()/repeatUntil()) against the
 * live page. Returns a boolean. Unknown condition types throw.
 */
async function evaluateCondition(page, condition) {
    switch (condition.type) {
        case 'selectorExists':
            return (await page.$(condition.selector)) !== null;
        case 'selectorMissing':
            return (await page.$(condition.selector)) === null;
        case 'textContains':
            return page.evaluate((sel, text) => {
                const root = sel ? document.querySelector(sel) : document.body;
                return !!root && (root.innerText || '').includes(text);
            }, condition.selector ?? null, condition.text);
        case 'urlContains':
            return page.url().includes(condition.text);
        case 'captured':
            return capture.done;
        default:
            throw new Error(`Unknown condition type: ${condition.type}`);
    }
}

/**
 * Solve a captcha: screenshot the image, OCR it, and type the answer into the
 * input. Dispatches by solver (only 'ocr' today; 'vision' etc. can be added
 * here without changing the PHP API).
 */
async function solveCaptcha(page, action, timeout) {
    if (action.solver !== 'ocr') {
        throw new Error(`Unsupported captcha solver: ${action.solver}`);
    }

    await page.waitForSelector(action.imageSelector, { timeout });
    const el = await page.$(action.imageSelector);
    if (!el) throw new Error(`Captcha image not found: ${action.imageSelector}`);

    const png = await el.screenshot();
    const answer = await ocrCaptcha(png, action.options || {});

    await page.waitForSelector(action.inputSelector, { timeout });
    await page.$eval(action.inputSelector, (input) => { input.value = ''; });
    await page.type(action.inputSelector, answer);
}

/**
 * Run an ordered list of browser actions on the page. Recursive: when() and
 * repeatUntil() run nested action lists based on conditions evaluated against
 * the live page. Any failure (e.g. a selector that never appears) throws and is
 * reported back to PHP as { success: false, error }.
 */
async function runActions(page, actions, timeout) {
    for (const action of actions) {
        switch (action.type) {
            case 'when': {
                const matched = await evaluateCondition(page, action.condition);
                const branch = matched ? action.then : action.else;
                if (branch) {
                    await runActions(page, branch, timeout);
                }
                break;
            }
            case 'repeatUntil': {
                // Always bounded: never an unbounded while(true) that could
                // hammer a server. `delay` throttles between iterations.
                const max = Math.max(1, action.max ?? 5);
                const delay = Math.max(0, action.delay ?? 0);
                for (let i = 0; i < max; i++) {
                    if (await evaluateCondition(page, action.condition)) break;
                    await runActions(page, action.body, timeout);
                    if (delay > 0 && i < max - 1) {
                        await new Promise(resolve => setTimeout(resolve, delay));
                    }
                }
                break;
            }
            case 'solveCaptcha':
                await solveCaptcha(page, action, timeout);
                break;
            case 'submitAndCapture':
                await submitAndCapture(page, action);
                break;
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
            case 'gotoAttr': {
                // Read a URL from an element's attribute and navigate to it.
                const dest = await page.evaluate((sel, attr) => {
                    const el = document.querySelector(sel);
                    if (!el) return null;
                    const val = el.getAttribute(attr);
                    return val ? new URL(val, location.href).href : null;
                }, action.selector, action.attr || 'href');
                if (!dest) {
                    throw new Error(`gotoAttr: attribute "${action.attr || 'href'}" not found on "${action.selector}"`);
                }
                await page.goto(dest, { waitUntil: 'networkidle2', timeout });
                break;
            }
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
            const out = { success: true, status: status, html: content };
            // Include any captured file/binary (from submitAndCapture).
            if (capture.done) {
                out.file = capture.file;          // base64
                out.contentType = capture.contentType;
            }
            console.log(JSON.stringify(out));
        }
    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            status: 500,
            error: error.message
        }));
    } finally {
        // Terminate the OCR worker if solveCaptcha created one.
        if (captchaWorker) {
            try { await captchaWorker.terminate(); } catch (e) { /* ignore */ }
        }
        // browser is undefined if launch() itself failed, so guard the close.
        if (browser) {
            await browser.close();
        }
    }
})();
