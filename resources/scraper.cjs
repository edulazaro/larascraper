const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const fs = require('fs');
const path = require('path');
const os = require('os');


puppeteer.use(StealthPlugin());

// Resolve a Chrome binary to launch. Puppeteer pins ONE exact build and aborts
// with "Could not find Chrome (ver. X)" when the cached binary is a different
// version, which happens on every puppeteer bump, since the cached Chrome is
// installed separately and does not update in lockstep. To stay resilient across
// that drift, point puppeteer at whatever Chrome is actually present in its cache,
// choosing the NEWEST version. An explicit PUPPETEER_EXECUTABLE_PATH always wins;
// if nothing is found we return undefined and let puppeteer resolve on its own.
function resolveChromePath() {
    if (process.env.PUPPETEER_EXECUTABLE_PATH) {
        return process.env.PUPPETEER_EXECUTABLE_PATH;
    }

    const cacheDir = process.env.PUPPETEER_CACHE_DIR
        || path.join(os.homedir(), '.cache', 'puppeteer');
    const chromeDir = path.join(cacheDir, 'chrome');

    let entries;
    try {
        entries = fs.readdirSync(chromeDir);
    } catch (e) {
        return undefined;
    }

    // Folder names look like "linux-148.0.7778.97" → sort by numeric version desc.
    const candidates = entries
        .map(name => {
            const m = name.match(/-(\d+(?:\.\d+)*)$/);
            return { name, version: m ? m[1].split('.').map(Number) : [0] };
        })
        .sort((a, b) => {
            const len = Math.max(a.version.length, b.version.length);
            for (let i = 0; i < len; i++) {
                const d = (b.version[i] || 0) - (a.version[i] || 0);
                if (d !== 0) return d;
            }
            return 0;
        });

    for (const { name } of candidates) {
        for (const rel of [['chrome-linux64', 'chrome'], ['chrome-linux', 'chrome']]) {
            const p = path.join(chromeDir, name, ...rel);
            if (fs.existsSync(p)) return p;
        }
    }

    return undefined;
}

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
const userAgent = args.ua || null;

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

// Buffered file-like responses seen during the run, so a plain click/navigation
// to a PDF (not only a form submit) can be captured. Filled by the response
// recorder, consumed by the capture() action.
const seenResponses = [];

// Is this content-type likely a downloadable file (vs a page resource)?
function isFileLike(contentType) {
    const ct = (contentType || '').toLowerCase();
    if (!ct) return true; // no content-type: could be a file
    return !(ct.includes('text/html') || ct.includes('text/css')
        || ct.includes('javascript') || ct.includes('image/')
        || ct.includes('font') || ct.includes('text/plain'));
}

// Does the action tree contain a capture action anywhere? Only then do we arm
// the response recorder, to avoid buffering bytes we do not need.
function hasCaptureAction(list) {
    for (const a of (list || [])) {
        if (a.type === 'capture') return true;
        if (a.type === 'when' && (hasCaptureAction(a.then) || hasCaptureAction(a.else))) return true;
        if (a.type === 'repeatUntil' && hasCaptureAction(a.body)) return true;
    }
    return false;
}

// Buffer file-like responses so capture() can grab the binary of whatever the
// preceding click/navigation triggered (not just a form submit).
function armResponseRecorder(page) {
    page.on('response', async (resp) => {
        if (!isFileLike(resp.headers()['content-type'] || '')) return;
        try {
            const buf = await resp.buffer(); // may throw for aborted/forced-download responses
            if (buf && buf.length > 0) {
                seenResponses.push({ contentType: resp.headers()['content-type'] || '', buffer: buf });
            }
        } catch (e) { /* aborted / non-bufferable (e.g. a forced download): ignore */ }
    });
}

