![Larascraper](art/banner.png)

# Larascraper - A Simple Scraper for Laravel

<p align="center">
    <a href="https://packagist.org/packages/edulazaro/larascraper"><img src="https://img.shields.io/packagist/dt/edulazaro/larascraper" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/larascraper"><img src="https://img.shields.io/packagist/v/edulazaro/larascraper" alt="Latest Stable Version"></a>
</p>

## Introduction

Larascrape allows you to scrape any URL using Laravel. It uses Puppeteer under the hood. Unlikely Sapatie Crawler or Browsershot, this scraper focuses on simplicity. While Spatie Crawler can leave opened many Chromium instances, filling your server memory, Larascrape starts the scraping process using Node, making sure the Chromium instance is closed before existint.

Unlikely Spatie Crawler, it supports Proxy authentication and in general is faster.

## Install

Larascraper needs **two** things: the PHP package (via Composer) and a few Node packages that the internal Puppeteer script relies on. Composer cannot install the Node packages for you, so it's a two step install.

**1. Require the package via Composer:**

```bash
composer require edulazaro/larascraper
```

**2. Install the Node dependencies.** Just run the bundled command:

```bash
php artisan larascraper:install
```

That single command installs the Node packages **and** the Chrome binary Puppeteer needs. You do **not** need to run anything else.

> **Prefer to do it by hand?** Then skip the command and run these two yourself instead (this is exactly what `larascraper:install` does for you, not an extra step):
> ```bash
> npm install puppeteer puppeteer-extra puppeteer-extra-plugin-stealth
> npx puppeteer browsers install chrome
> ```

Run `php artisan larascraper:install` **in the same environment where the scraper runs** (e.g. inside your Docker/Sail container), so Chrome lands in that environment's cache. The Chrome step matters: when `node_modules` is already present (for example mounted into a container), Puppeteer's automatic Chrome download is skipped, so the command installs it explicitly. If the Node packages are missing the scraper fails fast with a clear message rather than silently.

Use `--no-browser` if you provide your own Chrome via `PUPPETEER_EXECUTABLE_PATH`.

Please note that when you run the scraper via a scheduled task, chances are a non interactive terminal is used. Usually Node will be available, but it may not be the case when installing Node via NVM. In this scenario, check the **issues** section at the end.

## Basic Usage


Create a scraper class (manually or via the built-in command):

```bash
php artisan make:scraper BikeScraper
```

This generates a file like:


```php
namespace App\Scrapers;

use EduLazaro\Larascraper\Scraper;

class BikeScraper extends Scraper
{
    protected function handle(): array
    {
        return [
            'title' => $this->crawler->filter('title')->text('')
        ];
    }
}
```

You can now scrape a URL like this:

```php
use App\Scrapers\BikeScraper;

$result = BikeScraper::scrape('https://whatever.com/bikes/4')
    ->proxy('ip:port', 'username', 'password') // Optional
    ->timeout(10000) // Optional timeout in ms
    ->headers(['Accept-Language' => 'en']) // Optional headers
    ->run();

if ($result->success) {
    dd($result->data); // The array returned by handle()
} else {
    dd($result->status, $result->error);
}
```

`run()` returns a `ScraperResponse` value object so you can tell a failed fetch from an empty result:

