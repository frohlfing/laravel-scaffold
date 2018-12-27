# Scaffold Generator for Laravel 5.7

This package provides following artisan commands:

- scaffold:create - Create a new model, migration file and user interface.
- scaffold:remove - Remove all files that the scaffolder created.

## Installation
    
I have not yet deployed this package to Packagist, the Composers default package archive. Therefore, you must tell 
Composer where the package is. To do this, add the following lines into your `composer.json`:

    "repositories": [
        {
            "type": "git",
            "url": "https://github.com/frohlfing/laravel-scaffold.git"
        }
    ],

Download this package by running the following command:

    composer require frohlfing/laravel-scaffold:1.57.*@dev

Publish the stubs for editing. The stubs will be placed in `resources/stubs/vendor/scaffold`.

    php artisan vendor:publish --provider="FRohlfing\Scaffold\ScaffoldServiceProvider" --tag=stubs
     