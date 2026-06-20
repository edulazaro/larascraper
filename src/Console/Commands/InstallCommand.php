<?php

namespace EduLazaro\Larascraper\Console\Commands;

use Illuminate\Console\Command;

/**
 * Install the Node packages Puppeteer needs (and optionally publish scraper.cjs).
 */
class InstallCommand extends Command
{
    /** Node packages the bundled scraper.cjs needs to run. */
    private const NODE_PACKAGES = [
        'puppeteer',
        'puppeteer-extra',
        'puppeteer-extra-plugin-stealth',
    ];

    protected $signature = 'larascraper:install
        {--no-npm : Skip installing the Node packages, only show the command}
        {--publish : Also publish scraper.cjs to the project root}';

    protected $description = 'Install Larascraper: install the Node packages Puppeteer needs (and optionally publish scraper.cjs)';

    /**
     * Publish the script (optional) and install the required Node packages.
     *
     * @return int
     */
    public function handle(): int
    {
        $packages = implode(' ', self::NODE_PACKAGES);

        if ($this->option('publish')) {
            $this->info('Publishing scraper.cjs ...');
            $this->call('vendor:publish', [
                '--tag' => 'larascraper-scripts',
                '--force' => true,
            ]);
        }

        if ($this->option('no-npm')) {
            $this->warn('Skipping npm install. Install the Node packages manually:');
            $this->line("    npm install {$packages}");
            return self::SUCCESS;
        }

        $this->info("Installing Node packages: {$packages}");

        passthru('cd ' . escapeshellarg(base_path()) . ' && npm install ' . $packages, $exitCode);

        if ($exitCode !== 0) {
            $this->error('npm install failed. Run it manually:');
            $this->line("    npm install {$packages}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Larascraper installed. The Puppeteer scraper is ready to run.');

        return self::SUCCESS;
    }
}