// Newest buffered response matching `expect` (a content-type substring; PDFs
// also match by %PDF magic bytes). Null expect matches any file.
function pickCaptured(expect) {
    for (let i = seenResponses.length - 1; i >= 0; i--) {
        const r = seenResponses[i];
        const ct = (r.contentType || '').toLowerCase();
        const magic = String.fromCharCode(...new Uint8Array(r.buffer.slice(0, 4)));
        const want = String(expect || '').toLowerCase();

        // For PDFs the BYTES decide, never the content-type. When the browser
        // navigates to a PDF it renders it in the built-in viewer, and the
        // navigation response still carries `Content-Type: application/pdf`
        // while its body is the viewer's HTML wrapper (a few hundred bytes of
        // <!doctype html> around a closed shadow root). Trusting the header
        // there hands back that wrapper as if it were the document — silently,
        // which is worse than failing.
        const ok = want
            ? (want.includes('pdf') ? magic === '%PDF' : ct.includes(want))
            : true;

        if (ok) return r;
    }
    return null;
}

/**
 * Read the bytes of whatever the page is currently showing, from inside the
 * page. Used as a last resort by capture(): when a navigation lands directly on
 * a file, the browser may keep the body for its own viewer and never expose it
 * to the response recorder. An in-page fetch of the same URL re-requests it with
 * the session cookies already set and returns the real bytes.
 */
async function fetchCurrentUrl(page) {
    try {
        const result = await page.evaluate(async () => {
            const resp = await fetch(location.href, { credentials: 'include' });
            const contentType = resp.headers.get('content-type') || '';
            const bytes = new Uint8Array(await resp.arrayBuffer());
            let bin = '';
            for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
            return { contentType, base64: btoa(bin) };
        });

        if (result && result.base64) {
            seenResponses.push({
                contentType: result.contentType || '',
                buffer: Buffer.from(result.base64, 'base64'),
            });
            return true;
        }
    } catch (e) { /* la página puede no permitir fetch (about:blank, cross-origin) */ }

    return false;
}

/**
 * Capture the binary of the response triggered by the preceding action (a click,
 * navigation or submit that leads to a PDF/file). Unlike submitAndCapture it needs
 * no <form>: it reads from the response recorder. Waits (bounded) for a matching
 * response, since the network is async.
 */
async function captureResponse(page, action, timeout) {
    const deadline = Date.now() + (action.timeout || timeout);
    let match = null;
    while (Date.now() < deadline) {
        match = pickCaptured(action.expect);
        if (match) break;
        await new Promise(r => setTimeout(r, 100));
    }

    // Nada válido en el grabador: puede que el navegador esté PLANTADO sobre el
    // fichero (navegó a un PDF y se lo quedó su visor). Se pide otra vez desde
    // dentro de la página, que sí devuelve los bytes.
    if (!match && await fetchCurrentUrl(page)) {
        match = pickCaptured(action.expect);
    }

    if (!match) return false;
    capture.done = true;
    capture.file = Buffer.from(match.buffer).toString('base64');
    capture.contentType = match.contentType || action.expect || null;
    return true;
}

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
 * Submit a form in-page (via fetch) and record the response, so a following
 * capture() picks it up. The composable replacement for submitAndCapture():
 * `->submit('form')->capture(['expect' => 'application/pdf'])`.
 */
