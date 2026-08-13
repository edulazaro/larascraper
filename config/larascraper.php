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
    |   ],
    |
    | Keys with no entry here are not throttled at all and never touch the cache,
    | so this costs nothing until you ask for it.
    |
    */

    'throttle' => [
        //
    ],

];
