<?php

namespace FRohlfing\Scaffold\Services;

use Closure;
use Illuminate\Console\OutputStyle;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Translation\Translator;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Scaffolder
{
    /**
     * Options to create the files.
     *
     * @var array
     */
    private $options = [];

    /**
     * Input interface.
     *
     * @var InputInterface
     */
    private $input;

    /**
     * Output interface.
     *
     * @var \Illuminate\Console\OutputStyle
     */
    private $output;

    /**
     * Absolute path to the stubs.
     *
     * @var string
     */
    private $stubPath;

    /**
     * View containing the menu item marker.
     *
     * @var string
     */
    private $navView;

    /**
     * Create a new command instance.
     *
     * @param $options
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function __construct($options, InputInterface $input=null, OutputInterface $output=null)
    {
        $this->options = $options;
        $this->input = $input;
        $this->output = !($output instanceof OutputStyle) ? new OutputStyle($input, $output) : $output;
        $this->stubPath = realpath(__DIR__ . '/../../resources/stubs/scaffold');
        $this->navView = '_nav';
    }

    /**
     * Create the scaffolding.
     */
    public function create()
    {
        $this->call('Create Model... ',                               function() { return $this->createModel(); });

        if (!empty($this->options['relations']['1-1']) || !empty($this->options['relations']['1-n']) || !empty($this->options['relations']['n-1']) || !empty($this->options['relations']['n-n'])) {
            $this->call('Create relations in foreign models... ',     function() { return $this->createRelationsInForeignModels(); });
        }

        if (!$this->options['skip-ui']) {
            $this->call('Create Controller... ',                      function() { return $this->createController(); });
            $this->call('Create Views... ',                           function() { return $this->createViews(); });
            $this->call('Create navigation link... ',                 function() { return $this->createNavItem(); });
            $this->call('Create translation for navigation link... ', function() { return $this->createNavItemTranslation(); });
            $this->call('Create language files... ',                  function() { return $this->createLanguageFiles(); });
            $this->call('Create route entries... ',                   function() { return $this->createRoutes(); });
            $this->call('Add the ability into the ACL... ',           function() { return $this->createAbility(); });
        }
        else {
            $this->line('Creating of user interface was skipped.');
        }

        $this->call('Create model factory... ',                       function() { return $this->createModelFactory(); });
        $this->call('Create seeder... ',                              function() { return $this->createTableSeeder(); });
        $this->call('Create migration file... ',                      function() { return $this->createMigrationFile(); });

        if ($this->options['migrate']) {
            $this->call('Run database migration... ',                 function() { return $this->migrate(); });
        }
        else {
            $this->line('Database migration was skipped.');
        }

        if ($this->options['composer']) {
            $this->call('Dump composer autoload files... ',           function() { return $this->dumpComposer(); });
        }
        else {
            $this->line('Dumping of autoload files were skipped.');
        }
    }

    /**
     * Remove the scaffolding.
     */
    public function remove()
    {
        $this->call('Remove Model... ',                           function() { return $this->removeModel(); });
        $this->call('Remove relations in foreign models... ',     function() { return $this->removeRelationsInForeignModels(); });
        $this->call('Remove Controller... ',                      function() { return $this->removeController(); });
        $this->call('Remove Views... ',                           function() { return $this->removeViews(); });
        $this->call('Remove navigation link... ',                 function() { return $this->removeNavItem(); });
        $this->call('Remove translation for navigation link... ', function() { return $this->removeNavItemTranslation(); });
        $this->call('Remove language files... ',                  function() { return $this->removeLanguageFiles(); });
        $this->call('Remove route entries... ',                   function() { return $this->removeRoutes(); });
        $this->call('Remove the ability from the ACL... ',        function() { return $this->removeAbility(); });
        $this->call('Remove model factory... ',                   function() { return $this->removeModelFactory(); });
        $this->call('Remove seeder... ',                          function() { return $this->removeTableSeeder(); });
        $this->call('Remove migration file... ',                  function() { return $this->removeMigrationFile(); });
    }

    /**
     * Create the model.
     *
     * @return bool
     */
    private function createModel()
    {
        $model = $this->options['model'];
        $table = $this->options['table'];
        $fields = $this->options['fields'];
        $hasTimestamps = !$this->options['no-timestamps'];

        // generate declaration "use"

        $use = [];

        $use[] = 'use App\\Traits\\AccessesRules;';
        $use[] = 'use App\\Traits\\Searchable;';
        $use[] = 'use App\\Traits\\SerializableISO8061;';
        if ($hasTimestamps || array_first($fields, function($field) { return in_array($field['type'], ['date', 'datetime']); })) {
            $use[] = 'use Carbon\\Carbon;';
        }
        $use[] = 'use Illuminate\\Database\\Eloquent\\Builder;';
        if (!empty($this->options['relations']['1-n']) || !empty($this->options['relations']['n-n']) || array_first($fields, function($field) { return $field['type'] === 'collection'; })) {
            $use[] = 'use Illuminate\\Database\\Eloquent\\Collection;';
        }
        $use[] = 'use Illuminate\\Database\\Eloquent\\Model;';
        sort($use);
        $use = implode("\n", $use);

        // generate PHPDoc

        $phpdoc = [];
        foreach ($fields as $field) {
            if (in_array($field['type'], ['bigint', 'int', 'smallint', 'uint'])) {
                $type = 'int';
            }
            else if (in_array($field['type'], ['date', 'datetime'])) {
                $type = 'Carbon';
            }
            else if ($field['type'] === 'collection') {
                $type = 'Collection';
            }
            else if ($field['type'] === 'binary') {
                $type = 'mixed';
            }
            else if (in_array($field['type'], ['array', 'bool', 'float', 'object'])) {
                $type = $field['type'];
            }
            else { // 'char', 'decimal', 'guid', 'longtext', 'string', 'text', 'time'
                $type = 'string';
            }
            $phpdoc[] = '* @property ' . $type . ' $' . $field['name'];
        }
        if ($hasTimestamps) {
            $phpdoc[] = '* @property Carbon $created_at';
            $phpdoc[] = '* @property Carbon $updated_at';
        }

        foreach ($this->options['relations']['1-1'] as $relation) {
            $phpdoc[] = '* @property-read ' . $relation . ' $' . snake_case($relation);
        }
        foreach ($this->options['relations']['1-n'] as $relation) {
            $phpdoc[] = '* @property-read Collection|' . $relation . '[] $' . str_plural(snake_case($relation));
        }
        foreach ($this->options['relations']['n-1'] as $relation) {
            $phpdoc[] = '* @property-read ' . $relation . ' $' . snake_case($relation);
        }
        foreach ($this->options['relations']['n-n'] as $relation) {
            $phpdoc[] = '* @property-read Collection|' . $relation . '[] $' . str_plural(snake_case($relation));
        }

        $phpdoc[] = '* @method static Builder|' . $model . ' search($terms)';
        $phpdoc[] = '* @method static Builder|' . $model . ' whereId($value)';
        foreach ($fields as $field) {
            $phpdoc[] = '* @method static Builder|' . $model . ' where' . studly_case($field['name']) . '($value)';
        }
        if ($hasTimestamps) {
            $phpdoc[] = '* @method static Builder|' . $model . ' whereCreatedAt($value)';
            $phpdoc[] = '* @method static Builder|' . $model . ' whereUpdatedAt($value)';
        }

        $phpdoc = implode("\n ", $phpdoc);

        // generate property "fillable"

        $fillable = [];
        foreach ($fields as $field) {
            if ($field['name'] !== 'id') {
                $fillable[] = "'" . $field['name'] . "',";
            }
        }
        if ($hasTimestamps) {
            $fillable[] = "'created_at',";
            $fillable[] = "'updated_at',";
        }
        $fillable = !empty($fillable) ? "\n\t\t" . implode("\n\t\t", $fillable) . "\n\t" : '';

        // hidden

        $hidden = [];
        foreach ($fields as $field) {
            if (strpos($field['name'], 'password') !== false || strpos($field['name'], 'token') !== false) {
                $hidden[] = "'" . $field['name'] . "',";
            }
        }
        $hidden = !empty($hidden) ? "\n\t\t" . implode("\n\t\t", $hidden) . "\n\t" : '';

        // generate property "casts"
        // int, float, string, bool, object, array, collection, date and datetime

        $casts = [];
        foreach ($fields as $field) {
            if (in_array($field['type'], ['bigint', 'int', 'smallint', 'uint'])) {
                $casts[] = "'" . $field['name'] . "' => 'int',";
            }
            else if (in_array($field['type'], ['array', 'bool', 'collection', 'date', 'datetime', 'float', 'object'])) {
                $casts[] = "'" . $field['name'] . "' => '" . $field['type'] . "',";
            }
        }
        if ($hasTimestamps) {
            $casts[] = "'created_at' => 'datetime',";
            $casts[] = "'updated_at' => 'datetime',";
        }
        $casts = !empty($casts) ? "\n\t\t" . implode("\n\t\t", $casts) . "\n\t" : '';

        // generate property "searchable"

        $searchable = [];
        foreach ($fields as $field) {
            if ($field['name'] !== 'id' && $field['type'] !== 'bool' && strpos($field['name'], 'password') === false && strpos($field['name'], 'token') === false) {
                $searchable[] = "'" . $field['name'] . "',";
            }
        }
        if ($hasTimestamps) {
            $searchable[] = "'created_at',";
            $searchable[] = "'updated_at',";
        }
        $searchable = !empty($searchable) ? "\n\t\t" . implode("\n\t\t", $searchable) . "\n\t" : '';

        // generate property "rules"

        $rules = [];
        foreach ($fields as $field) {
            $rule = [];
            if (in_array($field['type'], ['bigint', 'int', 'smallint', 'uint'])) {
                $type = 'int';
            }
            else if (in_array($field['type'], ['float', 'decimal'])) {
                $type = 'numeric';
            }
            else if ($field['type'] === 'bool') {
                $type = 'boolean';
            }
            else if (in_array($field['type'], ['date', 'datetime'])) {
                $type = 'date';
            }
            else if (strpos($field['name'], 'email') !== false) {
                $type = 'email';
            }
            else {
                $type = null;
            }

            if ($type !== null) {
                $rule[] = $type;
            }

            if ($field['type'] === 'guid') {
                $rule[] = 'size:36';
            }

            if (!empty($field['length'])) {
                $rule[] = 'max:' . $field['length'];
            }

            foreach ($this->options['uniques'] as $uniques) {
                if (count($uniques) === 1 && $field['name'] === $uniques[0]) {
                    $rule[] = 'unique:' . $table . ',' . $field['name'] . ',{id}';
                }
            }

            if ($field['required']) {
                $rule[] = 'required';
            }
            else if ($type !== null && $field['default'] === null) {
                $rule[] = 'nullable';
            }

            if (!empty($rule)) {
                $rules[] = "'" . $field['name'] . "' => '" . implode('|', $rule) . "',";
            }
        }
        $rules = !empty($rules) ? "\n\t\t" . implode("\n\t\t", $rules) . "\n\t" : '';

        // todo
        $lists = [];
//        foreach ($fields as $field) {
//            if ($field['list'] !== null) {
//                $lists[] = "['" . implode("', '", $field['list']) . "']";
//            }
//        }
        $lists = !empty($lists) ? "\n\t\t" . implode("\n\t\t", $lists) . "\n\t" : '';

        // generate relations

        $relations = [];

        // One $model has one $relation
        foreach ($this->options['relations']['1-1'] as $relation) {
            $relations[] = $this->loadStub('relation', [
                'model'  => $relation,
                'name'   => lcfirst($relation),
                'class'  => 'BelongsTo',
                'method' => 'belongsTo',
            ]);
        }

        // One $model has many $relations
        foreach ($this->options['relations']['1-n'] as $relation) {
            $relations[] = $this->loadStub('relation', [
                'model'  => $relation,
                'name'   => str_plural(lcfirst($relation)),
                'class'  => 'HasMany',
                'method' => 'hasMany',
            ]);
        }

        // Many $models belongs to one $relation
        foreach ($this->options['relations']['n-1'] as $relation) {
            $relations[] = $this->loadStub('relation', [
                'model'  => $relation,
                'name'   => lcfirst($relation),
                'class'  => 'BelongsTo',
                'method' => 'belongsTo',
            ]);
        }

        // Many $models belongs to many $relations
        foreach ($this->options['relations']['n-n'] as $relation) {
            $relations[] = $this->loadStub('relation', [
                'model'  => $relation,
                'name'   => str_plural(lcfirst($relation)),
                'class'  => 'BelongsToMany',
                'method' => 'belongsToMany',
            ]);
        }

        $relations = !empty($relations) ? "\n\t" . implode("\n\n\t", str_replace("\n", "\n\t", $relations)) . "\n" : '';

        // load stub and replace the placeholder

        $timestamps = $hasTimestamps ? 'true' : 'false';

        $content = $this->loadStub('model', compact(
            'model', 'table', 'use', 'phpdoc',
            'timestamps', 'fillable', 'hidden', 'casts',
            'searchable', 'rules', 'lists', 'relations'
        ));

        // save the file

        return $this->saveFile('app/' . $model . '.php', $content);
    }

    /**
     * Remove the model.
     *
     * @return bool
     */
    private function removeModel()
    {
        return $this->removeFile('app/' . $this->options['model'] . '.php');
    }

    /**
     * Create relations in foreign models.
     *
     * @return bool
     */
    private function createRelationsInForeignModels()
    {
        $result = true;

        $model = $this->options['model'];

        // One $model has one $relation
        $func = lcfirst($model);
        foreach ($this->options['relations']['1-1'] as $relation) {
            $code = $this->loadStub('relation', [
                'model'  => $model,
                'name'   => $func,
                'class'  => 'HasOne',
                'method' => 'hasOne',
            ]);

            $phpPdoc = ' * @property-read ' . $model . ' ' . snake_case($model);

            if (!$this->createRelationInForeignModels($relation, $func, $code, $phpPdoc)) {
                $result = false;
            }
        }

        // One $model has many $relations
        $func = lcfirst($model);
        foreach ($this->options['relations']['1-n'] as $relation) {
            $code = $this->loadStub('relation', [
                'model'  => $model,
                'name'   => $func,
                'class'  => 'BelongsTo',
                'method' => 'belongsTo',
            ]);

            $phpPdoc = ' * @property-read ' . $model . ' ' . snake_case($model);

            if (!$this->createRelationInForeignModels($relation, $func, $code, $phpPdoc)) {
                $result = false;
            }
        }

        // Many $models belongs to one $relation
        $func = str_plural(lcfirst($model));
        foreach ($this->options['relations']['n-1'] as $relation) {
            $code = $this->loadStub('relation', [
                'model'  => $model,
                'name'   => $func,
                'class'  => 'HasMany',
                'method' => 'hasMany',
            ]);

            $phpPdoc = ' * @property-read Collection|' . $model . '[] ' . snake_case($model);

            if (!$this->createRelationInForeignModels($relation, $func, $code, $phpPdoc)) {
                $result = false;
            }
        }

        // Many $models belongs to many $relations
        $func = str_plural(lcfirst($model));
        foreach ($this->options['relations']['n-n'] as $relation) {
            $code = $this->loadStub('relation', [
                'model'  => $model,
                'name'   => $func,
                'class'  => 'BelongsToMany',
                'method' => 'belongsToMany',
            ]);

            $phpPdoc = ' * @property-read Collection|' . $model . '[] ' . snake_case($model);

            if (!$this->createRelationInForeignModels($relation, $func, $code, $phpPdoc)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Create the relation in the given foreign model.
     *
     * @param string $relation
     * @param string $func
     * @param string $code
     * @param string $phpdoc
     * @return bool
     */
    private function createRelationInForeignModels($relation, $func, $code, $phpdoc)
    {
        if (method_exists('App\\' . $relation, $func)) {
            // todo nur eine Ausgabe schaffen
            $this->warn('The relation of foreign model "' . $relation . '" to this model is already defined.');
            return false;
        }

        $path = 'app/' . $relation . '.php';
        $content = $this->loadFile($path);

        // generate declaration "use"

        if (strpos($phpdoc, 'Collection') !== false && strpos($content, 'use Illuminate\\Database\\Eloquent\\Collection;') === false) {
            if (($pos = strpos($content, 'use Illuminate\\Database\\Eloquent\\Builder;')) !== false) {
                $ahead = substr($content, 0, $pos + 41);
                $below = substr($content, $pos + 41);
                $content = $ahead . "\n" . 'use Illuminate\\Database\\Eloquent\\Collection;' . $below;
            }
        }

        // generate relation function

        if (($pos = strrpos($content, '}')) === false) {
            throw new RuntimeException('Closing parenthesis is missing in "' . $path . '".');
        }

        $ahead = substr($content, 0, $pos);
        $below = substr($content, $pos);
        $content = $ahead . "\n\t" . str_replace("\n", "\n\t", $code) . "\n" . $below;

        // generate PHPDoc

        if (($posClass = strpos($content, " */\nclass " . $relation)) !== false) {
            if (($pos = strpos($content, " * @method ")) === false || $pos > $posClass) {
                $pos = $posClass;
            }
            $ahead = substr($content, 0, $pos);
            $below = substr($content, $pos);
            $content = $ahead . $phpdoc . "\n" . $below;
        }

        return $this->saveFile($path, $content, true);
    }

    /**
     * Remove relations in foreign models.
     *
     * @return bool
     */
    private function removeRelationsInForeignModels()
    {
        // todo
        $this->warn('not implemented yet');

        return false;
    }

    /**
     * Create the migration file.
     *
     * @return bool
     */
    private function createMigrationFile()
    {
        $aliases = [
            'array'       => 'longText',
            'bigint'      => 'bigInteger',
            'bool'        => 'boolean',
            'collection'  => 'longText',
            'datetime'    => 'dateTime',
            'float'       => 'double',
            'guid'        => 'uuid',
            'int'         => 'integer',
            'longtext'    => 'longText',
            'object'      => 'longText',
            'smallint'    => 'smallInteger',
            'uint'        => 'unsignedInteger',
        ];

        $fields  = $this->options['fields'];
        $indexes = $this->options['indexes'];
        $uniques = $this->options['uniques'];
        $table   = $this->options['table'];
        $class   = 'Create' . studly_case($table) . 'Table';

        // generate code used in the stub

        $up = [];

        if ($this->options['big-increments']) {
            $up[] = '$table->bigIncrements(\'id\');';
        }
        else {
            $up[] = '$table->increments(\'id\');';
        }

        foreach ($fields as $field) {
            $func = isset($aliases[$field['type']]) ? $aliases[$field['type']] : $field['type'];
            $entry = '$table->' . $func . '(' .
                "'" . $field['name'] . "'" .
                (isset($field['length']) ? ', ' . $field['length'] : '') .
                (isset($field['scale']) ? ', ' . $field['scale'] : '') .
                ')';

            if ($field['type'] === 'bool') {
                $entry .= '->default(' . ($field['default'] ?: 'false') . ')';
            }
            else {
                if (!$field['required']) {
                    $entry .= '->nullable()';
                }
                if (isset($field['default'])) {
                    $entry .= '->default(' . $field['default'] . ')';
                }
            }

            $up[] = $entry . ';';
        }

        if (!$this->options['no-timestamps']) {
            $up[] = '$table->timestamps();';
        }

        if (!empty($uniques) || !empty($indexes)) {
            $up[] = '';
            foreach ($uniques as $unique) {
                $up[] = '$table->unique([\'' . implode("', '", $unique) . '\']);';
            }
            foreach ($indexes as $index) {
                $up[] = '$table->index([\'' . implode("', '", $index) . '\']);';
            }
        }

        $up = implode("\n\t\t\t", $up);

        // load stub and replace the placeholder

        $content = $this->loadStub('migration', compact('table', 'class', 'up'));

        // save

        $timestamp = substr(array_first(scandir(base_path('database/migrations')), function($file) use ($table) {
            return substr(basename($file), 17) === '_create_' . $table . '_table.php';
        }, date('Y_m_d_His')), 0, 17);

        return $this->saveFile('database/migrations/' . $timestamp . '_create_' . $table . '_table.php', $content);
    }

    /**
     * Remove the migration file.
     *
     * @return bool
     */
    private function removeMigrationFile()
    {
        $table = $this->options['table'];

        $timestamp = substr(array_first(scandir(base_path('database/migrations')), function($file) use ($table) {
            return substr(basename($file), 17) === '_create_' . $table . '_table.php';
        }, date('Y_m_d_His')), 0, 17);

        return $this->removeFile('database/migrations/' . $timestamp . '_create_' . $table . '_table.php');
    }

    /**
     * Create model factory.
     *
     * @return bool
     */
    private function createModelFactory()
    {
        // todo
        $this->warn('not implemented yet');

        return false;
    }

    /**
     * Create model factory.
     *
     * @return bool
     */
    private function removeModelFactory()
    {
        // todo
        $this->warn('not implemented yet');

        return false;
    }


    /**
     * Create table seeder.
     *
     * @return bool
     */
    private function createTableSeeder()
    {
        // todo
        $this->warn('not implemented yet');

        return false;
    }

    /**
     * Remove table seeder.
     *
     * @return bool
     */
    private function removeTableSeeder()
    {
        // todo
        $this->warn('not implemented yet');

        return false;
    }

    /**
     * Create the controller.
     *
     * @return bool
     */
    private function createController()
    {
        $model    = $this->options['model'];
        $entity   = lcfirst($model);
        $entities = str_plural($entity);
        $package  = $this->options['package'];

        // load stub and replace the placeholder
        $content = $this->loadStub('controller', compact(
            'package', 'model', 'entity', 'entities'
        ));

        // save

        return $this->saveFile('app/Http/Controllers/' . $model . 'Controller.php', $content);
    }

    /**
     * Remove the controller.
     *
     * @return bool
     */
    private function removeController()
    {
        return $this->removeFile('app/Http/Controllers/' . $this->options['model'] . 'Controller.php');
    }

    /**
     * Create the views.
     *
     * @return bool
     */
    private function createViews()
    {
        // todo für jede Datei einen einzelnen Aufruf tätigen, so dass im Fehlerfall auch nur eine Meldung pro Aufruf erscheint.

        $fields   = $this->options['fields'];
        $model    = $this->options['model'];
        $entity   = lcfirst($model);
        $entities = str_plural($entity);
        $package  = $this->options['package'];

        $result = true;

        // generate code for the index page

        $tableheader = [];
        foreach ($fields as $field) {
            $tableheader[] = "<th>{!! sort_column('" . $field['name'] . "', __('" . $package . ".model." . $field['name'] . "')) !!}</th>";
        }
        $tableheader = !empty($tableheader) ? implode("\n\t\t\t\t\t\t\t\t\t\t", $tableheader) : '';

        $tabledata = [];
        foreach ($fields as $field) {
            $expression = '$' . $entity . '->' . $field['name'];
            if ($field['type'] === 'bool') {
                $tabledata[] = '<td class="text-center">{!! ' . $expression . ' ? \'<i class="fas fa-check"></i>\' : \'\' !!}</td>';
            }
            else if ($field['type'] === 'date') {
                $tabledata[] = '<td>{{ format_date(' . $expression . ') }}</td>';
            }
            else if ($field['type'] === 'time') {
                $tabledata[] = '<td>{{ format_time(' . $expression . ') }}</td>';
            }
            else if ($field['type'] === 'datetime') {
                $tabledata[] = '<td>{{ format_datetime(' . $expression . ') }}</td>';
            }
            else {
                $tabledata[] = '<td>{{ ' . $expression . ' }}</td>';
            }
        }
        $tabledata = !empty($tabledata) ? implode("\n\t\t\t\t\t\t\t\t\t\t\t", $tabledata) : '';

        $content = $this->loadStub('index', compact(
            'package', 'entity', 'entities', 'tableheader', 'tabledata'
        ));

        if (!$this->saveFile('resources/views/' . $package . '/index.blade.php', $content)) {
            $result = false;
        }

        // generate code for the form

        $size = [
            'bigint'      => '20',      // -9.223.372.036.854.775.808 bis 9.223.372.036.854.775.807
            'int'         => '11',      // -2.147.483.648 bis 2.147.483.647
            'smallint'    => '6',       // -32.768 bis 32.767
            'uint'        => '10',      // 0 bis 4.294.967.295
            'guid'        => '36',      // 00000000-0000-0000-0000-000000000000
        ];

        $controls = [];
        foreach ($fields as $field) {
            $name = $field['name'];

            $class = '';
            if ($field['type'] === 'date') {
                $class .= 'datepicker ';
            }
            else if ($field['type'] === 'time') {
                $class .= 'timepicker ';
            }
            else if ($field['type'] === 'datetime') {
                $class .= 'datetimepicker ';
            }

            $attributes = '';
            if ($field['list'] === null && (isset($field['length']) || isset($size[$field['type']]))) {
                $attributes .= 'maxlength="' . (isset($field['length']) ? $field['length'] : $size[$field['type']]) . '" ';
            }
            if ($field['required']) {
                $attributes .= 'required="required" ';
            }
            if (empty($controls)) {
                $attributes .= 'autofocus="autofocus" ';
            }

            if (strpos($field['name'], 'email') !== false) {
                $inputtype = 'email';
            }
            else {
                $inputtype = 'text';
            }

            $list = '';
            if ($field['list'] !== null) {
                $stub = 'form-select';
                $list = "['" . implode("', '", $field['list']) . "']";
            }
            else if ($field['type'] === 'bool') {
                $stub = 'form-checkbox';
            }
            else if (in_array($field['type'], ['text', 'longtext'])) {
                $stub = 'form-textarea';
            }
            else {
                $stub = 'form-input';
            }

            $controls[] = str_replace("\n", "\n\t\t\t\t\t\t\t", $this->loadStub($stub, compact(
                'package', 'entity', 'name', 'inputtype', 'class', 'attributes', 'list'
            )));
        }
        $controls = !empty($controls) ? implode("\n\t\t\t\t\t\t\t", $controls) : '';

        $content = $this->loadStub('form', compact(
            'package', 'entity', 'controls'
        ));

        if (!$this->saveFile('resources/views/' . $package . '/form.blade.php', $content)) {
            $result = false;
        }

        // generate code for the show view

        $rows = [];
        foreach ($fields as $i => $field) {
            $name = $field['name'];
            $expression = '$' . $entity . '->' . $field['name'];
            if ($field['type'] === 'bool') {
                $expression = '{!! ' . $expression . ' ? \'<i class="fas fa-check"></i>\' : \'\' !!}';
            }
            else if ($field['type'] === 'date') {
                $expression = '{{ format_date(' . $expression . ') }}';
            }
            else if ($field['type'] === 'time') {
                $expression = '{{ format_time(' . $expression . ') }}';
            }
            else if ($field['type'] === 'datetime') {
                $expression = '{{ format_datetime(' . $expression . ') }}';
            }
            else {
                $expression = '{{ ' . $expression . ' }}';
            }
            $rows[] = str_replace("\n", "\n\t\t\t\t\t\t", $this->loadStub('show-row', compact(
                'package', 'name', 'expression'
            )));
        }
        $rows = !empty($rows) ? implode("\n\t\t\t\t\t\t", $rows) : '';

        $content = $this->loadStub('show', compact(
            'package', 'entity', 'rows'
        ));

        if (!$this->saveFile('resources/views/' . $package . '/show.blade.php', $content)) {
            $result = false;
        }

        // generate code for the script file

        $content = $this->loadStub('_script');

        if (!$this->saveFile('resources/views/' . $package . '/_script.blade.php', $content)) {
            $result = false;
        }

        return $result;
    }

    /**
     * Remove the views.
     *
     * @return bool
     */
    private function removeViews()
    {
        return $this->removeFolder('resources/views/' . $this->options['package']);
    }

    /**
     * Create the menu point to the menu template file.
     *
     * @return bool
     */
    private function createNavItem()
    {
        $package = $this->options['package'];
        $title   = title_case(str_replace('-', ' ', $package));

        // load stub and replace the placeholder

        $menuItem = $this->loadStub('nav', compact('package', 'title'));

        // load the view containing the menu

        $path = 'resources/views/' . str_replace('.', '/', $this->navView) . '.blade.php';
        $content = $this->loadFile($path);

        // make sure the nav item does not exist yet

        if (strpos($content, "url('" . $package . "')") !== false) {
            $this->warn('The navigation link "' . $package . '" is already defined.');
            return false;
        }

        // add the menu item and save

        if (($pos = strpos($content, '<!-- nav-item-mark -->')) === false) {
            $this->error('Marker "<!-- nav-item-mark -->" is missing in "' . $this->navView . '".');
            return false;
        }
        $ahead = substr($content, 0, $pos);
        $below = substr($content, $pos);
        $content = $ahead . trim($menuItem) . "\n\n" . $below;

        return $this->saveFile($path, $content, true);
    }

    /**
     * Remove the menu point from the menu template file.
     *
     * @return bool
     */
    private function removeNavItem()
    {
        $package = $this->options['package'];
        $title   = title_case(str_replace('-', ' ', $package));
        $path = 'resources/views/' . str_replace('.', '/', $this->navView) . '.blade.php';

        $themes = array_filter(scandir($this->stubPath), function($theme) {
            return $theme !== '.' && $theme !== '..';
        });

        foreach ($themes as $theme) {
            $this->options['theme'] = $theme;
            $menuItem = $this->loadStub('nav', compact('package', 'title'));
            $count = 0;
            $content = str_replace(trim($menuItem) . "\n\n", '', $this->loadFile($path), $count);
            if ($count === 1) {
                $this->options['theme'] = null;
                return $this->saveFile($path, $content, true);
            }
        }

        $this->options['theme'] = null;
        $this->warn('Navigation link "' . $package . '" not found.');

        return false;
    }

    /**
     * Create translation for navigation link.
     *
     * @return bool
     */
    private function createNavItemTranslation()
    {
        $package = $this->options['package'];
        $title   = title_case(str_replace('-', ' ', $package));

        $locales = array_filter(scandir(resource_path('lang')), function($locale) {
            return $locale !== '.' && $locale !== '..'  && $locale !== 'vendor' && is_dir(resource_path('lang/' . $locale));
        });

        /** @var Translator $translator */
        $translator = app('translator');
        $error = null;
        $warning = null;
        $result = true;
        foreach ($locales as $locale) {
            $path = 'resources/lang/' . $locale . '/app.php';
            if ($translator->has('app.nav.' . $package, $locale, false)) {
                $warning = 'The translation "app.nav.' . $package . '" for locale "' . $locale . '" is already defined.';
                $result = false;
            }
            else {
                $content = $this->loadFile($path);
                if (($pos = strpos($content, "'nav' => [")) === false) {
                    $error = 'Attribute "nav" is missing in "' . $path . '".';
                    $result = false;
                }
                else {
                    $ahead = substr($content, 0, $pos + 10);
                    $below = substr($content, $pos + 10);
                    $content = $ahead . "\n\t\t'" . $package . "' => '" . $title . "'," . $below;
                    if (!$this->saveFile($path, $content, true)) {
                        $result = false;
                    }
                }
            }
        }

        if ($error !== null) {
            $this->error($error);
        }
        else if ($warning !== null) {
            $this->warn($warning);
        }

        return $result;
    }

    /**
     * Remove translation for navigation link.
     *
     * @return bool
     */
    private function removeNavItemTranslation()
    {
        $package = $this->options['package'];
        $title   = title_case(str_replace('-', ' ', $package));

        $locales = array_filter(scandir(resource_path('lang')), function($locale) {
            return $locale !== '.' && $locale !== '..'  && $locale !== 'vendor' && is_dir(resource_path('lang/' . $locale));
        });

        $warning = null;
        $result = true;
        foreach ($locales as $locale) {
            $path = 'resources/lang/' . $locale . '/app.php';
            $count = 0;
            $content = str_replace("\n\t\t'" . $package . "' => '" . $title . "',", '', $this->loadFile($path), $count);
            if ($count !== 1 || !$this->saveFile($path, $content, true)) {
                $warning = 'Translation "app.nav.' . $package . '" for locale "' . $locale . '" not found.';
                $result = false;
            }
        }

        if ($warning !== null) {
            $this->warn($warning);
        }

        return $result;
    }

    /**
     * Create the language files.
     *
     * @return bool
     */
    private function createLanguageFiles()
    {
        $fields  = $this->options['fields'];
        $model   = $this->options['model'];
        $models  = str_plural($model);
        $package = $this->options['package'];

        $locales = array_filter(scandir(resource_path('lang')), function($locale) {
            return file_exists($this->stubPath . '/' . $this->options['theme'] . '/lang-' . $locale . '.stub') ||
                   file_exists($this->stubPath . '/default/lang-' . $locale . '.stub');
        });

        // generate list of model attributes

        $list = [];
        foreach ($fields as $field) {
            $title = title_case(snake_case(studly_case($field['name']), ' '));
            $list[] = "'" . $field['name'] . "' => '" . $title . "',";
        }
        $list = !empty($list) ? implode("\n\t\t", $list) . "\n\t" : '';

        $result = true;
        foreach ($locales as $locale) {

            // load stub and replace the placeholder

            $content = $this->loadStub('lang-' . $locale, compact(
                'model', 'models', 'list'
            ));

            // save

            if (!$this->saveFile('resources/lang/' . $locale . '/' . $package . '.php', $content)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Remove the language files.
     *
     * @return bool
     */
    private function removeLanguageFiles()
    {
        $package = $this->options['package'];

        $locales = array_filter(scandir(resource_path('lang')), function($locale) use ($package) {
            return file_exists(resource_path('lang/' . $locale . '/' . $package . '.php'));
        });

        $result = true;
        foreach ($locales as $locale) {
            if (!$this->removeFile('resources/lang/' . $locale . '/' . $package . '.php')) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Create the route entries.
     *
     * @return bool
     */
    private function createRoutes()
    {
        $model   = $this->options['model'];
        $models  = str_plural($model);
        $entity  = lcfirst($model);
        $package = $this->options['package'];

        // make sure the route does not exist yet

        $routes = app('router')->getRoutes();
        $found = array_first($routes, function(Route $item) use ($package) {
            $s = $item->getName();
            return strpos($s, $package . '.') === 0 || strpos($s, '.' . $package . '.') !== false;
        });
        if ($found) {
            $this->warn('The route entries "' . $package . '" are already defined.');
            return false;
        }

        // load stub and replace the placeholder

        $stub = $this->options['expose-routes'] ? 'routes-exposed' : 'routes';
        $newRoute = $this->loadStub($stub, compact(
            'package', 'model', 'models', 'entity'
        ));

        // add the route entries and save

        $content = rtrim($this->loadFile('routes/web.php'));
        $content .= "\n\n" . trim($newRoute) . "\n";

        return $this->saveFile('routes/web.php', $content, true);
    }

    /**
     * Remove the route entries.
     *
     * @return bool
     */
    private function removeRoutes()
    {
        $model   = $this->options['model'];
        $models  = str_plural($model);
        $entity  = lcfirst($model);
        $package = $this->options['package'];
        
        $content = $this->loadFile('routes/web.php');

        $count = 0;
        $newRoute = $this->loadStub('routes', compact(
            'package', 'model', 'models', 'entity'
        ));
        $content = str_replace("\n" . trim($newRoute) . "\n", '', $content, $count);
        if ($count !== 1) {
            $newRoute = $this->loadStub('routes-exposed', compact(
                'package', 'model', 'models', 'entity'
            ));
            $content = str_replace("\n" . trim($newRoute) . "\n", '', $content, $count);
        }
        if ($count !== 1) {
            $this->warn('The route entries "' . $package . '" are not defined.');
            return false;
        }

        return $this->saveFile('routes/web.php', $content, true);
    }

    /**
     * Add the ability into the ACL.
     *
     * @return bool
     */
    private function createAbility()
    {
        $package = $this->options['package'];

        if (config()->has('auth.acl.manage-' . $package)) {
            $this->warn('The ability "' . $package . '" is already added.');
            return false;
        }

        $path = 'config/auth.php';
        $content = $this->loadFile($path);
        if (($pos = strpos($content, "'acl' => [")) === false) {
            $this->error('Attribute "acl" is missing in "' . $path . '".');
            return false;
        }

        $roles = config('auth.roles', []);
        krsort($roles);
        $roles = "['" . implode("', '", $roles) . "']";

        $ahead = substr($content, 0, $pos + 10);
        $below = substr($content, $pos + 10);
        $content = $ahead . "\n\t\t'manage-" . $package . "' => " . $roles . "," . $below;

        return $this->saveFile($path, $content, true);
    }

    /**
     * Remove the ability from the ACL.
     *
     * @return bool
     */
    private function removeAbility()
    {
        $package = $this->options['package'];
        $path = 'config/auth.php';

        $roles = config('auth.roles', []);
        krsort($roles);
        $roles = "['" . implode("', '", $roles) . "']";

        $count = 0;
        $content = str_replace("\n\t\t'manage-" . $package . "' => " . $roles . ",", '', $this->loadFile($path), $count);
        if ($count !== 1) {
            $this->warn('The ability "' . $package . '" not found.');
            return false;
        }

        return $this->saveFile($path, $content, true);
    }

    /**
     * Migrate the database.
     *
     * @return bool
     */
    private function migrate()
    {
        if (Artisan::call('migrate', !empty($this->options['database']) ? ['--database' => $this->options['database']] : []) !== 0) {
            $this->error('Unable to migrate the database.');
            return false;
            //throw new RuntimeException('Unable to migrate the database.');
        }

        $this->line(Artisan::output());

        return true;
    }

    /**
     * Dump composer autoload files.
     *
     * @return bool
     */
    private function dumpComposer()
    {
        $composer = app('composer');
        $composer->dumpAutoloads();

//        system('composer dump-autoload ', $return);
//
//        if ($return !== 0) {
//            $this->error('Unable to dump composer autoload files.');
//            return false;
//            //throw new RuntimeException('Unable to migrate the database.');
//        }

        $this->line(Artisan::output());

        return true;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Helpers

    /**
     * Write a string as standard output.
     *
     * @param string $string
     */
    private function write($string)
    {
        $this->output->write($string);
    }

    /**
     * Write a string as standard output and adds a newline at the end.
     *
     * @param string $string
     */
    private function line($string = '')
    {
        $this->output->writeln($string);
    }

    /**
     * Write a string as information output (green font).
     *
     * @param string $string
     */
    private function info($string)
    {
        $this->output->write("<info>$string</info>");
    }

    /**
     * Write a string as warning output (yellow font).
     *
     * @param string $string
     */
    private function warn($string)
    {
//        if (!$this->output->getFormatter()->hasStyle('warning')) {
//            $style = new OutputFormatterStyle('yellow');
//            $this->output->getFormatter()->setStyle('warning', $style);
//        }
        $this->output->write("<comment>$string</comment>");
    }

    /**
     * Write a string as error output.
     *
     * @param string $string
     */
    private function error($string)
    {
        $this->output->write("<error>$string</error>");
    }

    /**
     * Confirm a question with the user.
     *
     * @param string $question
     * @param bool $default
     * @return bool
     */
    private function confirm($question, $default = false)
    {
        return $this->output->confirm($question, $default);
    }

    /**
     * Invoke the given function and write the result.
     *
     * @param string $string
     * @param Closure $func
     */
    private function call($string, Closure $func)
    {
        $this->write($string);
        if ($func()) {
            $this->info('ok');
        }
        $this->line();
    }

    /**
     * Load the file.
     *
     * @param string $path path relative to the base of the install
     * @return string
     */
    private function loadFile($path)
    {
        $absolutePath = base_path($path);

        if (($content = @file_get_contents($absolutePath)) === false) {
            throw new RuntimeException('Unable to load the file "' . $path . '"!');
        }

        return $content;
    }

    /**
     * Save the file.
     *
     * @param string $path path relative to the base of the install
     * @param string $content
     * @param bool $force
     * @return bool
     */
    private function saveFile($path, $content, $force = false)
    {
        $absolutePath = base_path($path);

        if (file_exists($absolutePath)) {
            if (!$force && !$this->options['force'] && !$this->confirm($path . ' already exists. Overwrite the file?', false)) {
                if ($this->options['no-interaction']) {
                    $this->warn($path . ' already exists.');
                }
                return false;
            }
            @unlink($absolutePath);
        }
        else {
            $folder = dirname($path);
            if (!file_exists($folder)) {
                @mkdir($folder, 0777, true);
            }
        }

        if (@file_put_contents($absolutePath, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to save the file "' . $path . '"!');
        }

        return true;
    }

    /**
     * Remove the file.
     *
     * @param string $path path relative to the base of the install
     * @return bool
     */
    private function removeFile($path)
    {
        $absolutePath = base_path($path);

        if (file_exists($absolutePath)) {
            if (!@unlink($absolutePath)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove the folder.
     *
     * @param string $path path relative to the base of the install
     * @return bool
     */
    private function removeFolder($path)
    {
        $absolutePath = base_path($path);

        if (file_exists($absolutePath)) {
            if (!remove_dir($absolutePath)) {
                return false;
            }
        }

        return true;
    }


    /**
     * Load the stub and replace the placeholder.
     *
     * @param string $name Name of the stub file (basename without extension ".stub")
     * @param array $mapping
     * @return string
     */
    private function loadStub($name, array $mapping = [])
    {
        // load the stub

        $absolutePath = $this->stubPath . '/' . $this->options['theme'] . '/' . $name . '.stub';
        if (!file_exists($absolutePath)) {
            $absolutePath = $this->stubPath . '/default/' . $name . '.stub';
        }

        if (($content = @file_get_contents($absolutePath)) === false) {
            throw new RuntimeException('Unable to load the stub "' . $name . '"!');
        }

        // replace the placeholder in the stub

        $placeholders = array_map(function($key) {
            return "##$key##";
        }, array_keys($mapping));

        return str_replace($placeholders, array_values($mapping), $content);
    }
}
