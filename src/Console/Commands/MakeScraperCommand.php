<?php

namespace EduLazaro\Larascraper\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

class MakeScraperCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'make:scraper {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Scraper class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Scraper';

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return  __DIR__ . '/stubs/scraper.stub';
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return "{$rootNamespace}\\Scrapers";
    }

    /**
     * Replace placeholders inside the stub file.
     *
     * @param string $stub
     * @param string $name
     * @return string
     */
    protected function replaceClass($stub, $name)
    {
        $scraperClass = class_basename($name);
        $namespace = $this->getNamespace($name);

        return str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $scraperClass],
            $stub
        );
    }

    /**
     * Get the correct file path where the action should be created.
     *
     * @param string $name
     * @return string
     */
    protected function getPath($name)
    {
        // Remove the root namespace (e.g., "App\") from the class name
        $name = Str::replaceFirst($this->rootNamespace(), '', $name);
        $name = str_replace('\\', '/', $name);

        return app_path("{$name}.php");
    }
}
