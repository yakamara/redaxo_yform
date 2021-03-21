<?php

/**
 * yform.
 *
 * @author jan.kristinus[at]redaxo[dot]org Jan Kristinus
 * @author <a href="http://www.yakamara.de">www.yakamara.de</a>
 */

class rex_yform_value_be_manager_relation extends rex_yform_value_abstract
{
    public static $yform_list_values = [];

    protected $relation;

    public function enterObject()
    {
        // ---------- CONFIG & CHECK

        $send = $this->params['send'];

        $this->relation = [];
        $this->relation['source_table'] = $this->params['main_table']; // "rex_em_data_" wegcutten
        $this->relation['label'] = $this->getLabel();  // HTML Bezeichnung

        $this->relation['target_table'] = $this->getElement('table'); // Zieltabelle
        $this->relation['target_field'] = $this->getElement('field'); // Zielfield welches angezeigt wird.

        $this->relation['relation_type'] = (int) $this->getElement('type'); // select single = 0 / select multiple = 1 / popup single = 2 / popup multiple = 3 / popup 1-n = 4/ inline 1-n = 5
        if ($this->relation['relation_type'] > 5) {
            $this->relation['relation_type'] = 0;
        }

        $this->relation['eoption'] = (int) $this->getElement('empty_option'); // "Leer" Option

        $this->relation['size'] = (int) $this->getElement('size'); // boxsize
        if ($this->relation['size'] < 1) {
            $this->relation['size'] = 10;
        }

        if (1 != $this->relation['eoption']) {
            $this->relation['eoption'] = 0;
        }

        // ---------- Value angleichen -> immer Array mit IDs daraus machen
        if (!is_array($this->getValue())) {
            if ($this->getElement('relation_table') && (!$this->params['send'] || null === $this->getValue())) {
                $this->setValue($this->getRelationTableValues());
            } elseif ('' == trim($this->getValue())) {
                $this->setValue([]);
            } else {
                $this->setValue(explode(',', $this->getValue()));
            }
        }

        // ---------- Filter
        $filter = [];
        if ($rawFilter = $this->getElement('filter')) {
            $filter = self::getFilterArray($rawFilter, $this->params['main_table'], [$this, 'getValueForKey']);
        }
        if (isset($this->params['rex_yform_set'][$this->getName()]) && is_array($this->params['rex_yform_set'][$this->getName()])) {
            $filter = array_merge($filter, $this->params['rex_yform_set'][$this->getName()]);
        }

        // ---------- check values
        $options = [];
        $valueName = '';
        $values = [];

        if (count($this->getValue()) > 0) {
            $listValues = self::getListValues($this->relation['target_table'], $this->relation['target_field'], $filter);
            foreach ((array) $this->getValue() as $v) {
                if (is_string($v) && isset($listValues[$v])) {
                    $values[] = $v;
                    $valueName = $listValues[$v] . ' [id=' . $v . ']';
                    $options[] = ['id' => $v, 'name' => $valueName];
                }
            }
            $this->setValue($values);
        }

        // ---------- empty option ?
        if (!$this->isValidationDisabled() && 1 == $this->params['send'] && 0 == $this->relation['eoption'] && 0 == count($this->getValue())
            && 4 != $this->relation['relation_type']
            && 5 != $this->relation['relation_type']
        ) {
            $this->params['warning'][$this->getId()] = $this->params['error_class'];
            $this->params['warning_messages'][$this->getId()] = $this->getElement('empty_value');
        }

        // ---------- save
        $this->params['value_pool']['email'][$this->getName()] = implode(',', $this->getValue());
        if (!$this->getElement('relation_table') && (4 != $this->relation['relation_type'] && 5 != $this->relation['relation_type'])) {
            $this->params['value_pool']['sql'][$this->getName()] = implode(',', $this->getValue());
        }

        if (!$this->needsOutput()) {
            return;
        }

        // ------------------------------------ Selectbox, single 0 or multiple 1
        if ($this->relation['relation_type'] < 2) {
            // ----- SELECT BOX
            $options = [];
            if (0 == $this->relation['relation_type'] && 1 == $this->relation['eoption']) {
                $options[] = ['id' => '', 'name' => '-'];
            }
            foreach (self::getListValues($this->relation['target_table'], $this->relation['target_field'], $filter) as $id => $name) {
                if (strlen($name) > 50) {
                    $name = mb_substr($name, 0, 45) . ' ... ';
                }
                $options[] = ['id' => $id, 'name' => $name . ' [id=' . $id . ']'];
            }

            $this->params['form_output'][$this->getId()] = $this->parse('value.be_manager_relation.tpl.php', compact('options'));
        }

        // ------------------------------------ POPUP, single, multiple 1-1, n-m
        if (2 == $this->relation['relation_type'] || 3 == $this->relation['relation_type']) {
            $link = 'index.php?page=yform/manager/data_edit&table_name=' . $this->relation['target_table'];
            foreach ($filter as $key => $value) {
                $link .= '&rex_yform_filter[' . $key . ']=' . $value . '&rex_yform_set[' . $key . ']=' . $value;
            }
            $this->params['form_output'][$this->getId()] = $this->parse('value.be_manager_relation.tpl.php', compact('valueName', 'options', 'link'));
        }

        // ------------------------------------ POPUP, 1-n
        if (4 == $this->relation['relation_type']) {
            $filter[$this->relation['target_field']] = $this->params['main_id'];
            $link = 'index.php?page=yform/manager/data_edit&table_name=' . $this->relation['target_table'];
            self::addFilterParams($link, $filter);
            $link = self::addOpenerParams($link);
            $link .= '&rex_yform_manager_popup=1';
            $this->params['form_output'][$this->getId()] = $this->parse('value.be_manager_relation.tpl.php', compact('valueName', 'options', 'link', 'send'));
        }

        // ------------------------------------ INLINE, 1-n
        if (5 == $this->relation['relation_type']) {
            $warning = false;
            $table = rex_yform_manager_table::get($this->relation['target_table']);

            // ----- Find PrioFieldname if exists

            $prioFieldName = '';
            $fields = [];
            foreach ($table->getFields() as $field) {
                if ('value' == $field->getType()) {
                    if ('prio' == $field->getTypeName()) {
                        $prioFieldName = $field->getName();
                    } else {
                        $fields[] = $field->getName();
                    }
                }
            }

            // ----- Form Definitions

            $forms = [];

            $form_name = $this->params['this']->getObjectparams('form_name');
            $form_array = array_merge($this->params['this']->getObjectparams('form_array'), [$this->getId()]);

            $fieldkey = $this->relation['source_table'] . '-' . $this->relation['target_table'] . '-' . $this->relation['target_field'];
            $relationKey = '__' . sha1($fieldkey) . '__';

            // ----- Existing Relations

            $relations = rex_yform_manager_dataset::query($this->relation['target_table'])
                ->where($this->relation['target_field'], $this->params['main_id']);
            if ('' != $prioFieldName) {
                $relations->orderBy($prioFieldName);
            }
            $relations = $relations->find();

            $relationIDs = [];
            foreach ($relations as $relation) {
                $relationIDs[] = $relation->getId();
            }

            // ----- Prototype Form

            $data = $table->createDataset();
            $yform = $data->getForm();

            $yform->setObjectparams('form_name', $form_name);
            $yform->setObjectparams('form_array', array_merge($form_array, [$relationKey])); // $relationKey]);

            $yform->setObjectparams('form_action', '');
            $yform->setObjectparams('form_showformafterupdate', 1);
            $yform->setObjectparams('data', ['1']);

            $yform->setObjectparams('submit_btn_show', false);
            $yform->setObjectparams('csrf_protection', false);
            if (isset($this->params['copied_from'])) {
                $yform->setObjectparams('copied_from', $this->params['copied_from']);
            }
            $form_elements = [];
            foreach ($yform->objparams['form_elements'] as $form_element) {
                if ('prio' == $form_element[0] && $form_element[1] == $prioFieldName) {
                    $form_elements[] = ['hidden', $prioFieldName];
                } elseif ('be_manager_relation' == $form_element[0] && $form_element[1] == $this->relation['target_field'] && $form_element[3] == $this->relation['source_table']) {
                    $form_elements[] = ['hidden', $form_element[1]];
                } elseif ('action' == $form_element[0]) {
                } else {
                    $form_elements[] = $form_element;
                }
            }
            $yform->objparams['form_elements'] = $form_elements;
            $prototypeForm = $yform->getForm();

            // \\ ----- Prototype Form

            // ----- Existing Forms - Init

            $value = [];

            if (!$send) {
                foreach ($relations as $counter => $relation) {
                    $yform = $relation->getForm();
                    $yform->setObjectparams('form_name', $form_name);
                    $yform->setObjectparams('form_array', array_merge($form_array, [$counter]));

                    $yform->setObjectparams('form_action', '');
                    $yform->setObjectparams('form_showformafterupdate', 1);

                    $yform->setObjectparams('getdata', true);

                    $yform->setObjectparams('submit_btn_show', false);
                    $yform->setObjectparams('csrf_protection', false);
                    if (isset($this->params['copied_from'])) {
                        $yform->setObjectparams('copied_from', $this->params['copied_from']);
                    }
                    $form_elements = [];
                    foreach ($yform->objparams['form_elements'] as $form_element) {
                        if ('prio' == $form_element[0] && $form_element[1] == $prioFieldName) {
                            $form_elements[] = ['hidden', $prioFieldName];
                        } elseif ('be_manager_relation' == $form_element[0] && $form_element[1] == $this->relation['target_field'] && $form_element[3] == $this->relation['source_table']) {
                            $form_elements[] = ['hidden', $form_element[1]];
                        } elseif ('action' == $form_element[0]) {
                        } else {
                            $form_elements[] = $form_element;
                        }
                    }
                    $yform->objparams['form_elements'] = $form_elements;

                    $hiddenId = '<input type="hidden" name="' . $yform->getFieldName('id') . '" value="' . $relation->getId() . '" />';
                    if (isset($this->params['copied_from'])) {
                        $hiddenId = '';
                    }

                    $forms[] = $hiddenId . $yform->getForm();
                }
            }

            // ----- Relations Forms sent

            if ($send) {
                $yform->setObjectparams('form_array', $form_array);
                $relationVars = $yform->getFieldValue('', []);

                if (is_array($relationVars)) {
                    foreach ($relationVars as $counter => $form) {
                        $data = $table->createDataset();
                        $yform = $data->getForm();
                        $yform->setObjectparams('form_name', $form_name);
                        $yform->setObjectparams('form_array', array_merge($form_array, [$counter]));

                        $yform->setObjectparams('form_action', '');
                        $yform->setObjectparams('form_showformafterupdate', 1);
                        $yform->setObjectparams('data', $form);

                        $yform->setObjectparams('submit_btn_show', false);
                        $yform->setObjectparams('csrf_protection', false);
                        $form_elements = [];

                        $relation_field = '';
                        foreach ($yform->objparams['form_elements'] as $form_element) {
                            if ('prio' == $form_element[0] && $form_element[1] == $prioFieldName) {
                                $form_elements[] = ['hidden', $prioFieldName];
                            } elseif ('be_manager_relation' == $form_element[0] && $form_element[1] == $this->relation['target_field'] && $form_element[3] == $this->relation['source_table']) {
                                $relation_field = $form_element[1];
                                $form_elements[] = ['hidden', $form_element[1]];
                            } elseif ('action' == $form_element[0]) {
                            } else {
                                $form_elements[] = $form_element;
                            }
                        }
                        $yform->objparams['form_elements'] = $form_elements;

                        // Wenn Formular abgeschickt und id hatte..
                        // beibehalten wenn in relations vorhanden
                        $hiddenId = '';
                        if (isset($form['id']) && in_array($form['id'], $relationIDs, true)) {
                            $hiddenId = '<input type="hidden" name="' . $yform->getFieldName('id') . '" value="' . $form['id'] . '" />';
                        }

                        $forms[] = $hiddenId . $yform->getForm();

                        if (isset($form['id']) && in_array($form['id'], $relationIDs, true)) {
                            $value[] = array_merge(['id' => $form['id']], $yform->getObjectparams('value_pool')['email']);
                        } else {
                            $value[] = $yform->getObjectparams('value_pool')['email'];
                        }

                        if (count($yform->objparams['warning']) > 0) {
                            $warning = true;
                        }
                    }
                }

                // \\ ----- Relations Forms sent

                if ($warning) {
                    $this->params['warning'][$this->getId()] = $this->params['error_class'];
                    $this->params['warning_messages'][$this->getId()] = $this->getElement('empty_value');
                }
            }

            $this->params['value_pool']['email'][$this->getName()] = $value;

            $this->params['form_output'][$this->getId()] = $this->parse('value.be_manager_inline_relation.tpl.php', compact('forms', 'prototypeForm', 'fieldkey', 'prioFieldName', 'relationKey'));
        }
    }

