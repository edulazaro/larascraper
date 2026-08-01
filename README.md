![Larascraper](art/banner.png)

# Larascraper - A Simple Scraper for Laravel

<p align="center">
    <a href="https://github.com/edulazaro/larascraper/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/larascraper/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/larascraper"><img src="https://img.shields.io/packagist/v/edulazaro/larascraper" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/larascraper"><img src="https://img.shields.io/packagist/dt/edulazaro/larascraper" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/larascraper"><img src="https://img.shields.io/packagist/php-v/edulazaro/larascraper" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/larascraper/blob/main/LICENSE.md"><img src="https://img.shields.io/packagist/l/edulazaro/larascraper" alt="License"></a>
</p>

## Introduction

Larascraper lets you scrape any URL from Laravel. It uses Puppeteer under the hood but focuses on simplicity: unlike Spatie Crawler or Browsershot, which can leave many Chromium instances open and fill your server memory, Larascraper drives the scrape through Node and makes sure the Chromium instance is closed before exiting.

Unlike Spatie Crawler, it also supports proxy authentication and is generally faster.

The design splits fetching from parsing. A **Scraper** orchestrates the request (retries, proxy, browser actions) and decides whether the scrape succeeded by looking at the content; a **Crawler** parses the HTML into data; and `run()` hands you back a small `ScraperResponse`. HTTP 200 is not the same as "the scrape worked" (a 200 can be a captcha or a "no results" page), so success is content based and lives in the scraper, not in the HTTP status.

## Contents

