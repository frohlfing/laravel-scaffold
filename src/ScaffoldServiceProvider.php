<?php

namespace FRohlfing\Scaffold;

use FRohlfing\Scaffold\Console\Commands\ScaffoldCreateCommand;
use FRohlfing\Scaffold\Console\Commands\ScaffoldRemoveCommand;
use Illuminate\Support\ServiceProvider;

class ScaffoldServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * Wenn das Package Routen beinhaltet, muss hier false stehen!
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
    }

    /**
     * Bootstrap any application services.
     *
     * This method is called after all other service providers have been registered, meaning you have access to all
     * other services that have been registered by the framework.
     *
     * @return void
     */
    public function boot()
    {
        // stubs
        $this->publishes([__DIR__ . '/../resources/stubs' => resource_path('stubs/vendor/scaffold')], 'stubs');

        // commands
        if ($this->app->runningInConsole()) {
            $this->commands([ScaffoldCreateCommand::class]);
            $this->commands([ScaffoldRemoveCommand::class]);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
