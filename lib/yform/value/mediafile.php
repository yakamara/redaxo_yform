<?php

/**
 * yform.
 *
 * @author jan.kristinus[at]redaxo[dot]org Jan Kristinus
 * @author <a href="http://www.yakamara.de">www.yakamara.de</a>
 */

class rex_yform_value_mediafile extends rex_yform_value_abstract
{
    public function enterObject()
    {
        if (!is_string($this->getValue())) {
            $this->setValue('');
        }

        $media_category_id = ('' == $this->getElement(8)) ? 0 : (int) $this->getElement('category');
        $media_category = rex_media_category::get($media_category_id);
        if (null === $media_category) {
            $media_category_id = 0;
        }

        $mediapool_user = ('' == $this->getElement('user')) ? 'yform::mediafile' : $this->getElement('user');
        $pool = $this->params['value_pool']['email'];
        $mediapool_user = preg_replace_callback('/###(\w+)###/',
                                                            static function ($m) use ($pool) {
                                                                return $pool[$m[1]]
                                                                         ?? 'key not found';
                                                            },
                                                            $mediapool_user);

        $sizes = array_map('intval', explode(',', $this->getElement('max_size')));
        $minsize = count($sizes) > 1 ? (int) ($sizes[0] * 1024) : 0;
        $maxsize = count($sizes) > 1 ? (int) ($sizes[1] * 1024) : (int) ($sizes[0] * 1024);

        $error = [];
        $err_msgs = explode(',', $this->getElement('messages')); // min_err,max_err,type_err,empty_err
        $err_msgs['min_err'] = $err_msgs[0];
        $err_msgs['max_err'] = $err_msgs[1] ?? $err_msgs[0];
        $err_msgs['type_err'] = $err_msgs[2] ?? $err_msgs[0];
        $err_msgs['empty_err'] = $err_msgs[3] ?? $err_msgs[0];

        $rdelete = md5($this->getFieldName('delete'));
        $rfile = 'file_' . md5($this->getFieldName('file'));

        if (!$this->isEditable()) {
            unset($_FILES[$rfile]);
        }

        // SIZE CHECK
        if ($this->params['send'] && isset($_FILES[$rfile]) && '' != $_FILES[$rfile]['name'] && ($_FILES[$rfile]['size'] > $maxsize || $_FILES[$rfile]['size'] < $minsize)) {
            if ($_FILES[$rfile]['size'] < $minsize) {
                $error[] = $err_msgs['min_err'];
            }
            if ($_FILES[$rfile]['size'] > $maxsize) {
                $error[] = $err_msgs['max_err'];
            }
            unset($_FILES[$rfile]);
            $this->setValue('');
        }

        if ($this->params['send']) {
            if (isset($_REQUEST[$rdelete]) && 1 == $_REQUEST[$rdelete]) {
                $this->setValue('');
            }

            if (isset($_FILES[$rfile]) && '' != $_FILES[$rfile]['name']) {
                $FILE['size'] = $_FILES[$rfile]['size'];
                $FILE['name'] = $_FILES[$rfile]['name'];
                $FILE['type'] = $_FILES[$rfile]['type'];
                $FILE['tmp_name'] = $_FILES[$rfile]['tmp_name'];
                $FILE['error'] = $_FILES[$rfile]['error'];

                // EXTENSION CHECK
                $extensions_array = explode(',', $this->getElement(4));
                $ext = '.' . pathinfo($FILE['name'], PATHINFO_EXTENSION);
                if (!in_array(mb_strtolower($ext), $extensions_array) && !in_array(mb_strtoupper($ext), $extensions_array)) {
                    $error[] = $err_msgs['type_err'];
                } else {
                    $NEWFILE = $this->saveMedia($FILE, rex_path::media(), $extensions_array, $media_category_id, $mediapool_user);

                    if ($NEWFILE['ok']) {
                        $this->setValue($NEWFILE['filename']);
                    } else {
                        $this->setValue('');
                        $error[] = 'unknown_save_error';
                    }
                }
            }
        }

        if ($this->params['send']) {
            $this->params['value_pool']['email'][$this->getElement('name')] = $this->getValue();
            if ($this->saveInDb()) {
                $this->params['value_pool']['sql'][$this->getElement('name')] = $this->getValue();
            }
        }

        //# check for required file
        if ($this->params['send'] && 1 == $this->getElement('required') && '' == $this->getValue()) {
            $error[] = $err_msgs['empty_err'];
        }

        if ($this->params['send'] && count($error) > 0) {
            $this->params['warning'][$this->getId()] = $this->params['error_class'];
            $this->params['warning_messages'][$this->getId()] = implode(', ', $error);
        }

        if ($this->needsOutput() && $this->isViewable()) {
            if (!$this->isEditable()) {
                $this->params['form_output'][$this->getId()] = $this->parse(['value.mediafile-view.tpl.php', 'value.view.tpl.php']);
            } else {
                $this->params['form_output'][$this->getId()] = $this->parse('value.mediafile.tpl.php');
            }
        }
    }