async function submitForm(page, action) {
    // NATIVE: ask the form to submit itself and get out of the way.
    //
    // The default path below builds the request by hand, which is fine for a
    // static form but wrong for a page that submits over AJAX: the response
    // lands in a variable of ours, the page never learns it arrived, and nothing
    // is painted. It also puts a request on the wire that the site's own code did
    // not make — missing the headers its JavaScript adds, out of the sequence it
    // always follows — which on a site that watches for that is a signature.
    //
    // requestSubmit() sends no request at all. It fires the form's submit event,
    // the page's own handler wakes up, and the page does everything the way it
    // normally does: right headers, right order, and it renders the answer where
    // it belongs. Which is what makes a plain waitForSelector work afterwards.
    if (action.native) {
        return page.evaluate((formSel) => {
            const form = document.querySelector(formSel);
            if (!form) return false;

            // requestSubmit fires the event; submit() would bypass every handler
            // and navigate, which is the opposite of the point.
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }

            return true;
        }, action.formSelector);
    }

    const result = await page.evaluate(async (formSel, into) => {
        const form = document.querySelector(formSel);
        if (!form) return null;

        const body = new URLSearchParams();

        for (const el of form.querySelectorAll('input[name], select[name], textarea[name]')) {
            // Send what a browser would send, and nothing else.
            //
            // ⚠️ AN UNCHECKED BOX USED TO BE SENT ANYWAY. Every element's `value`
            // went into the body regardless of its state, so a checkbox nobody
            // touched still submitted its value — silently turning on filters the
            // scraper never asked for. On a real search form that is not a cosmetic
            // bug: a stray "only plenary sessions" flag narrows a result set to
            // almost nothing, and the search still looks like it worked.
            if (el.disabled) continue;
            if (el.type === 'submit' || el.type === 'button' || el.type === 'reset') continue;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;

            // A <select multiple> contributes one entry per selected option, so
            // append rather than set; set() would keep only the last one.
            if (el.multiple && el.selectedOptions) {
                for (const opt of el.selectedOptions) body.append(el.name, opt.value);
                continue;
            }

            body.append(el.name, el.value);
        }

        const method = (form.getAttribute('method') || 'get').toUpperCase();
        const dest = new URL(form.getAttribute('action') || location.href, location.href).href;

        const resp = method === 'GET'
            ? await fetch(dest + (dest.includes('?') ? '&' : '?') + body.toString())
            : await fetch(dest, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });

        const contentType = resp.headers.get('content-type') || '';
        const bytes = new Uint8Array(await resp.arrayBuffer());
        let bin = '';
        for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);

        // WITHOUT `into`, THE PAGE NEVER LEARNS WHAT CAME BACK. The response is
        // fetched here, so the DOM is untouched: crawlers parse the page and see
        // the form they started from, waits time out on selectors that will never
        // appear, and a submit that worked perfectly reads as a submit that did
        // nothing. Handing a container selector puts the answer where the rest of
        // the chain is already looking.
        let rendered = false;

        if (into && /html|xml/i.test(contentType)) {
            const target = document.querySelector(into);
            if (target) {
                target.innerHTML = new TextDecoder('utf-8').decode(bytes);
                rendered = true;
            }
        }

        return { contentType, base64: btoa(bin), rendered };
    }, action.formSelector, action.into ?? null);

    if (result && result.base64) {
        seenResponses.push({ contentType: result.contentType || '', buffer: Buffer.from(result.base64, 'base64') });
    }
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
        throw Object.assign(new Error(
            'solveCaptcha needs the OCR packages. Install them with: ' +
            'php artisan larascraper:install --captcha (or npm install tesseract.js jimp)'
        ), { fatal: true });
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
 * Read a captcha image buffer with an OpenAI vision model instead of tesseract.
 * Distorted captchas that trip up OCR are usually read in a single attempt by a
 * vision model, at the cost of an OpenAI API call per solve. Uses node's global
 * fetch, so no extra packages are required.
 *
 * The API key comes from action.options.apiKey or the OPENAI_API_KEY env var;
 * absent either, it throws a clear error. The model defaults to 'gpt-4o-mini'.
 */