    // -------------------------------------------------------------------------

    public function postAction()
    {
        if ($this->needsOutput()) {
            if (5 == $this->relation['relation_type'] && $this->params['main_id'] > 0) {
                self::$yform_list_values = []; // delete cache

                $currentRelationsQuery = rex_yform_manager_dataset::query($this->relation['target_table'])
                    ->where($this->relation['target_field'], $this->params['main_id'])
                    ->find();

                $currentRelations = [];
                foreach ($currentRelationsQuery as $currentRelation) {
                    $currentRelations[$currentRelation->getId()] = $currentRelation;
                }
                $table = rex_yform_manager_table::get($this->relation['target_table']);

                $prioFieldName = '';
                foreach ($table->getFields() as $field) {
                    if ('value' == $field->getType()) {
                        if ('prio' == $field->getTypeName()) {
                            $prioFieldName = $field->getName();
                        }
                    }
                }

                $form_name = $this->params['this']->getObjectparams('form_name');
                $form_array = array_merge($this->params['this']->getObjectparams('form_array'), [$this->getId()]);

                $data = $table->createDataset();
                $yform = $data->getForm();

                $yform->setObjectparams('form_name', $form_name);
                $yform->setObjectparams('form_array', array_merge($form_array));

                $relationVars = $yform->getFieldValue();

                if (is_array($relationVars)) {
                    $prio = 0;
                    foreach ($relationVars as $counter => $form) {
                        ++$prio;

                        if ('' != $prioFieldName) {
                            $form[$prioFieldName] = $prio;
                        }

                        $form[$this->relation['target_field']] = $this->params['main_id'];

                        $data = $table->createDataset();
                        $yform = $data->getForm();
                        $yform->setObjectparams('form_name', $form_name);
                        $yform->setObjectparams('form_array', array_merge($form_array, [$counter]));

                        if (isset($form['id'])) {
                            $form['id'] = (int) $form['id'];
                            $yform->setObjectparams('main_id', $form['id']);
                            $yform->setObjectparams('main_where', 'id=' . $form['id']);
                            if (array_key_exists($form['id'], $currentRelations)) {
                                unset($currentRelations[$form['id']]);
                            }
                        }

                        $yform->setObjectparams('data', $form);
                        $yform->setObjectparams('send', true);
                        $yform->setObjectparams('submit_btn_show', false);
                        $yform->setObjectparams('csrf_protection', false);
                        $forms[] = $yform->getForm();
                    }
                }

                foreach ($currentRelations as $relation) {
                    $relation->delete(); // unused relations
                }
            }
        }

        // ---------------------------------------------------

        if (!$relationTable = $this->getElement('relation_table')) {
            return;
        }

        $source_id = -1;
        if (isset($this->params['value_pool']['email']['ID']) && $this->params['value_pool']['email']['ID'] > 0) {
            $source_id = (int) $this->params['value_pool']['email']['ID'];
        }
        if ($source_id < 1 && isset($this->params['main_id']) && $this->params['main_id'] > 0) {
            $source_id = (int) $this->params['main_id'];
        }

        if ($source_id < 1 || '' == $this->params['main_table']) {
            return false;
        }

        $relationTableField = $this->getRelationTableFields();
        if (!$relationTableField['source'] || !$relationTableField['target']) {
            return;
        }

        // ----- Value angleichen -> immer Array mit IDs daraus machen
        $values = [];
        if (!is_array($this->getValue())) {
            if ('' != trim($this->getValue())) {
                $values = explode(',', $this->getValue());
            }
        } else {
            $values = $this->getValue();
        }

        $values = array_map('intval', $values);

        $sql = rex_sql::factory();
        $sql->setDebug($this->params['debug']);
        $relationTablePreEditValues = $this->getRelationTableValues();
        foreach ($values as $value) {
            if (!isset($relationTablePreEditValues[$value])) {
                $sql->setTable($relationTable);
                $sql->setValue($relationTableField['source'], $source_id);
                $sql->setValue($relationTableField['target'], $value);
                $sql->insert();
            }
        }
        $sql->flushValues();
        $sql->setTable($relationTable);
        $sql->setWhere(' ' . $sql->escapeIdentifier($relationTableField['source']) . ' =' . $source_id . ' AND ' . (empty($values) ?: $sql->escapeIdentifier($relationTableField['target']) . ' NOT IN (' . implode(',', $values) . ')'));
        $sql->delete();
    }

