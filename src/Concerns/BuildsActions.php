<?php

namespace EduLazaro\Larascraper\Concerns;

use Closure;
use EduLazaro\Larascraper\Support\ActionBuilder;

/*
|--------------------------------------------------------------------------
| Browser actions (a query-builder for the page)
|--------------------------------------------------------------------------
| These methods build an ordered list of actions that Puppeteer runs in a
| single browser session, after navigating to the URL and before grabbing the
| final HTML. The waits happen inside Node (where the page is alive), not in
| PHP. PHP only describes the recipe.
|
| when()/repeatUntil() add control flow: a closure receives a sub-builder you
| chain actions on, and the JS runner evaluates the condition against the live
| page at runtime to decide which branch (or how many loops) to run. The
| condition must be JS-evaluable (selectorExists/selectorMissing/textContains/
| urlContains) because PHP is not live inside the browser.
*/
trait BuildsActions
{
    /** @var array The ordered list of actions to run in the browser. */
    protected array $actions = [];

    /**
     * Get the built action list (used to nest a sub-builder's actions).
     *
     * @return array
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Click an element matching the CSS selector (waits for it first).
     *
     * @param string $selector CSS selector to click.
     * @param bool $waitForNavigation Set true when the click triggers a page
     *        load/navigation, so the wait is armed before the click (avoids a
     *        race). For clicks that only update the DOM, leave it false.
     */
    public function click(string $selector, bool $waitForNavigation = false): static
    {
        $action = ['type' => 'click', 'selector' => $selector];

        if ($waitForNavigation) {
            $action['waitForNavigation'] = true;
        }

        $this->actions[] = $action;
        return $this;
    }

    /**
     * Click an element and wait for the resulting navigation to finish.
     */
    public function clickAndWait(string $selector): static
    {
        return $this->click($selector, true);
    }

    /**
     * Type text into an input matching the CSS selector (waits for it first).
     */
    public function type(string $selector, string $text): static
    {
        $this->actions[] = ['type' => 'type', 'selector' => $selector, 'text' => $text];
        return $this;
    }

    /**
     * Select one or more options (by value) on a <select> matching the CSS selector.
     *
     * Pass a string to select a single option (unchanged behaviour). Pass an
     * array of values to select several at once on a <select multiple>; they are
     * applied in a single page.select() call so earlier values are not
     * deselected. An array is normalized to a sequential list of strings so it
     * always json_encode()s as a JSON array (not an object) and every element
     * reaches Puppeteer as a string; the Node runner spreads that list into
     * page.select(). A string value is recorded untouched.
     *
     * @param string $selector CSS selector of the <select>.
     * @param string|array $value A single value, or a list of values for a
     *        multi-select.
     */
    public function select(string $selector, string|array $value): static
    {
        if (is_array($value)) {
            $value = array_values(array_map(static fn ($v): string => (string) $v, $value));
        }

        $this->actions[] = ['type' => 'select', 'selector' => $selector, 'value' => $value];
        return $this;
    }

    /**
     * Set the value of an element directly (waits for it first), firing input + change
     * events so JS widgets/listeners react. Useful for hidden inputs that a custom widget
     * populates (multiselects backed by an <input type="hidden">), or fields that
     * type()/select() can't reach.
     */
    public function setValue(string $selector, string $value): static
    {
        $this->actions[] = ['type' => 'setValue', 'selector' => $selector, 'value' => $value];
        return $this;
    }

    /**
     * Tick every checkbox matching the CSS selector, firing a bubbling 'change'
     * event so JS widgets react.
     *
     * Sets `.checked = true` on each match and dispatches 'change', the pattern
     * multiselect widgets (e.g. bootstrap-multiselect) rely on, where the real
     * checkboxes live hidden inside a collapsed dropdown that a native click()
     * cannot reach. Already-ticked boxes are left untouched (no spurious event).
     * Uses querySelectorAll, so it handles multiple matches; a no-match is a
     * silent no-op.
     */
    public function check(string $selector): static
    {
        $this->actions[] = ['type' => 'check', 'selector' => $selector];
        return $this;
    }

    /**
     * Untick every checkbox matching the CSS selector, firing a bubbling 'change'
     * event so JS widgets react.
     *
     * The inverse of check(): sets `.checked = false` on each match and dispatches
     * 'change'. For widget-backed multiselects (e.g. bootstrap-multiselect) whose
     * checkboxes are hidden in a collapsed dropdown a native click() cannot reach.
     * Already-unticked boxes are left untouched (no spurious event). Uses
     * querySelectorAll, so it handles multiple matches; a no-match is a silent
     * no-op.
     */
    public function uncheck(string $selector): static
    {
        $this->actions[] = ['type' => 'uncheck', 'selector' => $selector];
        return $this;
    }

