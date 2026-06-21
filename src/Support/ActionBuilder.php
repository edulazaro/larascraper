<?php

namespace EduLazaro\Larascraper\Support;

use EduLazaro\Larascraper\Concerns\BuildsActions;

/**
 * A lightweight builder used to collect a branch of browser actions.
 *
 * Closures passed to when()/repeatUntil() receive an instance of this class and
 * chain the same action methods available on the main scraper. It only
 * accumulates actions (via the BuildsActions trait); it does not run anything.
 */
class ActionBuilder
{
    use BuildsActions;
}