    // -------------------------------------------------------------------------

    public function getDefinitions()
    {
        return [
            'type' => 'value',
            'name' => 'be_manager_relation',
            'values' => [
                'name' => ['type' => 'name',    'label' => rex_i18n::msg('yform_values_defaults_name')],
                'label' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_label')],
                'table' => ['type' => 'table',   'label' => rex_i18n::msg('yform_values_be_manager_relation_table')],
                'field' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_be_manager_relation_field')],
                'type' => ['type' => 'choice',  'label' => rex_i18n::msg('yform_values_be_manager_relation_type'), 'choices' => ['0' => 'select (single)', '1' => 'select (multiple)', '2' => 'popup (single)', '3' => 'popup (multiple)', '4' => 'popup (multiple 1-n)', '5' => 'inline (multiple 1-n)']], // ,popup (multiple / relation)=4
                'empty_option' => ['type' => 'boolean', 'label' => rex_i18n::msg('yform_values_be_manager_relation_empty_option')],
                'empty_value' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_be_manager_relation_empty_value')],
                'size' => ['type' => 'text', 'name' => 'boxheight',    'label' => rex_i18n::msg('yform_values_be_manager_relation_size')],
                'filter' => ['type' => 'textarea', 'label' => rex_i18n::msg('yform_values_be_manager_relation_filter')],
                'relation_table' => ['type' => 'table', 'label' => rex_i18n::msg('yform_values_be_manager_relation_relation_table'), 'empty_option' => 1],
                'attributes' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_attributes'), 'notice' => rex_i18n::msg('yform_values_defaults_attributes_notice')],
                'notice' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_notice')],
            ],
            'description' => rex_i18n::msg('yform_values_be_manager_relation_description'),
            'db_type' => ['text', 'varchar(191)', 'int'],
            'formbuilder' => false,
            'hooks' => [
                'preCreate' => static function (rex_yform_manager_field $field) {
                    return !$field->getElement('relation_table') && ('4' != $field->getElement('type') && '5' != $field->getElement('type'));
                },
            ],
            'multi_edit' => static function (rex_yform_manager_field $field) {
                return ('4' != $field->getElement('type') && '5' != $field->getElement('type')) && !$field->getElement('relation_table');
            },
        ];
    }

