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
        {--no-browser : Skip downloading the Chrome binary (e.g. when using a system Chrome via PUPPETEER_EXECUTABLE_PATH)}
        {--publish : Also publish scraper.cjs to the project root}';

    protected $description = 'Install Larascraper: install the Node packages and the Chrome binary Puppeteer needs (and optionally publish scraper.cjs)';

    /**
     * Publish the script (optional), install the Node packages and the Chrome binary.
     *
     * @return int
     */
    public function handle(): int
    {
        $packages = implode(' ', self::NODE_PACKAGES);
        $base = escapeshellarg(base_path());

        if ($this->option('publish')) {
            $this->info('Publishing scraper.cjs ...');
            $this->call('vendor:publish', [
                '--tag' => 'larascraper-scripts',
                '--force' => true,
            ]);
        }

        // 1. Node packages.
        if ($this->option('no-npm')) {
            $this->warn('Skipping npm install. Install the Node packages manually:');
            $this->line("    npm install {$packages}");
        } else {
            $this->info("Installing Node packages: {$packages}");

            passthru("cd {$base} && npm install {$packages}", $npmExit);

            if ($npmExit !== 0) {
                $this->error('npm install failed. Run it manually:');
                $this->line("    npm install {$packages}");
                return self::FAILURE;
            }
        }

        // 2. Chrome binary. Puppeteer needs it, but its postinstall download is
        //    skipped when the packages are already present (e.g. a node_modules
        //    mounted into a container), so install it explicitly here.
        if ($this->option('no-browser')) {
            $this->warn('Skipping the Chrome download (--no-browser). Make sure a browser is available,');
            $this->line('    e.g. set PUPPETEER_EXECUTABLE_PATH, or run: npx puppeteer browsers install chrome');
        } else {
            $this->info('Installing the Chrome binary Puppeteer needs ...');

            passthru("cd {$base} && npx --yes puppeteer browsers install chrome", $browserExit);

            if ($browserExit !== 0) {
                $this->error('Chrome install failed. Run it manually:');
                $this->line('    npx puppeteer browsers install chrome');
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Larascraper installed. The Puppeteer scraper is ready to run.');

        return self::SUCCESS;
    }
}
