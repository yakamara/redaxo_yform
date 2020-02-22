<?php

/**
 * yform.
 *
 * @author jan.kristinus[at]redaxo[dot]org Jan Kristinus
 * @author <a href="http://www.yakamara.de">www.yakamara.de</a>
 */

class rex_yform_validate_type extends rex_yform_validate_abstract
{
    public function enterObject()
    {
        $Object = $this->getValueObject();

        if (!$this->isObject($Object)) {
            return;
        }

        if ($this->getElement('not_required') == 1 && $Object->getValue() == '') {
            return;
        }

        $w = false;

        switch (trim($this->getElement('type'))) {
            case 'int':
            case 'integer':
                $xsRegEx_int = '/^[0-9]+$/i';
                if (preg_match($xsRegEx_int, $Object->getValue()) == 0) {
                    $w = true;
                }
                break;
            case 'float':
                $xsRegEx_float = "/^([0-9]+|([0-9]+\.[0-9]+))$/i";
                if (preg_match($xsRegEx_float, $Object->getValue()) == 0) {
                    $w = true;
                }
                break;
            case 'numeric':
                if (!is_numeric($Object->getValue())) {
                    $w = true;
                }
                break;
            case 'string':
                break;
            case 'email':
                if (!preg_match("/^[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/", $Object->getValue())) {
                    $w = true;
                }
                break;
            case 'url':
                $xsRegEx_url = '/^(?:http[s]?:\/\/)[a-zA-Z0-9][a-zA-Z0-9._-]*\.(?:[a-zA-Z0-9][a-zA-Z0-9._-]*\.)*[a-zA-Z]{2,20}(?:\/[^\\/\:\*\?\"<>\|]*)*(?:\/[a-zA-Z0-9_%,\.\=\?\-#&]*)*$' . '/';
                if (preg_match($xsRegEx_url, $Object->getValue()) == 0) {
                    $w = true;
                }
                break;
            case 'time':
                $w = true;
                $ex = explode(':', $Object->getValue());
                if (count($ex) == 3 && $ex[0] > -839 && $ex[0] < 839 && $ex[1] >= 0 && $ex[1] < 60 && $ex[2] >= 0 && $ex[2] < 60) {
                    $w = false;
                }
                break;
            case 'date':
                $w = true;
                if (preg_match("/^(\d{4})-(\d{2})-(\d{2})$/", $Object->getValue(), $matches)) {
                    if (checkdate($matches[2], $matches[3], $matches[1])) {
                        $w = false;
                    }
                }
                break;
            case 'datetime':
                $w = true;
                if (preg_match("/^(\d{4})-(\d{2})-(\d{2}) ([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/", $Object->getValue(), $matches)) {
                    if (checkdate($matches[2], $matches[3], $matches[1])) {
                        $w = false;
                    }
                }
                break;
            case 'hex':
                $xsRegEx_hex = '/^[0-9a-fA-F]+$/i';
                if (preg_match($xsRegEx_hex, $Object->getValue()) == 0) {
                    $w = true;
                }
                break;
            case 'iban':
                $iban = strtolower($Object->getValue());

                $countries = [
                    'al'=>28,'ad'=>24,'at'=>20,'az'=>28,'bh'=>22,'be'=>16,'ba'=>20,'br'=>29,'bg'=>22,'cr'=>21,'hr'=>21,'cy'=>28,'cz'=>24,
                    'dk'=>18,'do'=>28,'ee'=>20,'fo'=>18,'fi'=>18,'fr'=>27,'ge'=>22,'de'=>22,'gi'=>23,'gr'=>27,'gl'=>18,'gt'=>28,'hu'=>28,
                    'is'=>26,'ie'=>22,'il'=>23,'it'=>27,'jo'=>30,'kz'=>20,'kw'=>30,'lv'=>21,'lb'=>28,'li'=>21,'lt'=>20,'lu'=>20,'mk'=>19,
                    'mt'=>31,'mr'=>27,'mu'=>30,'mc'=>27,'md'=>24,'me'=>22,'nl'=>18,'no'=>15,'pk'=>24,'ps'=>29,'pl'=>28,'pt'=>25,'qa'=>29,
                    'ro'=>24,'sm'=>27,'sa'=>24,'rs'=>22,'sk'=>24,'si'=>19,'es'=>24,'se'=>24,'ch'=>21,'tn'=>24,'tr'=>26,'ae'=>23,'gb'=>22,'vg'=>24
                ];
                $chars = [
                    'a'=>10,'b'=>11,'c'=>12,'d'=>13,'e'=>14,'f'=>15,'g'=>16,'h'=>17,'i'=>18,'j'=>19,'k'=>20,'l'=>21,'m'=>22,
                    'n'=>23,'o'=>24,'p'=>25,'q'=>26,'r'=>27,'s'=>28,'t'=>29,'u'=>30,'v'=>31,'w'=>32,'x'=>33,'y'=>34,'z'=>35
                ];

                if (!isset($countries[substr($iban,0,2)]) || strlen($iban) != $countries[substr($iban,0,2)]) {
                    $w = true;
                } else {
                    $movedChar = substr($iban, 4).substr($iban,0,4);
                    $movedCharArray = str_split($movedChar);
                    $newString = '';

                    foreach ($movedCharArray as $k => $v) {
                        if (!is_numeric($movedCharArray[$k])) {
                            $movedCharArray[$k] = $chars[$movedCharArray[$k]];
                        }
                        $newString .= $movedCharArray[$k];
                    }

                    if (function_exists('bcmod')) {
                        $w = bcmod($newString, '97') == 1;
                    } else {
                        $x = $newString;
                        $y = '97';
                        $take = 5;
                        $mod = '';

                        do {
                            $a = (int)$mod.substr($x, 0, $take);
                            $x = substr($x, $take);
                            $mod = $a % $y;
                        }
                        while (strlen($x));

                        $w = (int)$mod == 1;
                    }
                }

                break;
            case 'json':
                json_decode($Object->getValue());
                if (json_last_error() != JSON_ERROR_NONE) {
                    $w = true;
                }
                break;
            case '':
                break;
            default:
                echo 'Type ' . $this->getElement(3) . ' nicht definiert';
                $w = true;
                break;
        }

        if ($w) {
            $this->params['warning'][$Object->getId()] = $this->params['error_class'];
            $this->params['warning_messages'][$Object->getId()] = $this->getElement('message');
        }
    }

    public function getDescription()
    {
        return 'validate|type|name|int/float/numeric/string/email/url/date/datetime/hex/iban/json|warning_message|[1=field not empty]';
    }

    public function getDefinitions()
    {
        return [
            'type' => 'validate',
            'name' => 'type',
            'values' => [
                'name' => ['type' => 'select_name', 'label' => rex_i18n::msg('yform_validate_type_name')],
                'type' => ['type' => 'choice',    'label' => rex_i18n::msg('yform_validate_type_type'), 'choices' => 'int,float,numeric,string,email,url,time,date,datetime,hex,iban,json'],
                'message' => ['type' => 'text',    'label' => rex_i18n::msg('yform_validate_type_message')],
                'not_required' => ['type' => 'boolean',    'label' => rex_i18n::msg('yform_validate_type_not_required'), 'default' => 0],
            ],
            'description' => rex_i18n::msg('yform_validate_type_description'),
            'famous' => true,
        ];
    }
}