    public static function getListValue($params)
    {
        /** @var rex_yform_manager_field $field */
        $field = $params['params']['field'];

        if (4 == $field['type'] || 5 == $field['type']) {
            if (!isset($params['list'])) {
                return '';
            }

            $link = 'index.php?page=yform/manager/data_edit&table_name=' . $field['table'];
            if (isset($field['filter']) && $field['filter']) {
                $filter = self::getFilterArray($field['filter'], $field['table_name'], static function ($key) use ($params) {
                    return $params['list']->getValue($key);
                });
            }
            $filter[$field['field']] = $params['list']->getValue('id');

            self::addFilterParams($link, $filter);
            $link = self::addOpenerParams($link);

            return '<a href="' . $link . '">' . rex_i18n::translate($field['label']) . '</a>';
        }

        // with relation-table
        if ((3 == $field['type'] || 1 == $field['type']) && @$field['relation_table'] && null === $params['value']) {
            $params['value'] = [];
            $relationTableFields = self::getRelationTableFieldsForTables($field['table_name'], $field['relation_table'], $field['table']);

            if ($relationTableFields['source'] && $relationTableFields['target']) {
                $sql = rex_sql::factory();
                // $sql->setDebug();
                $sql->setQuery('
                    SELECT ' . $sql->escapeIdentifier($relationTableFields['target']) . ' as id
                    FROM ' . $sql->escapeIdentifier($field['relation_table']) . '
                    WHERE ' . $sql->escapeIdentifier($relationTableFields['source']) . ' = ' . (int) $params['list']->getValue('id')
                );
                while ($sql->hasNext()) {
                    $id = $sql->getValue('id');
                    $params['value'][$id] = $id;
                    $sql->next();
                }
            }

            $params['value'] = implode(',', $params['value']);
        }

        // relation_table
        $listValues = self::getListValues($field['table'], $field['field']);

        // filter values
        $return = [];
        foreach (explode(',', $params['value']) as $value) {
            if (isset($listValues[$value])) {
                $return[] = $listValues[$value];
            }
        }

        return implode('<br />', $return);
    }

