<?php

/**
 * yform.
 *
 * @author jan.kristinus[at]redaxo[dot]org Jan Kristinus
 * @author <a href="http://www.yakamara.de">www.yakamara.de</a>
 */

class rex_yform_validate_customfunction extends rex_yform_validate_abstract
{
    public function customfunction_execute()
    {
        $names = $this->getElement('name');
        if (!is_array($names)) {
            $names = explode(',', $names);
        }

        $func = $this->getElement('function');
        $parameter = $this->getElement('params');

        $comparator = true;
        if (is_string($func) && mb_substr($func, 0, 1) == '!') {
            $comparator = false;
            $func = mb_substr($func, 1);
        }

        $Objects = [];
        foreach ($names as $name) {
            $Object = $this->getValueObject($name);
            if (!$this->isObject($Object)) {
                return;
            }
            $Objects[$name] = $this->getValueObject($name);
        }

        $ObjectValues = [];
        foreach ($Objects as $k => $Object) {
            $ObjectValues[$k] = $Object->getValue();
        }

        if (count($ObjectValues) == 1) {
            $ObjectValues = current($ObjectValues);
            $names = $names[0];
        }

        if (!is_callable($func)) {
            foreach ($Objects as $Object) {
                $this->params['warning'][$Object->getId()] = $this->params['error_class'];
                $this->params['warning_messages'][$Object->getId()] = 'ERROR: customfunction "' . $func . '" not found';
            }
        } elseif (call_user_func($func, $names, $ObjectValues, $parameter, $this, $Objects) === $comparator) {
            foreach ($Objects as $Object) {
                $this->params['warning'][$Object->getId()] = $this->params['error_class'];
                if (!empty($this->getElement('message'))) {
                    $this->params['warning_messages'][$Object->getId()] = $this->getElement('message');
                }
            }
        }
    }

    public function preValidateAction()
    {
        if ($this->getElement('validate_type') == 'pre') {
            return $this->customfunction_execute();
        }
    }

    public function enterObject()
    {
        if ($this->getElement('validate_type') != 'pre' && $this->getElement('validate_type') != 'post') {
            return $this->customfunction_execute();
        }
    }

    public function postValueAction()
    {
        if ($this->getElement('validate_type') == 'post') {
            return $this->customfunction_execute();
        }
    }

    public function getDescription()
    {
        return 'validate|customfunction|name[s]|[!]function/class::method|weitere_parameter|warning_message|type[default:normal,pre,post]';
    }

    public function getDefinitions()
    {
        return [
            'type' => 'validate',
            'name' => 'customfunction',
            'values' => [
                'name' => ['type' => 'select_names', 'label' => rex_i18n::msg('yform_validate_customfunction_name')],
                'function' => ['type' => 'text',  'label' => rex_i18n::msg('yform_validate_customfunction_function')],
                'params' => ['type' => 'text',   'label' => rex_i18n::msg('yform_validate_customfunction_params')],
                'message' => ['type' => 'text',   'label' => rex_i18n::msg('yform_validate_customfunction_message')],
                'validate_type' => ['type' => 'choice',   'label' => rex_i18n::msg('yform_validate_customfunction_type'), 'choices' => ['normal', 'pre', 'post'], 'default' => 'normal'],
            ],
            'description' => rex_i18n::msg('yform_validate_customfunction_description'),
            'multi_edit' => false,
        ];
    }
}