async function visionCaptcha(pngBuffer, options) {
    const apiKey = options.apiKey || process.env.OPENAI_API_KEY;
    if (!apiKey) {
        throw Object.assign(new Error(
            'vision captcha solver needs an OpenAI API key via options apiKey or OPENAI_API_KEY'
        ), { fatal: true });
    }

    const model = options.model || 'gpt-4o-mini';
    const b64 = Buffer.from(pngBuffer).toString('base64');

    const resp = await fetch('https://api.openai.com/v1/chat/completions', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${apiKey}`,
        },
        body: JSON.stringify({
            model: model,
            max_tokens: 20,
            temperature: 0,
            messages: [{
                role: 'user',
                content: [
                    {
                        type: 'text',
                        text: 'This image is a captcha. Reply with ONLY the exact characters shown, no spaces, no punctuation, no explanation.',
                    },
                    {
                        type: 'image_url',
                        image_url: { url: 'data:image/png;base64,' + b64 },
                    },
                ],
            }],
        }),
    });

    if (!resp.ok) {
        // A rate limit (429) or a server error (5xx) is transient: return an
        // empty answer so a surrounding repeatUntil() advances and tries the
        // captcha again, instead of a thrown error aborting the whole scrape. A
        // 4xx (bad key, bad request, unknown model) is a real misconfiguration
        // that retrying cannot fix, so surface it as a FATAL error that a
        // surrounding repeatUntil() will not swallow or retry.
        if (resp.status === 429 || resp.status >= 500) {
            return '';
        }
        const body = await resp.text().catch(() => '');
        throw Object.assign(
            new Error(`vision captcha solver: OpenAI API returned HTTP ${resp.status} ${body}`.trim()),
            { fatal: true }
        );
    }

    const data = await resp.json().catch(() => null);
    const text = data && data.choices && data.choices[0]
        && data.choices[0].message && data.choices[0].message.content;
    // A missing answer (an odd response shape, a model refusal, or a captcha the
    // model could not read) is a soft failure: return '' so repeatUntil() retries
    // with a fresh captcha rather than aborting the whole scrape.
    if (typeof text !== 'string') {
        return '';
    }

    let answer = text.trim();
    // Strip whitespace always; strip non-alphanumerics unless the caller opts out
    // with options.strip === false (some captchas include punctuation).
    answer = answer.replace(/\s+/g, '');
    if (options.strip !== false) {
        answer = answer.replace(/[^a-z0-9]/gi, '');
    }
    return answer;
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
            throw Object.assign(new Error(`Unknown condition type: ${condition.type}`), { fatal: true });
    }
}

/**
 * Solve a captcha: screenshot the image, read it, and type the answer into the
 * input. Dispatches by solver: 'ocr' (default, tesseract) or 'vision' (OpenAI
 * vision model). Both share the same screenshot + type-in flow; they differ only
 * in how the image bytes become text. Unknown solvers throw.
 */
async function solveCaptcha(page, action, timeout) {
    await page.waitForSelector(action.imageSelector, { timeout });
    const el = await page.$(action.imageSelector);
    if (!el) throw new Error(`Captcha image not found: ${action.imageSelector}`);

    const png = await el.screenshot();
    const options = action.options || {};

    let answer;
    if (action.solver === 'vision') {
        answer = await visionCaptcha(png, options);
    } else if (action.solver === 'ocr') {
        answer = await ocrCaptcha(png, options);
    } else {
        throw Object.assign(new Error(`Unsupported captcha solver: ${action.solver}`), { fatal: true });
    }

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
                // hammer a server. `delay` throttles between iterations. A throw
                // inside one iteration counts as a failed attempt (not fatal):
                // the loop re-evaluates the condition and retries on the next
                // pass, bounded by `max`, instead of aborting the whole run on a
                // transient bad page. If every attempt is exhausted without the
                // condition ever holding and the last attempt errored, that
                // error is surfaced rather than failing silently.
                const max = Math.max(1, action.max ?? 5);
                const delay = Math.max(0, action.delay ?? 0);
                let lastError = null;
                for (let i = 0; i < max; i++) {
                    if (await evaluateCondition(page, action.condition)) { lastError = null; break; }
                    try {
                        await runActions(page, action.body, timeout);
                        lastError = null;
                    } catch (e) {
                        // A fatal error is a caller/config mistake (unknown solver
                        // or condition type, a 4xx from the captcha solver) that
                        // retrying cannot fix: abort immediately rather than burn
                        // `max` attempts or swallow it. Anything else is a
                        // transient page failure: count it as one failed attempt
                        // and let the loop retry on the next pass.
                        if (e && e.fatal) throw e;
                        lastError = e;
                    }
                    if (delay > 0 && i < max - 1) {
                        await new Promise(resolve => setTimeout(resolve, delay));
                    }
                }
                // Surface the last transient error only if every attempt was
                // exhausted without the condition ever holding. The final check
                // can itself throw (e.g. an execution context torn down by an
                // in-flight navigation); if so, fall back to the real lastError.
                if (lastError) {
                    let met = false;
                    try { met = await evaluateCondition(page, action.condition); }
                    catch (e) { met = false; }
                    if (!met) throw lastError;
                }
                break;
            }
            case 'solveCaptcha':
                await solveCaptcha(page, action, timeout);
                break;
            case 'submitAndCapture':
                await submitAndCapture(page, action);
                break;
            case 'submit':
                await submitForm(page, action);
                break;
            case 'capture':
                await captureResponse(page, action, timeout);
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
            case 'select': {
                await page.waitForSelector(action.selector, { timeout });
                const values = Array.isArray(action.value) ? action.value : [action.value];
                // Skip an empty list: page.select(selector) with zero values
                // deselects every option, which is never what select([]) means.
                if (values.length > 0) {
                    await page.select(action.selector, ...values);
                }
                break;
            }
            case 'setValue':
                await page.waitForSelector(action.selector, { timeout });
                await page.$eval(action.selector, (el, v) => {
                    el.value = v;
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }, action.value ?? '');
                break;
            case 'check':
            case 'uncheck': {
                // Tick/untick checkboxes by setting .checked and firing a
                // bubbling 'change'; reaches widget-backed checkboxes (e.g.
                // bootstrap-multiselect) that are hidden in a collapsed dropdown,
                // where a native click() can't. querySelectorAll handles multiple
                // matches; a no-match is a silent no-op.
                const want = action.type === 'check';
                await page.evaluate((sel, wantChecked) => {
                    document.querySelectorAll(sel).forEach(cb => {
                        if (cb.checked !== wantChecked) {
                            cb.checked = wantChecked;
                            cb.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });
                }, action.selector, want);
                break;
            }
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
            case 'waitForSelector': {
                // A per-action `timeout` overrides the global one; a falsy value
                // (0 or unset) falls back to the global, so `timeout: 0` can
                // never become Puppeteer's "wait forever". `optional` makes a
                // genuine TIMEOUT a valid outcome (the element may legitimately
                // never appear, e.g. an empty result set) so the run continues;
                // any other error (an invalid selector, etc.) still surfaces
                // instead of being silently swallowed.
                const waitOpts = { timeout: action.timeout || timeout };
                if (action.optional) {
                    try {
                        await page.waitForSelector(action.selector, waitOpts);
                    } catch (e) {
                        if (e && e.name !== 'TimeoutError') throw e;
                    }
                } else {
                    await page.waitForSelector(action.selector, waitOpts);
                }
                break;
            }
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
                await page.goto(dest, { waitUntil: action.waitUntil || 'networkidle2', timeout });
                break;
            }
            case 'reload':
                await page.reload({ waitUntil: action.waitUntil || 'networkidle2', timeout });
                break;
            case 'goto': {
                const dest = new URL(action.url, page.url()).href;
                await page.goto(dest, { waitUntil: action.waitUntil || 'networkidle2', timeout });
                break;
            }
            default:
                throw Object.assign(new Error(`Unknown action type: ${action.type}`), { fatal: true });
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

        const launchOptions = { headless: 'new', args: launchArgs };
        const chromePath = resolveChromePath();
        if (chromePath) launchOptions.executablePath = chromePath;

        browser = await puppeteer.launch(launchOptions);
        const page = await browser.newPage();

        // WHAT THE PAGE DID BEHIND OUR BACK. A scraper that drives a page can only
        // see the DOM it ends up with, and a DOM that never changed looks exactly
        // like a DOM whose XHR was never sent, was refused, or threw on the way.
        // Telling those apart from the outside is guesswork; from in here it is
        // three event handlers.
        //
        // Only the interesting few are kept: XHR/fetch (the calls a modern page
        // makes to do its actual work), requests that failed outright, and errors
        // the page's own JavaScript raised. Images, css and fonts are noise.
        const diagnostics = { xhr: [], failed: [], errors: [] };
        const CAP = 40;

        const note = (bucket, entry) => {
            if (bucket.length < CAP) bucket.push(entry);
        };

        page.on('response', (res) => {
            const type = res.request().resourceType();
            if (type === 'xhr' || type === 'fetch') {
                note(diagnostics.xhr, `${res.status()} ${res.request().method()} ${res.url()}`);
            }
        });

        page.on('requestfailed', (req) => {
            note(diagnostics.failed, `${req.method()} ${req.url()} — ${req.failure()?.errorText ?? 'failed'}`);
        });

        // pageerror is an uncaught exception in the page's own code. One of these
        // early in the load can leave every widget on the page unbound, which
        // looks like "the site ignored us" and is nothing of the sort.
        page.on('pageerror', (err) => {
            note(diagnostics.errors, String(err?.message ?? err).slice(0, 300));
        });

        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                note(diagnostics.errors, `console: ${msg.text()}`.slice(0, 300));
            }
        });

        if (proxyUser && proxyPass) {
            await page.authenticate({ username: proxyUser, password: proxyPass });
        }

        // WHO WE SAY WE ARE IS ASKED, NOT INVENTED. This used to be a string typed
        // by hand — 'Chrome/110 on Windows' — and it rotted: the browser actually
        // launching here is Chrome 148 on Linux. That mismatch is worse than an
        // honest headless UA, because Client Hints (Sec-CH-UA, navigator
        // .userAgentData) are filled in by the real Chrome and cannot be talked
        // out of it. A browser claiming one version while emitting another is
        // contradicting itself, and a real one never does.
        //
        // So we ask the browser we just launched and change the single word that
        // gives it away. Version and platform stay true, which is the whole point.
        // The build number needs no faking either: Chrome froze it at 0.0.0 years
        // ago, so 'Chrome/148.0.0.0' is character-for-character what a real one
        // sends. Costs one CDP round-trip, ~0.3ms against a ~220ms launch.
        const realUserAgent = (await browser.userAgent()).replace('HeadlessChrome', 'Chrome');

        // A User-Agent among the headers is ROUTED HERE rather than left to
        // setExtraHTTPHeaders, which would drop it on the floor: once
        // setUserAgent runs, the CDP override outranks any extra header, and even
        // without it the stealth plugin sets one that outranks it too. So a
        // caller writing the header by hand would have seen it silently ignored —
        // on the http driver the same header works, and a difference like that
        // between drivers is a trap, not a feature.
        //
        // It also has to go through setUserAgent to be worth honouring at all:
        // that is the call that moves BOTH the wire header and
        // navigator.userAgent. A change to only one of them leaves the browser
        // disagreeing with itself, which is the tell this whole block exists to
        // avoid.
        const headerKey = Object.keys(headers).find(k => k.toLowerCase() === 'user-agent');
        const headerUserAgent = headerKey ? headers[headerKey] : null;

        // Same precedence as the http driver: an explicit header outranks --ua,
        // because writing one by hand is the more deliberate act of the two.
        await page.setUserAgent(headerUserAgent || userAgent || realUserAgent);

        // Dropped from the extra headers now that it has been applied properly.
        // Leaving it would be dead weight at best and, if Chrome ever changed
        // which one wins, a second source of truth for the same fact.
        if (headerKey) {
            delete headers[headerKey];
        }

        if (Object.keys(headers).length > 0) {
            await page.setExtraHTTPHeaders(headers);
        }

        // Arm the response recorder before navigating, so capture() can grab a
        // file the initial load or a later click/navigation triggers.
        if (hasCaptureAction(actions)) {
            armResponseRecorder(page);
        }

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
            // Only when there is something to say, so a healthy run stays quiet.
            const seen = Object.fromEntries(
                Object.entries(diagnostics).filter(([, v]) => v.length > 0)
            );
            if (Object.keys(seen).length > 0) {
                out.diagnostics = seen;
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