    public static function getListValues($table, $field, array $filter = [])
    {
        $filterHash = sha1(json_encode($filter));
        if (!isset(self::$yform_list_values[$table][$field][$filterHash])) {
            $tableObject = rex_yform_manager_table::get($table);
            self::$yform_list_values[$table][$field][$filterHash] = [];
            $db = rex_sql::factory();
            // $db->setDebug();
            $where = '';
            $join = '';
            $joinIndex = 1;
            if ($filter) {
                $where = [];
                foreach ($filter as $key => $value) {
                    if (!is_array($value)) {
                        $where[] = 't0.' . $db->escapeIdentifier($key) . ' = ' . $db->escape($value);
                    } elseif ($relation = $tableObject->getRelation($key)) {
                        $join .= ' LEFT JOIN ' . $db->escapeIdentifier($relation['table']) . ' t' . $joinIndex . ' ON t0.' . $db->escapeIdentifier($key) . ' = t' . $joinIndex . '.id';
                        foreach ($value as $k => $v) {
                            $where[] = 't' . $joinIndex . '.' . $db->escapeIdentifier($k) . ' = ' . $db->escape($v);
                        }
                        ++$joinIndex;
                    }
                }
                $where = ' WHERE ' . implode(' AND ', $where);
            }
            $concat = self::getNameConcatFields($field);
            $fields = [];
            foreach ($concat as $c) {
                if ($c['field']) {
                    $fields[] = 't0.' . $db->escapeIdentifier($c['name']);
                }
            }
            $order = 't0.' . $db->escapeIdentifier($tableObject['list_sortfield'] ?: 'id') . ' ' . ($tableObject['list_sortorder'] ?: 'ASC');
            $db_array = $db->getArray('select t0.id, ' . implode(', ', $fields) . ' from ' . $db->escapeIdentifier($table) . ' t0' . $join . $where . ' ORDER BY ' . $order);
            foreach ($db_array as $entry) {
                $value = '';
                foreach ($concat as $c) {
                    if ($c['field']) {
                        $v = $entry[$c['name']];
                        if ($relation = $tableObject->getRelation($c['name'])) {
                            $relationListValues = self::getListValues($relation['table'], $relation['field']);
                            if (isset($relationListValues[$v])) {
                                $v = $relationListValues[$v];
                            }
                        }
                        $value .= $v;
                    } else {
                        $value .= $c['name'];
                    }
                }
                self::$yform_list_values[$table][$field][$filterHash][$entry['id']] = $value;
            }
        }
        return self::$yform_list_values[$table][$field][$filterHash];
    }