    /**
     * Hover over an element matching the CSS selector.
     */
    public function hover(string $selector): static
    {
        $this->actions[] = ['type' => 'hover', 'selector' => $selector];
        return $this;
    }

    /**
     * Press a keyboard key (e.g. "Enter", "Tab", "Escape").
     *
     * @param string $key The key to press.
     * @param bool $waitForNavigation Set true when the key press submits a form
     *        / triggers navigation, so the wait is armed before the press.
     */
    public function press(string $key, bool $waitForNavigation = false): static
    {
        $action = ['type' => 'press', 'key' => $key];

        if ($waitForNavigation) {
            $action['waitForNavigation'] = true;
        }

        $this->actions[] = $action;
        return $this;
    }

    /**
     * Wait until an element appears in the DOM.
     *
     * Pass a single CSS selector, or an array of selectors to wait for ANY of
     * them: the list is grouped into one comma selector, so the wait resolves as
     * soon as the first match appears (handy for "results OR a no-results
     * marker", where either is a valid terminal state).
     *
     * By default a timeout throws and fails the run. Set 'optional' => true to
     * treat a timeout as a valid outcome: the element may legitimately never
     * appear (e.g. an empty result set), so the wait is swallowed and the run
     * continues instead of failing. 'timeout' overrides the global fetch timeout
     * for this one wait (in milliseconds), so an optional wait can be kept short.
     *
     * @param string|array $selector A CSS selector, or a list of selectors to
     *        wait for any of.
     * @param array $options 'optional' (bool, default false: swallow a timeout
     *        and continue) and 'timeout' (int ms, overrides the global timeout
     *        for this wait only).
     */
    public function waitForSelector(string|array $selector, array $options = []): static
    {
        if (is_array($selector)) {
            $parts = array_values(array_filter(
                array_map(static fn ($s): string => trim((string) $s), $selector),
                static fn (string $s): bool => $s !== ''
            ));
            $selector = implode(', ', $parts);
        }

        if (trim($selector) === '') {
            throw new \InvalidArgumentException(
                'waitForSelector() needs a non-empty CSS selector (or a list with at least one non-empty selector).'
            );
        }

        $action = ['type' => 'waitForSelector', 'selector' => $selector];

        if (isset($options['timeout'])) {
            $action['timeout'] = (int) $options['timeout'];
        }

        if (! empty($options['optional'])) {
            $action['optional'] = true;
        }

        $this->actions[] = $action;
        return $this;
    }

    /**
     * Wait for a navigation/reload to finish (e.g. after a click or submit).
     */
    public function waitForNavigation(): static
    {
        $this->actions[] = ['type' => 'waitForNavigation'];
        return $this;
    }

    /**
     * Wait a fixed amount of time, in milliseconds.
     */
    public function wait(int $ms): static
    {
        $this->actions[] = ['type' => 'wait', 'ms' => $ms];
        return $this;
    }

    /**
     * Scroll the page to the top or bottom (useful for lazy/infinite content).
     *
     * @param string $to "bottom" (default) or "top".
     */
    public function scroll(string $to = 'bottom'): static
    {
        $this->actions[] = ['type' => 'scroll', 'to' => $to];
        return $this;
    }

    /**
     * Convenience alias for scroll('bottom').
     */
    public function scrollToBottom(): static
    {
        return $this->scroll('bottom');
    }

    /**
     * Solve a simple image (text) captcha and type the answer into an input.
     *
     * Screenshots the captcha image, reads it, and types the result into the
     * given field. Two solvers are supported via the 'solver' option:
     *   'ocr' (default): tesseract.js + jimp, optional Node packages installed
     *          with `php artisan larascraper:install --captcha`.
     *   'vision': an OpenAI vision model, higher accuracy on distorted captchas
     *          at the cost of an OpenAI API call per solve. Needs an API key in
     *          the options ('apiKey') or the OPENAI_API_KEY env var; the model
     *          defaults to 'gpt-4o-mini' (override with the 'model' option).
     *
     * @param string $imageSelector CSS selector of the captcha <img>.
     * @param string $inputSelector CSS selector of the input to type the answer into.
     * @param array $options Solver options: 'solver' (default 'ocr'); for OCR
     *        'whitelist', 'psm', 'crop', 'scale', 'threshold', 'contrast', 'lang';
     *        for vision 'apiKey', 'model', 'strip' (set false to keep punctuation).
     * @return static
     */
    public function solveCaptcha(string $imageSelector, string $inputSelector, array $options = []): static
    {
        $this->actions[] = [
            'type' => 'solveCaptcha',
            'imageSelector' => $imageSelector,
            'inputSelector' => $inputSelector,
            'solver' => $options['solver'] ?? 'ocr',
            'options' => $options,
        ];

        return $this;
    }

