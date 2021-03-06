<?php

class MigrationParser
{

    /**
     * @var array
     */
    protected $structure = [];

    /**
     * @var array
     */
    protected $keys = [];

    /**
     * @var array
     */
    protected $constraints = [];

    /**
     * @var array
     */
    protected $integerMaps = [
        'int'       => 'integer',
        'bigint'    => 'bigInteger',
        'mediumint' => 'mediumInteger',
        'smallint'  => 'smallInteger',
        'tinyint'   => 'tinyInteger',
    ];

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var string
     */
    protected $structureFile;

    /**
     * @var string
     */
    protected $keysFile;

    /**
     * @var string
     */
    protected $constraintsFile;

    /**
     * MigrationParser constructor.
     *
     * @param string $tableName
     * @param string $structureFile
     * @param string $keysFile
     * @param string $constraintsFile
     */
    public function __construct($tableName, $structureFile, $keysFile, $constraintsFile)
    {
        $this->tableName = $tableName;
        $this->structureFile = $structureFile;
        $this->keysFile = $keysFile;
        $this->constraintsFile = $constraintsFile;
    }

    public function makeMigration()
    {
        $this->buildStructure();
        $this->buildKeys();
        $this->buildConstraints();

        $indent = str_repeat(' ', 12);
        $eol = "\n";

        $structure = trim(implode($eol . $indent, $this->formatStructure())) . $eol;
        $keys = trim(implode($eol . $indent, $this->formatKeys())) . $eol;
        $constraints = trim(implode($eol . $indent, $this->formatConstraints())) . $eol;

        $output = file_get_contents(__DIR__ . '/create.stub');

        $className = 'Create' . $this->studly($this->tableName) . 'Table';

        $output = str_replace(
            ['DummyClass', 'DummyTable', '// structure', '// keys', '// constraints'],
            [$className, $this->tableName, $structure, $keys, $constraints],
            $output
        );

        return $output;
    }