    public function getDescription()
    {
        return 'mediafile|name|label|groesseinkb|endungenmitpunktmitkommasepariert|pflicht=1|min_err,max_err,type_err,empty_err|[no_db]|mediacatid|user';
    }

    public function getDefinitions()
    {
        return [
            'type' => 'value',
            'name' => 'mediafile',
            'values' => [
                'name' => ['type' => 'name',    'label' => rex_i18n::msg('yform_values_defaults_name')],
                'label' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_label')],
                'max_size' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_mediafile_max_size')],
                'types' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_mediafile_types')],
                'required' => ['type' => 'boolean', 'label' => rex_i18n::msg('yform_values_mediafile_required')],
                'messages' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_mediafile_messages')],
                'no_db' => ['type' => 'no_db',   'label' => rex_i18n::msg('yform_values_defaults_table'),  'default' => 0],
                'category' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_mediafile_category')],
                'user' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_mediafile_user')],
                'notice' => ['type' => 'text',    'label' => rex_i18n::msg('yform_values_defaults_notice')],
            ],
            'description' => rex_i18n::msg('yform_values_mediafile_description'),
            'db_type' => ['text'],
            'multi_edit' => false,
        ];
    }

    public function saveMedia($FILE, $filefolder, $extensions_array, $rex_media_category_id, $mediapool_user)
    {
        $FILENAME = $FILE['name'];
        $FILESIZE = $FILE['size'];
        $FILETYPE = $FILE['type'];

        $NFILENAME = mb_strtolower(preg_replace('/[^a-zA-Z0-9.\-\$\+]/', '_', $FILENAME));
        if ('' != strrpos($NFILENAME, '.')) {
            $NFILE_NAME = mb_substr($NFILENAME, 0, mb_strlen($NFILENAME) - (mb_strlen($NFILENAME) - mb_strrpos($NFILENAME, '.')));
            $NFILE_EXT = mb_substr($NFILENAME, mb_strrpos($NFILENAME, '.'), mb_strlen($NFILENAME) - mb_strrpos($NFILENAME, '.'));
        } else {
            $NFILE_NAME = $NFILENAME;
            $NFILE_EXT = '';
        }

        $ERROR_EXT = ['.php', '.php3', '.php4', '.php5', '.phtml', '.pl', '.asp', '.aspx', '.cfm'];
        if (in_array($NFILE_EXT, $ERROR_EXT)) {
            $NFILE_NAME .= $NFILE_EXT;
            $NFILE_EXT = '.txt';
        }

        $standard_extensions_array = ['.rtf', '.pdf', '.doc', '.gif', '.jpg', '.jpeg'];
        if (0 == count($extensions_array)) {
            $extensions_array = $standard_extensions_array;
        }

        if (!in_array($NFILE_EXT, $extensions_array)) {
            $RETURN = false;
            $RETURN['ok'] = false;
            return $RETURN;
        }

        $NFILENAME = $NFILE_NAME . $NFILE_EXT;

        if (file_exists($filefolder . '/' . $NFILENAME)) {
            for ($cf = 1; $cf < 1000; ++$cf) {
                $NFILENAME = $NFILE_NAME . '_' . $cf . $NFILE_EXT;
                if (!file_exists($filefolder . '/' . $NFILENAME)) {
                    break;
                }
            }
        }

        $message = '';
        if (!move_uploaded_file($FILE['tmp_name'], $filefolder . "/$NFILENAME")) {
            if (!copy($FILE['tmp_name'], $filefolder . '/' . $NFILENAME)) {
                $message = 'move file $NFILENAME failed | ';
                $RETURN = false;
                $RETURN['ok'] = false;
                return $RETURN;
            }
        }

        @chmod($filefolder . '/' . $NFILENAME, rex::getFilePerm());
        $RETURN['type'] = $FILETYPE;
        $RETURN['msg'] = $message;
        $RETURN['ok'] = true;
        $RETURN['filename'] = $NFILENAME;

        $saveSQL = rex_sql::factory()
            ->setTable(rex::getTablePrefix() . 'media')
            ->setValue('title', '')
            ->setValue('filetype', $FILETYPE)
            ->setValue('filename', $NFILENAME)
            ->setValue('originalname', $FILENAME)
            ->setValue('filesize', $FILESIZE)
            ->setValue('category_id', $rex_media_category_id)
            ->setValue('createdate', date(rex_sql::FORMAT_DATETIME))
            ->setValue('createuser', $mediapool_user)
            ->setValue('updatedate', date(rex_sql::FORMAT_DATETIME))
            ->setValue('updateuser', $mediapool_user);

        // get widht height
        $size = @getimagesize($filefolder . '/' . $NFILENAME);

        if ($size) {
            $saveSQL->setValue('width', $size[0]);
            $saveSQL->setValue('height', $size[1]);
        }

        $saveSQL->insert();

        return $RETURN;
    }
}