    /**
     * Submit a form in-page (via fetch). Reads the form's fields exactly as a
     * browser would — unchecked boxes and disabled inputs stay out, every
     * selected option of a multiple select goes in — and requests its action
     * with the form's own method. The composable replacement for
     * submitAndCapture():
     *
     *     ->submit('form')->capture(['expect' => 'application/pdf'])
     *
     * Use it when clicking the submit button does not work. That happens more
     * than it should: a widget that swallows the click, a handler bound to a
     * different element, a button covered by an open dropdown. All of them look
     * identical from outside — no error, no request, no change — and this path
     * sidesteps the page's own JavaScript entirely.
     *
     * ⚠️ THE PAGE DOES NOT SEE THE RESPONSE unless you say where to put it. The
     * fetch happens beside the DOM, not through it, so without `into` a crawler
     * keeps parsing the page you started from and a wait keeps waiting for a
     * selector that will never arrive. Pass a container to render into when the
     * response is HTML you intend to read:
     *
     *     ->submit('#search', ['into' => '#results'])
     *         ->waitForSelector('#results .row')
     *         ->crawl(ResultsCrawler::class)
     *
     * @param string $formSelector CSS selector of the <form> to submit.
     * `native` is the other way to do this, and usually the better one on a page
     * with JavaScript of its own: instead of making the request, it fires the
     * form's submit event and lets the page do everything — its handler, its
     * headers, its rendering. Nothing is forged and nothing needs `into`, because
     * the page paints the answer itself and a plain waitForSelector() sees it:
     *
     *     ->submit('#search', ['native' => true])->waitForSelector('.result')
     *
     * @param string $formSelector CSS selector of the <form> to submit.
     * @param array{into?: string, native?: bool} $options `into`: selector of the
     *        element to render an HTML response into. `native`: fire the form's
     *        own submit instead of requesting it here (then `into` is unused, and
     *        the response never reaches capture()).
     * @return static
     */
    public function submit(string $formSelector, array $options = []): static
    {
        $this->actions[] = [
            'type' => 'submit',
            'formSelector' => $formSelector,
            'into' => $options['into'] ?? null,
            'native' => $options['native'] ?? false,
        ];

        return $this;
    }

    /**
     * Submit a form and capture the response if it is a file/binary.
     *
     * @deprecated since 2.3, removed in 3.0. Use submit() + capture() instead:
     *             `->submit('form')->capture(['expect' => 'application/pdf'])`.
     *
     * Builds the form's fields, submits it in-page (via fetch), and if the
     * response matches `expect` (a content-type substring, e.g. 'application/pdf')
     * the bytes are captured into the ScraperResponse ($result->file). Pairs with
     * Condition::captured() inside repeatUntil() to retry until the file arrives.
     *
     * @param string $formSelector CSS selector of the <form> to submit.
     * @param array $options 'expect' (content-type substring to accept as a file).
     * @return static
     */
    public function submitAndCapture(string $formSelector, array $options = []): static
    {
        $this->actions[] = [
            'type' => 'submitAndCapture',
            'formSelector' => $formSelector,
            'expect' => $options['expect'] ?? null,
        ];

        return $this;
    }

    /**
     * Capture the binary of the response triggered by the PRECEDING action (a
     * click, navigation or submit that leads to a PDF or other file). Unlike
     * submitAndCapture() it needs no `<form>`: the browser records file-like
     * responses and this grabs the one matching the expected content type. The
     * bytes land in `$result->file` and the type in `$result->contentType`
     * (browser driver only). Pair with `repeatUntil(Condition::captured(), ...)`
     * to retry.
     *
     * @param string|array $options A content-type substring (e.g.
     *        `'application/pdf'`), or an options array: `'expect'` (content-type
     *        substring) and `'timeout'` (ms to wait for the response). No value
     *        captures the first file-like response.
     * @return static
     */
    public function capture(string|array $options = []): static
    {
        $opts = is_string($options) ? ['expect' => $options] : $options;

        $this->actions[] = [
            'type' => 'capture',
            'expect' => $opts['expect'] ?? null,
            'timeout' => $opts['timeout'] ?? null,
        ];

        return $this;
    }

