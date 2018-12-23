<?php

namespace FRohlfing\Scaffold\Console\Commands;

use Exception;
use FRohlfing\Scaffold\Services\Scaffolder;
use Illuminate\Console\Command;

/*
 * Test:
 * php artisan devtools:scaffold -n -f -c -m AllField array1:array bigint1:bigint binary1:binary bool1:bool char1:char collection1:collection date1:date datetime1:datetime decimal1:decimal5,3 float1:float guid1:guid int1:int longtext1:longtext object1:object smallint1:smallint string1:string text1:text time1:time uint1:uint select1:string25[a,b], select2:int[one,two]=1
*/

class ScaffoldRemoveCommand extends Command
{
    /**
     * Exit Codes.
     */
    const EXIT_SUCCESS = 0;
    const EXIT_FAILURE = 1;

    /**
     * The name and signature of the console command.
     *
     * Inherited options:
     *   -h, --help            Display this help message
     *   -q, --quiet           Do not output any message
     *   -V, --version         Display this application version
     *       --ansi            Force ANSI output
     *       --no-ansi         Disable ANSI output
     *   -n, --no-interaction  Do not ask any interactive question
     *       --env[=ENV]       The environment the command should run under
     *   -v|vv|vvv, --verbose  Increase the verbosity of messages
     *
     * @var string
     */
    protected $signature = 'scaffold:remove 
                            { model : Name of the model }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove all files that the scaffolder created.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $options = $this->options();
            $options['model']   = ucfirst(str_singular($this->argument('model')));
            $options['table']   = snake_case(str_plural($this->argument('model')));
            $options['package'] = kebab_case(str_plural($options['model']));
            $scaffolder = new Scaffolder($options, $this->input, $this->output);
            $scaffolder->remove();
        }
        catch (Exception $e) {
            $this->error($e->getMessage());
            return static::EXIT_FAILURE;
        }

        $this->info('Files removed successfully!');

        return static::EXIT_SUCCESS;
    }
}