    public static function clearCache(string $table)
    {
        unset(self::$yform_list_values[$table]);
    }

    private static function getNameConcatFields($field)
    {
        preg_match_all('/(?:^|(?<=,))\s*((\'|")(.*?)\2|[^\'"\s].*?)\s*(?:(?=,)|$)/', $field, $matches, PREG_SET_ORDER);
        $concat = [];
        foreach ($matches as $match) {
            if (isset($match[2])) {
                $concat[] = [
                    'field' => false,
                    'name' => $match[3],
                ];
            } else {
                $concat[] = [
                    'field' => true,
                    'name' => $match[1],
                ];
            }
        }
        if (empty($concat)) {
            return [[
                'field' => true,
                'name' => 'id',
            ]];
        }
        return $concat;
    }

    private static function getFilterArray($rawFilter, $table, callable $getValueForKey)
    {
        $rawFilter = preg_split('/\v+/', $rawFilter);
        $filter = [];
        $setValue = static function ($key, $value) use (&$filter) {
            if (false !== strpos($key, '.')) {
                [$key1, $key2] = explode('.', $key, 2);
                $filter[$key1][$key2] = $value;
            } else {
                $filter[$key] = $value;
            }
        };
        foreach ($rawFilter as $f) {
            $f = explode('=', $f, 2);
            if (2 === count($f)) {
                $key = trim($f[0]);
                $value = trim($f[1]);
                if (preg_match('/^###(.+)###$/', $value, $matches)) {
                    $value = $matches[1];
                    if (false !== strpos($value, '.')) {
                        $value = explode('.', $value);
                        $relation = rex_yform_manager_table::get($table)->getRelation($value[0]);
                        $value[0] = $getValueForKey($value[0]);
                        if ($value[0] && $relation) {
                            $relationSql = rex_sql::factory();
                            //$relationSql->debugsql = true;
                            $tables = '' . $relationSql->escapeIdentifier($relation['table']) . ' t0';
                            for ($i = 1; $i < count($value) - 1; ++$i) {
                                $relation = rex_yform_manager_table::get($relation['table'])->getRelation($value[$i]);
                                $tables .= ' LEFT JOIN ' . $relationSql->escapeIdentifier($relation['table']) . ' t' . $i . ' ON t' . $i . '.id = t' . ($i - 1) . '.' . $relationSql->escapeIdentifier($value[$i]) . '';
                            }
                            $relationSql->setQuery('SELECT t' . ($i - 1) . '.' . $relationSql->escapeIdentifier($value[$i]) . ' FROM ' . $tables . ' WHERE t0.id = ' . (int) $value[0]);
                            if ($relationSql->getRows()) {
                                $setValue($key, $relationSql->getValue($value[$i]));
                            }
                        }
                    } elseif ($value = $getValueForKey($value)) {
                        $setValue($key, $value);
                    }
                } else {
                    $setValue($key, $value);
                }
            }
        }
        return $filter;
    }