    protected function studly($value)
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }

    public function buildStructure()
    {
        $this->structure = [];

        $rows = file($this->structureFile);
        array_shift($rows);

        foreach ($rows as $row) {

            list($field, $colType, $null, $key, $default, $extra, $comment) = explode("\t", $row);

            if (preg_match('#^(\w+)(\((.*?)\))?(.*?)?$#', $colType, $matches)) {

                $type = strtolower($matches[1]);
                $args = $matches[3] ?: null;
                $typeExtra = trim($matches[4]) ?: null;

                if (strpos($args, ',') === false) {
                    $args = $args ?: null;
                } else {
                    $args = explode(',', $args);
                }

                $data = [
                    'field'    => $field,
                    'nullable' => ($null === 'YES'),
                    'default'  => ($default !== 'NULL') ? $default : null,
                    '_colType' => $colType,
                ];

                $method = 'parse' . ucfirst($type);

                if (method_exists($this, $method)) {
                    $data = array_merge(
                        $data,
                        $this->{$method}($type, $args, $typeExtra, $extra)
                    );
                } else {
                    $data['method'] = 'UNKNOWN:' . $type;
                }
                
                $data['comment'] = trim(str_replace(["\r", "\n"], '', $comment));

                $this->structure[$field] = $data;
            }
        }

        // look for softDeletes
        if (
            array_key_exists('deleted_at', $this->structure)
            && $this->structure['deleted_at']['method'] === 'timestamp'
        ) {
            $this->structure['deleted_at']['method'] = 'softDeletes';
            $this->structure['deleted_at']['args'] = null;
            $this->structure['deleted_at']['default'] = null;
            $this->structure['deleted_at']['nullable'] = false;
            $this->structure['deleted_at']['field'] = null;
        }

        // look for timestamps
        if (
            array_key_exists('created_at', $this->structure)
            && $this->structure['created_at']['method'] === 'timestamp'
            && array_key_exists('updated_at', $this->structure)
            && $this->structure['updated_at']['method'] === 'timestamp'
        ) {
            unset($this->structure['updated_at']);
            $method = $this->structure['created_at']['nullable'] ? 'nullableTimestamps' : 'timestamps';
            $this->structure['created_at']['method'] = $method;
            $this->structure['created_at']['args'] = null;
            $this->structure['created_at']['default'] = null;
            $this->structure['created_at']['nullable'] = false;
            $this->structure['created_at']['field'] = null;
        }

        // look for rememberToken
        if (
            array_key_exists('remember_token', $this->structure)
            && $this->structure['remember_token']['method'] === 'string'
            && $this->structure['remember_token']['nullable'] === true
            && $this->structure['remember_token']['args'] === '100'
        ) {
            $this->structure['remember_token']['method'] = 'rememberToken';
            $this->structure['remember_token']['args'] = null;
            $this->structure['remember_token']['default'] = null;
            $this->structure['remember_token']['nullable'] = false;
            $this->structure['remember_token']['field'] = null;
        }
    }

    public function formatStructure()
    {
        $fields = [];
        foreach ($this->structure as $field => $data) {

            $method = $data['method'];
            $isNumeric = (stripos($method, 'integer') !== false)
                || $method === 'decimal'
                || $method === 'double'
                || $method === 'float';

            $temp = '$table->' . $method;
            if ($data['field']) {
                $temp .= '(\'' . $field . '\'';
                if ($method === 'enum') {
                    $temp .= ', [' . implode(', ', (array)$data['args']) . '])';
                } elseif ($data['args']) {
                    $temp .= ', ' . implode(', ', (array)$data['args']) . ')';
                } else {
                    $temp .= ')';
                }
            } else {
                $temp .= '()';
            }
            if ($data['nullable']) {
                $temp .= '->nullable()';
            }
            if ($data['default']) {
                if ($isNumeric) {
                    $temp .= '->default(' . $data['default'] . ')';
                } elseif ($method==='boolean') {
                    $temp .= '->default(' . ($data['default'] ? 'true' : 'false') . ')';
                } elseif (strtolower(trim($data['default'])) === 'current_timestamp') {
                    $temp .= '->default(\DB::raw(\'CURRENT_TIMESTAMP\'))';
                } else {
                    $temp .= '->default(\'' . trim($data['default']) . '\')';
                }
            }

            // If isn't empty, set the comment
            if ($data['comment'] !== '') {
                $temp .= '->comment(\'' . $data['comment'] . '\')';
            }

            $fields[$field] = $temp . ';';
        }

        return $fields;
    }

    public function buildKeys()
    {
        $this->keys = [];

        $rows = file($this->keysFile);
        array_shift($rows);

        foreach ($rows as $row) {
            list($table, $nonUnique, $keyName, $seq, $colName, $extra) = explode("\t", $row, 6);

            if (!array_key_exists($keyName, $this->keys)) {
                $this->keys[$keyName] = [
                    'method'  => $nonUnique ? 'index' : 'unique',
                    'columns' => [],
                ];
            }
            $this->keys[$keyName]['columns'][$seq] = $colName;
        }

        // if we have a primary key ...
        if (array_key_exists('PRIMARY', $this->keys)) {
            $primary = $this->keys['PRIMARY'];
            // and it's for one columns ...
            if (count($primary['columns']) === 1) {
                $primaryColumn = reset($primary['columns']);
                $field = $this->structure[$primaryColumn];
                // and that column is an "increments" field ...
                if (stripos($field['method'], 'increments') !== false) {
                    // then don't build the primary key, since Laravel takes care of it
                    unset($this->keys['PRIMARY']);
                }
            }
        }
    }

    public function formatKeys()
    {
        $fields = [];
        foreach ($this->keys as $field => $data) {
            $columns = $this->escapeArray($data['columns']);
            if ($field === 'PRIMARY') {
                $temp = '$table->primary';
                $temp .= '(' . $columns . ')';
            } else {
                $temp = '$table->' . $data['method'];
                $temp .= '(' . $columns . ', \'' . $field . '\')';
            }
            $fields[$field] = $temp . ';';
        }

        return $fields;
    }

    public function buildConstraints()
    {
        $this->constraints = [];

        $rows = file($this->constraintsFile);
        array_shift($rows);

        foreach ($rows as $row) {
            list($constraint, $colName, $refTable, $refColumn, $updateRule, $deleteRule) = explode("\t", $row);

            if (array_key_exists($constraint, $this->keys)) {
                unset($this->keys[$constraint]);
            }

            $this->constraints[$constraint] = compact('colName', 'refTable', 'refColumn', 'updateRule', 'deleteRule');
        }
    }

    public function formatConstraints()
    {
        $fields = [];
        foreach ($this->constraints as $field => $data) {
            $columns = $this->escapeArray($data['colName']);
            $temp = '$table->foreign(' . $columns . ', \'' . $field . '\')' .
                '->references(\'' . $data['refColumn'] . '\')' .
                '->on(\'' . $data['refTable'] . '\')' .
                '->onDelete(\'' . $data['deleteRule'] . '\')' .
                '->onUpdate(\'' . $data['updateRule'] . '\')';

            $fields[$field] = $temp . ';';
        }

        return $fields;
    }

    protected function copyToClipboard($content)
    {
        $cmd = 'echo ' . escapeshellarg($content) . ' | __CF_USER_TEXT_ENCODING=' . posix_getuid() . ':0x8000100:0x8000100 pbcopy';
        shell_exec($cmd);
    }

    protected function extractSize($string)
    {
        if (preg_match('#\(([^)]+)\)#', $string, $m)) {
            return $m[1];
        }
    }

    protected function escapeArray($array)
    {
        $array = (array)$array;
        array_walk($array, function(&$value, $idx) {
            if (!is_numeric($value)) {
                $value = '\'' . str_replace('\'', '\\\'', $value) . '\'';
            }
        });

        $string = implode(', ', $array);

        if (count($array) > 1) {
            return '[' . $string . ']';
        }

        return $string;
    }

    protected function parseInt($type, $args, $typeExtra, $extra)
    {
        $method = $this->integerMaps[$type];
        if (strpos($extra, 'auto_increment') !== false) {
            $method = str_replace('nteger', 'ncrements', $method);
        } elseif (strpos($typeExtra, 'unsigned') !== false) {
            $method = 'unsigned' . ucfirst($method);
        }

        return $this->defaultParse($method);
    }

    protected function parseBigint($type, $args, $typeExtra, $extra)
    {
        return $this->parseInt($type, $args, $typeExtra, $extra);
    }

    protected function parseMediumint($type, $args, $typeExtra, $extra)
    {
        return $this->parseInt($type, $args, $typeExtra, $extra);
    }

    protected function parseSmallint($type, $args, $typeExtra, $extra)
    {
        return $this->parseInt($type, $args, $typeExtra, $extra);
    }

    protected function parseTinyint($type, $args, $typeExtra, $extra)
    {
        if ($args === 1) {
            $method = 'boolean';
            $args = $unsigned = null;

            return compact('method', 'args', 'unsigned');
        }

        return $this->parseInt($type, $args, $typeExtra, $extra);
    }

    protected function parseBlob($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('binary', $args);
    }

    protected function parseChar($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('char', $args);
    }

    protected function parseDate($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('date');
    }

    protected function parseDatetime($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('dateTime');
    }

    protected function parseDecimal($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('decimal', $args);
    }

    protected function parseDouble($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('double', $args);
    }

    protected function parseFloat($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('float', $args);
    }

    protected function parseLongtext($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('longText', $args);
    }

    protected function parseMediumtext($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('mediumText', $args);
    }

    protected function parseTinytext($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('tinyText', $args);
    }

    protected function parseText($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('text', $args);
    }

    protected function parseVarchar($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('string', $args);
    }

    protected function parseEnum($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('enum', $args);
    }

    protected function parseTime($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('time', $args);
    }

    protected function parseTimestamp($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('timestamp', $args);
    }

    protected function parseJson($type, $args, $typeExtra, $extra)
    {
        return $this->defaultParse('json', $args);
    }

    private function defaultParse($method, $args = null)
    {
        return compact('method', 'args');
    }
}
