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

];