- [Install](#install)
- [Drivers (browser vs HTTP)](#drivers-browser-vs-http)
- [Basic Usage](#basic-usage)
- [Writing a Crawler](#writing-a-crawler)
- [The ScraperResponse](#the-scraperresponse)
- [Handling failures (RequestException)](#handling-failures-requestexception)
- [Passing parameters (run / with / make)](#passing-parameters-run--with--make)
- [Configuration](#configuration) (proxy, timeout, headers, retries)
- [POST requests, body and cookies (HTTP driver)](#post-requests-body-and-cookies-http-driver)
- [Interacting with the page (actions)](#interacting-with-the-page-actions)
- [Conditional flow (when / repeatUntil)](#conditional-flow-when--repeatuntil)
- [Solving simple captchas](#solving-simple-captchas)
- [Downloading files](#downloading-files)
- [Reading a captured PDF (text / vision)](#reading-a-captured-pdf-text--vision)
- [Spiders (concurrent crawling)](#spiders-concurrent-crawling)
- [The shared Session (cookie jar)](#the-shared-session-cookie-jar)
- [Artisan Commands](#artisan-commands)
- [LaraClaude integration](#laraclaude-integration)
- [Testing a scraper](#testing-a-scraper)
- [Issues](#issues)
- [Examples](#examples)

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

## Drivers (browser vs HTTP)

Larascraper has **two engines**, chosen with `->driver(...)` on the fetch chain (or the `$driver` class property). Worth knowing first, because every example below runs on one of them:

- **`browser`** (default): a real headless browser (Puppeteer / Chromium). Renders JavaScript and runs [actions](#interacting-with-the-page-actions) (click, type, solve captchas, capture files). Slower, and needs Node + Chrome.
- **`http`**: a plain HTTP request (Laravel's HTTP client). Fast, no browser, but **cannot** render JS or run actions. Best for static pages, APIs and direct file URLs.

You pick the driver inside `handle()`, on the chain that `$this->scrape($url)` returns:

```php
// Browser (Puppeteer), the default:
$this->scrape('https://whatever.com/bikes/4')->run();

// Plain HTTP, no browser:
$this->scrape('https://whatever.com/bikes/4')->driver('http')->run();
```

You can also make a scraper HTTP-only by setting the property once (`protected string $driver = 'http';`) or per call with `with(driver: 'http')`.

| Driver | Renders JS | Actions | Speed | Needs Node/Chrome |
|---|---|---|---|---|
| `browser` (default) | ✅ | ✅ | Slower | ✅ |
| `http` | ❌ | ❌ | Fast | ❌ |

Both drivers share the same fetch chain (`proxy()`, `timeout()`, `headers()`, the terminals) and both `->run()` into the same `ScraperResponse`, so your `handle()` stays the same shape. Combining `->driver('http')` with actions (`click()`, `type()`, ...) throws a `LogicException`. POST, body and cookie options for the `http` driver have [their own section](#post-requests-body-and-cookies-http-driver) below.

> The `http` driver uses Laravel's HTTP client (Guzzle). If it isn't installed, run `composer require guzzlehttp/guzzle`.

## Basic Usage

Create a scraper class (manually or via the built-in command):

```bash
php artisan make:scraper BikeScraper
```

This generates a file like:

```php
namespace App\Scrapers;

use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;

class BikeScraper extends Scraper
{
    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->run();
    }
}
```

Inside `handle()`, `$this->scrape($url)` starts a fetch chain and the terminal decides what you get back. A bare `->run()` returns the raw HTML wrapped in a `ScraperResponse`. To turn that page into structured data, chain a **Crawler** class:

```php
namespace App\Scrapers;

use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;

class BikeScraper extends Scraper
{
    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)
            ->crawl(BikeCrawler::class)   // parse the fetched HTML in a Crawler
            ->run();
    }
}
```

The Crawler is a plain class that only knows about HTML. It reads the document with `$this->filter(...)` (a Symfony DomCrawler) and returns whatever data you want:

```php
namespace App\Scrapers\Crawlers;

use EduLazaro\Larascraper\Crawler;

class BikeCrawler extends Crawler
{
    protected function handle(): array
    {
        return [
            'name'  => $this->filter('h1')->text(''),
            'price' => $this->filter('.price')->text(''),
            'specs' => $this->filter('ul.specs li')->each(fn ($li) => trim($li->text(''))),
        ];
    }
}
```

Now run the scraper from anywhere with the static `run()` entry point:

```php
use App\Scrapers\BikeScraper;

$result = BikeScraper::run('https://shop.com/bikes/4');

if ($result->success) {
    $bike = $result->data;   // ['name' => ..., 'price' => ..., 'specs' => [...]]
}
```

`BikeScraper::run($url)` is the outside entry point; the fetch chain (`$this->scrape($url)->crawl(...)->run()`) lives **inside** `handle()`. That is the whole loop: a Scraper to fetch, a Crawler to parse, and a `ScraperResponse` to read.

If you just need one or two values and don't want a Crawler class, pass a CSS selector to `crawl()` and use the inline terminals `text()` / `texts()`:

```php
protected function handle(string $url): array
{
    return $this->scrape($url)
        ->crawl('.bike-card h3')   // a CSS selector, not a class
        ->texts();                 // string[] of every match (text() for the first)
}
```

## Writing a Crawler

A Crawler is parsing only: it receives a document and extracts data, with no idea how that document was fetched. That makes it reusable across scrapers and easy to test against a fixture.

The input is generic (`mixed`): an HTML string, an XML string, an arbitrary text payload, or even an array of named parts when one fetch yields more than one document.

You extend `EduLazaro\Larascraper\Crawler` and implement `handle()`. Inside it you have:

- **`$this->filter($cssSelector)`** returns a Symfony `DomCrawler` node list over the HTML document, so you can chain `->text('')`, `->attr('href')`, `->each(...)`, `->count()`, and everything DomCrawler offers.
- **`$this->filter($cssSelector, 'xml')`** filters the input as **XML** instead. CSS selectors compile to XPath, so `filter('item', 'xml')` matches `<item>`, and the returned node still allows `->filterXPath()` chaining for namespaced XML.
- **`$this->raw()`** returns the untouched input (the string, or the whole array), so you can parse it with regex, `simplexml`, `json_decode`, or read the array's parts directly.
- **`$this->html()`** returns the full HTML of the document, if you need the raw string.

`filter()` and `html()` require a string input; called on a non-string input they throw a clear `LogicException` pointing you at `raw()`.

```php
namespace App\Scrapers\Crawlers;

use EduLazaro\Larascraper\Crawler;

class BikeCrawler extends Crawler
{
    protected function handle(): array
    {
        return [
            'name'  => $this->filter('h1')->text(''),
            'price' => $this->filter('.price')->text(''),
            'url'   => $this->filter('a.buy')->attr('href'),
        ];
    }
}
```

### Signalling a content failure

A 200 response can still be a captcha wall, a block page, or an empty result set. When a Crawler notices the page did not yield what it needed, it throws a `ScrapeException` whose message is the error code:

```php
use EduLazaro\Larascraper\Crawler;
use EduLazaro\Larascraper\Exceptions\ScrapeException;

class BikeCrawler extends Crawler
{
    protected function handle(): array
    {
        if ($this->filter('h1.product-title')->count() === 0) {
            throw new ScrapeException('no_product');   // captcha / wrong layout / gone
        }

        return ['name' => $this->filter('h1.product-title')->text('')];
    }
}
```

The `crawl(BikeCrawler::class)->run()` terminal **catches** that `ScrapeException` and folds it into a `ScraperResponse` with `success = false` and `error = 'no_product'`. It does not bubble out of `run()`; the caller branches on `$result->success` (see [Handling failures](#handling-failures-requestexception)).

You can drive the same Crawler against a document directly, which is handy in tests. The standard entry is `run($input)`, consistent with `Scraper::run()` and `Spider::run()`:

```php
$data = BikeCrawler::run($html);   // standard entry: create + parse

// XML input, filtered as XML:
$items = FeedCrawler::run($xmlString);

// Multi-part input read through raw():
$data = SplitCrawler::run(['meta' => $metaXml, 'body' => $bodyHtml]);
```

`create($input)->parse()` (and `(new BikeCrawler($input))->parse()`) still work: `parse()` is kept as a legacy alias of `run()`.

## The ScraperResponse

`run()` (and `with(...)->run()`) always returns a `ScraperResponse`, a small value object with exactly three fields:

| Property | Description |
|---|---|
| `$result->data` | What the scrape produced: a Crawler's parsed data, a raw value returned from `handle()`, or the raw HTML for a bare `->run()`. |
| `$result->success` | `true` when the scrape succeeded at the **content** level. Defaults to `true`. |
| `$result->error` | A scrape-level error code (`'captcha'`, `'no_results'`, ...) when `success` is `false`, otherwise `null`. It is never an HTTP status. |

### How `run()` normalizes what `handle()` returns

`handle()` never has to build a `ScraperResponse` by hand. `run()` wraps whatever it returns:

- **a raw value** (a string, an array, ...) becomes `ScraperResponse(data: $value, success: true)`.
- **a `ScraperResponse`** (from a `crawl(Class)->run()` terminal, or from `$this->fail()` / `$this->ok()`) passes through unchanged.
- **`return $this->fail('no_results')`** produces `ScraperResponse(success: false, error: 'no_results')`. No `new`, no `throw`.
- **`throw new ScrapeException('no_results')`** inside `handle()` or a Crawler is caught and folded into that same failed response. It behaves exactly like `fail()`, for failures raised deep in nested code.
- **`return $this->ok($data)`** is an explicit success (identical to returning `$data` raw, provided for symmetry).

```php
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;

class LawScraper extends Scraper
{
    protected function handle(string $url): array|ScraperResponse
    {
        $text = trim($this->scrape($url)->capture()->file()->text());

        if ($text === '') {
            return $this->fail('no_text');   // success = false, error = 'no_text'
        }

        return ['text' => $text];            // success = true, data = ['text' => ...]
    }
}
```

### Where the HTTP facts live

The `ScraperResponse` deliberately does **not** carry HTTP facts (status, cookies, the raw html, a captured binary). Those belong to the request layer, exposed inside `handle()` as **`$this->request`** (a `RequestResponse`):

```php
protected function handle(string $url): array
{
    $data = $this->scrape($url)->crawl(BikeCrawler::class)->run()->data;

    // HTTP-level facts of the last fetch, if you need them:
    $status  = $this->request->status;      // 200
    $cookies = $this->request->cookies;     // ['session' => '...']

    return ['data' => $data, 'status' => $status];
}
```

`$this->request` is a `RequestResponse` with `status`, `error`, `html`, `file`, `contentType` and `cookies`. It is the internal HTTP result; `success`/`error` on the `ScraperResponse` are the scraper's own content judgement (a `status = 200` can still be `success = false, error = 'captcha'`). If you need any HTTP fact on the way out, fold it into `data`.

## Handling failures (RequestException)

There are two layers of failure, but the caller only ever catches **one** exception:

- **Request-level failure** (the network was down, or a status the fetcher treats as failure survived the bounded retries) throws a `RequestException`. It carries the `RequestResponse`, so you can branch on `$e->response->status`. This is the only exception that reaches the caller.
- **Scrape-level failure** (captcha, no results, wrong document) comes back as `success = false` + `error`, **not** an exception. It is produced by `return $this->fail('code')`, or by a `ScrapeException` thrown inside a Crawler or `handle()` (both are caught and folded into the response).

So the calling code looks like this:

```php
use App\Scrapers\BikeScraper;
use EduLazaro\Larascraper\Exceptions\RequestException;

try {
    $result = BikeScraper::run($url);          // always a ScraperResponse

    if (! $result->success) {
        // content failure: $result->error is 'captcha' / 'no_results' / ...
        return;
    }

    $bike = $result->data;
} catch (RequestException $e) {
    // request layer only -> retry on 503, skip on 404, ...
    report("HTTP {$e->response->status}: {$e->getMessage()}");
}
```

Content failure is data (not an exception) because it is an *expected* outcome of scraping: pages block and layouts change, so you branch on `$result->success`. A genuine transport failure is the exceptional case, and that is the one `RequestException` you catch.

## Passing parameters (run / with / make)

Three static entry points reach the same instance, mirroring `edulazaro/laractions` so the two packages feel identical.

**`run(...$params)`** sends its arguments to **`handle()`**. They can be positional, named, or an associative array mapped by name to `handle()`'s parameters:

```php
BikeScraper::run($url);                 // -> handle(string $url)
BikeScraper::run(url: $url);            // named argument
BikeScraper::run(['url' => $url]);      // assoc array mapped by name
```

A single array passed to a single array-typed parameter is forwarded whole as that argument (the "attribute bag"):

```php
class PriceApiScraper extends Scraper
{
    protected function handle(array $ids): array { /* ... */ }
}

PriceApiScraper::run([1, 2, 3]);        // $ids = [1, 2, 3]
```

**`with(...$params)`** injects into the scraper's **properties** by name (driver, tries, timeout, proxy, headers, ...), returns a chainable wrapper, then you call `run()`:

```php
BikeScraper::with(driver: 'http', tries: 5)->run($url);
BikeScraper::with(['driver' => 'http', 'retryDelay' => 10])->run($url);
```

**`make(...$deps)`** builds the instance through Laravel's container. Note that `run()` already resolves the scraper through the container, so a scraper's bindable constructor dependencies are injected automatically. `BikeScraper::run($url)` is enough:

```php
BikeScraper::run($url);
```

If you must run a manually-constructed instance with explicit args, drive it directly rather than through the static `run()`:

```php
BikeScraper::make($dep)->handleToResponse([$url]);
```

## Configuration

You tune a scraper in three interchangeable places: as **class properties** (best for a reusable scraper), on the **fetch chain** inside `handle()` (per request), or with **`with(...)`** for a one-off override.

As class properties:

```php
use EduLazaro\Larascraper\Scraper;

class BikeScraper extends Scraper
{
    protected string $driver = 'browser';
    protected int $timeout = 10000;                     // ms
    protected int $tries = 5;                           // attempts
    protected int $retryDelay = 10;                     // seconds between attempts
    protected array $headers = ['Accept-Language' => 'en'];
    protected ?string $proxy = '200.20.14.84:40200';
    protected ?string $proxyUser = 'username';
    protected ?string $proxyPass = 'password';
}
```

Or on the chain, per request:

```php
protected function handle(string $url): ScraperResponse
{
    return $this->scrape($url)
        ->proxy('200.20.14.84:40200', 'username', 'password')
        ->timeout(10000)
        ->headers(['Accept-Language' => 'en'])
        ->retry(3, 5)
        ->crawl(BikeCrawler::class)
        ->run();
}
```

### Proxy

With or without authentication:

```php
->proxy('200.20.14.84:40200')
->proxy('200.20.14.84:40200', 'username', 'password')   // with auth
```

The class-property equivalent is `$proxy`, `$proxyUser` and `$proxyPass`.

### Timeout

Milliseconds; 20000 by default:

```php
->timeout(10000)
```

### Headers

```php
->headers([
    'Accept-Language' => 'en',
    'X-Custom-Header' => 'Hello',
])
```

### Retries

The number of attempts and the delay (seconds) between them. The chain method is `retry()`; the class properties are `$tries` (default 3) and `$retryDelay` (default 15):

```php
->retry(3, 5)   // 3 attempts, 5s apart
```

Only the transient statuses `408`, `429`, `500`, `502`, `503` and `504` are retried; any other error fails fast (and, if it survives the retries, throws a `RequestException`).

## POST requests, body and cookies (HTTP driver)

The `http` driver can also send POST (or any verb), a request body, and cookies, useful for JSON/form APIs and session-protected endpoints. These are chain methods on `$this->scrape($url)`:

```php
protected function handle(string $query): ScraperResponse
{
    return $this->scrape('https://example.com/search.action')
        ->driver('http')
        ->method('POST')                                   // or ->post()
        ->body(['q' => $query, 'page' => 1])               // form by default
        ->asForm()                                         // or ->asJson()
        ->cookies(['JSESSIONID' => $this->sessionId], 'example.com')
        ->run();
}
```

For a JSON body, `post()` sets the verb, the body and the format in one call:

```php
->post(['ids' => [1, 2, 3]], 'json')     // POST + JSON body
```

| Method | Description |
|---|---|
| `->method($verb)` | Set the HTTP verb (default `GET`). |
| `->post($body = [], $format = 'form')` | Shorthand: set the verb to POST, plus the body and its format. |
| `->body($data)` | Set the request body (an array for form or JSON). |
| `->asForm()` / `->asJson()` | Choose the body format (`form` is the default). |
| `->cookies($pairs, $domain = null)` | Send cookies as `['name' => 'value']` for the given domain (defaults to the URL host). |

Any `Set-Cookie` values the server returned are on `$this->request->cookies` (a `['name' => 'value']` array), so you can capture a session from one request and reuse it on the next:

```php
protected function handle(): array
{
    // Log in, then read the session cookie off the request layer.
    $this->scrape($this->loginUrl)->driver('http')->post($this->credentials)->run();
    $session = $this->request->cookies['JSESSIONID'] ?? null;

    // Reuse it on the protected endpoint.
    return $this->scrape($this->dataUrl)
        ->driver('http')
        ->cookies(['JSESSIONID' => $session], 'example.com')
        ->crawl(DataCrawler::class)
        ->run()
        ->data;
}
```

> These are **request options**, not page actions, so they're available on the `http` driver. The `browser` driver throws if you set `method()`/`body()`/`cookies()`; it navigates as a real browser instead.

## Interacting with the page (actions)

Sometimes the content you need only appears after interacting with the page: accepting a cookie banner, filling and submitting a form, paginating, expanding a "show more" section or scrolling to trigger lazy loading.

You can chain **actions** on the fetch chain before the terminal. They are sent to Puppeteer and executed **in order, in a single browser session**, right after navigation and before the final HTML is captured. The waits happen inside Node (where the page is alive), so timing works naturally:

```php
protected function handle(string $url): ScraperResponse
{
    return $this->scrape($url)
        ->click('#accept-cookies')
        ->type('#search', 'zelda')
        ->press('Enter', waitForNavigation: true)  // submit + wait for the new page
        ->waitForSelector('.results')
        ->scrollToBottom()                          // trigger lazy loading
        ->wait(800)
        ->crawl(ResultsCrawler::class)              // parse the final HTML
        ->run();
}
```

### Available actions

| Method | Description |
|---|---|
| `->click($selector)` | Click an element (waits for it first). |
| `->click($selector, waitForNavigation: true)` / `->clickAndWait($selector)` | Click that triggers a page load, and wait for it. |
| `->type($selector, $text)` | Type text into an input (waits for it first). |
| `->select($selector, $value)` | Choose an option (by value) in a `<select>`. Pass an **array** of values to select several at once on a `<select multiple>`, e.g. `->select('#organo', ['11', '12', '13'])`; they are applied in a single call so earlier values are not deselected. A string selects one option. |
| `->setValue($selector, $value)` | Set an element's value directly, firing `input` + `change` events. For hidden inputs populated by a custom widget (multiselects backed by an `<input type="hidden">`), or fields `type()`/`select()` can't reach. |
| `->check($selector)` / `->uncheck($selector)` | Tick / untick every matching checkbox, firing a bubbling `change` event. Works on widget-backed checkboxes hidden in a collapsed dropdown (e.g. bootstrap-multiselect) where a native `click()` can't reach them. Already-in-state boxes are left alone; a no-match is a silent no-op. |
| `->hover($selector)` | Hover over an element. |
| `->press($key)` | Press a key (`Enter`, `Tab`, `Escape`...). Pass `waitForNavigation: true` when it submits a form. |
| `->waitForSelector($selector, $options = [])` | Wait until an element appears (lazy/JS content). Pass an **array** of selectors to wait for **any** of them (grouped into one comma selector), e.g. `->waitForSelector(['.results', '.no-results'])`, so the wait resolves on whichever lands first. Options: `'optional' => true` treats a timeout as a valid outcome (the element may legitimately never appear, e.g. an empty result set) so the run continues instead of failing; `'timeout' => $ms` overrides the global timeout for this one wait. |
| `->waitForNavigation()` | Wait for a navigation to finish. |
| `->wait($ms)` | Wait a fixed number of milliseconds. |
| `->scroll('bottom'\|'top')` / `->scrollToBottom()` | Scroll the page (infinite scroll / lazy load). |
| `->visit($url, $waitUntil = 'networkidle2')` | Navigate to a URL mid-flow (resolved against the current page). Handy at the start of a `repeatUntil()` body to return to a viewer page so each attempt starts from fresh server state. |
| `->gotoAttr($selector, $attr = 'href', $waitUntil = 'networkidle2')` | Navigate to the URL held in an element's attribute, e.g. an `<object data="...">` / `<embed src="...">` PDF viewer where the next URL lives in an attribute, not a link. |
| `->reload($waitUntil = 'networkidle2')` | Reload the current page (e.g. to regenerate a captcha image before solving it). |

> **`waitUntil` on navigation actions.** `visit()`, `gotoAttr()` and `reload()` accept a Puppeteer wait condition. The default `'networkidle2'` is right for most pages, but some servers keep connections open and **never reach network idle**; there `'networkidle2'` would burn the whole timeout. For those, pass `'domcontentloaded'` and rely on a following `waitForSelector()` as the real "content is ready" signal: `->visit($url, 'domcontentloaded')->waitForSelector('.results')`.

If an action fails (for example a selector that never appears within the timeout), the fetch fails, which raises a `RequestException` after the retries, just like an HTTP error.

> **Not every wait should be fatal.** `waitForSelector($sel, ['optional' => true])` is the escape hatch: a timeout is swallowed and the run continues, for elements that legitimately may never appear (an empty result set, an optional banner). Keep it short with `'timeout'` so an absent element does not burn the whole global timeout. To wait for whichever of several outcomes lands first (results OR a "no results" marker), pass a list: `->waitForSelector(['.results', '.no-results'])`.

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

protected function handle(string $url): ScraperResponse
{
    return $this->scrape($url)
        ->when(
            Condition::selectorExists('#cookie-banner'),
            fn ($b) => $b->click('#accept-cookies'),  // only if the banner is there
        )
        ->crawl(ProductCrawler::class)
        ->run();
}
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

> **A failed attempt is not a failed run.** If a `repeatUntil()` body throws mid-iteration (a transient page where an expected element is missing, a `gotoAttr()` that finds no PDF this pass), that counts as one failed attempt: the loop re-checks the condition and retries on the next pass, bounded by `max`, instead of aborting the whole fetch. Only when every attempt is exhausted *and* the condition still never holds is the last error surfaced. This makes loops that navigate to a freshly regenerated page each pass (a new captcha image, a re-issued session) resilient to a single bad iteration.

### Conditions

Build conditions with the `Condition` helper (each returns the data the Node runner evaluates):

| Condition | True when... |
|---|---|
| `Condition::selectorExists($selector)` | an element matching the selector exists |
| `Condition::selectorMissing($selector)` | no element matching the selector exists |
| `Condition::textContains($text, $selector = null)` | the text is found (in `$selector`, or the whole page) |
| `Condition::urlContains($text)` | the current URL contains the substring |
| `Condition::captured()` | a file/binary has been captured (pair with `capture()` in a loop) |

## Solving simple captchas

For simple **image (text) captchas**, `solveCaptcha()` screenshots the captcha image, reads it with OCR, and types the answer into an input. The OCR packages (`tesseract.js`, `jimp`) are **optional**; install them with `php artisan larascraper:install --captcha`. If they are missing, the fetch fails with a clear message pointing you to that command.

```php
protected function handle(string $url): ScraperResponse
{
    return $this->scrape($url)
        ->solveCaptcha('#captcha-img', '#captcha-input', [
            'whitelist' => 'abcdefghijklmnopqrstuvwxyz0123456789', // allowed characters
            'psm'       => 8,                                      // tesseract page-seg mode
            'threshold' => 150,                                    // binarization threshold
            // 'crop', 'scale', 'contrast', 'lang' are also accepted
        ])
        ->clickAndWait('#submit')
        ->crawl(ResultCrawler::class)
        ->run();
}
```

Because OCR isn't perfect, pair it with `repeatUntil()` to retry until the captcha is accepted (see above).

### OpenAI vision solver

Distorted captchas that tesseract struggles with are usually read in a single attempt by an OpenAI vision model. Set `'solver' => 'vision'` to screenshot the captcha and send it to OpenAI instead of running OCR. It needs no extra Node packages (it uses `fetch`), but each solve is an OpenAI API call (per-call cost). The API key comes from the `'apiKey'` option or the `OPENAI_API_KEY` env var; the model defaults to `gpt-4o-mini`:

```php
return $this->scrape($url)
    ->solveCaptcha('#captcha-img', '#captcha-input', [
        'solver' => 'vision',
        'apiKey' => '...',            // or set OPENAI_API_KEY in the environment
        'model'  => 'gpt-4o-mini',    // default; any vision-capable model works
        // 'strip' => false,          // keep punctuation in the answer (default strips it)
    ])
    ->clickAndWait('#submit')
    ->crawl(ResultCrawler::class)
    ->run();
```

The default solver stays `'ocr'` (tesseract); `'vision'` is opt-in per call. A transient OpenAI error (a `429` rate limit or a `5xx` server error) yields an empty answer rather than throwing, so a surrounding `repeatUntil()` simply retries the captcha; a bad key or a `4xx` request surfaces as an error.

> **Scope:** this handles captchas where you read text and type it. It does **not** solve reCAPTCHA/hCaptcha image grids; those need a different approach.

## Downloading files

When the response is a **file** (a PDF, a ZIP), you grab it in the chain with `capture()` and end with the **`->file()`** terminal, which runs the fetch and returns a `CapturedFile`:

### From a click or link

```php
use EduLazaro\Larascraper\Scraper;

class ReportScraper extends Scraper
{
    protected function handle(string $pageUrl): array
    {
        $file = $this->scrape($pageUrl)
            ->click('a.download-pdf')
            ->capture('application/pdf')   // grab the response the click triggers
            ->file();                      // run the chain, return the CapturedFile

        $file->save(storage_path('app/report.pdf'));

        return ['bytes' => $file->size()];
    }
}
```

`capture($expect)` records the file-like responses the page produces and keeps the one matching `expect` (a content-type substring like `application/pdf`; PDFs also match by their `%PDF` magic bytes). With no argument it takes the first file-like response. The `->file()` terminal returns the captured `CapturedFile`, or raises a `no_file` scrape failure (a `ScrapeException` folded into `success = false`) when nothing was captured. Pair `capture()` with `repeatUntil(Condition::captured(), ...)` to retry. It captures files that **render inline** (the browser's PDF viewer); a forced download (`Content-Disposition: attachment`) is not captured.

### From a form

When the file only comes back from **submitting a form** (hidden fields, tokens, a session), submit it and capture the response:

```php
->submit('form')->capture('application/pdf')->file()
```

> `submit()` + `capture()` is the composable pattern. The old one-call `submitAndCapture()` is **deprecated**; prefer `submit()` + `capture()`.

### From a direct URL

If the file lives at a plain URL, you do not need the browser: the `http` driver downloads it directly. A **binary** response (a PDF, a ZIP, an image...) is exposed through the same `->file()` terminal (and on `$this->request->file`); text responses (HTML, JSON, XML) still arrive as the response html.

```php
use EduLazaro\Larascraper\Scraper;

class LawScraper extends Scraper
{
    protected string $driver = 'http';

    protected function handle(string $url): string
    {
        return $this->scrape($url)->file()->text() ?: '';
    }
}

$text = LawScraper::run('https://example.com/law.pdf')->data;
```

### Behind a captcha

Wrap the capture in `repeatUntil(Condition::captured(), ...)` and solve the captcha first. Guard `solveCaptcha()` with `when()` so a page without a captcha does not break the loop, and re-navigate each attempt (with `visit()` + `gotoAttr()`) if the site regenerates the captcha:

```php
use EduLazaro\Larascraper\Support\Condition;

$file = $this->scrape($viewerUrl)
    ->repeatUntil(
        Condition::captured(),
        fn ($b) => $b
            ->visit($viewerUrl)                                          // fresh state each try
            ->gotoAttr('object[type*="pdf"], embed[type*="pdf"]', 'data') // real URL + fresh captcha
            ->when(
                Condition::selectorExists('img[src*="captcha"]'),
                fn ($c) => $c->solveCaptcha('img[src*="captcha"]', 'input[name=captcha]'),
            )
            ->submit('form')
            ->capture('application/pdf'),
        max: 8,
        delay: 400,
    )
    ->file();
```

`Condition::captured()` is true as soon as a file is grabbed, so the loop stops on success and gives up after `max` attempts.

## Reading a captured PDF (text / vision)

Once you have a `CapturedFile` from the `->file()` terminal, read its text with `->text()` (the PDF's text layer, free) and `->vision()` (OCR, for scanned pages):

```php
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;

class LawScraper extends Scraper
{
    protected function handle(string $url): ScraperResponse
    {
        $file = $this->scrape($url)->click('a.pdf')->capture()->file();

        $text = $file->text();                 // text layer (gs)

        if ($text === '') {                    // scanned PDF? fall back to OCR
            $text = $file->vision('ai');
        }

        return $text === ''
            ? $this->fail('no_text')
            : $this->ok(['text' => $text]);
    }
}

$result = LawScraper::run($url);
$text = $result->success ? $result->data['text'] : null;
```

The `CapturedFile` API:

- **`$file->text($engine = 'gs')`** reads the PDF's existing text layer. No OCR, fast, free. Engines: `gs` (ghostscript), `poppler` (pdftotext), `smalot` (smalot/pdfparser). Returns `''` for a scanned PDF (no text layer), so you can fall back to vision.
- **`$file->vision($engine = 'ai')`** rasterizes each page to an image and reads it, for scanned PDFs. Engines: `ai` (a vision model) and `tesseract`.
- **`$file->bytes()`** / **`$file->save($path)`** for the raw bytes.
- **`$file->contentType()`** / **`$file->size()`** for metadata.

Each engine shells out to its tool (`ghostscript`, `poppler-utils` for `pdftotext`/`pdftoppm`, `tesseract`) or, for `vision('ai')`, calls an OpenAI-compatible endpoint; a missing tool fails with a clear message. Configure the `ai` engine with `config/larascraper.php` (`openai_key`, `vision_model`, `vision_lang`, `vision_dpi`) or the `OPENAI_API_KEY` env var.

> **System requirements (PDF engines).** These are OS packages, not PHP/Composer dependencies, so Composer can't install them for you; add them to your image (e.g. your Dockerfile) if you use these engines. They're also listed under `suggest` in this package's `composer.json`.
>
> | Feature | Binary | Install (Debian/Ubuntu) |
> |---|---|---|
> | `text()` (default `gs`) | `gs` | `apt-get install ghostscript` |
> | `text('poppler')` and `vision()` page rasterization | `pdftotext`, `pdftoppm` | `apt-get install poppler-utils` |
> | `vision('tesseract')` | `tesseract` | `apt-get install tesseract-ocr` |
>
> Note `vision('ai')` still needs **poppler-utils**: it rasterizes each page with `pdftoppm` before sending it to the cloud vision model.

## Spiders (concurrent crawling)

A **Scraper** fetches one page. A **Spider** is the orchestrator on top: it walks a whole source (a bulletin, a paginated index, a range of ids) and drives many unit Scrapers, threading a single shared [Session](#the-shared-session-cookie-jar) through the whole run. Reach for a Spider when you are crawling *many* pages from one source and want concurrency, rate-limiting, per-item error isolation and one accumulating login/CSRF session in one place; reach for a plain Scraper when you just need one page.

A Spider is **imperative**: you extend `EduLazaro\Larascraper\Spider` and write one `handle()` that drives the whole crawl. There is no declarative wiring (no `$scraper` property, no `targets()`, `collect()`, `bootSession()`, `shouldVisit()` or `onError()` to override). You log in at the top of `handle()`, decide the work, filter it, and fan it out with **`pool()`**, the concurrent primitive:

```php
use EduLazaro\Larascraper\Spider;
use EduLazaro\Larascraper\Support\ScraperResponse;

class GamesSpider extends Spider
{
    protected int $concurrency = 20;   // up to 20 detail pages in flight
    protected int $delay = 250;        // ms between pool waves (rate-limit)

    public function handle(): int
    {
        // 1. Log in once. The shared Session captures the cookie, so every
        //    scraper the spider runs afterwards inherits the session.
        LoginScraper::make()->useSession($this->session)->handleToResponse();

        // 2. Drive a list scraper inline to discover the detail urls, then
        //    filter out what is already stored (incremental / resume).
        $ids = collect(GameListScraper::run()->data)
            ->reject(fn ($id) => Game::where('remote_id', $id)->exists());

        // 3. Fan out: run GameScraper over each id, CONCURRENTLY.
        $this->pool($ids, GameScraper::class, $this->save(...));

        return $ids->count();
    }

    protected function save(mixed $data, mixed $id, ScraperResponse $response): void
    {
        // Per-item collector: check success here, one bad page never aborts
        // the crawl.
        if ($response->success) {
            Game::updateOrCreate(['remote_id' => $id], $data);
        }
    }
}
```

The unit Scrapers it drives are ordinary Scrapers. Each item is the scraper's `handle()` **params**, not a url, so the scraper builds its own url:

```php
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;

class GameScraper extends Scraper
{
    protected string $driver = 'http';   // required for concurrency (see below)

    protected function handle(int $id): ScraperResponse
    {
        return $this->scrape("https://shop.com/games/{$id}")
            ->crawl(GameCrawler::class)
            ->run();
    }
}
```

Run the whole crawl with the static entry point; `handle()`'s return value comes back to the caller:

```php
$count = GamesSpider::run();
```

### The members

| Member | Description |
|---|---|
| `handle(): mixed` | **Abstract.** The whole orchestration: log in, decide the work, filter it, drive scrapers with `pool()`. Its return value is what `Spider::run()` hands back (a count, a summary, ...). |
| `protected int $concurrency` | Default number of scraper runs in flight per `pool()` wave. `10` by default; override per call with `pool(..., concurrency: N)`. |
| `protected int $delay` | Milliseconds to pause between `pool()` waves (rate-limit); `0` (default) means no pause. |
| `public ?Session $session` | The shared [cookie jar](#the-shared-session-cookie-jar) for this run, created for you by `run()` and threaded into every scraper `pool()` drives. Public so `handle()` can hand it to a login scraper: `X::make()->useSession($this->session)`. |
| `Spider::run(...$params)` | Static entry point. Builds the spider through the container (so constructor dependencies inject), gives it a fresh `Session`, and returns `handle()`'s value. |
| `Spider::make(...$params)` | Build the instance through the container without running it (for when you drive `handle()` yourself). |

### `pool()` in detail

```php
protected function pool(
    iterable $items,
    string|PendingScraper $scraper,
    callable $collect,
    ?int $concurrency = null,
): void
```

`pool()` runs `$scraper` over every item in `$items` **concurrently** and calls `$collect` once per finished item. Concretely:

- **`$items`** are the per-run **params**, not urls. An **array** item is spread as the scraper's `handle()` arguments (`['id' => 5, 'lang' => 'en']` or `[5, 'en']`); any **scalar** is passed as the single argument. The scraper builds its own url from those params. `$items` may be a lazy generator, so an open-ended crawl never materializes the full list up front.
- **`$scraper`** is either a class-string (`GameScraper::class`, made fresh through the container per item) or a configured template from `Scraper::with(...)` (e.g. `GameScraper::with(driver: 'http', tries: 5)`), cloned per item so its driver/proxy/props apply to each run without sharing mutable state across items. The shared `Session` is threaded into every run.
- **`$collect`** is any callable, an inline closure or a method reference like `$this->save(...)`, called once per item as **`($data, $item, $response)`**: the scrape `data`, the `$item` that produced it, and the full `ScraperResponse`. Branch on `$response->success` inside it.
- **`$concurrency`** caps how many runs are in flight; it defaults to the `$concurrency` property.

**How the concurrency works.** Each item runs its scraper inside a PHP **Fiber**. When a scraper fetches on the `http` driver, the fetch **suspends** the fiber with a fully-resolved request spec instead of blocking. The scheduler gathers every currently-suspended fiber's spec into **one `Http::pool()` wave** (overlapped `curl_multi` network I/O under one handler), then resumes each fiber with its settled `Response`. As fibers finish they free their slot and the scheduler refills from `$items` to keep `$concurrency` in flight; `$delay` milliseconds pass between waves. A multi-step scraper's later fetch rides the same wave as another item's first fetch.

**Per-item error isolation.** A `RequestException`, or any other `Throwable`, from one item's run is caught and turned into a failed `ScraperResponse` (`success = false`, `error` set, `data = null`) that is **still** handed to `$collect`. One bad item never aborts the crawl; you see it as `! $response->success` in the collector. The retriable statuses `408`, `429`, `500`, `502`, `503`, `504` are retried in the concurrent path too, at the scheduler level (a retriable wave result is re-issued in a later wave rather than resumed), so it matches the sequential retry set.

### Where `bootSession` / `shouldVisit` / `onError` went

They are not framework hooks anymore, they are just code you write inside `handle()`:

- **Boot the session**: run a login scraper at the top of `handle()` with `->useSession($this->session)` (see the example). Every later run inherits the cookie.
- **Skip already-stored work**: filter `$items` *before* `pool()` (`->reject(...)`, `where(...)->exists()`), so you never fetch what you already have.
- **Handle a bad page**: check `$response->success` inside `$collect`; a failed run arrives there as a failed `ScraperResponse`, so a single bad page is data, not an abort.

### Concurrency needs the `http` driver

`pool()`'s overlap comes from `Http::pool()`, so it only applies to the **`http`** driver. The **`browser`** (Puppeteer) driver is **not** pooled: each browser run is an isolated Chromium, so items on that driver run one at a time (still correct, just not overlapped). And the overlap is **across items**: dependent multi-step requests *within one item* (log in, then read a protected page in the same `handle()`) stay sequential, which is exactly what you want, only independent items overlap.

## The shared Session (cookie jar)

`EduLazaro\Larascraper\Support\Session` is a small, mutable cookie jar that a whole crawl shares. One `Session` object is created once and **threaded by reference** into every Scraper of the run, so a login or CSRF cookie established on the first request rides along to every request that follows and the jar keeps accumulating.

Cookies are held per **host** (the request URL's host), last-wins on name collisions, so two hosts never leak into each other. It has two methods, plus `all()` to inspect it:

```php
$session->cookiesFor('shop.test');            // ['name' => 'value', ...] for one host
$session->store('shop.test', ['sid' => '9']); // merge cookies for a host (last-wins)
```

A Spider creates the Session for you in `run()` and threads it into every scraper `pool()` drives, and exposes it as `$this->session` so `handle()` can hand it to a login scraper up front (`X::make()->useSession($this->session)->handleToResponse()`), so you rarely touch it directly. When you drive scrapers by hand, a Scraper is made session-aware with `useSession()` (instance, chainable) or `withSession()` (static, mirrors `with()`):

```php
use EduLazaro\Larascraper\Support\Session;

$session = new Session();

GameScraper::withSession($session)->run($firstUrl);   // static entry point
GameScraper::make()->useSession($session)->handleToResponse([$nextUrl]);
```

The jar is merged **under** any explicit per-call `cookies(...)`, so an explicit cookie always wins over the session, and it only receives `Set-Cookie` after a **successful** fetch, so a failed or 5xx response never clobbers the good session cookies later targets rely on. Cookies stay transport state: they live on `$this->request->cookies` inside `handle()` and never surface on the content-only `ScraperResponse`.

> **Driver caveat: http yes, browser no.** The shared jar only works on the **`http`** driver. On the **`browser`** (Puppeteer) driver it is a documented **no-op**: each Puppeteer run is an isolated browser and that driver rejects explicit cookies, so `Runner::supportsCookies()` is false there. A Session gives session continuity for http-driver spiders, not browser-driver ones, which is why the unit Scraper in the example sets `protected string $driver = 'http';`.

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

You can generate a scraper class with:

```bash
php artisan make:scraper MyScraper
```

List all scrapers in the `app/Scrapers` directory:

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
- Generates the class with `make:scraper` and fills `handle()` (plus a Crawler) from the **real** page markup (not guesses).
- Wires up the right [actions](#interacting-with-the-page-actions) when the page needs interaction (cookie walls, search forms, pagination, infinite scroll).
- Runs the scraper once to confirm the fields come back populated.

Install the plugin in Claude Code with `/plugin install github:edulazaro/laraclaude`.

## Testing a scraper

You can easily test a scraper with Tinker:

```bash
php artisan tinker
```

And then running:

```php
$result = \App\Scrapers\BikeScraper::run('https://whatever.com');
dd($result->success, $result->data);
```

Because a `Crawler` only knows about HTML, you can also unit-test the parsing on its own against a fixture, without touching the network:

```php
$data = \App\Scrapers\Crawlers\BikeCrawler::create($fixtureHtml)->parse();
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

Now Node will be available for non interactive terminals and the scraping process should run successfully.

In general, it's not recommended to use NVM on production environments.

## Examples

A few complete, copy-pasteable scrapers that put the pieces together.

**1. A basic HTML scraper with a Crawler** - fetch a page, parse fields, signal a content failure:

```php
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;

class BikeScraper extends Scraper
{
    protected function handle(string $url): ScraperResponse
    {
        return $this->scrape($url)->crawl(BikeCrawler::class)->run();
    }
}
```

```php
use EduLazaro\Larascraper\Crawler;
use EduLazaro\Larascraper\Exceptions\ScrapeException;

class BikeCrawler extends Crawler
{
    protected function handle(): array
    {
        if ($this->filter('h1')->count() === 0) {
            throw new ScrapeException('no_product');   // -> success = false, error = 'no_product'
        }

        return [
            'name'  => $this->filter('h1')->text(''),
            'price' => $this->filter('.price')->text(''),
            'specs' => $this->filter('ul.specs li')->each(fn ($li) => trim($li->text(''))),
        ];
    }
}
```

```php
$result = BikeScraper::run('https://shop.com/bikes/4');

if ($result->success) {
    $bike = $result->data;   // ['name' => ..., 'price' => ..., 'specs' => [...]]
} else {
    report("scrape failed: {$result->error}");
}
```

**2. A search form with actions** - type, submit, wait, lazy-load, then extract inline:

```php
use EduLazaro\Larascraper\Scraper;

class SearchScraper extends Scraper
{
    protected function handle(string $query): array
    {
        return $this->scrape('https://shop.com/search')
            ->type('#q', $query)
            ->press('Enter', waitForNavigation: true)
            ->waitForSelector('.result')
            ->scrollToBottom()
            ->crawl('.result h3')   // inline CSS selector
            ->texts();              // string[] -> run() wraps it as data
    }
}

$titles = SearchScraper::run('zelda')->data;   // ['Zelda 1', 'Zelda 2', ...]
```

**3. A JSON API via the `http` driver** - no browser, POST a JSON body:

```php
use EduLazaro\Larascraper\Scraper;

class PriceApiScraper extends Scraper
{
    protected string $driver = 'http';

    protected function handle(array $ids): array
    {
        $response = $this->scrape('https://api.shop.com/prices')
            ->post(['ids' => $ids], 'json')
            ->run();                       // ScraperResponse(data: raw JSON body)

        return json_decode($response->data, true) ?? [];
    }
}

$prices = PriceApiScraper::run(ids: [1, 2, 3])->data;
```

**4. A PDF behind a button, read with text + OCR fallback and a fail branch**:

```php
use EduLazaro\Larascraper\Scraper;
use EduLazaro\Larascraper\Support\ScraperResponse;

class LawScraper extends Scraper
{
    protected function handle(string $pageUrl): ScraperResponse
    {
        $file = $this->scrape($pageUrl)
            ->click('a.download-pdf')
            ->capture('application/pdf')
            ->file();                       // CapturedFile, or a 'no_file' scrape failure

        $text = $file->text();              // the PDF's text layer (fast, free)

        if ($text === '') {                 // scanned PDF? fall back to OCR
            $text = $file->vision('ai');
        }

        return trim($text) === ''
            ? $this->fail('no_text')        // success = false, error = 'no_text'
            : $this->ok(['text' => $text]);
    }
}

$result = LawScraper::run('https://boe.example/doc/123');

if (! $result->success) {
    // $result->error is 'no_text' (empty PDF) or 'no_file' (nothing captured)
    return;
}

$text = $result->data['text'];
```

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
