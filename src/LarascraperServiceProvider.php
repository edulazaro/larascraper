<?php

namespace EduLazaro\Larascraper;

use Illuminate\Support\ServiceProvider;
use EduLazaro\Larascraper\Console\Commands\MakeScraperCommand;
use EduLazaro\Larascraper\Console\Commands\ListScrapersCommand;
use EduLazaro\Larascraper\Console\Commands\InstallCommand;

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

        $this->publishes([
            __DIR__.'/../config/larascraper.php' => config_path('larascraper.php'),
        ], 'larascraper-config');


        if ($this->app->runningInConsole()) {

            $this->commands([MakeScraperCommand::class]);
            $this->commands([ListScrapersCommand::class]);
            $this->commands([InstallCommand::class]);
        }
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        // Defaults available even when the app has not published the file, so
        // config('larascraper.*') stops silently falling back to hardcoded values.
        $this->mergeConfigFrom(__DIR__.'/../config/larascraper.php', 'larascraper');
    }
}