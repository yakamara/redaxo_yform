<?php

/**
 * yform.
 *
 * @author jan.kristinus[at]redaxo[dot]org Jan Kristinus
 * @author <a href="http://www.yakamara.de">www.yakamara.de</a>
 */

if (!function_exists('rex_yform_manager_checkField')) {
    function rex_yform_manager_checkField($l, $v, $p)
    {
        return rex_yform_manager::checkField($l, $v, $p);
    }
}

class rex_yform_manager
{
    /** @var rex_yform_manager_table */
    public $table = '';
    public $linkvars = [];
    public $type = '';
    public $dataPageFunctions = [];
    public static $debug = false;

    protected static $reservedFieldColumns = ['id', 'table_name', 'prio', 'type_id', 'type_name', 'list_hidden', 'search'];

    public function __construct()
    {
        $this->setDataPageFunctions();
    }

    // ----- Permissions
    public function setDataPageFunctions($f = ['add', 'delete', 'search', 'export', 'import', 'truncate_table'])
    {
        $this->dataPageFunctions = $f;
    }

    public function hasDataPageFunction($f)
    {
        return in_array($f, $this->dataPageFunctions) ? true : false;
    }

    // ----- Seitenausgabe
    public function setLinkVars($linkvars)
    {
        $this->linkvars = array_merge($this->linkvars, $linkvars);
    }

    public function getLinkVars()
    {
        return $this->linkvars;
    }

