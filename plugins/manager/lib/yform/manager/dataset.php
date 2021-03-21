<?php

class rex_yform_manager_dataset
{
    use rex_instance_pool_trait;

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    private static $debug = false;

    private static $tableToModel = [];
    private static $modelToTable = [];

    private static $internalForms = [];

    private $table;

    private $id;
    private $exists;

    private $data;
    private $newData = [];
    private $dataLoaded = false;

    private $relatedCollections = [];

    private $messages = [];

    private function __construct($table, $id = null)
    {
        $this->table = $table;
        $this->id = $id;
    }

    /**
     * @param null|string $table
     *
     * @return static
     */
    public static function create($table = null)
    {
        $table = $table ?: static::modelToTable();
        $class = self::tableToModel($table);
        $dataset = new $class($table);
        $dataset->dataLoaded = true;
        $dataset->exists = false;

        return $dataset;
    }

    /**
     * @param int         $id    Dataset ID
     * @param null|string $table Table name
     *
     * @return null|static
     */
    public static function get($id, $table = null)
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(sprintf('$id has to be an integer greater than 0, but "%s" given', $id));
        }

        $table = $table ?: static::modelToTable();

        $class = self::getModelClass($table);
        if ($class && __CLASS__ === static::class) {
            /* @noinspection PhpUndefinedMethodInspection */
            return $class::get($id, $table);
        }

        return static::getInstance([$table, $id], static function ($table, $id) {
            return static::query($table)->findId($id);
        });
    }

    /**
     * @param int         $id    Dataset ID
     * @param null|string $table Table name
     *
     * @return static
     */
    public static function getRaw($id, $table = null)
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(sprintf('$id has to be an integer greater than 0, but "%s" given', $id));
        }

        $table = $table ?: static::modelToTable();

        $class = self::getModelClass($table);
        if ($class && __CLASS__ === static::class) {
            /* @noinspection PhpUndefinedMethodInspection */
            return $class::getRaw($id, $table);
        }

        $callback = static function ($table, $id) {
            $class = self::tableToModel($table);
            return new $class($table, $id);
        };
        // needed for php 5
        $callback = $callback->bindTo(null, __CLASS__);

        return static::getInstance([$table, $id], $callback);
    }

    /**
     * @param null|string $table
     *
     * @return rex_yform_manager_collection
     */
    public static function getAll($table = null)
    {
        return static::query($table)->find();
    }

    /**
     * @return rex_yform_manager_table
     */
    public static function table()
    {
        $class = static::class;

        if (__CLASS__ === $class || !isset(self::$modelToTable[$class])) {
            throw new RuntimeException(sprintf('Method "%s()" is only callable for registered model classes.', __METHOD__));
        }

        return rex_yform_manager_table::get(self::$modelToTable[$class]);
    }

    /**
     * @param null|string $table
     *
     * @return rex_yform_manager_query
     */
    public static function query($table = null)
    {
        return rex_yform_manager_query::get($table ?: static::modelToTable());
    }

    /**
     * @param string      $query
     * @param null|string $table
     *
     * @return null|static
     */
    public static function queryOne($query, array $params = [], $table = null)
    {
        $table = $table ?: static::modelToTable();

        $class = self::getModelClass($table);
        if ($class && __CLASS__ === static::class) {
            /* @noinspection PhpUndefinedMethodInspection */
            return $class::queryOne($query, $params, $table);
        }

        $sql = rex_sql::factory();
        $sql
            ->setDebug(self::$debug)
            ->setQuery($query, $params);

        if (!$sql->getRows()) {
            return null;
        }

        $data = [];
        foreach ($sql->getFieldnames() as $key) {
            $data[$key] = $sql->getValue($key);
        }

        return static::fromSqlData($data, $table);
    }

    /**
     * @param string      $query
     * @param null|string $table
     *
     * @return rex_yform_manager_collection
     */
    public static function queryCollection($query, array $params = [], $table = null)
    {
        $table = $table ?: static::modelToTable();

        $class = self::getModelClass($table);
        if ($class && __CLASS__ === static::class) {
            /* @noinspection PhpUndefinedMethodInspection */
            return $class::queryCollection($query, $params, $table);
        }

        $sql = rex_sql::factory();
        $sql->setDebug(self::$debug);

        $data = $sql->getArray($query, $params);

        $datasets = [];
        foreach ($data as $row) {
            $datasets[] = static::fromSqlData($row, $table);
        }

        return new rex_yform_manager_collection($table, $datasets);
    }

    /**
     * @param string $table
     * @param string $modelClass
     */
    public static function setModelClass($table, $modelClass)
    {
        self::$tableToModel[$table] = $modelClass;
        self::$modelToTable[$modelClass] = $table;
    }

    /**
     * @param string $table
     *
     * @return null|string
     */
    public static function getModelClass($table)
    {
        return isset(self::$tableToModel[$table]) ? self::$tableToModel[$table] : null;
    }

    /**
     * @return string
     */
    public function getTableName()
    {
        return $this->table;
    }

    /**
     * @return rex_yform_manager_table
     */
    public function getTable()
    {
        return rex_yform_manager_table::get($this->table);
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return bool
     */
    public function exists()
    {
        if (!$this->dataLoaded) {
            $this->loadData();
        }

        return $this->exists;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function hasValue($key)
    {
        if (!$this->dataLoaded) {
            $this->loadData();
        }

        return null !== $this->data && array_key_exists($key, $this->data);
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function setValue($key, $value)
    {
        if (!$this->dataLoaded) {
            $this->loadData();
        }

        $this->data[$key] = $value;
        $this->newData[$key] = $value;
        unset($this->relatedCollections[$key]);

        return $this;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function getValue($key)
    {
        if ('id' === $key) {
            return $this->getId();
        }

        if (!$this->dataLoaded) {
            $this->loadData();
        }

        return $this->data[$key];
    }

    /**
     * @return array
     */
    public function getData()
    {
        if (!$this->dataLoaded) {
            $this->loadData();
        }

        return $this->data;
    }

    public function loadData()
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray('SELECT * FROM `'.$this->table.'` WHERE id = ? LIMIT 1', [$this->id]);
        $this->exists = isset($rows[0]);
        if ($this->exists) {
            $this->data = $rows[0];
        } else {
            $this->data = null;
        }
        $this->dataLoaded = true;
        $this->relatedCollections = [];
    }

    public function invalidateData()
    {
        $this->dataLoaded = false;
        $this->data = null;
        $this->newData = null;
        $this->exists = null;
        $this->relatedCollections = [];
    }

    /**
     * @param string $key
     *
     * @return null|self
     */
    public function getRelatedDataset($key)
    {
        $relation = $this->getTable()->getRelation($key);

        if (!$relation) {
            throw new InvalidArgumentException(sprintf('Field "%s" in table "%s" is not a relation field.', $key, $this->getTableName()));
        }

        $id = $this->getValue($key);

        if (!$id) {
            return null;
        }

        // php-cs-fixer would replace `rex_yform_manager_dataset::get()` by `self::get()`
        // but it would not work in this case, so we are using `__CLASS__`.
        $class = __CLASS__;

        /* @noinspection PhpUndefinedMethodInspection */
        return $class::get($id, $relation['table']);
    }

    /**
     * @param string $key
     *
     * @return rex_yform_manager_collection
     */
    public function getRelatedCollection($key)
    {
        if (isset($this->relatedCollections[$key])) {
            return $this->relatedCollections[$key];
        }

        $query = $this->getRelatedQuery($key);

        return $this->relatedCollections[$key] = $query->find();
    }

    /**
     * @param string $key
     *
     * @return $this
     */
    public function setRelatedCollection($key, rex_yform_manager_collection $collection)
    {
        $this->relatedCollections[$key] = $collection;

        return $this;
    }

    /**
     * @param string $key
     *
     * @return rex_yform_manager_query
     */
    public function getRelatedQuery($key)
    {
        $relation = $this->getTable()->getRelation($key);

        if (!$relation) {
            throw new InvalidArgumentException(sprintf('Field "%s" in table "%s" is not a relation field.', $key, $this->getTableName()));
        }

        $query = self::query($relation['table']);

        if (0 == $relation['type'] || 2 == $relation['type']) {
            $query->where('id', $this->getValue($key));
        } elseif (4 == $relation['type'] || 5 == $relation['type']) {
            $query->where($relation['field'], $this->getId());
        } elseif (empty($relation['relation_table'])) {
            $query->where('id', explode(',', $this->getValue($key)));
        } else {
            $columns = $this->getTable()->getRelationTableColumns($key);
            $query
                ->join($relation['relation_table'], null, $relation['relation_table'].'.'.$columns['target'], $relation['table'].'.id')
                ->where($relation['relation_table'].'.'.$columns['source'], $this->getId());
        }

        return $query;
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        $yform = clone $this->getInternalForm();
        $this->setFormMainId($yform);
        $yform->initializeFields();

        $table = $this->getTable();
        $fields = $table->getValueFields();
        foreach ($this->data as $key => $value) {
            if ('id' === $key) {
                continue;
            }
            if (isset($fields[$key])) {
                $yform->objparams['data'][$key] = $value;
            }
        }

        $yform->setFieldValue('send', [], '1');

        $yform->executeFields();
        $this->messages = $yform->getObjectparams('warning_messages');

        return empty($this->messages);
    }

    /**
     * @return bool
     */
    public function save()
    {
        $yform = clone $this->getInternalForm();
        $this->setFormMainId($yform);
        $yform->initializeFields();

        $table = $this->getTable();
        $fields = $table->getValueFields();
        $columns = $table->getColumns();
        foreach ($this->data as $key => $value) {
            if ('id' === $key) {
                continue;
            }
            if (isset($fields[$key])) {
                $yform->objparams['data'][$key] = $value;
            } elseif (isset($columns[$key])) {
                $yform->objparams['value_pool']['sql'][$key] = $value;
            }
        }

        $yform->setFieldValue('send', [], '1');
        $this->executeForm($yform);
        $this->messages = $yform->getObjectparams('warning_messages');

        return empty($this->messages);
    }

    /**
     * @return string[]
     */
    public function getMessages()
    {
        return $this->messages;
    }

    public function delete()
    {
        if (!rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_DELETE', true, ['table' => $this->getTable(), 'data_id' => $this->id, 'data' => $this]))) {
            return false;
        }

        if ($this->getTable()->hasHistory()) {
            $this->makeSnapshot(self::ACTION_DELETE);
        }

        $sql = rex_sql::factory();
        $sql
            ->setDebug(self::$debug)
            ->setTable($this->table)
            ->setWhere(['id' => $this->id])
            ->delete();

        $this->getTable()->removeRelationTableRelicts();

        rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_DELETED', '', ['table' => $this->getTable(), 'data_id' => $this->id, 'data' => $this]));

        self::clearInstance([$this->getTable()->getName(), $this->id]);

        $this->invalidateData();
        $this->dataLoaded = true;

        return true;
    }

    /**
     * Fields of yform Definitions.
     *
     * @return rex_yform_manager_field[]
     */
    public function getFields(array $filter = [])
    {
        return $this->getTable()->getFields($filter);
    }

    /**
     * @return rex_yform
     */
    public function getForm()
    {
        $yform = $this->createForm();
        $this->setFormMainId($yform);

        return $yform;
    }

    public function executeForm(rex_yform $yform, callable $afterFieldsExecuted = null)
    {
        $exists = $this->exists();
        $oldData = $this->getData();

        if (isset($yform->objparams['copied_from'])) {
            /** @var rex_yform $yform */
            $yform = rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_ADD', $yform, ['table' => $this->getTable(), 'copied_from_data_id' => $this->id, 'data' => $this]));
        } elseif ($exists) {
            /** @var rex_yform $yform */
            $yform = rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_UPDATE', $yform, ['table' => $this->getTable(), 'data_id' => $this->id, 'data' => $this]));
        } else {
            /** @var rex_yform $yform */
            $yform = rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_ADD', $yform, ['table' => $this->getTable(), 'data' => $this]));
        }

        $yform->setObjectparams('manager_dataset', $this);
        $yform->executeFields();

        if ($afterFieldsExecuted) {
            call_user_func($afterFieldsExecuted, $yform);
        }

        if (!$this->id) {
            rex_extension::register('YFORM_SAVED', function (rex_extension_point $ep) {
                if ($ep->getSubject() instanceof Exception) {
                    return;
                }

                /** @var rex_yform_action_db $dbAction */
                $dbAction = $ep->getParam('form');
                if ($dbAction->getParam('manager_dataset') !== $this) {
                    return;
                }

                $this->id = $dbAction->getParam('main_id') ?: null;
                if ($this->id) {
                    self::addInstance($this->id, $this);
                    rex_yform_value_be_manager_relation::clearCache($this->table);
                }
            }, rex_extension::EARLY);
        }

        $form = $yform->executeActions();

        if ($yform->objparams['actions_executed']) {
            if ($exists) {
                rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_UPDATED', $yform, ['table' => $this->getTable(), 'data_id' => $this->id, 'data' => $this, 'old_data' => $oldData]));
            } else {
                rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_ADDED', $yform, ['table' => $this->getTable(), 'data_id' => $this->id, 'data' => $this]));
            }
        }

        return $form;
    }

    /**
     * @param string $action
     */
    public function makeSnapshot($action)
    {
        if (!in_array($action, [self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE])) {
            throw new InvalidArgumentException(sprintf('Unknown action "%s", allowed actions are %s::ACTION_CREATE, ::ACTION_UPDATE and ::ACTION_DELETE', $action, __CLASS__));
        }

        $user = rex::getEnvironment();
        if ('backend' == $user && rex::getUser()) {
            $user = rex::getUser()->getLogin();
        }
        // ep to overwrite user
        $user = rex_extension::registerPoint(new rex_extension_point('YCOM_HISTORY_USER', $user));

        $sql = rex_sql::factory();
        $sql->setDebug(self::$debug);
        $sql
            ->setTable(rex::getTable('yform_history'))
            ->setValue('table_name', $this->table)
            ->setValue('dataset_id', $this->id)
            ->setValue('action', $action)
            ->setValue('user', $user)
            ->setValue('timestamp', $sql::datetime())
            ->insert();

        $historyId = $sql->getLastId();

        $sql
            ->setTable($this->table)
            ->setWhere(['id' => $this->id])
            ->select();

        $inserts = [];
        foreach ($sql->getFieldnames() as $field) {
            if ('id' === $field) {
                continue;
            }

            $inserts[] = sprintf(
                '(%d, %s, %s)',
                $historyId,
                $sql->escape($field),
                $sql->escape($sql->getValue($field))
            );
        }

        $sql->setQuery('INSERT INTO '.rex::getTable('yform_history_field').' (`history_id`, `field`, `value`) VALUES '.implode(', ', $inserts));
    }

    /**
     * @param int $snapshotId
     *
     * @return bool
     */
    public function restoreSnapshot($snapshotId)
    {
        $sql = rex_sql::factory();
        $sql->setDebug(self::$debug);
        $sql->setQuery(sprintf('SELECT * FROM %s WHERE history_id = %d', rex::getTable('yform_history_field'), $snapshotId));

        $columns = $this->getTable()->getColumns();
        foreach ($sql as $row) {
            $key = $sql->getValue('field');
            if (isset($columns[$key])) {
                $this->setValue($key, $sql->getValue('value'));
            }
        }

        return $this->save();
    }

    public function __isset($key)
    {
        return $this->hasValue($key);
    }

    public function __get($key)
    {
        return $this->getValue($key);
    }

    public function __set($key, $value)
    {
        $this->setValue($key, $value);
    }

    private function getInternalForm()
    {
        if (isset(self::$internalForms[$this->table])) {
            return self::$internalForms[$this->table];
        }

        /** @var self $dummy */
        $dummy = new static($this->table, 'dummy');

        $yform = $dummy->createForm();
        $yform->setObjectparams('real_field_names', true);
        $yform->setObjectparams('form_needs_output', false);
        $yform->setObjectparams('csrf_protection', false);
        $yform->setObjectparams('get_field_type', '');

        return self::$internalForms[$this->table] = $yform;
    }

    private function createForm()
    {
        $yform = new rex_yform();
        $fields = $this->getFields();
        $yform->setDebug(self::$debug);

        foreach ($fields as $field) {
            $class = 'rex_yform_'.$field->getType().'_'.$field->getTypeName();

            /** @var rex_yform_base_abstract $cl */
            $cl = new $class();
            $definitions = $cl->getDefinitions();

            $values = [];
            $i = 1;
            foreach ($definitions['values'] as $key => $_) {
                $values[] = $field->getElement($key);
                ++$i;
            }

            if ('value' == $field->getType()) {
                $yform->setValueField($field->getTypeName(), $values);
            } elseif ('validate' == $field->getType()) {
                $yform->setValidateField($field->getTypeName(), $values);
            } elseif ('action' == $field->getType()) {
                $yform->setActionField($field->getTypeName(), $values);
            }
        }

        $yform->setObjectparams('main_table', $this->table);
        $yform->setActionField('db', [$this->table, 'main_where']);

        return $yform;
    }

    private function setFormMainId(rex_yform $yform)
    {
        if ($this->exists()) {
            $where = 'id = ' . (int) $this->id;
            $yform->setObjectparams('main_id', $this->id);
            $yform->setObjectparams('main_where', $where);
        } elseif ($this->id) {
            $yform->objparams['value_pool']['sql']['id'] = $this->id;
        }
    }

    private static function tableToModel($table)
    {
        return self::getModelClass($table) ?: __CLASS__;
    }

    private static function modelToTable()
    {
        $class = static::class;

        if (isset(self::$modelToTable[$class])) {
            return self::$modelToTable[$class];
        }

        if (__CLASS__ === $class) {
            throw new RuntimeException('Missing $table argument');
        }

        throw new RuntimeException(sprintf('Missing $table declaration for model class "%s"', $class));
    }

    /**
     * @param string $table
     *
     * @return static
     */
    private static function fromSqlData(array $data, $table)
    {
        $id = $data['id'];
        $class = self::tableToModel($table);

        /** @var static $dataset */
        $dataset = new $class($table, $id);
        self::addInstance([$table, $id], $dataset);

        $dataset->dataLoaded = true;
        $dataset->exists = true;
        $dataset->data = $data;

        return $dataset;
    }
}