    /**
     * Navigate to the URL held in an element's attribute.
     *
     * Reads `attr` from the first element matching `selector`, resolves it
     * against the current page URL, and navigates there. Useful when the next
     * page's URL lives in an attribute rather than a clickable link, e.g. an
     * `<object data="...">` / `<embed src="...">` PDF viewer.
     *
     * @param string $selector CSS selector of the element holding the URL.
     * @param string $attr Attribute to read (default 'href').
     * @param string $waitUntil Puppeteer navigation wait condition. Default
     *        'networkidle2'; use 'domcontentloaded' for sites that keep
     *        connections open and never reach network idle.
     * @return static
     */
    public function gotoAttr(string $selector, string $attr = 'href', string $waitUntil = 'networkidle2'): static
    {
        $this->actions[] = [
            'type' => 'gotoAttr',
            'selector' => $selector,
            'attr' => $attr,
            'waitUntil' => $waitUntil,
        ];

        return $this;
    }

    /**
     * Reload the current page.
     *
     * Handy inside repeatUntil() loops where each attempt needs a freshly
     * regenerated page, e.g. requesting a new captcha image before solving it.
     *
     * @param string $waitUntil Puppeteer navigation wait condition (default
     *        'networkidle2'; use 'domcontentloaded' for never-idle sites).
     * @return static
     */
    public function reload(string $waitUntil = 'networkidle2'): static
    {
        $this->actions[] = ['type' => 'reload', 'waitUntil' => $waitUntil];

        return $this;
    }

    /**
     * Navigate to an absolute or relative URL.
     *
     * Like the initial scrape URL, but as a mid-flow action, e.g. returning to
     * a viewer page at the start of each repeatUntil() iteration so the next
     * step (gotoAttr/captcha) starts from a fresh server state.
     *
     * @param string $url Destination URL (resolved against the current page).
     * @param string $waitUntil Puppeteer navigation wait condition. Default
     *        'networkidle2'; use 'domcontentloaded' for sites that keep
     *        connections open and never reach network idle.
     * @return static
     */
    public function visit(string $url, string $waitUntil = 'networkidle2'): static
    {
        $this->actions[] = ['type' => 'goto', 'url' => $url, 'waitUntil' => $waitUntil];

        return $this;
    }

    /**
     * Conditionally run a branch of actions, evaluated against the live page.
     *
     * The condition is JS-evaluable data (not a PHP boolean), e.g.
     * `['type' => 'selectorExists', 'selector' => '.results']`. The $then
     * closure receives a sub-builder; chain actions on it. An optional $else
     * closure runs when the condition is false.
     *
     * @param array $condition JS-evaluable condition descriptor.
     * @param Closure $then Builds the actions to run when the condition holds.
     * @param Closure|null $else Builds the actions to run otherwise.
     * @return static
     */
    public function when(array $condition, Closure $then, ?Closure $else = null): static
    {
        $action = [
            'type' => 'when',
            'condition' => $condition,
            'then' => $this->buildBranch($then),
        ];

        if ($else !== null) {
            $action['else'] = $this->buildBranch($else);
        }

        $this->actions[] = $action;
        return $this;
    }

    /**
     * Repeat a branch of actions until a condition holds (or max is reached).
     *
     * Useful for "retry until it works" flows, e.g. solving a captcha until the
     * captcha image disappears. The loop is ALWAYS bounded: `max` defaults to 5
     * and is clamped to at least 1, so it can never run unbounded and hammer a
     * server. Use `delay` to throttle the time between iterations (recommended
     * when each iteration hits a remote server).
     *
     * @param array $condition JS-evaluable condition descriptor (stop when true).
     * @param Closure $body Builds the actions to run each iteration.
     * @param int $max Maximum iterations before giving up (hard upper bound).
     * @param int $delay Milliseconds to wait between iterations (0 = no wait).
     * @return static
     */
    public function repeatUntil(array $condition, Closure $body, int $max = 5, int $delay = 0): static
    {
        $this->actions[] = [
            'type' => 'repeatUntil',
            'condition' => $condition,
            'max' => max(1, $max),
            'delay' => max(0, $delay),
            'body' => $this->buildBranch($body),
        ];

        return $this;
    }

    /**
     * Run a closure against a fresh ActionBuilder and return its action list.
     *
     * @param Closure $callback
     * @return array
     */
    protected function buildBranch(Closure $callback): array
    {
        $builder = new ActionBuilder();
        $callback($builder);
        return $builder->getActions();
    }
}
