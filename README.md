# Larascraper - A Simple Scraper for Laravel

<p align="center">
    <a href="https://packagist.org/packages/edulazaro/larascraper"><img src="https://img.shields.io/packagist/dt/edulazaro/larascraper" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/larascraper"><img src="https://img.shields.io/packagist/v/edulazaro/larascraper" alt="Latest Stable Version"></a>
</p>

## Introduction

Larascrape allows you to scrape any URL using Laravel. It uses Puppeteer under the hood. Unlikely Sapatie Crawler or Browsershot, this scraper focuses on simplicity. While Spatie Crawler can leave opened many Chromium instances, filling your server memory, Larascrape starts the scraping process using Node, making sure the Chromium instance is closed before existint.

Unlikely Spatie Crawler, it supports Proxy authentication and in general is faster.

## Install

Run this command via Composer:

```bash
composer require edulazaro/larascraper
```

Then install the required Node dependencies:

```bash
npm install puppeteer puppeteer-extra puppeteer-extra-plugin-stealth
```

These packages are required for the internal Puppeteer script to run.


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

$data = BikeScraper::scrape('https://whatever.com/bikes/4')
    ->proxy('ip:port', 'username', 'password') // Optional
    ->timeout(10000) // Optional timeout in ms
    ->headers(['Accept-Language' => 'en']) // Optional headers
    ->run();

dd($data);
```

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

## Retry logic

You can add the number of attempts and the number of seconds to wait between attempts:

```php
->retry(3, 5)
```

Retry 3 times and wait 5 seconds betwee attempts. Please note only the error codes 408, 429, 500, 502, 503 and 504 will be retried.

## Artisan Commands

You can generate a scraper instance with:

```bash
php artisan make:scraper MyScraper
```

List all scrapers in app/Scrapers directory:

```bash
php artisan list:scrapers
```

## Testing a scraper

You can easily test a scraper with Tinker:

```bash
php artisan tinker
```

And the running:

```php
$data = \App\Scrapers\TestScraper::scrape('https://whatever.com')->run();
dd($data);
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

