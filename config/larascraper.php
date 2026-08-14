<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies
    |--------------------------------------------------------------------------
    |
    | Proxies available to every scrape that does not set one explicitly with
    | ->proxy(). When more than one is listed, a random one is picked per
    | request, which spreads traffic across IPs and survives a single address
    | being blocked by the target site.
    |
    | Each entry may be either a plain string or an array:
    |
    |   'proxies' => [
    |       '203.0.113.10:8080',
    |       'http://user:secret@203.0.113.11:8080',
    |       'socks5://203.0.113.12:1080',
    |       ['url' => '203.0.113.13:8080', 'user' => 'user', 'pass' => 'secret'],
    |   ],
    |
    | Credentials written inline are split out of the URL before use: Chrome
    | ignores them in --proxy-server, so the browser runner needs them apart.
    | Both spellings end up in the same place, so pick whichever reads better.
    |
    | An explicit ->proxy() call always wins over this list.
    |
    */

    'proxies' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | User agent (http driver only)
    |--------------------------------------------------------------------------
    |
    | Who the `http` driver says it is. Without this it introduces itself as
    | 'GuzzleHttp/7', which is not neutral — it announces that a script is
    | calling, and plenty of sites answer accordingly.
    |
    | ⚠️ THE `browser` DRIVER IGNORES THIS, deliberately. It asks the Chrome it
    | just launched and drops the word that gives headless away, so what it claims
    | always matches what it is. Letting a string from here override that would
    | put back the exact bug this replaced: a user agent naming one version while
    | Client Hints emit another, which is a louder tell than an honest headless
    | one. Nobody is here to ask on the http driver, so a written-out value is the
    | only option — and it is HERE, not in the code, so you can bump it without
    | waiting for a release.
    |
    | It ages, which is why it lives here and not in the code: you can bump it
    | without waiting for a release. Every scraper property, ->userAgent() call,
    | and explicit User-Agent header still wins over it.
    |
    | ⚠️ SET IT TO '' TO CLAIM NOTHING. That is not neutral — the HTTP client then
    | introduces the request as 'GuzzleHttp/7', announcing that a script is
    | calling — but it is what the package sent before this option existed, so it
    | is the one line back to the old behaviour if you were relying on it.
    |
    */

    'http_user_agent' => \EduLazaro\Larascraper\Runners\HttpRunner::DEFAULT_USER_AGENT,

    /*
    |--------------------------------------------------------------------------
    | Throttling
    |--------------------------------------------------------------------------
    |
    | Pacing and proxy lockout, per THROTTLE KEY. A scraper declares its key with
    | the $throttleKey property; without one it uses the host of the URL it is
    | fetching. Keys are deliberately not hosts: one domain can serve a listing
    | happily while refusing a download endpoint, and a lockout earned by one
    | should not stop the other. Scrapers that should share a budget share a key.
    |
    |   'cendoj.search' => [
    |       'interval'  => 10,    // seconds between requests, across all processes
    |       'lock_base' => 120,   // first lockout after a proxy is refused
    |       'lock_max'  => 3600,  // ceiling; each further refusal doubles the wait
    |       'max_wait'  => 30,    // give up rather than hold a worker longer (0 = no limit)
    |   ],
    |
    | Requests reserve their turn instead of waiting on the last one, so processes
    | that wake together depart apart. With 'max_wait' set, a turn further away
    | than that throws a ThrottledException rather than parking a queue worker.
    |
    | Keys with no entry here are not throttled at all and never touch the cache,
    | so this costs nothing until you ask for it.
    |
    */

    'throttle' => [
        //
    ],

];
