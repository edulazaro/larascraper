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
     * Select an option (by value) on a <select> matching the CSS selector.
     */
    public function select(string $selector, string $value): static
    {
        $this->actions[] = ['type' => 'select', 'selector' => $selector, 'value' => $value];
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
     * Wait until an element matching the CSS selector appears in the DOM.
     */
    public function waitForSelector(string $selector): static
    {
        $this->actions[] = ['type' => 'waitForSelector', 'selector' => $selector];
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
     * given field. The default solver is OCR (tesseract.js + jimp), which are
     * optional Node packages; install them with `php artisan larascraper:install
     * --captcha`. The `solver` option is left open for future solvers (e.g.
     * 'vision'); only 'ocr' is supported today.
     *
     * @param string $imageSelector CSS selector of the captcha <img>.
     * @param string $inputSelector CSS selector of the input to type the answer into.
     * @param array $options Solver options: 'solver' (default 'ocr'), and for OCR
     *        'whitelist', 'psm', 'crop', 'scale', 'threshold', 'contrast', 'lang'.
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
     * Submit a form and capture the response if it is a file/binary.
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
     * Navigate to the URL held in an element's attribute.
     *
     * Reads `attr` from the first element matching `selector`, resolves it
     * against the current page URL, and navigates there. Useful when the next
     * page's URL lives in an attribute rather than a clickable link — e.g. an
     * `<object data="...">` / `<embed src="...">` PDF viewer.
     *
     * @param string $selector CSS selector of the element holding the URL.
     * @param string $attr Attribute to read (default 'href').
     * @return static
     */
    public function gotoAttr(string $selector, string $attr = 'href'): static
    {
        $this->actions[] = [
            'type' => 'gotoAttr',
            'selector' => $selector,
            'attr' => $attr,
        ];

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
