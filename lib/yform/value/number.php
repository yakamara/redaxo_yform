<?php

/**
 * yform.
 *
 * @author jan.kristinus[at]redaxo[dot]org Jan Kristinus
 * @author <a href="http://www.yakamara.de">www.yakamara.de</a>
 */

class rex_yform_value_number extends rex_yform_value_abstract
{
    public function enterObject()
    {
        if ('' == $this->getValue() && !$this->params['send']) {
            $this->setValue($this->getElement('default'));
        }

        if ('' === $this->getValue()) {
            $this->setValue(null);
        } else {
            $this->setValue($this->getValue());
        }

        if ($this->needsOutput() && $this->isViewable()) {
            if (!$this->isEditable()) {
                $this->params['form_output'][$this->getId()] = $this->parse(['value.number-view.tpl.php', 'value.integer-view.tpl.php', 'value.view.tpl.php'], ['prepend' => $this->getElement('unit')]);

            } else {
                $this->params['form_output'][$this->getId()] = $this->parse(['value.number.tpl.php', 'value.integer.tpl.php', 'value.text.tpl.php'], ['type' => 'number', 'attributes' =>  ['max' => '100000', 'step' => '0.01'], 'prepend' => $this->getElement('unit')]);
            }
        }

        $this->params['value_pool']['email'][$this->getName()] = $this->getValue();
        if ($this->saveInDb()) {
            $this->params['value_pool']['sql'][$this->getName()] = $this->getValue();
        }
    }

    public function getDescription()
    {
        return 'number|name|label|precision|scale|defaultwert|[no_db]|[unit]|[notice]|[attributes]';
    }

    public function getDefinitions()
    {
        return [
            'type' => 'value',
            'name' => 'number',
            'values' => [
                'name' => ['type' => 'name',    'label' => rex_i18n::msg('yform_values_defaults_name')],
                'label' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_label')],
                'precision' => ['type' => 'integer', 'label' => rex_i18n::msg('yform_values_number_precision'), 'default' => '10'],
                'scale' => ['type' => 'integer', 'label' => rex_i18n::msg('yform_values_number_scale'), 'default' => '2'],
                'default' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_number_default')],
                'no_db' => ['type' => 'no_db',   'label' => rex_i18n::msg('yform_values_defaults_table'),  'default' => 0],
                'unit' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_unit')],
                'notice' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_notice')],
                'attributes' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_attributes'), 'notice' => rex_i18n::msg('yform_values_defaults_attributes_notice')],
                'widget' => ['type' => 'choice', 'label' => rex_i18n::msg('yform_values_defaults_widgets'), 'choices' => ['input:text' => 'input:text', 'input:number' => 'input:number'], 'default' => 'input:number'],

            ],
            'validates' => [
                ['type' => ['name' => 'precision', 'type' => 'integer', 'message' => rex_i18n::msg('yform_values_number_error_precision', '1', '65'), 'not_required' => false]],
                ['type' => ['name' => 'scale', 'type' => 'integer', 'message' => rex_i18n::msg('yform_values_number_error_scale', '0', '30'), 'not_required' => false]],
                ['compare' => ['name' => 'scale', 'name2' => 'precision', 'message' => rex_i18n::msg('yform_values_number_error_compare'), 'compare_type' => '>']],
                ['intfromto' => ['name' => 'precision', 'from' => '1', 'to' => '65', 'message' => rex_i18n::msg('yform_values_number_error_precision', '1', '65')]],
                ['intfromto' => ['name' => 'scale', 'from' => '0', 'to' => '30', 'message' => rex_i18n::msg('yform_values_number_error_scale', '0', '30')]],
            ],
            'description' => rex_i18n::msg('yform_values_number_description'),
            'db_type' => ['DECIMAL({precision},{scale})'],
            'hooks' => [
                'preCreate' => static function (rex_yform_manager_field $field, $db_type) {
                    $db_type = str_replace('{precision}', $field->getElement('precision') ?? 6, $db_type);
                    $db_type = str_replace('{scale}', $field->getElement('scale') ?? 2, $db_type);
                    return $db_type;
                },
            ],
            'db_null' => true,
        ];
    }

    public static function getListValue($params)
    {
        return (!empty($params['params']['field']['unit']) && '' != $params['subject']) ? $params['params']['field']['unit'].' '.$params['subject'] : $params['subject'];
    }

    public static function getSearchField($params)
    {
        $params['searchForm']->setValueField('text', ['name' => $params['field']->getName(), 'label' => $params['field']->getLabel(), 'notice' => rex_i18n::msg('yform_search_integer_notice'), 'prepend' => $params['field']->getElement('unit')]);
    }

    public static function getSearchFilter($params)
    {
        $sql = rex_sql::factory();

        $value = $params['value'];
        $field = $sql->escapeIdentifier($params['field']->getName());

        if ('(empty)' == $value) {
            return ' (' . $field . ' = "" or ' . $field . ' IS NULL) ';
        }
        if ('!(empty)' == $value) {
            return ' (' . $field . ' <> "" and ' . $field . ' IS NOT NULL) ';
        }

        if (preg_match('/^\s*(-?\d+)\s*\.\.\s*(-?\d+)\s*$/', $value, $match)) {
            $match[1] = (int) $match[1];
            $match[2] = (int) $match[2];
            return ' ' . $field . ' BETWEEN ' . $match[1] . ' AND ' . $match[2];
        }
        preg_match('/^\s*(<|<=|>|>=|<>|!=)?\s*(.*)$/', $value, $match);
        $comparator = $match[1] ?: '=';
        $value = $match[2];
        $value = $sql->escape($value);
        return ' ' . $field . ' ' . $comparator . ' ' . $value;
    }
}