    private static function addFilterParams(&$link, array $filter)
    {
        foreach ($filter as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    self::addFilterParams($link, [$key . '][' . $k => $v]);
                }
            } else {
                $link .= '&rex_yform_filter[' . $key . ']=' . $value . '&rex_yform_set[' . $key . ']=' . $value;
            }
        }
    }

    private static function addOpenerParams($link)
    {
        $rex_yform_manager_opener = rex_request('rex_yform_manager_opener', 'array');
        if (count($rex_yform_manager_opener) > 0) {
            foreach ($rex_yform_manager_opener as $k => $v) {
                $link .= '&rex_yform_manager_opener[' . $k . ']=' . urlencode($v);
            }
        }
        return $link;
    }

    protected function getRelationTableFields()
    {
        return self::getRelationTableFieldsForTables($this->params['main_table'], $this->getElement('relation_table'), $this->getElement('table'));
    }

    protected function getRelationTableValues()
    {
        $values = [];
        $relationTableFields = $this->getRelationTableFields();
        if ($relationTableFields['source'] && $relationTableFields['target']) {
            $sql = rex_sql::factory();
            $sql->setDebug($this->params['debug']);
            $sql->setQuery('
                SELECT ' . $sql->escapeIdentifier($relationTableFields['target']) . ' as id
                FROM ' . $sql->escapeIdentifier($this->getElement('relation_table')) . '
                WHERE ' . $sql->escapeIdentifier($relationTableFields['source']) . ' = ' . (int) $this->params['main_id']
            );
            while ($sql->hasNext()) {
                $id = $sql->getValue('id');
                $values[$id] = $id;
                $sql->next();
            }
        }
        return $values;
    }

    public static function getSearchField($params)
    {
        if (4 == $params['field']->getElement('type') || 5 == $params['field']->getElement('type')) {
            return;
        }

        $params['searchForm']->setValueField('be_manager_relation', [
            'name' => $params['field']->getName(),
            'label' => $params['field']->getLabel(),
            'empty_option' => true,
            'table' => $params['field']->getElement('table'),
            'field' => $params['field']->getElement('field'),
            'type' => 2,
        ]);
    }

    public static function getSearchFilter($params)
    {
        $value = $params['value'];

        if (null !== $value && !is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            return null;
        }
        $value = (string) $value;

        if ('' == $value) {
            return null;
        }

        /** @var rex_yform_manager_field $field */
        $field = $params['field'];
        $sql = rex_sql::factory();

        if (!$field->getElement('relation_table')) {
            return 'FIND_IN_SET(' . $sql->escape($value) . ', ' . $sql->escapeIdentifier($field) . ')';
        }

        $relationTableFields = self::getRelationTableFieldsForTables($field->getElement('table_name'), $field->getElement('relation_table'), $field->getElement('table'));
        if (!$relationTableFields['source'] || !$relationTableFields['target']) {
            return null;
        }

        return sprintf(
            'EXISTS (SELECT * FROM %s WHERE %1$s.%s = t0.id AND %1$s.%s = %d)',
            $sql->escapeIdentifier($field->getElement('relation_table')),
            $sql->escapeIdentifier($relationTableFields['source']),
            $sql->escapeIdentifier($relationTableFields['target']),
            (int) $value
        );
    }

    private static function getRelationTableFieldsForTables($mainTable, $relationTable, $targetTable)
    {
        $table = rex_yform_manager_table::get($relationTable);
        $source = $table->getRelationsTo($mainTable);
        $target = $table->getRelationsTo($targetTable);

        if (empty($source) || empty($target)) {
            return ['source' => null, 'target' => null];
        }

        if (reset($source)->getName() == reset($target)->getName()) {
            return ['source' => reset($source)->getName(), 'target' => next($target)->getName()];
        }

        return ['source' => reset($source)->getName(), 'target' => reset($target)->getName()];
    }
}