    // ---------------------------------- data functions
    public function getDataPage()
    {
        rex_extension::registerPoint(new rex_extension_point('YFORM_MANAGER_DATA_PAGE', $this));

        // ********************************************* DATA ADD/EDIT/LIST

        $func = rex_request('func', 'string', '');
        $data_id = rex_request('data_id', 'int', 0);
        $show_list = true;

        // -------------- rex_yform_manager_filter and sets

        $field_names = [];
        foreach ($this->table->getValueFields() as $field) {
            $field_names[] = $field->getName();
        }

        $rex_yform_filter = rex_request('rex_yform_filter', 'array');
        foreach ($rex_yform_filter as $k => $v) {
            if (!in_array($k, $field_names)) {
                unset($rex_yform_filter[$k]);
            }
        }

        $rex_yform_set = rex_request('rex_yform_set', 'array');
        foreach ($rex_yform_set as $k => $v) {
            if (!in_array($k, $field_names)) {
                unset($rex_yform_set[$k]);
            }
        }

        $rex_yform_list = [];
        $rex_yform_list['list'] = rex_request('list', 'string');
        $rex_yform_list['sort'] = rex_request('sort', 'string');
        $rex_yform_list['sorttype'] = rex_request('sorttype', 'string');
        $rex_yform_list['start'] = rex_request('start', 'int', null) ?? rex_request($rex_yform_list['list'].'_start', 'int', null) ?? 0;

        $_csrf_key = 'table_field-'.$this->table->getTableName();
        $rex_yform_list += rex_csrf_token::factory($_csrf_key)->getUrlParams();

        $popup = false;
        $rex_yform_manager_opener = rex_request('rex_yform_manager_opener', 'array');
        if (isset($rex_yform_manager_opener['id']) && '' != $rex_yform_manager_opener['id']) {
            $popup = true; // id, field, multiple
        }

        $rex_yform_manager_popup = rex_request('rex_yform_manager_popup', 'int');
        if (1 == $rex_yform_manager_popup) {
            $popup = true;
        }

        rex_extension::registerPoint(new rex_extension_point('YFORM_MANAGER_DATA_PAGE', $this));

        $rex_yform_filter = rex_extension::registerPoint(new rex_extension_point('YFORM_MANAGER_DATA_EDIT_FILTER', $rex_yform_filter, [
            'table' => $this->table,
        ]));

        $rex_yform_set = rex_extension::registerPoint(new rex_extension_point('YFORM_MANAGER_DATA_EDIT_SET', $rex_yform_set, [
            'table' => $this->table,
        ]));

        $searchObject = new rex_yform_manager_search($this->table);
        $searchObject->setSearchLinkVars($this->getLinkVars());
        $searchObject->setSearchLinkVars($rex_yform_list);
        $searchObject->setSearchLinkVars(['rex_yform_filter' => $rex_yform_filter]);
        $searchObject->setSearchLinkVars(['rex_yform_set' => $rex_yform_set]);
        $searchObject->setSearchLinkVars(['rex_yform_manager_opener' => $rex_yform_manager_opener]);
        $searchObject->setSearchLinkVars(['rex_yform_manager_popup' => $rex_yform_manager_popup]);

        $searchform = '';
        if ($this->hasDataPageFunction('search')) {
            $fragment = new rex_fragment();
            $fragment->setVar('class', 'edit', false);
            $fragment->setVar('title', rex_i18n::msg('yform_manager_search'));
            $fragment->setVar('body', $searchObject->getForm(), false);
            $searchform = $fragment->parse('core/page/section.php');
            $this->setLinkVars($searchObject->getSearchVars());
        }

        $description = $popup || ('' == $this->table->getDescription()) ? '' : '<br />' . $this->table->getDescription();

        echo rex_extension::registerPoint(
            new rex_extension_point(
                'YFORM_MANAGER_DATA_PAGE_HEADER',
                rex_view::title(rex_i18n::msg('yform_table') . ': ' . $this->table->getNameLocalized() . ' <small>[' . $this->table->getTablename() . ']' . $description . '</small>', ''),
                [
                    'yform' => $this,
                ]
            )
        );

        if ('' != $func && in_array($func, ['delete', 'dataset_delete', 'truncate_table'])) {
            if (!rex_csrf_token::factory($_csrf_key)->isValid()) {
                echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
                $func = '';
            }
        }

        echo rex_extension::registerPoint(new rex_extension_point('YFORM_MANAGER_REX_INFO', ''));

        $show_editpage = rex_extension::registerPoint(new rex_extension_point('YFORM_MANAGER_DATA_EDIT_FUNC', true,
        [
            'table' => $this->table,
            'link_vars' => $this->getLinkVars(),
        ]
        ));

        if ($show_editpage) {
            if ($data_id > 0) {
                $data_query = $this->table->query()
                    ->alias('t0')
                    ->where('id', $data_id);
                $where = $this->getDataListQueryWhere(array_merge($rex_yform_filter, $rex_yform_set), $searchObject, $this->table);
                if ($where) {
                    $data_query->whereRaw($where);
                }
                $data_collection = $data_query->find();

                if (1 == count($data_collection)) {
                    $data_id = $data_collection[0]->getId();
                } else {
                    $data_id = null;
                }
            }

            // -------------- Searchfields / Searchtext
            $rex_link_vars = array_merge(
                $this->getLinkVars(),
                $rex_yform_list,
                ['rex_yform_manager_opener' => $rex_yform_manager_opener],
                ['rex_yform_manager_popup' => $rex_yform_manager_popup],
                ['rex_yform_filter' => $rex_yform_filter],
                ['rex_yform_set' => $rex_yform_set]
            );

            // -------------- FILTER UND SETS PRÜFEN

            if (!$popup && 'import' == $func && $this->hasDataPageFunction('import')) {
                include rex_path::plugin('yform', 'manager', 'pages/data_import.php');
            }

            if (!$popup && 'history' == $func) {
                echo rex_view::info('<a href="index.php?' . http_build_query(array_merge($rex_link_vars)) . '"><b>&laquo; ' . rex_i18n::msg('yform_back_to_overview') . '</b></a>');
                include rex_path::plugin('yform', 'manager', 'pages/data_history.php');
                $show_list = false;
            }

            if ('delete' == $func && $data_id && $this->hasDataPageFunction('delete')) {
                if ($this->table->getRawDataset($data_id)->delete()) {
                    echo rex_view::success(rex_i18n::msg('yform_datadeleted'));
                    $func = '';
                }
            }

            if ('dataset_delete' == $func && $this->hasDataPageFunction('truncate_table')) {
                $query = $this->table->query();
                $where = $this->getDataListQueryWhere(array_merge($rex_yform_filter, $rex_yform_set), $searchObject, $this->table);
                if ($where) {
                    $query->whereRaw($where);
                }
                $collection = $query->find();
                $collection->delete();
                echo rex_view::success(rex_i18n::msg('yform_dataset_deleted'));
                $func = '';
            }

            if (!$popup && 'truncate_table' == $func && $this->hasDataPageFunction('truncate_table')) {
                $this->table->query()->find()->delete();
                echo rex_view::success(rex_i18n::msg('yform_table_truncated'));
                $func = '';
            }

            if (!$popup && 'dataset_export' == $func && $this->hasDataPageFunction('export')) {
                ob_end_clean();

                $sql = $this->getDataListQuery(array_merge($rex_yform_filter, $rex_yform_set), $searchObject, $this->table);

                $g = rex_sql::factory();
                $g->setQuery($sql);
                $dataset = rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_TABLE_EXPORT', $g->getArray(), ['table' => $this->table]));

                $fields = ['id' => '"id"'];
                foreach ($this->table->getFields() as $field) {
                    if ('value' == $field->getType() && 'none' != $field->getDatabaseFieldType()) {
                        $fields[$field->getName()] = '"' . $field->getName() . '"';
                    }
                }

                $exportDataset = [];
                foreach ($dataset as $data) {
                    $exportData = [];
                    foreach ($fields as $fieldName => $fV) {
                        $exportData[$fieldName] = '"' . str_replace(['"', "\n", "\r"], ['""', '', ''], $data[$fieldName]) . '"';
                    }
                    $exportDataset[] = implode(';', $exportData);
                }

                $fileContent = pack('CCC', 0xef, 0xbb, 0xbf);
                $fileContent .= implode(';', $fields);
                $fileContent .= "\n".implode("\n", $exportDataset);

                $fileName = 'export_data_' . date('YmdHis') . '.csv';
                header('Content-Disposition: attachment; filename="' . $fileName . '"; charset=utf-8');
                rex_response::sendContent($fileContent, 'application/octetstream');

                exit;
            }

            // -------------- form
            if (
                    ('add' == $func && $this->hasDataPageFunction('add')) ||
                    ('edit' == $func && $data_id) ||
                    ('copy' == $func && $data_id && $this->table->isDatasetCopyAllowed()) ||
                    ('collection_edit' == $func && $this->table->isMassEditAllowed())
            ) {
                $back = rex_view::info('<a href="index.php?' . http_build_query(array_merge($rex_link_vars)) . '"><b>&laquo; ' . rex_i18n::msg('yform_back_to_overview') . '</b></a>');

                if ('collection_edit' === $func) {
                    $query = $this->table->query();
                    $where = $this->getDataListQueryWhere(array_merge($rex_yform_filter, $rex_yform_set), $searchObject, $this->table);
                    if ($where) {
                        $query->whereRaw($where);
                    }
                    $data = $query->find();

                    $yform = $data->getForm();
                } else {
                    /** @var rex_yform_manager_dataset $data */
                    $data = 'add' == $func ? $this->table->createDataset() : $this->table->getRawDataset($data_id);

                    if ('copy' == $func) {
                        $dataOriginal = $this->table->getRawDataset($data_id);
                        $data = $this->table->createDataset();
                        foreach ($dataOriginal->getData() as $key => $value) {
                            if ('id' == $key) {
                                continue;
                            }
                            $data->setValue($key, $value);
                        }
                    }
                    $yform = $data->getForm();
                    $yform->setObjectparams('form_name', 'data_edit-'.$this->table->getTableName());
                }

                $yform->setHiddenFields($this->getLinkVars());
                $yform->setHiddenFields($rex_yform_list);
                $yform->setHiddenFields(['rex_yform_filter' => $rex_yform_filter]);
                $yform->setHiddenFields(['rex_yform_set' => $rex_yform_set]);
                $yform->setHiddenFields(['rex_yform_manager_opener' => $rex_yform_manager_opener]);
                $yform->setHiddenFields(['rex_yform_manager_popup' => $rex_yform_manager_popup]);

                if ('' != rex_request('rex_yform_show_formularblock', 'string')) {
                    // Optional .. kann auch geloescht werden. Dient nur zu Hilfe beim Aufbau
                    // von yform-Formularen über php
                    // Textblock gibt den formalarblock als text aus, um diesen in das yform modul einsetzen zu können.
                    //  rex_yform_show_formularblock=1
                    $text_block = '';
                    foreach ($this->table->getFields() as $field) {
                        $class = 'rex_yform_'.$field->getType().'_'.$field->getTypeName();

                        /** @var rex_yform_base_abstract $cl */
                        $cl = new $class();
                        $definitions = $cl->getDefinitions();

                        $values = [];
                        $i = 1;
                        foreach ($definitions['values'] as $key => $_) {
                            $key = $this->getFieldName($key, $field->getType());
                            if (isset($field[$key])) {
                                $values[] = $field[$key];
                            } elseif (isset($field['f' . $i])) {
                                $values[] = $field['f' . $i];
                            } else {
                                $values[] = '';
                            }
                            ++$i;
                        }

                        if ('value' == $field->getType()) {
                            $text_block .= "\n" . '$yform->setValueField("' . $field->getTypeName() . '",array("' . implode('","', $values) . '"));';
                        } elseif ('validate' == $field->getType()) {
                            $text_block .= "\n" . '$yform->setValidateField("' . $field->getTypeName() . '",array("' . implode('","', $values) . '"));';
                        } elseif ('action' == $field->getType()) {
                            $text_block .= "\n" . '$yform->setActionField("' . $field->getTypeName() . '",array("' . implode('","', $values) . '"));';
                        }
                        // $text_block .= "\n".$field["type_name"].'|'.implode("|",$values);
                    }
                    echo '<pre>' . $text_block . '</pre>';
                }

                $yform->setObjectparams('fixdata', $rex_yform_set);

                $yform_clone = clone $yform;

                if ('edit' == $func && $data_id) {
                    $yform->setHiddenField('data_id', $data_id);
                    $yform->setObjectparams('getdata', true);
                    $yform->setValueField('submit', ['name' => 'submit', 'labels' => rex_i18n::msg('yform_save').','.rex_i18n::msg('yform_save_apply'), 'values' => '1,2', 'no_db' => true, 'css_classes' => 'btn-save,btn-apply']);
                } elseif ('copy' == $func && $data_id) {
                    $yform->setObjectparams('copied_from', $data_id);
                    $yform->setObjectparams('getdata', true);
                    $yform->setValueField('submit', ['name' => 'submit', 'labels' => rex_i18n::msg('yform_add').','.rex_i18n::msg('yform_add_apply'), 'values' => '1,2', 'no_db' => true, 'css_classes' => 'btn-save,btn-apply']);
                    $func = 'add';
                } elseif ('add' == $func) {
                    $yform->setValueField('submit', ['name' => 'submit', 'labels' => rex_i18n::msg('yform_add').','.rex_i18n::msg('yform_add_apply'), 'values' => '1,2', 'no_db' => true, 'css_classes' => 'btn-save,btn-apply']);
                } elseif ('collection_edit' === $func) {
                    $yform->setValueField('submit', ['name' => 'submit', 'labels' => rex_i18n::msg('yform_save').','.rex_i18n::msg('yform_save_apply'), 'values' => '1,2', 'no_db' => true, 'css_classes' => 'btn-save,btn-apply']);
                }

                $yform->setHiddenField('func', $func); // damit es neu im clone gesetzt werden kann

                $sql_db = rex_sql::factory();
                $sql_db->beginTransaction();

                $transactionErrorMessage = null;

                try {
                    $form = $data->executeForm($yform, static function (rex_yform $yform) {
                        /** @var rex_yform_value_abstract $valueObject */
                        foreach ($yform->objparams['values'] as $valueObject) {
                            if ('submit' == $valueObject->getName()) {
                                if (2 == $valueObject->getValue()) { // apply
                                    $yform->setObjectparams('form_showformafterupdate', 1);
                                    $yform->executeFields();
                                }
                            }
                        }
                    });

                    $sql_db->commit();
                } catch (\Throwable $e) {
                    $sql_db->rollBack();
                    $transactionErrorMessage = $e->getMessage();

                    if ($transactionErrorMessage) {
                        echo rex_view::error(rex_i18n::msg('yform_editdata_collection_error_abort', $transactionErrorMessage));
                    }
                }

                if ($yform->objparams['actions_executed']) {
                    if ('edit' == $func) {
                        echo rex_view::info(rex_i18n::msg('yform_thankyouforupdate'));
                    } elseif ('add' == $func) {
                        echo rex_view::info(rex_i18n::msg('yform_thankyouforentry'));

                        $submit_type = 1; // normal, 2=apply
                        foreach ($yform->objparams['values'] as $valueObject) {
                            /** @var rex_yform_value_abstract $valueObject */
                            if ('submit' == $valueObject->getName()) {
                                if (2 == $valueObject->getValue()) { // apply
                                    $submit_type = 2;
                                }
                            }
                        }

                        if (2 == $submit_type) {
                            $data_id = $yform->objparams['main_id'];
                            $func = 'edit';
                            $yform = $yform_clone;
                            $yform->setFieldValue('send', [], '');
                            $yform->setHiddenField('func', $func);
                            $yform->setHiddenField('data_id', $data_id);
                            $yform->setObjectparams('main_id', $data_id);
                            $yform->setObjectparams('main_where', "id=$data_id");
                            $yform->setObjectparams('getdata', true);
                            $yform->setObjectparams('send', false);
                            $yform->setValueField('submit', ['name' => 'submit', 'labels' => rex_i18n::msg('yform_save').','.rex_i18n::msg('yform_save_apply'), 'values' => '1,2', 'no_db' => true, 'css_classes' => 'btn-save,btn-apply']);
                            $form = $yform->getForm();
                        }
                    }
                }

                if ($yform->objparams['form_show'] || ($yform->objparams['form_showformafterupdate'])) {
                    if ('collection_edit' === $func) {
                        $title = rex_i18n::msg('yform_editdata_collection', $data->count());
                    } elseif ('add' == $func) {
                        $title = rex_i18n::msg('yform_adddata');
                    } else {
                        $title = rex_i18n::rawMsg('yform_editdata', $data_id);
                    }

                    $fragment = new rex_fragment();
                    $fragment->setVar('class', 'edit', false);
                    $fragment->setVar('title', $title);
                    $fragment->setVar('body', $form, false);
                    // $fragment->setVar('buttons', $buttons, false);
                    $form = $fragment->parse('core/page/section.php');

                    if ($this->table->isSearchable() && $this->hasDataPageFunction('search')) {
                        $fragment = new rex_fragment();
                        $fragment->setVar('content', [$searchform, $form], false);
                        $fragment->setVar('classes', ['col-sm-3 col-md-3 col-lg-2', 'col-sm-9 col-md-9 col-lg-10'], false);
                        echo $fragment->parse('core/page/grid.php');
                    } else {
                        echo $form;
                    }

                    echo rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_FORM', '', ['form' => $form, 'func' => $func, 'this' => $this, 'table' => $this->table]));

                    echo $back;

                    $show_list = false;
                }
            }

            if ($show_list) {
                $sql = $this->getDataListQuery($rex_yform_filter, $searchObject, $this->table);

                /** @var rex_list $list */
                $list = rex_list::factory($sql, $this->table->getListAmount());
                $list->addTableAttribute('class', 'table-striped table-hover yform-table-' . rex_string::normalize($this->table->getTableName()));

                $rex_yform_list[$list->getPager()->getCursorName()] = rex_request($list->getPager()->getCursorName(), 'int', 0);

                $tdIcon = '<i class="rex-icon rex-icon-table"></i>';
                $thIcon = '_';
                if ($this->hasDataPageFunction('add')) {
                    $thIcon = '<a href="index.php?' . http_build_query(array_merge(['func' => 'add'], $rex_link_vars)) . '"' . rex::getAccesskey(rex_i18n::msg('add'), 'add') . '><i class="rex-icon rex-icon-add"></i></a>';
                }
                $list->addColumn($thIcon, $tdIcon, 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon" data-title="' . rex_i18n::msg('id') . '">###VALUE###</td>']);
                $list->setColumnParams($thIcon, array_merge(['data_id' => '###id###', 'func' => 'edit'], $rex_yform_list));

                $list->setColumnLabel('id', rex_i18n::msg('yform_id'));
                $list->setColumnSortable('id');

                $link_list_params = array_merge(
                    $this->getLinkVars(),
                    ['table_name' => $this->table->getTablename()],
                    ['rex_yform_filter' => $rex_yform_filter],
                    ['rex_yform_set' => $rex_yform_set],
                    ['rex_yform_manager_opener' => $rex_yform_manager_opener],
                    ['rex_yform_manager_popup' => $rex_yform_manager_popup]
                );

                foreach ($link_list_params as $paramKey => $paramValue) {
                    if (is_array($paramValue)) {
                        foreach ($paramValue as $paramKey2 => $paramValue2) {
                            if (is_array($paramValue2)) {
                                throw new \Exception('multi dimensional arrays are not supported!');
                            }

                            $list->addParam($paramKey.'['.$paramKey2.']', $paramValue2);
                        }
                    } else {
                        $list->addParam($paramKey, $paramValue);
                    }
                }

                foreach ($this->table->getFields() as $field) {
                    if (!$field->isHiddenInList() && $field->getTypeName()) {
                        if (method_exists('rex_yform_value_' . $field->getTypeName(), 'getListValue')) {
                            $list->setColumnFormat(
                            $field->getName(),
                            'custom',
                                ['rex_yform_value_' . $field->getTypeName(), 'getListValue'],
                                ['field' => $field->toArray(), 'fields' => $this->table->getFields()]
                            );
                        }
                    }

                    if ('value' == $field->getType()) {
                        if ($field->isHiddenInList()) {
                            $list->removeColumn($field->getName());
                        } else {
                            $list->setColumnSortable($field->getName());
                            $list->setColumnLabel($field->getName(), $field->getLabel());
                        }
                    }
                }

                if (isset($rex_yform_manager_opener['id'])) {
                    $list->addColumn(rex_i18n::msg('yform_data_select'), '');
                    $list->setColumnFormat(
                    rex_i18n::msg('yform_data_select'),
                    'custom',
                    static function ($params) {
                        $value = '';

                        $tablefield = explode('.', $params['params']['opener_field']);
                        if (1 == count($tablefield)) {
                            if (isset($params['list']->getParams()['table_name'])) {
                                $target_table = $params['list']->getParams()['table_name'];
                                $target_field = $tablefield[0];
                                $values = rex_yform_value_be_manager_relation::getListValues($target_table, $target_field);
                                $value = $values[$params['list']->getValue('id')];
                            }
                        } else {
                            [$table_name, $field_name] = explode('.', $params['params']['opener_field']);
                            $table = rex_yform_manager_table::get($table_name);
                            if ($table) {
                                $fields = $table->getValueFields(['name' => $field_name]);
                                if (isset($fields[$field_name])) {
                                    $target_table = $fields[$field_name]->getElement('table');
                                    $target_field = $fields[$field_name]->getElement('field');

                                    $values = rex_yform_value_be_manager_relation::getListValues($target_table, $target_field);
                                    $value = $values[$params['list']->getValue('id')];
                                }
                            }
                        }
                        return '<a href="javascript:setYFormDataset(' . $params['params']['opener_id'] . ',###id###,\''.rex_escape($value, 'js').' [id=###id###]\',' . $params['params']['opener_multiple'] . ')">'.rex_i18n::msg('yform_data_select').'</a>';
                    },
                    [
                        'opener_id' => $rex_yform_manager_opener['id'],
                        'opener_field' => $rex_yform_manager_opener['field'],
                        'opener_multiple' => $rex_yform_manager_opener['multiple'],
                    ]
                    );
                } else {

                    // to take care, that db field are not in conflict with th names, ' ' have been added to identifier

                    $list->addColumn(rex_i18n::msg('yform_function').' ', '<i class="rex-icon rex-icon-edit"></i> ' . rex_i18n::msg('yform_edit'));
                    $list->setColumnParams(rex_i18n::msg('yform_function').' ', array_merge(['data_id' => '###id###', 'func' => 'edit'], $rex_yform_list));

                    $colspan = 1;

                    if ($this->table->isDatasetCopyAllowed()) {
                        ++$colspan;

                        $list->addColumn(rex_i18n::msg('yform_copy'), '<i class="rex-icon rex-icon-duplicate"></i> ' . rex_i18n::msg('yform_copy'));
                        $list->setColumnLayout(rex_i18n::msg('yform_copy'), ['', '<td class="rex-table-action">###VALUE###</td>']);
                        $list->setColumnParams(rex_i18n::msg('yform_copy'), array_merge(['data_id' => '###id###', 'func' => 'copy'], $rex_yform_list));
                    }

                    if ($this->hasDataPageFunction('delete')) {
                        ++$colspan;

                        $list->addColumn(rex_i18n::msg('yform_delete').' ', '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('yform_delete'));
                        $list->setColumnLayout(rex_i18n::msg('yform_delete').' ', ['', '<td class="rex-table-action">###VALUE###</td>']);
                        $list->setColumnParams(rex_i18n::msg('yform_delete').' ', array_merge(['data_id' => '###id###', 'func' => 'delete'], $rex_yform_list));
                        $list->addLinkAttribute(rex_i18n::msg('yform_delete').' ', 'onclick', 'return confirm(\' id=###id### ' . rex_i18n::msg('yform_delete') . ' ?\')');
                    }

                    if (!$popup && $this->table->hasHistory()) {
                        ++$colspan;

                        $list->addColumn(rex_i18n::msg('yform_history').' ', '<i class="rex-icon fa-history"></i> ' . rex_i18n::msg('yform_history'));
                        $list->setColumnLayout(rex_i18n::msg('yform_history').' ', ['', '<td class="rex-table-action">###VALUE###</td>']);
                        $list->setColumnParams(rex_i18n::msg('yform_history').' ', ['func' => 'history', 'dataset_id' => '###id###', 'filter_dataset' => 1]);
                    }

                    $list->setColumnLayout(rex_i18n::msg('yform_function').' ', ['<th class="rex-table-action" colspan="'.$colspan.'">###VALUE###</th>', '<td class="rex-table-action">###VALUE###</td>']);

                }

                $list = rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_LIST', $list, ['table' => $this->table]));

                if ($rex_yform_filter) {
                    $filter = [];
                    $getFilter = static function (rex_yform_manager_field $field, $value) {
                        if ('be_manager_relation' == $field->getTypeName()) {
                            $listValues = rex_yform_value_be_manager_relation::getListValues($field->getElement('table'), $field->getElement('field'), ['id' => $value]);
                            if (isset($listValues[$value])) {
                                $value = $listValues[$value];
                            }
                        }
                        return '<b>' . rex_i18n::translate($field->getLabel()) .':</b> ' . $value;
                    };
                    foreach ($rex_yform_filter as $key => $value) {
                        if (is_array($value)) {
                            $relTable = rex_yform_manager_table::get($this->table->getValueField($key)->getElement('table'));
                            foreach ($value as $k => $v) {
                                $filter[] = $getFilter($relTable->getValueField($k), $v);
                            }
                        } else {
                            $filter[] = $getFilter($this->table->getValueField($key), $value);
                        }
                    }
                    echo rex_view::info(implode('<br>', $filter));
                }
                $panel_options = '';
                $data_links = [];

                if (count($data_links) > 0) {
                    $fragment = new rex_fragment();
                    $fragment->setVar('size', 'xs', false);
                    $fragment->setVar('buttons', $data_links, false);
                    $panel_options .= '<small class="rex-panel-option-title">' . rex_i18n::msg('yform_data') . '</small> ' . $fragment->parse('core/buttons/button_group.php');
                }

                // INFO LINK
                $dataset_links = [];

                if (!$popup && $this->table->isMassEditAllowed()) {
                    $item = [];
                    $item['label'] = rex_i18n::msg('yform_edit');
                    $item['url'] = 'index.php?' . http_build_query(array_merge(['func' => 'collection_edit'], $rex_link_vars));
                    $item['attributes']['class'][] = 'btn-default';
                    $dataset_links[] = $item;
                }
                if (!$popup && (1 == $this->table->isExportable() && $this->hasDataPageFunction('export'))) {
                    $item = [];
                    $item['label'] = rex_i18n::msg('yform_export');
                    $item['url'] = 'index.php?' . http_build_query(array_merge(['func' => 'dataset_export'], $rex_link_vars));
                    $item['attributes']['class'][] = 'btn-default';
                    $dataset_links[] = $item;
                }
                if ($this->table->isMassDeletionAllowed() && $this->hasDataPageFunction('truncate_table')) {
                    $item = [];
                    $item['label'] = rex_i18n::msg('yform_delete');
                    $item['url'] = 'index.php?' . http_build_query(array_merge(['func' => 'dataset_delete'], $rex_link_vars));
                    $item['attributes']['class'][] = 'btn-delete';
                    $item['attributes']['id'] = 'dataset-delete';
                    $item['attributes']['onclick'][] = 'return confirm(\'' . rex_i18n::msg('yform_dataset_delete_confirm') . '\');';
                    $dataset_links[] = $item;
                }
                if (count($dataset_links) > 0) {
                    $fragment = new rex_fragment();
                    $fragment->setVar('size', 'xs', false);
                    $fragment->setVar('buttons', $dataset_links, false);
                    $panel_options .= '<small class="rex-panel-option-title">' . rex_i18n::msg('yform_dataset') . '</small> ' . $fragment->parse('core/buttons/button_group.php');
                }

                $table_links = [];
                if (!$popup && $this->table->isImportable() && $this->hasDataPageFunction('import')) {
                    $item = [];
                    $item['label'] = rex_i18n::msg('yform_import');
                    $item['url'] = 'index.php?' . http_build_query(array_merge(['func' => 'import'], $rex_link_vars));
                    $item['attributes']['class'][] = 'btn-default';
                    $table_links[] = $item;
                }
                if (!$popup && rex::getUser()->isAdmin()) {
                    $item = [];
                    $item['label'] = rex_i18n::msg('yform_edit');
                    $item['url'] = 'index.php?page=yform/manager&table_id=' . $this->table->getId() . '&func=edit';
                    $item['attributes']['class'][] = 'btn-default';
                    $table_links[] = $item;
                }
                if (!$popup && $this->table->isMassDeletionAllowed() && $this->hasDataPageFunction('truncate_table')) {
                    $item = [];
                    $item['label'] = rex_i18n::msg('yform_truncate_table');
                    $item['url'] = 'index.php?' . http_build_query(array_merge(['func' => 'truncate_table'], $rex_link_vars));
                    $item['attributes']['class'][] = 'btn-delete';
                    $item['attributes']['id'] = 'truncate-table';
                    $item['attributes']['onclick'][] = 'return confirm(\'' . rex_i18n::msg('yform_truncate_table_confirm') . '\');';
                    $table_links[] = $item;
                }
                if (!$popup && $this->table->hasHistory()) {
                    $item = [];
                    $item['label'] = rex_i18n::msg('yform_history');
                    $item['url'] = 'index.php?' . http_build_query(array_merge(['func' => 'history'], $rex_link_vars));
                    $item['attributes']['class'][] = 'btn-default';
                    $table_links[] = $item;
                }
                $field_links = [];
                if (!$popup && rex::getUser()->isAdmin()) {
                    $item = [];
                    $item['label'] = rex_i18n::msg('yform_edit');
                    $item['url'] = 'index.php?page=yform/manager/table_field&table_name=' . $this->table->getTableName();
                    $item['attributes']['class'][] = 'btn-default';
                    $field_links[] = $item;
                }
                ['table_links' => $table_links, 'field_links' => $field_links] = rex_extension::registerPoint(
                    new rex_extension_point(
                        'YFORM_DATA_LIST_LINKS',
                        ['table_links' => $table_links, 'field_links' => $field_links],
                        ['table' => $this->table, 'popup' => $popup]
                    )
                );
                if (count($table_links) > 0) {
                    $fragment = new rex_fragment();
                    $fragment->setVar('size', 'xs', false);
                    $fragment->setVar('buttons', $table_links, false);
                    $panel_options .= '<small class="rex-panel-option-title">' . rex_i18n::msg('yform_table') . '</small> ' . $fragment->parse('core/buttons/button_group.php');
                }
                if (count($field_links) > 0) {
                    $fragment = new rex_fragment();
                    $fragment->setVar('size', 'xs', false);
                    $fragment->setVar('buttons', $field_links, false);
                    $panel_options .= '<small class="rex-panel-option-title">' . rex_i18n::msg('yform_manager_fields') . '</small> ' . $fragment->parse('core/buttons/button_group.php');
                }

                $content = $list->get();
                $fragment = new rex_fragment();
                $fragment->setVar('title', rex_i18n::msg('yform_tabledata_overview'));
                $fragment->setVar('options', $panel_options, false);
                $fragment->setVar('content', $content, false);
                $content = $fragment->parse('core/page/section.php');

                $grid = [];
                $grid['content'] = [];
                if ($this->table->isSearchable() && $this->hasDataPageFunction('search')) {
                    $grid['content']['searchform'] = $searchform;
                }
                $grid['content']['searchlist'] = $content;

                $grid['classes'] = [];
                $grid['classes']['searchform'] = 'col-sm-3 col-md-3 col-lg-2';
                $grid['classes']['searchlist'] = 'col-sm-9 col-md-9 col-lg-10';

                $grid['fragment'] = 'yform/manager/page/grid.php';

                $grid = rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_LIST_GRID', $grid, ['table' => $this->table]));

                $fragment = new rex_fragment();
                $fragment->setVar('content', $grid['content'], false);
                $fragment->setVar('classes', $grid['classes'], false);
                echo $fragment->parse($grid['fragment']);
            }
        } // end: $show_editpage
    }

    public function getDataListQueryFilterWhere($rex_filter, rex_yform_manager_table $table)
    {
        $sql_o = rex_sql::factory();
        $sql = [];
        if (count($rex_filter) > 0) {
            $sql_filter = '';
            foreach ($rex_filter as $k => $v) {
                if ('' != $sql_filter) {
                    $sql_filter .= ' AND ';
                }
                if (!is_array($v)) {
                    $sql_filter .= $sql_o->escapeIdentifier($k) . ' = ' . $sql_o->escape($v);
                } elseif ($relation = $table->getRelation($k)) {
                    foreach ($v as $k2 => $v2) {
                        $sql_filter .= '(SELECT ' . $sql_o->escapeIdentifier($k2) . ' FROM ' . $sql_o->escapeIdentifier($relation['table']) . ' WHERE id = t0.' . $sql_o->escapeIdentifier($k) . ') = ' . $sql_o->escape($v2);
                    }
                }
            }
            $sql[] = $sql_filter;
        }
        return $sql;
    }

    public function getDataListQueryWhere($rex_filter, $searchObject, rex_yform_manager_table $table)
    {
        $sql = $this->getDataListQueryFilterWhere($rex_filter, $table);

        $searchFilter = $searchObject->getQueryFilterArray();
        if (count($searchFilter) > 0) {
            $sql[] = '( ' . implode(' AND ', $searchFilter) . ' )';
        }

        if (count($sql) > 0) {
            $sql = implode(' and ', $sql);
        } else {
            $sql = '';
        }

        return $sql;
    }

    public function getDataListQuery($rex_filter, $searchObject, rex_yform_manager_table $table)
    {
        $sql = 'select * from ' . $table->getTablename() . ' t0';
        $sql_felder = rex_sql::factory();
        $sql_felder->setQuery('SELECT * FROM ' . rex_yform_manager_field::table() . ' WHERE table_name="' . $table->getTablename() . '" AND type_id="value" ORDER BY prio');

        $max = $sql_felder->getRows();
        if ($max > 0) {
            $existingFields = array_map(static function ($column) {
                return $column['name'];
            }, rex_sql::showColumns($table->getTablename()));

            $fields = [];
            for ($i = 0; $i < $sql_felder->getRows(); ++$i) {
                if (in_array($sql_felder->getValue('name'), $existingFields)) {
                    $fields[] = '`' . $sql_felder->getValue('name') . '`';
                } else {
                    $fields[] = 'NULL AS `' . $sql_felder->getValue('name') . '`';
                }
                $sql_felder->next();
            }
            $sql = 'select `id`,' . implode(',', $fields) . ' from `' . $table->getTablename() . '` t0';
        }

        $where = $this->getDataListQueryWhere($rex_filter, $searchObject, $table);
        if ($where) {
            $sql .= ' where '.$where;
        }
        if ('' != $table->getSortFieldName()) {
            $sql .= ' ORDER BY `' . $table->getSortFieldName() . '` ' . $table->getSortOrderName();
        }
        $sql = rex_extension::registerPoint(new rex_extension_point('YFORM_DATA_LIST_SQL', $sql, ['table' => $table]));

        return $sql;
    }

    // ---------------------------------- table functions
    public function setTable(rex_yform_manager_table $table)
    {
        $this->table = $table;
    }

    // ---------------------------------- field functions
    public function getFieldPage()
    {
        // ********************************************* FIELD ADD/EDIT/LIST

        $func = rex_request('func', 'string', 'list');
        $type_id = rex_request('type_id', 'string');
        $type_name = rex_request('type_name', 'string');
        $field_id = rex_request('field_id', 'int');

        $link_vars = '';
        foreach ($this->getLinkVars() as $k => $v) {
            $link_vars .= '&' . urlencode($k) . '=' . urlencode($v);
        }

        $TYPE = ['value' => rex_i18n::msg('yform_values'), 'validate' => rex_i18n::msg('yform_validates'), 'action' => rex_i18n::msg('yform_action')];

        $table = $this->table;

        $table_info = '<b>' . $table->getNameLocalized() . ' [<a href="index.php?page=yform/manager/table_edit&start=0&table_id='.$table->getId().'&func=edit">' . $table->getTableName() . '</a>]</b> ';
        echo rex_view::info($table_info);

        $_csrf_key = 'table_field-'.$table->getTableName();

        if ('' != $func && in_array($func, ['delete', 'updatetablewithdelete', 'updatetable'])) {
            if (!rex_csrf_token::factory($_csrf_key)->isValid()) {
                echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
                $func = 'list';
            }
        }

        $mfields = $table->getMissingFields();
        $type_real_field = rex_request('type_real_field', 'string');
        if ('' != $type_real_field && !array_key_exists($type_real_field, $mfields)) {
            $type_real_field = '';
        }

        if ('' != $type_real_field) {
            $panel = '';
            $panel .= '<dl class="dl-horizontal text-left">';

            $rfields = $this->table->getColumns();
            foreach ($rfields[$type_real_field] as $k => $v) {
                $panel .= '<dt>' . ucfirst($k) . ':</dt><dd>' . $v . '</dd>';
            }
            $panel .= '</dl>';

            $fragment = new rex_fragment();
            $fragment->setVar('class', 'info');
            $fragment->setVar('title', 'Folgendes Feld wird verwendet: ' . $type_real_field);
            $fragment->setVar('body', $panel, false);
            echo $fragment->parse('core/page/section.php');
        }

        $types = rex_yform::getTypeArray();
        if ('choosenadd' == $func) {
            $link = 'index.php?' . $link_vars . '&table_name=' . $table->getTableName() . '&func=add&';

            $content = [];
            $panels = [];

            if (!$table->hasId()) {
                $content[] = rex_i18n::msg('yform_id_is_missing').''.rex_i18n::msg('yform_id_missing_info');
            } else {
                if ('' == $type_real_field && count($mfields) > 0) {
                    $tmp = '';
                    $d = 0;
                    foreach ($mfields as $k => $v) {
                        ++$d;
                        $l = 'index.php?' . $link_vars . '&table_name=' . $table->getTableName() . '&func=choosenadd&type_real_field=' . $k . '&type_layout=t';
                        $tmp .= '<a class="btn btn-default" href="' . $l . '">' . $k . '</a> ';
                    }

                    $fragment = new rex_fragment();
                    $fragment->setVar('class', 'info');
                    $fragment->setVar('title', 'Es gibt noch Felder in der Tabelle welche nicht zugewiesen sind.');
                    $fragment->setVar('body', $tmp, false);
                    echo $fragment->parse('core/page/section.php');
                }

                $tmp = '';
                if (isset($types['value'])) {
                    ksort($types['value']);
                    $tmp_famous = '';
                    $tmp_deprecated = '';
                    $tmp = '';
                    foreach ($types['value'] as $k => $v) {
                        if (isset($v['manager']) && !$v['manager']) {
                        } elseif (isset($v['deprecated']) && $v['deprecated']) {
                            $tmp_deprecated .= '<tr class="yform-classes-deprecated"><th data-title="Value"><a class="btn btn-default btn-block" href="' . $link . 'type_id=value&type_name=' . $k . '&type_real_field=' . $type_real_field . '"><code>' . $k . '</code></a></th><td class="vertical-middle">' . $v['deprecated'] .'<br />' . $v['description'] . '</td></tr>';
                        } elseif (isset($v['famous']) && $v['famous']) {
                            $tmp_famous .= '<tr class="yform-classes-famous"><th data-title="Value"><a class="btn btn-default btn-block" href="' . $link . 'type_id=value&type_name=' . $k . '&type_real_field=' . $type_real_field . '"><code>' . $k . '</code></a></th><td class="vertical-middle">' . $v['description'] . '</td></tr>';
                        } else {
                            $tmp .= '<tr><th data-title="Value"><a class="btn btn-default btn-block" href="' . $link . 'type_id=value&type_name=' . $k . '&type_real_field=' . $type_real_field . '"><code>' . $k . '</code></a></th><td class="vertical-middle">' . $v['description'] . '</td></tr>';
                        }
                    }
                    $tmp = '<table class="table table-hover yform-table-help">'.$tmp_famous.$tmp.$tmp_deprecated.'</table>';
                }
                $fragment = new rex_fragment();
                $fragment->setVar('title', $TYPE['value']);
                $fragment->setVar('content', $tmp, false);
                $panels[] = $fragment->parse('core/page/section.php');

                $tmp = '';
                if (isset($types['validate'])) {
                    ksort($types['validate']);
                    $tmp_famous = '';
                    $tmp = '';
                    foreach ($types['validate'] as $k => $v) {
                        if (isset($v['famous']) && $v['famous']) {
                            $tmp_famous .= '<tr class="yform-classes-famous"><th data-title="Validate"><a class="btn btn-default btn-block" href="' . $link . 'type_id=validate&type_name=' . $k . '"><code>' . $k . '</code></a></th><td class="vertical-middle">' . $v['description'] . '</td></tr>';
                        } else {
                            $tmp .= '<tr><th data-title="Validate"><a class="btn btn-default btn-block" href="' . $link . 'type_id=validate&type_name=' . $k . '"><code>' . $k . '</code></a></th><td class="vertical-middle">' . $v['description'] . '</td></tr>';
                        }
                    }
                    $tmp = '<table class="table table-hover yform-table-help">'.$tmp_famous.$tmp.'</table>';
                }

                $fragment = new rex_fragment();
                $fragment->setVar('title', $TYPE['validate']);
                $fragment->setVar('content', $tmp, false);
                $panels[] = $fragment->parse('core/page/section.php');
            }

            $fragment = new rex_fragment();
            $fragment->setVar('title', rex_i18n::msg('yform_choosenadd'));
            $fragment->setVar('body', rex_i18n::msg('yform_choosenadd_description'), false);
            echo $fragment->parse('core/page/section.php');

            $fragment = new rex_fragment();
            $fragment->setVar('content', $panels, false);
            echo $fragment->parse('core/page/grid.php');

            $table_echo = '<a class="btn btn-default" href="index.php?' . $link_vars . '&amp;table_name=' . $table->getTableName() . '">' . rex_i18n::msg('yform_back_to_overview') . '</a>';

            $fragment = new rex_fragment();
            $fragment->setVar('footer', $table_echo, false);
            echo $fragment->parse('core/page/section.php');
        }

        if (('add' == $func || 'edit' == $func) && isset($types[$type_id][$type_name])) {
            $field = new rex_yform_manager_field(['type_id' => $type_id, 'type_name' => $type_name]);

            $yform = new rex_yform();
            $yform->setDebug(false);

            foreach ($this->getLinkVars() as $k => $v) {
                $yform->setHiddenField($k, $v);
            }

            $yform->setObjectparams('form_name', $_csrf_key);

            $yform->setHiddenField('func', $func);
            $yform->setHiddenField('table_name', $table->getTableName());
            $yform->setHiddenField('type_real_field', $type_real_field);

            $yform->setHiddenField('list', rex_request('list', 'string'));
            $yform->setHiddenField('sort', rex_request('sort', 'string'));
            $yform->setHiddenField('sorttype', rex_request('sorttype', 'string'));
            $yform->setHiddenField('start', rex_request('start', 'string'));

            $yform->setValueField('hidden', ['table_name', $table->getTableName()]);
            $yform->setValueField('hidden', ['type_name', $type_name, 'REQUEST']);
            $yform->setValueField('hidden', ['type_id', $type_id, 'REQUEST']);

            $yform->setValueField('prio', ['prio', rex_i18n::msg('yform_values_defaults_prio'), ['type_id', 'type_name', 'name', 'label'], ['table_name']]);

            $selectFields = [];
            $i = 1;
            foreach ($types[$type_id][$type_name]['values'] as $k => $v) {
                $k_field = $this->getFieldName($k, $type_id);
                $selectFields['f' . $i] = $k_field;
                ++$i;

                switch ($v['type']) {
                    case 'name':
                        $v['notice'] = ($v['notice'] ?? '');
                        if ('edit' == $func) {
                            $yform->setValueField('showvalue', [$k_field, rex_i18n::msg('yform_values_defaults_name'), 'notice' => $v['notice']]);
                        } else {
                            if (!isset($v['value']) && '' != $type_real_field) {
                                $v['value'] = $type_real_field;
                            } elseif (!isset($v['value'])) {
                                $v['value'] = '';
                            }

                            $yform->setValueField('text', [$k_field, rex_i18n::msg('yform_values_defaults_name'), $v['value'], 'notice' => $v['notice']]);
                            $yform->setValidateField('empty', [$k_field, rex_i18n::msg('yform_validatenamenotempty')]);
                            $yform->setValidateField('preg_match', [$k_field, "/(([a-zA-Z])+([a-zA-Z0-9\_])*)/", rex_i18n::msg('yform_validatenamepregmatch')]);
                            $yform->setValidateField('customfunction', [$k_field, 'rex_yform_manager_checkField', ['table_name' => $table->getTableName()], rex_i18n::msg('yform_validatenamecheck')]);
                        }
                        break;

                    case 'no_db':
                        if (!isset($v['values'])) {
                            $v['values'] = [0, 1];
                        }
                        if (!isset($v['default']) || 1 != $v['default']) {
                            $v['default'] = 0;
                        }
                        $yform->setValueField('checkbox', ['name' => $k_field, 'label' => rex_i18n::msg('yform_donotsaveindb'), 'values' => 'no_db', 'default' => $v['default']]);
                        break;

                    case 'boolean':
                        if (!isset($v['values'])) {
                            $v['values'] = [0, 1];
                        }
                        if (!isset($v['default'])) {
                            $v['default'] = '';
                        }
                        $v['notice'] = ($v['notice'] ?? '');
                        $yform->setValueField('checkbox', ['name' => $k_field, 'label' => $v['label'], 'default' => $v['default'], 'notice' => $v['notice']]);
                        break;

                    case 'table':
                        $_tables = rex_yform_manager_table::getAll();
                        $_options = [];
                        if (isset($v['empty_option']) && $v['empty_option']) {
                            $_options[0] = '–=';
                        }
                        foreach ($_tables as $_table) {
                            $_options[$_table['table_name']] = str_replace('=', '-', rex_i18n::translate($_table['name']) . ' [' . $_table['table_name'] . ']') . '=' . $_table['table_name'];
                            $_options[$_table['table_name']] = str_replace(',', '.', $_options[$_table['table_name']]);
                        }
                        if (!isset($v['default'])) {
                            $v['default'] = '';
                        }
                        $yform->setValueField('choice', ['name' => $k_field, 'label' => $v['label'], 'choices' => implode(',', $_options), 'default' => $v['default']]);
                        break;

                    case 'select_name':
                        $_fields = [];
                        foreach ($table->getValueFields() as $_k => $_v) {
                            $_fields[] = $_k;
                        }
                        $v['notice'] = ($v['notice'] ?? '');
                        $yform->setValueField('choice', ['name' => $k_field, 'label' => $v['label'], 'choices' => implode(',', $_fields), 'notice' => $v['notice']]);
                        break;

                    case 'select_names':
                        $_fields = [];
                        foreach ($table->getValueFields() as $_k => $_v) {
                            $_fields[] = $_k;
                        }
                        $v['notice'] = ($v['notice'] ?? '');
                        $yform->setValueField('choice', ['name' => $k_field, 'label' => $v['label'], 'choices' => implode(',', $_fields), 'multiple' => true, 'notice' => $v['notice']]);
                        break;

                    case 'text':
                        // nur beim "Bezeichnungsfeld"
                        if ('label' == $k_field && '' != $type_real_field && !isset($v['value'])) {
                            $v['value'] = $type_real_field;
                        } elseif (!isset($v['value'])) {
                            $v['value'] = '';
                        }
                        $v['name'] = $k_field;
                        $yform->setValueField('text', $v);
                        break;

                    case 'textarea':
                    case 'choice':
                    default:
                        $v['name'] = $k_field;
                        $yform->setValueField($v['type'], $v);
                        break;
                }
            }

            if (isset($types[$type_id][$type_name]['validates']) && is_array($types[$type_id][$type_name]['validates'])) {
                foreach ($types[$type_id][$type_name]['validates'] as $k => $v) {
                    $yform->setValidateField(key($v), current($v));
                }
            }

            $yform->setActionField('showtext', ['', '<p>' . rex_i18n::msg('yform_thankyouforentry') . '</p>']);
            $yform->setObjectparams('main_table', rex_yform_manager_field::table());

            if ('edit' == $func) {
                $yform->setObjectparams('submit_btn_label', rex_i18n::msg('yform_field_update'));
                $yform->setHiddenField('field_id', $field_id);
                $yform->setActionField('manage_db', [rex_yform_manager_field::table(), "id=$field_id"]);
                $yform->setObjectparams('main_id', $field_id);
                $yform->setObjectparams('main_where', "id=$field_id");
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT * FROM ' . rex_yform_manager_field::table() . " WHERE id=$field_id");
                foreach ($selectFields as $alias => $s_field) {
                    if ($alias != $s_field) {
                        if ((!$sql->hasValue($s_field) || null === $sql->getValue($s_field) || '' === $sql->getValue($s_field)) && $sql->hasValue($alias)) {
                            $sql->setValue($s_field, $sql->getValue($alias));
                        }
                        $yform->setValueField('hidden', [$alias, '']);
                    }
                }
                $yform->setObjectparams('sql_object', $sql);
                $yform->setObjectparams('getdata', true);
            } elseif ('add' == $func) {
                $yform->setObjectparams('submit_btn_label', rex_i18n::msg('yform_field_add'));
                $yform->setActionField('manage_db', [rex_yform_manager_field::table()]);
            }

            if ('value' == $type_id) {
                $db_choices = $field->getDatabaseFieldTypes();
                $default = $field->getDatabaseFieldDefaultType();
                $yform->setValueField('choice', ['name' => 'db_type', 'label' => rex_i18n::msg('yform_field_db_type'), 'choices' => $db_choices, 'default' => $default]);

                if (!$field->isHiddenInListDisabled()) {
                    $yform->setValueField('checkbox', ['name' => 'list_hidden', 'label' => rex_i18n::msg('yform_hideinlist'), 'default' => '1']);
                }

                if (!$field->isSearchableDisabled()) {
                    $yform->setValueField('checkbox', ['name' => 'search', 'label' => rex_i18n::msg('yform_useassearchfieldalidatenamenotempty'), 'default' => '1']);
                }
            } elseif ('validate' == $type_id) {
                $yform->setValueField('hidden', ['list_hidden', 1]);
                $yform->setValueField('hidden', ['search', 0]);
            }

            $form = $yform->getForm();

            if ($yform->objparams['form_show']) {
                if ('add' == $func) {
                    $title = rex_i18n::msg('yform_addfield') . ' "' . $type_name. '"';
                } else {
                    $title = rex_i18n::msg('yform_editfield') . ' "' . $type_name .'"';
                }

                $fragment = new rex_fragment();
                $fragment->setVar('class', 'edit', false);
                $fragment->setVar('title', $title);
                $fragment->setVar('body', $form, false);
                // $fragment->setVar('buttons', $buttons, false);
                $form = $fragment->parse('core/page/section.php');

                echo $form;
                $table_echo = '<a class="btn btn-default" href="index.php?' . $link_vars . '&amp;table_name=' . $table->getTableName() . '">' . rex_i18n::msg('yform_back_to_overview') . '</a>';
                $fragment = new rex_fragment();
                $fragment->setVar('footer', $table_echo, false);
                echo $fragment->parse('core/page/section.php');

                $func = '';
            } else {
                if ('edit' == $func) {
                    rex_yform_manager_table_api::generateTableAndFields($table);
                    echo rex_view::success(rex_i18n::msg('yform_thankyouforupdate'));
                } elseif ('add' == $func) {
                    rex_yform_manager_table_api::generateTableAndFields($table);
                    echo rex_view::success(rex_i18n::msg('yform_thankyouforentry'));
                }
                $func = 'list';
            }
        }

        if ('delete' == $func) {
            if (!rex_csrf_token::factory($_csrf_key)->isValid()) {
                echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
            } else {
                $sf = rex_sql::factory();
                $sf->setDebug(self::$debug);
                $sf->setQuery('select * from ' . rex_yform_manager_field::table() . ' where table_name="' . $table->getTableName() . '" and id=' . $field_id);
                $sfa = $sf->getArray();
                if (1 == count($sfa)) {
                    $query = 'delete from ' . rex_yform_manager_field::table() . ' where table_name="' . $table->getTableName() . '" and id=' . $field_id;
                    $delsql = rex_sql::factory();
                    $delsql->setDebug(self::$debug);
                    $delsql->setQuery($query);
                    echo rex_view::success(rex_i18n::msg('yform_tablefielddeleted'));
                    rex_yform_manager_table_api::generateTableAndFields($table);
                } else {
                    echo rex_view::warning(rex_i18n::msg('yform_tablefieldnotfound'));
                }
                $func = 'list';
            }
        }

        // ********************************************* CREATE/UPDATE FIELDS
        if ('updatetable' == $func) {
            rex_yform_manager_table_api::generateTableAndFields($table);
            echo rex_view::info(rex_i18n::msg('yform_tablesupdated'));
            $func = 'list';
        }

        if ('updatetablewithdelete' == $func) {
            rex_yform_manager_table_api::generateTableAndFields($table, true);
            echo rex_view::info(rex_i18n::msg('yform_tablesupdated'));
            $func = 'list';
        }

        if ('show_form_notation' == $func) {
            $formbuilder_fields = $table->getFields();

            $notation_php = '';
            $notation_pipe = '';
            $notation_email = '';

            $notation_php_pre = [
                '$yform = new rex_yform();',
                '$yform->setObjectparams(\'form_name\', \'table-'.$table->getTableName().'\');',
                '$yform->setObjectparams(\'form_action\',rex_getUrl(\'REX_ARTICLE_ID\'));',
                '$yform->setObjectparams(\'form_ytemplate\', \'bootstrap\');',
                '$yform->setObjectparams(\'form_showformafterupdate\', 0);',
                '$yform->setObjectparams(\'real_field_names\', true);',
            ];

            $notation_php .= implode("\n", $notation_php_pre) . "\n";

            $notation_pipe_pre = [
                'objparams|form_name|table-'.$table->getTableName().'',
                'objparams|form_ytemplate|bootstrap',
                'objparams|form_showformafterupdate|0',
                'objparams|real_field_names|true',
            ];

            $notation_pipe .= implode("\n", $notation_pipe_pre) . "\n";

            foreach ($formbuilder_fields as $field) {
                $class = 'rex_yform_'.$field->getType().'_'.$field->getTypeName();

                $cl = new $class();
                $definitions = $cl->getDefinitions();

                $values = [];
                $i = 1;
                foreach ($definitions['values'] as $key => $_) {
                    $key = $this->getFieldName($key, $field['type_id']);
                    if (isset($field[$key])) {
                        $values[] = htmlspecialchars($field[$key]);
                    } elseif (isset($field['f' . $i])) {
                        $values[] = htmlspecialchars($field['f' . $i]);
                    } else {
                        $values[] = '';
                    }
                    ++$i;
                }
                $php_values = array_map(static function ($v) {
                    return str_replace("'", "\'", $v);
                }, $values);

                if ('value' == $field['type_id']) {
                    $notation_php .= "\n" . '$yform->setValueField(\'' . $field['type_name'] . '\', [\'' . rtrim(implode('\',\'', $php_values), '\',\'') . '\']);';
                    $notation_pipe .= "\n" . $field['type_name'] . '|' . rtrim(implode('|', $values), '|') . '|';
                    $notation_email .= "\n" . rex_i18n::translate($field['label']) . ': REX_YFORM_DATA[field="' . $field['name'] . '"]';
                } elseif ('validate' == $field['type_id']) {
                    $notation_php .= "\n" . '$yform->setValidateField(\'' . $field['type_name'] . '\', [\'' . rtrim(implode('\',\'', $php_values), '\',\'') . '\']);';
                    $notation_pipe .= "\n" . $field['type_id'] . '|' . $field['type_name'] . '|' . rtrim(implode('|', $values), '|') . '|';
                } elseif ('action' == $field['type_id']) {
                    $notation_php .= "\n" . '$yform->setActionField(\'' . $field['type_name'] . '\', [\'' . rtrim(implode('\',\'', $php_values), '\',\'') . '\']);';
                    $notation_pipe .= "\n" . $field['type_id'] . '|' . $field['type_name'] . '|' . rtrim(implode('|', $values), '|') . '|';
                }
            }

            $notation_php .= "\n\n"  . '$yform->setActionField(\'tpl2email\', [\'emailtemplate\', \'emaillabel\', \'email@domain.de\']);';
            $notation_php .= "\n".'echo $yform->getForm();';

            $notation_pipe .= "\n\n"  . 'action|tpl2email|emailtemplate|emaillabel|email@domain.de';

            $fragment = new rex_fragment();
            $fragment->setVar('title', 'PHP');
            $fragment->setVar('body', '<pre class="pre-scrollable">' . $notation_php . '</pre>', false);
            $content = $fragment->parse('core/page/section.php');
            echo $content;

            $fragment = new rex_fragment();
            $fragment->setVar('title', 'Pipe');
            $fragment->setVar('body', '<pre class="pre-scrollable">' . $notation_pipe . '</pre>', false);
            $content = $fragment->parse('core/page/section.php');
            echo $content;

            $fragment = new rex_fragment();
            $fragment->setVar('title', 'E-Mail');
            $fragment->setVar('body', '<pre class="pre-scrollable">' . $notation_email . '</pre>', false);
            $content = $fragment->parse('core/page/section.php');
            echo $content;

            $func = 'list';
        }

        if ('list' == $func) {
            $show_list = true;
            $show_list = rex_extension::registerPoint(new rex_extension_point('YFORM_MANAGER_TABLE_FIELD_FUNC', $show_list,
            [
                'table' => $table,
                'link_vars' => $this->getLinkVars(),
            ]
            )
            );

            if ($show_list) {
                function rex_yform_list_format($p, $value = '')
                {
                    if ('' != $value) {
                        $p['value'] = $value;
                    }
                    switch ($p['list']->getValue('type_id')) {
                        case 'validate':
                            $style = 'color:#aaa;'; // background-color:#cfd9d9;
                            break;
                        case 'action':
                            $style = 'background-color:#cfd9d9;';
                            break;
                        default:
                            $style = 'background-color:#eff9f9;';
                            break;
                    }

                    if ('label' == $p['field']) {
                        $p['value'] = rex_i18n::translate($p['value']);
                    }

                    return '<td style="' . $style . '">' . $p['value'] . '</td>';
                }

                function rex_yform_list_edit_format($p)
                {
                    return rex_yform_list_format($p, $p['list']->getColumnLink(rex_i18n::msg('yform_function'), '<i class="rex-icon rex-icon-edit"></i> ' . rex_i18n::msg('yform_edit')));
                }

                function rex_yform_list_delete_format($p)
                {
                    return rex_yform_list_format($p, $p['list']->getColumnLink(rex_i18n::msg('yform_delete'), '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('yform_delete')));
                }

                $context = new rex_context(
                $this->getLinkVars()
                );

                $fragment = new rex_fragment();
                $fragment->setVar('size', 'xs', false);
                $fragment->setVar('buttons', [
                    [
                        'label' => rex_i18n::msg('yform_data_view'),
                        'url' => 'index.php?page=yform/manager/data_edit&table_name=' . $table->getTableName(),
                        'attributes' => [
                            'class' => [
                                'btn-default',
                            ],
                        ],
                    ],
                ], false);
                $panel_options = '<small class="rex-panel-option-title">' . rex_i18n::msg('yform_datas') . '</small> ' . $fragment->parse('core/buttons/button_group.php');

                $fragment = new rex_fragment();
                $fragment->setVar('size', 'xs', false);
                $fragment->setVar('buttons', [
                    [
                        'label' => rex_i18n::msg('yform_edit'),
                        'url' => 'index.php?page=yform/manager/table_edit&func=edit&table_id='.$table->getId(),
                        'attributes' => [
                            'class' => [
                                'btn-default',
                            ],
                        ],
                    ],
                    [
                        'label' => rex_i18n::msg('yform_manager_show_form_notation'),
                        'url' => $context->getUrl(['table_name' => $table->getTableName(), 'func' => 'show_form_notation']),
                        'attributes' => [
                            'class' => [
                                'btn-default',
                            ],
                        ],
                    ],
                    [
                        'label' => rex_i18n::msg('yform_updatetable'),
                        'url' => $context->getUrl(['table_name' => $table->getTableName(), 'func' => 'updatetable'] + rex_csrf_token::factory($_csrf_key)->getUrlParams()),
                        'attributes' => [
                            'class' => [
                                'btn-default',
                            ],
                        ],
                    ],
                    [
                        'label' => rex_i18n::msg('yform_updatetable').' '.rex_i18n::msg('yform_updatetable_with_delete'),
                        'url' => $context->getUrl(['table_name' => $table->getTableName(), 'func' => 'updatetablewithdelete'] + rex_csrf_token::factory($_csrf_key)->getUrlParams()),
                        'attributes' => [
                            'class' => [
                                'btn-default',
                            ],
                            'onclick' => [
                                'return confirm(\'' . rex_i18n::msg('yform_updatetable_with_delete_confirm') . '\')',
                            ],
                        ],
                    ],
                ], false);
                $panel_options .= '<small class="rex-panel-option-title">' . rex_i18n::msg('yform_table') . '</small> ' . $fragment->parse('core/buttons/button_group.php');

                $sql = 'select id, prio, type_id, type_name, name, label from ' . rex_yform_manager_field::table() . ' where table_name="' . $table->getTableName() . '" order by prio';
                $list = rex_list::factory($sql, 200);
                // $list->debug = 1;
                // $list->setColumnFormat('id', 'Id');

                $tdIcon = '<i class="rex-icon rex-icon-table"></i>';
                $thIcon = '<a href="' . $list->getUrl(['table_name' => $table->getTableName(), 'func' => 'choosenadd']) . '"' . rex::getAccesskey(rex_i18n::msg('add'), 'add') . '><i class="rex-icon rex-icon-add"></i></a>';
                $list->addColumn($thIcon, $tdIcon, 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
                $list->setColumnParams($thIcon, ['field_id' => '###id###', 'func' => 'edit', 'type_name' => '###type_name###', 'type_id' => '###type_id###']);

                foreach ($this->getLinkVars() as $k => $v) {
                    $list->addParam($k, $v);
                }
                $list->addParam('start', rex_request('start', 'int'));

                $list->addParam('table_name', $table->getTableName());

                $list->removeColumn('id');

                $list->setColumnLabel('prio', rex_i18n::msg('yform_manager_table_prio_short'));
                //$list->setColumnLayout('prio', ['<th class="rex-table-priority">###VALUE###</th>', '<td class="rex-table-priority" data-title="' . rex_i18n::msg('yform_manager_table_prio_short') . '">###VALUE###</td>']);
                $list->setColumnLayout('prio', ['<th>###VALUE###</th>', '###VALUE###']);
                $list->setColumnFormat('prio', 'custom', 'rex_yform_list_format');

                $list->setColumnLabel('type_id', rex_i18n::msg('yform_manager_type_id'));
                $list->setColumnLayout('type_id', ['<th>###VALUE###</th>', '###VALUE###']);
                $list->setColumnFormat('type_id', 'custom', 'rex_yform_list_format');

                $list->setColumnLabel('type_name', rex_i18n::msg('yform_manager_type_name'));
                $list->setColumnLayout('type_name', ['<th>###VALUE###</th>', '###VALUE###']);
                $list->setColumnFormat('type_name', 'custom', 'rex_yform_list_format');

                $list->setColumnLabel('name', rex_i18n::msg('yform_values_defaults_name'));
                $list->setColumnLayout('name', ['<th>###VALUE###</th>', '###VALUE###']); // ###VALUE###
                $list->setColumnFormat('name', 'custom', 'rex_yform_list_format');

                $list->setColumnLabel('label', rex_i18n::msg('yform_values_defaults_label'));
                $list->setColumnLayout('label', ['<th>###VALUE###</th>', '###VALUE###']); // ###VALUE###
                $list->setColumnFormat('label', 'custom', 'rex_yform_list_format');

                $list->addColumn(rex_i18n::msg('yform_function'), '<i class="rex-icon rex-icon-edit"></i> ' . rex_i18n::msg('yform_edit'));
                $list->setColumnParams(rex_i18n::msg('yform_function'), ['field_id' => '###id###', 'func' => 'edit', 'type_name' => '###type_name###', 'type_id' => '###type_id###']);
                $list->setColumnLayout(rex_i18n::msg('yform_function'), ['<th class="rex-table-action" colspan="2">###VALUE###</th>', '###VALUE###']);
                $list->setColumnFormat(rex_i18n::msg('yform_function'), 'custom', 'rex_yform_list_edit_format');

                $list->addColumn(rex_i18n::msg('yform_delete'), '<i class="rex-icon rex-icon-delete"></i> ' . rex_i18n::msg('yform_delete'));
                $list->setColumnParams(rex_i18n::msg('yform_delete'), ['field_id' => '###id###', 'func' => 'delete'] + rex_csrf_token::factory($_csrf_key)->getUrlParams());
                $list->setColumnLayout(rex_i18n::msg('yform_delete'), ['', '###VALUE###']);
                $list->setColumnFormat(rex_i18n::msg('yform_delete'), 'custom', 'rex_yform_list_delete_format');
                $list->addLinkAttribute(rex_i18n::msg('yform_delete'), 'onclick', 'return confirm(\' [###type_id###, ###type_name###, ###name###] ' . rex_i18n::msg('yform_delete') . ' ?\')');

                $content = $list->get();

                $fragment = new rex_fragment();
                $fragment->setVar('title', rex_i18n::msg('yform_manager_tablefield_overview'));
                $fragment->setVar('options', $panel_options, false);
                $fragment->setVar('content', $content, false);
                $content = $fragment->parse('core/page/section.php');
                echo $content;
            }
        }
    }

    private function getFieldName($key, $type)
    {
        if (is_int($key)) {
            ++$key;
            if (1 === $key) {
                return 'name';
            }
            if (2 === $key && 'value' === $type) {
                return 'label';
            }
            return 'f' . $key;
        }

        if (in_array($key, self::$reservedFieldColumns)) {
            $key = 'field_' . $key;
        }
        return $key;
    }

    // ----- Allgemeine Methoden

    // ----- Felder

    public static function checkField($l, $v, $p)
    {
        $q = 'select * from ' . rex_yform_manager_field::table() . ' where table_name="' . $p['table_name'] . '" and type_id="value" and ' . $l . '="' . $v . '" LIMIT 1';
        $c = rex_sql::factory();
        $c->setDebug(self::$debug);
        $c->setQuery($q);
        if ($c->getRows() > 0) {
            return true;
        }
        return false;
    }

    public function createTable($mifix, $data_table, $params = [], $debug = false)
    {
        // Tabelle erstellen wenn noch nicht vorhanden
        $c = rex_sql::factory();
        $c->setDebug($debug);
        $c->setQuery('CREATE TABLE IF NOT EXISTS `' . $data_table . '` ( `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');

        // Tabellenset in die Basics einbauen, wenn noch nicht vorhanden
        $c = rex_sql::factory();
        $c->setDebug($debug);
        $c->setQuery('DELETE FROM ' . rex_yform_manager_table::table() . ' where table_name="' . $data_table . '"');
        $c->setTable(rex_yform_manager_table::table());

        $params['table_name'] = $data_table;
        if (!isset($params['status'])) {
            $params['status'] = 1;
        }

        if (!isset($params['name'])) {
            $params['name'] = 'Tabelle "' . $data_table . '"';
        }

        if (!isset($params['prio'])) {
            $params['prio'] = 100;
        }

        if (!isset($params['search'])) {
            $params['search'] = 0;
        }

        if (!isset($params['hidden'])) {
            $params['hidden'] = 0;
        }

        if (!isset($params['export'])) {
            $params['export'] = 0;
        }

        foreach ($params as $k => $v) {
            $c->setValue($k, $v);
        }

        $c->insert();

        return true;
    }

    public static function checkMediaInUse($params)
    {
        $warning = $params['subject'];

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT `table_name`, `type_name`, `name` FROM `' . rex_yform_manager_field::table() . '` WHERE `type_id`="value" AND `type_name` IN("be_medialist","be_mediapool","mediafile")');

        $rows = $sql->getRows();

        if (0 == $rows) {
            return $warning;
        }

        $where = [];
        $filename = addslashes($params['filename']);
        while ($sql->hasNext()) {
            $table = $sql->getValue('table_name');
            switch ($sql->getValue('type_name')) {
                case 'be_mediapool':
                case 'mediafile':
                    $where[$table][] = $sql->getValue('name') . '="' . $filename . '"';
                    break;
                case 'be_medialist':
                    $where[$table][] = 'FIND_IN_SET("' . $filename . '", ' . $sql->getValue('name') . ')';
                    break;
                default:
                    trigger_error('Unexpected fieldtype "' . $sql->getValue('type_name') . '"!', E_USER_ERROR);
            }
            $sql->next();
        }

        $tupel = '';
        foreach ($where as $table => $cond) {
            $sql->setQuery('SELECT id FROM ' . $table . ' WHERE ' . implode(' OR ', $cond));

            while ($sql->hasNext()) {
                $sql_tupel = rex_sql::factory();
                $sql_tupel->setQuery('SELECT name FROM `' . rex_yform_manager_table::table() . '` WHERE `table_name`="' . $table . '"');

                $tupel .= '<li><a href="javascript:openPage(\'index.php?page=yform/manager/data_edit&amp;table_name=' . $table . '&amp;data_id=' . $sql->getValue('id') . '&amp;func=edit\')">' . $sql_tupel->getValue('name') . ' [id=' . $sql->getValue('id') . ']</a></li>';

                $sql->next();
            }
        }

        if ('' != $tupel) {
            $warning[] = 'Tabelle<br /><ul>' . $tupel . '</ul>';
        }

        return $warning;
    }
}
