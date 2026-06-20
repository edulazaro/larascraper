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

**2. Install the Node dependencies.** The easiest way is the bundled command:

```bash
php artisan larascraper:install
```

This installs the Node packages **and** the Chrome binary Puppeteer needs:

```bash
npm install puppeteer puppeteer-extra puppeteer-extra-plugin-stealth
npx puppeteer browsers install chrome
```

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
| `->hover($selector)` | Hover over an element. |
| `->press($key)` | Press a key (`Enter`, `Tab`, `Escape`…). Pass `waitForNavigation: true` when it submits a form. |
| `->waitForSelector($selector)` | Wait until an element appears (lazy/JS content). |
| `->waitForNavigation()` | Wait for a navigation to finish. |
| `->wait($ms)` | Wait a fixed number of milliseconds. |
| `->scroll('bottom'\|'top')` / `->scrollToBottom()` | Scroll the page (infinite scroll / lazy load). |

If an action fails (for example a selector that never appears within the timeout), the scrape fails cleanly with `success = false` and the error message, just like an HTTP error.

> **Tip:** for a click or key press that loads a new page, use `waitForNavigation: true` on that action (or `clickAndWait()`) rather than a separate `->waitForNavigation()` call. That arms the wait *before* the click, avoiding a race where the navigation finishes before the wait starts.

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

