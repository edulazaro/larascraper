<?php

namespace EduLazaro\Larascraper\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ListScrapersCommand extends Command
{
    protected $signature = 'list:scrapers';
    protected $description = 'List all registered scrapers, including subfolders';

    public function handle()
    {
        $scrapersPath = app_path('Scrapers');

        if (!File::exists($scrapersPath)) {
            $this->error("No scrapers found in $scrapersPath");
            return;
        }

        // Recursively scan subdirectories
        $files = File::allFiles($scrapersPath);

        if (empty($files)) {
            $this->warn("No scraper files found in $scrapersPath.");
            return;
        }

        $this->info("Available Scrapers:");

        foreach ($files as $file) {
            $relativePath = str_replace([$scrapersPath, '/', '.php'], ['', '\\', ''], $file->getRealPath());
            $scraperClass = "App\\Scrapers{$relativePath}";

            $this->line("- $scraperClass");
        }
    }
}