| Property | Description |
|---|---|
| `$result->success` | `true` when the page loaded (and any actions ran) without error. |
| `$result->status` | The HTTP status code. |
| `$result->error` | The error message when `success` is `false`, otherwise `null`. |
| `$result->html` | The raw HTML that was fetched. |
| `$result->data` | Whatever your `handle()` method returned (usually an array). |
| `$result->file` | The raw bytes of a captured file/binary (e.g. a PDF), or `null`. See [Downloading files](#downloading-files). |
| `$result->contentType` | The content type of the captured file (e.g. `application/pdf`). |

A response carries either `data` (parsed HTML) or `file` (a captured binary), depending on the scrape.

> **Upgrading from 1.x:** `run()` used to return the `handle()` value directly. It now returns a `ScraperResponse`; read your parsed data from `$result->data`.

You can pass parameters to the `run` method as long as they are handled:

```php
namespace App\Scrapers;

use EduLazaro\Larascraper\Scraper;

class BikeScraper extends Scraper
{
    protected function handle(string $name): array
    {
        return [
            'title' => $this->crawler->filter($name)->text('')
        ];
    }
}
```

And then you can do:

```php
use App\Scrapers\BikeScraper;

BikeScraper::scrape('https://whatever.com/bikes/4')->run(name: 'title');
```

## Proxy Support

Larascraper supports proxies with or without authentication:

```php
->proxy('200.20.14.84:40200')
```

Or if using authentication:

```php
->proxy('200.20.14.84:40200', 'username', 'password')
```

## Timeout

To add a custom timeout (20000 ms by default):

```php
->timeout(10000) // Timeout in milliseconds
```

## Headers

To append custom headers:

```php
->headers([
    'Accept-Language' => 'en',
    'X-Custom-Header' => 'Hello'
])
```

## Drivers (browser vs HTTP)

By default Larascraper drives a real headless browser (Puppeteer), which renders JavaScript and can interact with the page. For static pages or APIs that don't need a browser, you can switch to the lightweight **`http`** driver, which fetches the URL with a plain HTTP request (via Laravel's HTTP client) — no Chromium, much faster and cheaper:

```php
// Browser (Puppeteer) — the default, nothing changes:
BikeScraper::scrape('https://whatever.com/bikes/4')->run();

// Plain HTTP — no browser:
BikeScraper::scrape('https://whatever.com/bikes/4')
    ->driver('http')
    ->run();
```

Both drivers share the same fluent API (`proxy()`, `timeout()`, `headers()`) and return the same `ScraperResponse`, so your `handle()` method doesn't change.

| Driver | Renders JS | Actions (click/type/…) | Speed | Needs Node/Chrome |
|---|---|---|---|---|
| `browser` (default) | ✅ | ✅ | Slower | ✅ |
| `http` | ❌ | ❌ | Fast | ❌ |

