<?php

namespace EduLazaro\Larascraper\Support;

/**
 * Factory of JS-evaluable conditions for when()/repeatUntil().
 *
 * A condition is plain data (an array) that the Node runner evaluates against
 * the live page at runtime; PHP is not inside the browser, so it can only
 * describe *what* to check, not run the check itself. Each method returns the
 * array that travels to the scraper script.
 */
class Condition
{
    /**
     * True when an element matching the selector exists in the DOM.
     */
    public static function selectorExists(string $selector): array
    {
        return ['type' => 'selectorExists', 'selector' => $selector];
    }

    /**
     * True when no element matching the selector exists in the DOM.
     */
    public static function selectorMissing(string $selector): array
    {
        return ['type' => 'selectorMissing', 'selector' => $selector];
    }

    /**
     * True when the given text is found (optionally within a selector).
     *
     * @param string $text The substring to look for.
     * @param string|null $selector Limit the search to this element; null = whole page.
     */
    public static function textContains(string $text, ?string $selector = null): array
    {
        $condition = ['type' => 'textContains', 'text' => $text];

        if ($selector !== null) {
            $condition['selector'] = $selector;
        }

        return $condition;
    }

    /**
     * True when the current page URL contains the given substring.
     */
    public static function urlContains(string $text): array
    {
        return ['type' => 'urlContains', 'text' => $text];
    }

    /**
     * True once a file/binary has been captured (by submitAndCapture).
     *
     * Use inside repeatUntil() to stop retrying as soon as the file arrives.
     */
    public static function captured(): array
    {
        return ['type' => 'captured'];
    }
}
