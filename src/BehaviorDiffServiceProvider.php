<?php

namespace Toshkq93\BehaviorDiffLaravel;

use Illuminate\Support\ServiceProvider;
use Toshkq93\BehaviorDiffLaravel\Console\DiffCommand;
use Toshkq93\BehaviorDiffLaravel\Console\RunCommand;
use Toshkq93\BehaviorDiffLaravel\Console\ScanCommand;

class BehaviorDiffServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/behavior-diff.php', 'behavior-diff');
    }

    public function boot()
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ScanCommand::class,
            RunCommand::class,
            DiffCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/behavior-diff.php' => $this->app->configPath('behavior-diff.php'),
        ], 'behavior-diff-config');
    }
}