> The `http` driver uses Laravel's HTTP client, which relies on Guzzle. If it isn't already installed, run `composer require guzzlehttp/guzzle`.
>
> **Note:** the `http` driver cannot run [actions](#interacting-with-the-page-actions). Combining `->driver('http')` with `click()`, `type()`, etc. throws a `LogicException` — use the default `browser` driver for pages that need interaction or JavaScript.

### POST requests, body and cookies (HTTP driver)

The `http` driver can also send POST (or any verb), a request body, and cookies — useful for JSON/form APIs and session-protected endpoints:

```php
// POST a form body with a session cookie:
ApiScraper::scrape('https://example.com/search.action')
    ->driver('http')
    ->method('POST')                                   // or ->post()
    ->body(['q' => 'zelda', 'page' => 1], 'form')      // 'form' (default) or 'json'
    ->cookies(['JSESSIONID' => $sessionId], 'example.com')
    ->run();

// JSON body shorthand:
ApiScraper::scrape($url)->post()->asJson()->body($payload)->run();
```

| Method | Description |
|---|---|
| `->method($verb)` / `->post()` | Set the HTTP verb (default `GET`). `post()` is a shorthand for `method('POST')`. |
| `->body($data, $format = 'form')` | Request body. `'form'` sends URL-encoded form fields, `'json'` sends JSON. |
| `->asForm()` / `->asJson()` | Set the body format without re-passing the body. |
| `->cookies($pairs, $domain)` | Send cookies as `['name' => 'value']` for the given domain. |

The response exposes any `Set-Cookie` values the server returned in `$result->cookies` (a `['name' => 'value']` array), so you can capture a session from one request and reuse it on the next:

```php
$login = ApiScraper::scrape($loginUrl)->driver('http')->post()->body($creds)->run();
$session = $login->cookies['JSESSIONID'] ?? null;
```

> These are **request options**, not page actions, so they're available on the `http` driver. The `browser` driver throws if you set `method()`/`body()`/`cookies()` — it navigates as a real browser instead.

## Interacting with the page (actions)

Sometimes the content you need only appears after interacting with the page: accepting a cookie banner, filling and submitting a form, paginating, expanding a "show more" section or scrolling to trigger lazy loading.

You can chain **actions** before calling `run()`. They are sent to Puppeteer and executed **in order, in a single browser session**, right after navigation and before the final HTML is captured. The waits happen inside Node (where the page is alive), so timing works naturally:

```php
$result = MyScraper::scrape('https://shop.com/search')
    ->click('#accept-cookies')
    ->type('#search', 'zelda')
    ->press('Enter', waitForNavigation: true) // submit + wait for the new page
    ->waitForSelector('.results')
    ->scrollToBottom()                         // trigger lazy loading
    ->wait(800)
    ->run();                                   // handle() parses the final HTML

$items = $result->data;                        // your handle() result
```

### Available actions

| Method | Description |
|---|---|
| `->click($selector)` | Click an element (waits for it first). |
| `->click($selector, waitForNavigation: true)` / `->clickAndWait($selector)` | Click that triggers a page load, and wait for it. |
| `->type($selector, $text)` | Type text into an input (waits for it first). |
| `->select($selector, $value)` | Choose an option (by value) in a `<select>`. |
| `->setValue($selector, $value)` | Set an element's value directly, firing `input` + `change` events. For hidden inputs populated by a custom widget (multiselects backed by an `<input type="hidden">`), or fields `type()`/`select()` can't reach. |
| `->hover($selector)` | Hover over an element. |
| `->press($key)` | Press a key (`Enter`, `Tab`, `Escape`…). Pass `waitForNavigation: true` when it submits a form. |
| `->waitForSelector($selector)` | Wait until an element appears (lazy/JS content). |
| `->waitForNavigation()` | Wait for a navigation to finish. |
| `->wait($ms)` | Wait a fixed number of milliseconds. |
| `->scroll('bottom'\|'top')` / `->scrollToBottom()` | Scroll the page (infinite scroll / lazy load). |
| `->visit($url, $waitUntil = 'networkidle2')` | Navigate to a URL mid-flow (resolved against the current page). Handy at the start of a `repeatUntil()` body to return to a viewer page so each attempt starts from fresh server state. |
| `->gotoAttr($selector, $attr = 'href', $waitUntil = 'networkidle2')` | Navigate to the URL held in an element's attribute — e.g. an `<object data="...">` / `<embed src="...">` PDF viewer where the next URL lives in an attribute, not a link. |
| `->reload($waitUntil = 'networkidle2')` | Reload the current page (e.g. to regenerate a captcha image before solving it). |

> **`waitUntil` on navigation actions.** `visit()`, `gotoAttr()` and `reload()` accept a Puppeteer wait condition. The default `'networkidle2'` is right for most pages, but some servers keep connections open and **never reach network idle** — there `'networkidle2'` would burn the whole timeout. For those, pass `'domcontentloaded'` and rely on a following `waitForSelector()` as the real "content is ready" signal: `->visit($url, 'domcontentloaded')->waitForSelector('.results')`.

If an action fails (for example a selector that never appears within the timeout), the scrape fails cleanly with `success = false` and the error message, just like an HTTP error.

> **Tip:** for a click or key press that loads a new page, use `waitForNavigation: true` on that action (or `clickAndWait()`) rather than a separate `->waitForNavigation()` call. That arms the wait *before* the click, avoiding a race where the navigation finishes before the wait starts.

Every `$selector` is a plain **CSS selector** passed to Puppeteer, so anything CSS supports works: id (`#id`), class (`.class`), and **attribute selectors** including `name`:

```php
->type('[name=email]', 'me@example.com')      // by name attribute
->type('input[name=captcha]', $code)          // tag + name
->click('[name=submit]')
->select('[name=lang]', 'en')                 // a <select name="lang">
```

`[name=x]`, `[name="x"]` and `input[name=x]` all work, as do `[data-id=5]`, `[type=submit]`, etc.

## Conditional flow (when / repeatUntil)

The action chain is a little **query builder for the page**: besides the linear actions above, you can branch and loop. The condition is evaluated by Puppeteer against the *live* page at runtime. PHP isn't inside the browser, so you describe *what* to check with the `Condition` helper, and Node does the checking.

**`when()`** runs a branch only if a condition holds. The closure receives a sub-builder (`$b`) you chain actions on, exactly like Laravel's `$query->when($cond, fn ($q) => ...)`:

```php
use EduLazaro\Larascraper\Support\Condition;

MyScraper::scrape($url)
    ->when(
        Condition::selectorExists('#cookie-banner'),
        fn ($b) => $b->click('#accept-cookies'),  // only if the banner is there
    )
    ->run();
```

The `else` branch is optional (and rarely needed; usually you just continue the main chain afterwards):

```php
->when(
    Condition::textContains('No results', '.notice'),
    fn ($b) => $b->click('#clear-filters'),       // then
    fn ($b) => $b->waitForSelector('.product'),   // else
)
```

**`repeatUntil()`** repeats a branch until a condition holds, for "retry until it works" flows like solving a captcha or paginating. **It is always bounded**: `max` defaults to 5 and is clamped to at least 1 (there is no unbounded mode), and `delay` throttles the time between iterations so you don't hammer a server:

```php
->repeatUntil(
    Condition::selectorMissing('#captcha-img'),   // stop once the captcha is gone
    fn ($b) => $b
        ->solveCaptcha('#captcha-img', '#captcha-input')
        ->clickAndWait('#verify'),
    max: 6,
    delay: 1500,                                  // wait 1.5s between attempts
)
```

### Conditions

Build conditions with the `Condition` helper (each returns the data the Node runner evaluates):

| Condition | True when… |
|---|---|
| `Condition::selectorExists($selector)` | an element matching the selector exists |
| `Condition::selectorMissing($selector)` | no element matching the selector exists |
| `Condition::textContains($text, $selector = null)` | the text is found (in `$selector`, or the whole page) |
| `Condition::urlContains($text)` | the current URL contains the substring |

## Solving simple captchas

For simple **image (text) captchas**, `solveCaptcha()` screenshots the captcha image, reads it with OCR, and types the answer into an input. The OCR packages (`tesseract.js`, `jimp`) are **optional**; install them with `php artisan larascraper:install --captcha`. If they are missing, the scrape fails with a clear message pointing you to that command.

```php
MyScraper::scrape($url)
    ->solveCaptcha('#captcha-img', '#captcha-input', [
        'whitelist' => 'abcdefghijklmnopqrstuvwxyz0123456789', // allowed characters
        'psm'       => 8,                                      // tesseract page-seg mode
        'threshold' => 150,                                    // binarization threshold
        // 'crop', 'scale', 'contrast', 'lang' are also accepted
    ])
    ->clickAndWait('#submit')
    ->run();
```

Because OCR isn't perfect, pair it with `repeatUntil()` to retry until the captcha is accepted (see above). The `solver` option is reserved for future solvers (e.g. `'vision'`); only `'ocr'` is supported today.

> **Scope:** this handles captchas where you read text and type it. It does **not** solve reCAPTCHA/hCaptcha image grids; those need a different approach.

## Downloading files

Sometimes the result you want is a **file** (a PDF behind a form, a ZIP, a generated report), not HTML. For that, use the ready-made `FileScraper`. You don't write a class: use it directly, submit the form with `submitAndCapture()`, and read the bytes from `$result->file`.

```php
use EduLazaro\Larascraper\FileScraper;

$result = FileScraper::scrape('https://example.com/report')
    ->submitAndCapture('form', ['expect' => 'application/pdf']) // submit + capture the file
    ->run();

if ($result->success && $result->file) {
    file_put_contents('report.pdf', $result->file);   // $result->file is the raw bytes
}
```

`submitAndCapture($formSelector, ['expect' => ...])` submits the form in-page and captures the response when its content type matches `expect` (a substring like `application/pdf`); otherwise it leaves nothing captured. The captured bytes land in `$result->file` and the type in `$result->contentType`.

Serve it as a download from a controller:

```php
return response($result->file)
    ->header('Content-Type', $result->contentType)
    ->header('Content-Disposition', 'attachment; filename="report.pdf"');
```

### File behind a captcha

Combine it with `solveCaptcha()` and `repeatUntil(Condition::captured(), ...)`: retry solving the captcha until the file is captured (always bounded by `max`):

```php
use EduLazaro\Larascraper\FileScraper;
use EduLazaro\Larascraper\Support\Condition;

$result = FileScraper::scrape('https://example.com/protected-document')
    ->repeatUntil(
        Condition::captured(),                        // stop once the file is captured
        fn ($b) => $b
            ->solveCaptcha('#captcha-img', 'input[name=captcha]')
            ->submitAndCapture('form', ['expect' => 'application/pdf']),
        max: 8,
        delay: 500,
    )
    ->run();

if ($result->success && $result->file) {
    file_put_contents('document.pdf', $result->file);
}
```

`Condition::captured()` is `true` as soon as `submitAndCapture()` grabs a file, so the loop stops on success and gives up after `max` attempts if the OCR never lands.

**File behind a viewer + regenerating captcha.** Some sites show a viewer page whose real download URL lives in an `<object data="...">`, and the captcha is regenerated only when you re-enter that flow (a plain `reload()` won't refresh it). Re-navigate each attempt with `visit()` + `gotoAttr()` so every try gets a fresh captcha:

```php
$result = FileScraper::scrape($viewerUrl)
    ->repeatUntil(
        Condition::captured(),
        fn ($b) => $b
            ->visit($viewerUrl)                                   // back to the viewer — fresh state
            ->gotoAttr('object[type*="pdf"], embed[type*="pdf"]', 'data') // → real URL + fresh captcha
            ->solveCaptcha('img[src*="captcha"]', 'input[name=captcha]')
            ->submitAndCapture('form', ['expect' => 'application/pdf']),
        max: 8,
        delay: 400,
    )
    ->run();
```

## Retry logic

You can add the number of attempts and the number of seconds to wait between attempts:

```php
->retry(3, 5)
```

Retry 3 times and wait 5 seconds betwee attempts. Please note only the error codes 408, 429, 500, 502, 503 and 504 will be retried.

## Artisan Commands

Install the Node dependencies the Puppeteer script needs:

```bash
php artisan larascraper:install
```

Options:

- `--publish` also publishes `scraper.cjs` to the project root (so you can customize it).
- `--no-npm` skips the `npm install` and just prints the command to run.
- `--no-browser` skips downloading Chrome (use it when a system Chrome is provided via `PUPPETEER_EXECUTABLE_PATH`).
- `--captcha` also installs the optional OCR packages (`tesseract.js`, `jimp`) used by `solveCaptcha()`. Left out by default so projects that don't solve captchas stay lean.

You can generate a scraper instance with:

```bash
php artisan make:scraper MyScraper
```

List all scrapers in app/Scrapers directory:

```bash
php artisan list:scrapers
```

## LaraClaude integration

Larascraper is supported by [LaraClaude](https://github.com/edulazaro/laraclaude), a Laravel toolkit plugin for [Claude Code](https://claude.ai/code). It ships a `/lc:generate-scraper` skill that builds scrapers for you:

```
/lc:generate-scraper BikeScraper https://shop.com/bikes
```

Given a name and a target URL, the skill:

- Checks that Larascraper (and the Node/Puppeteer side) is installed.
- Reads the installed `Scraper` API so it only uses methods your version actually has.
- Generates the class with `make:scraper` and fills `handle()` from the **real** page markup (not guesses).
- Wires up the right [actions](#interacting-with-the-page-actions) when the page needs interaction (cookie walls, search forms, pagination, infinite scroll).
- Runs the scraper once to confirm the fields come back populated.

Install the plugin in Claude Code with `/plugin install github:edulazaro/laraclaude`.

## Testing a scraper

You can easily test a scraper with Tinker:

```bash
php artisan tinker
```

And the running:

```php
$result = \App\Scrapers\TestScraper::scrape('https://whatever.com')->run();
dd($result->success, $result->data);
```

## Issues

This section contains common configuration issues.

### Using Node via NVM

If you use Node via NVM and you try to run the scraper via a scheduled task, chances are Node is not available. To make it available, edit your **bash_profile** with an editor like Vi, Vim or Nano:

```
nano ~/.bash_profile
```

Then make sure this is included at the top:

```
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"  # This loads nvm
[ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"  # This loads nvm bash_completion
```

Save the file and run:

```
source ~/.bash_profile
```

Now Node will be available for non interative terminals and the scraping process should run successfully.

In general, it's not recommended the usage of NVM on production environments.

## Sponsors

Larascraper is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" width="24" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" width="24" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Larascraper is open-sourced software licensed under the [MIT license](LICENSE.md).

