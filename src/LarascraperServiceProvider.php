<?php

namespace EduLazaro\Larascraper;

use Illuminate\Support\ServiceProvider;
use EduLazaro\Larascraper\Console\Commands\MakeScraperCommand;
use EduLazaro\Larascraper\Console\Commands\ListScrapersCommand;

class LarascraperServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../resources/scraper.cjs' => base_path('scraper.cjs'),
        ], 'larascraper-scripts');


        if ($this->app->runningInConsole()) {

            $this->commands([MakeScraperCommand::class]);
            $this->commands([ListScrapersCommand::class]);
        }
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}