<?php

namespace FRohlfing\Scaffold\Console\Commands;

use Exception;
use FRohlfing\Scaffold\Services\Scaffolder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/*
 * Test:
 * php artisan devtools:scaffold -n -f -c -m AllField array1:array bigint1:bigint binary1:binary bool1:bool char1:char collection1:collection date1:date datetime1:datetime decimal1:decimal5,3 float1:float guid1:guid int1:int longtext1:longtext object1:object smallint1:smallint string1:string text1:text time1:time uint1:uint select1:string25[a,b], select2:int[one,two]=1
*/

class ScaffoldCreateCommand extends Command
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
    protected $signature = 'scaffold:create 
                            { model               : Name of the model }
                            { fields?*            : Space separated list of fields (optional) }
                            { --p|package=        : Package name (default: kebab case and plural of model name) }
                            { --t|table=          : Use this table for the model. The Table must exists }
                            { --b|big-increments  : Use unsigned bigint as primary key instead of unsigned int }
                            { --d|no-timestamps   : Do not create timestamps created_at and updated_at }
                            { --u|skip-ui         : Create just only the model and migration file }
                            { --r|expose-routes   : Register routes separately instead of using Route::resource() }
                            { --m|migrate         : Run the database migration }
                            { --c|composer        : Dump composer autoload files }
                            { --f|force           : Overwrite any existing files }
                            { --s|theme=          : Theme (default or vue) }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new model, migration file and user interface.';

    /**
     * Field types that can be used in the command argument.
     *
     * @var array
     */
    private $fieldTypes = [
        'array', 'bigint', 'binary', 'bool', 'char', 'collection', 'date', 'datetime', 'decimal',
        'float', 'guid', 'int', 'longtext', 'object', 'smallint', 'string', 'text', 'time', 'uint',
    ];

    /**
     * Available themes.
     *
     * @var array
     */
    private $themes = ['default', 'vue'];

    /**
     * Create a new console command instance.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setHelp(
            "Field Definition:\n" .
            "  <name>:<type><length>[option1,options2,...]!=<default>\n" .
            "    name      \tField name\n" .
            "    :type     \tField type (default: string)\n" .
            "    length    \tField length (optional; only for string, char and decimal)\n" .
            "    [options] \tComma separated list of options to create a select field (optional)\n" .
            "    !         \tRequired field (optional)\n" .
            "    =default  \tDefault value (optional)\n" .
            "\n" .
            "Possible field types:\n" .
            "  " . implode(', ', $this->fieldTypes) . "\n" .
            "\n" .
            "EXAMPLE:\n" .
            "  scaffold:create Customer email name:string50! birthday:date gender:string[male,female]!=1 vip:bool=false amount:decimal8,2"
        );
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $options = $this->options();

            if (!isset($options['theme'])) {
                $options['theme'] = 'default';
            }
            else if (!in_array($options['theme'], $this->themes)) {
                $this->error('Theme "' . $options['theme'] . '" is not defined!');
                $options['theme'] = $this->choice('Which theme should be used?', $this->themes, 0);
            }

            $options['model'] = ucfirst(str_singular($this->argument('model')));

            if (!isset($options['package'])) {
                $options['package'] = kebab_case(str_plural($options['model']));
            }

            if (!empty($options['table'])) {
                if (!empty($this->argument('fields'))) {
                    throw new InvalidArgumentException('Option "table" is invalid when a list of fields is entered.');
                }
                $options['fields']  = $this->readFieldsFromTableSchema();
                $options['uniques'] = $this->readUniqueIndexesFromTableSchema();
                $options['indexes'] = $this->readSimpleIndexesFromTableSchema();
            }
            else {
                $options['table']   = snake_case(str_plural($this->argument('model')));
                $options['fields']  = $this->askFields();
                $options['uniques'] = $this->askIndexes(array_keys($options['fields']), true);
                $options['indexes'] = $this->askIndexes(array_keys($options['fields']), false);
            }

            $options['relations'] = $this->askRelations();

            $scaffolder = new Scaffolder($options, $this->input, $this->output);
            $scaffolder->create();
        }
        catch (Exception $e) {
            $this->error($e->getMessage());
            return static::EXIT_FAILURE;
        }

        $this->info('Files created successfully!');

        return static::EXIT_SUCCESS;
    }

    /**
     * Read the fields from the table schema.
     */
    private function readFieldsFromTableSchema()
    {
        $fields = [];

        //$this->line('Read database table schema of "' . $this->option('table') . '".');

        /** @var \Illuminate\Database\Connection $db */
        $db = DB::connection();
        $table = $db->getTablePrefix() . $this->option('table');
        $schema = $db->getDoctrineSchemaManager();
        if (!$schema->tablesExist([$table])) {
            throw new InvalidArgumentException('Table "' . $table . '" does not exist.');
        }

        $columns = $schema->listTableColumns($table);

        // doctrine types:
        //  - identical:  smallint, bigint, decimal, float, string, text, guid, binary, date, datetime, time, blob, array, object
        $map = [
            'blob'     => 'binary',
            'boolean'  => 'bool',
            'integer'  => 'int',
            'json'     => 'array',
        ];

        foreach ($columns as $column) {
            $name = $column->getName();

            if (in_array($name, ['id', 'created_at', 'updated_at']) || $column->getAutoincrement()) {
                continue;
            }

            $type = $column->getUnsigned() ? 'uint' : $column->getType()->getName();
            if ($type === 'string' && $column->getFixed()) {
                if ($column->getLength() === 36 && (strpos($name, 'guid') !== false || strpos($name, 'uuid') !== false)) {
                    $type = 'guid';
                }
                else {
                    $type = 'char';
                }
            }
            else if ($type === 'text' && ($column->getLength() === 0 || $column->getLength() > 65535)) {
                $type = 'longtext';
            }
            else if (!in_array($type, $this->fieldTypes)) {
                if (isset($map[$type])) {
                    $type = $map[$type];
                }
                else {
                    $this->warn('Unsupported type "' . $type . '". Use "string" for field "' . $name . '" instead.');
                    $type = 'string';
                }
            }

            $length = null;
            $scale = null;
            if (in_array($type, ['string', 'char'])) {
                $length = $column->getLength() !== 255 ? $column->getLength() : null;
            }
            else if ($type === 'decimal') {
                $length = $column->getPrecision() !== 8  ? $column->getPrecision() : null;
                $scale = $column->getScale() !== 2 ? $column->getScale() : null;
            }

            $fields[$name] = [
                'name'      => $name,
                'type'      => $type,
                'default'   => $type === 'bool' ? ($column->getDefault() ?: null) : $column->getDefault(),
                'required'  => $type !== 'bool' && $column->getNotnull(),
                'length'    => $length,
                'scale'     => $scale,
                'list'      => null,
            ];
        }

        return $fields;
    }

    /**
     * Read the unique indexes from the table schema.
     */
    private function readUniqueIndexesFromTableSchema()
    {
        $uniques = [];

        //$this->line('Determine unique fields from table "' . $this->option('table') . '".');

        /** @var \Illuminate\Database\Connection $db */
        $db = DB::connection();
        $table = $db->getTablePrefix() . $this->option('table');
        $schema = $db->getDoctrineSchemaManager();
        $tableIndexes = $schema->listTableIndexes($table);

        foreach ($tableIndexes as $index) {
            if (!$index->isPrimary() && $index->isUnique()) {
                $uniques[] = $index->getColumns();
            }
        }

        return $uniques;
    }

    /**
     * Read the simple indexes from the table schema.
     */
    private function readSimpleIndexesFromTableSchema()
    {
        $indexes = [];

        //$this->line('Determine indexes from table "' . $this->option('table') . '".');

        /** @var \Illuminate\Database\Connection $db */
        $db = DB::connection();
        $table = $db->getTablePrefix() . $this->option('table');
        $schema = $db->getDoctrineSchemaManager();
        $tableIndexes = $schema->listTableIndexes($table);

        foreach ($tableIndexes as $index) {
            if ($index->isSimpleIndex()) {
                $indexes[] = $index->getColumns();
            }
        }

        return $indexes;
    }

    /**
     * Ask the information about the fields.
     */
    private function askFields()
    {
        $fields = [];

        $arguments = !empty($this->argument('fields')) ? $this->argument('fields') : [];
        if (!$this->option('no-interaction') && empty($arguments)) {
            $this->comment('Please enter a space separated list of database fields.' . PHP_EOL);
            $this->line(str_replace('devtools:scaffold Customer ', '', $this->getHelp()));
            $input = $this->ask('Fields ? [space separated list]');
            $arguments = $input !== null ? explode(' ', $input) : [];
        }

        foreach ($arguments as $index => $field) {
            $pieces = explode(':', $field);
            $name = $pieces[0];
            $type = count($pieces) > 1 ? $pieces[1] : 'string';

            // name and title
            $fields[$name] = [];
            $fields[$name]['name'] = $name;

            // default value
            $fields[$name]['default'] = null;
            if (strpos($type, '=') !== false) {
                $pieces = explode('=', $type);
                $type = $pieces[0];
                $fields[$name]['default'] = $pieces[1];
            }

            // determine if required
            $fields[$name]['required'] = false;
            if (strpos($type, '!') !== false) {
                $fields[$name]['required'] = true;
                $type = str_replace('!', '', $type);
            }

            // select list
            $fields[$name]['list'] = null;
            if (($pos1 = strpos($type, '[')) !== false && ($pos2 = strpos($type, ']')) !== false && $pos1 < $pos2) {
                $fields[$name]['list'] = array_map('trim', explode(',', substr($type, $pos1 + 1, $pos2 - $pos1 - 1)));
                $type = substr($type, 0, $pos1) . substr($type, $pos2 + 1);
            }

            // separate length from type
            $length = null;
            $scale = null;
            $type = preg_replace_callback('/(\d+)(?:,(\d+))?/', function ($matches) use (&$length, &$scale) {
                $length = $matches[1];
                if (isset($matches[2])) {
                    $scale = $matches[2];
                }
                return '';
            }, $type);

            // validate the (pure) field type
            $type = strtolower($type);
            while (!in_array($type, $this->fieldTypes)) {
                $this->error('Field type "' .$type. '" is invalid.');
                if ($this->option('no-interaction')) {
                    $this->warn('Type "string" is used for field "' . $name . '" instead.');
                    $type = 'string';
                }
                else {
                    $type = strtolower($this->ask('Which type is right for field "' . $name . '"?', 'string'));
                }
            }

            // set length and scale
            $fields[$name]['length'] = $length !== null && in_array($type, ['string', 'char', 'decimal']) ? (int)$length : null;
            $fields[$name]['scale'] = $scale !== null && $type === 'decimal' && (int)$scale < (int)$length ? (int)$scale : null;

            // default type cast
            if (isset($fields[$name]['default'])) {
                if (strtolower($fields[$name]['default']) === 'null') {
                    $fields[$name]['default'] = null;
                }
                else if (in_array($type, ['bigint', 'int', 'smallint', 'uint'])) {
                    $fields[$name]['default'] = $fields[$name]['default'] * 1 ;
                }
                else if ($type === 'float') {
                    $fields[$name]['default'] = $fields[$name]['default'] * 1.0 ;
                }
                else if ($type === 'bool') {
                    if (strtolower($fields[$name]['default']) === 'true') {
                        $fields[$name]['default'] = true;
                    }
                    else if (strtolower($fields[$name]['default']) === 'false') {
                        $fields[$name]['default'] = false;
                    }
                    else {
                        $fields[$name]['default'] = boolval($fields[$name]['default']);
                    }
                }
            }

            // field type
            $fields[$name]['type'] = $type;
        }

        return $fields;
    }

    /**
     * Ask the unique indexes.
     *
     * @param array $choices
     * @param bool $unique
     * @return array
     */
    private function askIndexes($choices, $unique = false)
    {
        $indexes = [];

        $input = $this->ask('Which fields should be ' . ($unique ? 'unique' : 'indexed') . '? [space separated list]');
        $input = $input !== null ? explode(' ', $input) : [];

        foreach ($input as $item) {
            $fields = explode(',', $item);
            $index = [];
            foreach ($fields as $field) {
                while ($field !== null && !in_array($field, $choices)) {
                    $this->error('Field "' . $field . '" is not specified!');
                    $field = $this->ask('Which field should be ' . ($unique ? 'unique' : 'indexed') . ' instead of "' . $field . '"?');
                }
                if ($field !== null) {
                    $index[] = $field;
                }
            }
            if (!empty($index)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * This will ask for the relations to other models.
     *
     * @return array
     */
    private function askRelations()
    {
        $relations = [
            '1-1' => [],
            '1-n' => [],
            'n-1' => [],
            'n-n' => [],
        ];

        if (!$this->confirm('Does this model have any relations?', false)) {
            return $relations;
        }

        $model  = ucfirst(str_singular($this->argument('model')));
        $models = str_plural($model);

        $input = $this->ask('One ' . $model . ' has one ...? [space separated list of models]');
        $relations['1-1'] = $this->explodeRelations($input);

        $input = $this->ask('One ' . $model . ' has many ...? [space separated list of models]');
        $relations['1-n'] = $this->explodeRelations($input);

        $input = $this->ask('Many ' . $models . ' belongs to one ...? [space separated list of models]');
        $relations['n-1'] = $this->explodeRelations($input);

        $input = $this->ask('Many ' . $models . ' belongs to many ...? [space separated list of models]');
        $relations['n-n'] = $this->explodeRelations($input);

        return $relations;
    }

    /**
     * Explode the relations from user input to an array.
     *
     * @param string $input
     * @return array
     */
    private function explodeRelations($input)
    {
        $relations = [];

        $models = $input !== null ? array_map('ucfirst', explode(' ', $input)) : [];
        foreach ($models as $model) {
            while (!class_exists('App\\' . $model) || !in_array(Model::class, class_parents('App\\' . $model))) {
                $this->error('"' . $model . '" is not a Model!');
                $model = $this->ask('Which Model should be used instead of "' . $model . '"?');
                if ($model === null) {
                    break;
                }
            }
            if ($model !== null) {
                $relations[] = $model;
            }
        }

        return $relations;
    }
}
