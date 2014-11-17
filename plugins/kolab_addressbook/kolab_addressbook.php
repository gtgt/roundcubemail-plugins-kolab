<?php

/**
 * Kolab address book
 *
 * Sample plugin to add a new address book source with data from Kolab storage
 * It provides also a possibilities to manage contact folders
 * (create/rename/delete/acl) directly in Addressbook UI.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2011, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_addressbook extends rcube_plugin
{
    public $task = '?(?!login|logout).*';

    private $sources;
    private $folders;
    private $rc;
    private $ui;

    const GLOBAL_FIRST = 0;
    const PERSONAL_FIRST = 1;
    const GLOBAL_ONLY = 2;
    const PERSONAL_ONLY = 3;

    /**
     * Startup method of a Roundcube plugin
     */
    public function init()
    {
        require_once(dirname(__FILE__) . '/lib/rcube_kolab_contacts.php');

        $this->rc = rcube::get_instance();

        // load required plugin
        $this->require_plugin('libkolab');

        // register hooks
        $this->add_hook('addressbooks_list', array($this, 'address_sources'));
        $this->add_hook('addressbook_get', array($this, 'get_address_book'));
        $this->add_hook('config_get', array($this, 'config_get'));

        if ($this->rc->task == 'addressbook') {
            $this->add_texts('localization');
            $this->add_hook('contact_form', array($this, 'contact_form'));
            $this->add_hook('template_object_directorylist', array($this, 'directorylist_html'));

            // Plugin actions
            $this->register_action('plugin.book', array($this, 'book_actions'));
            $this->register_action('plugin.book-save', array($this, 'book_save'));
            $this->register_action('plugin.book-search', array($this, 'book_search'));
            $this->register_action('plugin.book-subscribe', array($this, 'book_subscribe'));

            // Load UI elements
            if ($this->api->output->type == 'html') {
                $this->load_config();
                require_once($this->home . '/lib/kolab_addressbook_ui.php');
                $this->ui = new kolab_addressbook_ui($this);
            }
        }
        else if ($this->rc->task == 'settings') {
            $this->add_texts('localization');
            $this->add_hook('preferences_list', array($this, 'prefs_list'));
            $this->add_hook('preferences_save', array($this, 'prefs_save'));
        }

        $this->add_hook('folder_delete', array($this, 'prefs_folder_delete'));
        $this->add_hook('folder_rename', array($this, 'prefs_folder_rename'));
        $this->add_hook('folder_update', array($this, 'prefs_folder_update'));
    }


    /**
     * Handler for the addressbooks_list hook.
     *
     * This will add all instances of available Kolab-based address books
     * to the list of address sources of Roundcube.
     * This will also hide some addressbooks according to kolab_addressbook_prio setting.
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function address_sources($p)
    {
        $abook_prio = $this->addressbook_prio();
        $undelete   = $this->rc->config->get('undo_timeout');

        // Disable all global address books
        // Assumes that all non-kolab_addressbook sources are global
        if ($abook_prio == self::PERSONAL_ONLY) {
            $p['sources'] = array();
        }

        $sources = array();
        foreach ($this->_list_sources() as $abook_id => $abook) {
            // register this address source
            $sources[$abook_id] = $this->abook_prop($abook_id, $abook);
        }

        // Add personal address sources to the list
        if ($abook_prio == self::PERSONAL_FIRST) {
            // $p['sources'] = array_merge($sources, $p['sources']);
            // Don't use array_merge(), because if you have folders name
            // that resolve to numeric identifier it will break output array keys
            foreach ($p['sources'] as $idx => $value)
                $sources[$idx] = $value;
            $p['sources'] = $sources;
        }
        else {
            // $p['sources'] = array_merge($p['sources'], $sources);
            foreach ($sources as $idx => $value)
                $p['sources'][$idx] = $value;
        }

        return $p;
    }

    /**
     * Helper method to build a hash array of address book properties
     */
    protected function abook_prop($id, $abook)
    {
        if ($abook->virtual) {
            return array(
                'id'       => $id,
                'name'     => $abook->get_name(),
                'listname' => $abook->get_foldername(),
                'group'    => $abook instanceof kolab_storage_folder_user ? 'user' : $abook->get_namespace(),
                'readonly' => true,
                'editable' => false,
                'kolab'    => true,
                'virtual'  => true,
            );
        }
        else {
            return array(
                'id'       => $id,
                'name'     => $abook->get_name(),
                'listname' => $abook->get_foldername(),
                'readonly' => $abook->readonly,
                'editable' => $abook->editable,
                'groups'   => $abook->groups,
                'undelete' => $abook->undelete && $this->rc->config->get('undo_timeout'),
                'realname' => rcube_charset::convert($abook->get_realname(), 'UTF7-IMAP'), // IMAP folder name
                'group'    => $abook->get_namespace(),
                'subscribed' => $abook->is_subscribed(),
                'carddavurl' => $abook->get_carddav_url(),
                'removable' => true,
                'kolab'    => true,
            );
        }
    }

    /**
     *
     */
    public function directorylist_html($args)
    {
        $out = '';
        $jsdata = array();
        $sources = (array)$this->rc->get_address_sources();

        // list all non-kolab sources first
        foreach (array_filter($sources, function($source){ return empty($source['kolab']); }) as $j => $source) {
            $id = strval(strlen($source['id']) ? $source['id'] : $j);
            $out .= $this->addressbook_list_item($id, $source, $jsdata) . '</li>';
        }

        // render a hierarchical list of kolab contact folders
        kolab_storage::folder_hierarchy($this->folders, $tree);
        $out .= $this->folder_tree_html($tree, $sources, $jsdata);

        $this->rc->output->set_env('contactgroups', array_filter($jsdata, function($src){ return $src['type'] == 'group'; }));
        $this->rc->output->set_env('address_sources', array_filter($jsdata, function($src){ return $src['type'] != 'group'; }));

        $args['content'] = html::tag('ul', $args, $out, html::$common_attrib);
        return $args;
    }

    /**
     * Return html for a structured list <ul> for the folder tree
     */
    public function folder_tree_html($node, $data, &$jsdata)
    {
        $out = '';
        foreach ($node->children as $folder) {
            $id = $folder->id;
            $source = $data[$id];
            $is_collapsed = strpos($this->rc->config->get('collapsed_abooks',''), '&'.rawurlencode($id).'&') !== false;

            if ($folder->virtual) {
                $source = $this->abook_prop($folder->id, $folder);
            }
            else if (empty($source)) {
                $this->sources[$id] = new rcube_kolab_contacts($folder->name);
                $source = $this->abook_prop($id, $this->sources[$id]);
            }

            $content = $this->addressbook_list_item($id, $source, $jsdata);

            if (!empty($folder->children)) {
                $child_html = $this->folder_tree_html($folder, $data, $jsdata);

                if (!empty($child_html) && preg_match('!</ul>\n*$!', $content)) {
                    $content = preg_replace('!</ul>\n*$!', $child_html . '</ul>', $content);
                }
                else if (!empty($child_html)) {
                    $content .= html::tag('ul', array('style' => ($is_collapsed ? "display:none;" : null)), $child_html);
                }
            }

            $out .= $content . '</li>';
        }

        return $out;
    }

    /**
     *
     */
    protected function addressbook_list_item($id, $source, &$jsdata, $search_mode = false)
    {
        $folder  = $this->folders[$id];
        $current = rcube_utils::get_input_value('_source', rcube_utils::INPUT_GPC);

        if (!$source['virtual']) {
            $jsdata[$id] = $source;
            $jsdata[$id]['name'] = html_entity_decode($source['name'], ENT_NOQUOTES, RCUBE_CHARSET);
        }

        // set class name(s)
        $classes = array('addressbook');
        if ($source['group'])
            $classes[] = $source['group'];
        if ($current === $id)
            $classes[] = 'selected';
        if ($source['readonly'])
            $classes[] = 'readonly';
        if ($source['virtual'])
            $classes[] = 'virtual';
        if ($source['class_name'])
            $classes[] = $source['class_name'];

        $name = !empty($source['listname']) ? $source['listname'] : (!empty($source['name']) ? $source['name'] : $id);
        $label_id = 'kabt:' . $id;
        $inner = ($source['virtual'] ?
            html::a(array('tabindex' => '0'), $name) :
            html::a(array(
                    'href' => $this->rc->url(array('_source' => $id)),
                    'rel' => $source['id'],
                    'id' => $label_id,
                    'onclick' => "return " . rcmail_output::JS_OBJECT_NAME.".command('list','" . rcube::JQ($id) . "',this)",
                ), $name)
        );

        if (isset($source['subscribed'])) {
            $inner .= html::span(array(
                'class' => 'subscribed',
                'title' => $this->gettext('foldersubscribe'),
                'role' => 'checkbox',
                'aria-checked' => $source['subscribed'] ? 'true' : 'false',
            ), '');
        }

        // don't wrap in <li> but add a checkbox for search results listing
        if ($search_mode) {
            $jsdata[$id]['group'] = join(' ', $classes);

            if (!$source['virtual']) {
                $inner .= html::tag('input', array(
                    'type' => 'checkbox',
                    'name' => '_source[]',
                    'value' => $id,
                    'checked' => $prop['active'],
                    'aria-labelledby' => $label_id,
                ));
            }
            return html::div(null, $inner);
        }

        $out .= html::tag('li', array(
                'id' => 'rcmli' . rcube_utils::html_identifier($id, true),
                'class' => join(' ', $classes), 
                'noclose' => true,
            ),
            html::div($source['subscribed'] ? 'subscribed' : null, $inner)
        );

        $groupdata = array('out' => '', 'jsdata' => $jsdata, 'source' => $id);
        if ($source['groups'] && function_exists('rcmail_contact_groups')) {
            $groupdata = rcmail_contact_groups($groupdata);
        }

        $jsdata = $groupdata['jsdata'];
        $out .= $groupdata['out'];

        return $out;
    }

    /**
     * Sets autocomplete_addressbooks option according to
     * kolab_addressbook_prio setting extending list of address sources
     * to be used for autocompletion.
     */
    public function config_get($args)
    {
        if ($args['name'] != 'autocomplete_addressbooks') {
            return $args;
        }

        $abook_prio = $this->addressbook_prio();
        // here we cannot use rc->config->get()
        $sources    = $GLOBALS['CONFIG']['autocomplete_addressbooks'];

        // Disable all global address books
        // Assumes that all non-kolab_addressbook sources are global
        if ($abook_prio == self::PERSONAL_ONLY) {
            $sources = array();
        }

        if (!is_array($sources)) {
            $sources = array();
        }

        $kolab_sources = array();
        foreach (array_keys($this->_list_sources()) as $abook_id) {
            if (!in_array($abook_id, $sources))
                $kolab_sources[] = $abook_id;
        }

        // Add personal address sources to the list
        if (!empty($kolab_sources)) {
            if ($abook_prio == self::PERSONAL_FIRST) {
                $sources = array_merge($kolab_sources, $sources);
            }
            else {
                $sources = array_merge($sources, $kolab_sources);
            }
        }

        $args['result'] = $sources;

        return $args;
    }


    /**
     * Getter for the rcube_addressbook instance
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function get_address_book($p)
    {
        if ($p['id']) {
            $this->_list_sources();
            if ($this->sources[$p['id']]) {
                $p['instance'] = $this->sources[$p['id']];
            }
            else {
                $id = kolab_storage::id_decode($p['id']);
                if (preg_match('![^A-Za-z0-9=/+&._ -]!', $id))  // check for falsely base64 decoded identifier
                    $id = $p['id'];
                $folder = kolab_storage::get_folder($id);
                if ($folder->type != 'contact' && $id != $p['id']) {  // try with unencoded (old-style) identifier
                    $folder = kolab_storage::get_folder($p['id']);
                }
                if ($folder->type) {
                    $this->sources[$p['id']] = new rcube_kolab_contacts($folder->name);
                    $p['instance'] = $this->sources[$p['id']];
                }
            }
        }

        return $p;
    }


    private function _list_sources()
    {
        // already read sources
        if (isset($this->sources))
            return $this->sources;

        kolab_storage::$encode_ids = true;
        $this->sources = array();
        $this->folders = array();

        $abook_prio = $this->addressbook_prio();

        // Personal address source(s) disabled?
        if ($abook_prio == self::GLOBAL_ONLY) {
            return $this->sources;
        }

        // get all folders that have "contact" type
        $folders = kolab_storage::sort_folders(kolab_storage::get_folders('contact'));

        if (PEAR::isError($folders)) {
            rcube::raise_error(array(
              'code' => 600, 'type' => 'php',
              'file' => __FILE__, 'line' => __LINE__,
              'message' => "Failed to list contact folders from Kolab server:" . $folders->getMessage()),
            true, false);
        }
        else {
            // we need at least one folder to prevent from errors in Roundcube core
            // when there's also no sql nor ldap addressbook (Bug #2086)
            if (empty($folders)) {
                if ($folder = kolab_storage::create_default_folder('contact')) {
                    $folders = array(new kolab_storage_folder($folder, 'contact'));
                }
            }

            // convert to UTF8 and sort
            $names = array();
            foreach ($folders as $folder) {
                // create instance of rcube_contacts
                $abook_id = $folder->id;
                $abook = new rcube_kolab_contacts($folder->name);
                $this->sources[$abook_id] = $abook;
                $this->folders[$abook_id] = $folder;
            }
        }

        return $this->sources;
    }


    /**
     * Plugin hook called before rendering the contact form or detail view
     *
     * @param array $p Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function contact_form($p)
    {
        // none of our business
        if (!is_object($GLOBALS['CONTACTS']) || !is_a($GLOBALS['CONTACTS'], 'rcube_kolab_contacts'))
            return $p;

        // extend the list of contact fields to be displayed in the 'personal' section
        if (is_array($p['form']['personal'])) {
            $p['form']['personal']['content']['profession']    = array('size' => 40);
            $p['form']['personal']['content']['children']      = array('size' => 40);
            $p['form']['personal']['content']['freebusyurl']   = array('size' => 40);
            $p['form']['personal']['content']['pgppublickey']  = array('size' => 70);
            $p['form']['personal']['content']['pkcs7publickey'] = array('size' => 70);

            // re-order fields according to the coltypes list
            $p['form']['contact']['content']  = $this->_sort_form_fields($p['form']['contact']['content']);
            $p['form']['personal']['content'] = $this->_sort_form_fields($p['form']['personal']['content']);

            /* define a separate section 'settings'
            $p['form']['settings'] = array(
                'name'    => $this->gettext('settings'),
                'content' => array(
                    'freebusyurl'  => array('size' => 40, 'visible' => true),
                    'pgppublickey' => array('size' => 70, 'visible' => true),
                    'pkcs7publickey' => array('size' => 70, 'visible' => false),
                )
            );
            */
        }

        return $p;
    }


    private function _sort_form_fields($contents)
    {
      $block    = array();
      $contacts = reset($this->sources);

      foreach (array_keys($contacts->coltypes) as $col) {
          if (isset($contents[$col]))
              $block[$col] = $contents[$col];
      }

      return $block;
    }


    /**
     * Handler for user preferences form (preferences_list hook)
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function prefs_list($args)
    {
        if ($args['section'] != 'addressbook') {
            return $args;
        }

        $ldap_public = $this->rc->config->get('ldap_public');
        $abook_type  = $this->rc->config->get('address_book_type');

        // Hide option if there's no global addressbook
        if (empty($ldap_public) || $abook_type != 'ldap') {
            return $args;
        }

        // Check that configuration is not disabled
        $dont_override = (array) $this->rc->config->get('dont_override', array());
        $prio          = $this->addressbook_prio();

        if (!in_array('kolab_addressbook_prio', $dont_override)) {
            // Load localization
            $this->add_texts('localization');

            $field_id = '_kolab_addressbook_prio';
            $select   = new html_select(array('name' => $field_id, 'id' => $field_id));

            $select->add($this->gettext('globalfirst'), self::GLOBAL_FIRST);
            $select->add($this->gettext('personalfirst'), self::PERSONAL_FIRST);
            $select->add($this->gettext('globalonly'), self::GLOBAL_ONLY);
            $select->add($this->gettext('personalonly'), self::PERSONAL_ONLY);

            $args['blocks']['main']['options']['kolab_addressbook_prio'] = array(
                'title' => html::label($field_id, Q($this->gettext('addressbookprio'))),
                'content' => $select->show($prio),
            );
        }

        return $args;
    }

    /**
     * Handler for user preferences save (preferences_save hook)
     *
     * @param array $args Hash array with hook parameters
     *
     * @return array Hash array with modified hook parameters
     */
    public function prefs_save($args)
    {
        if ($args['section'] != 'addressbook') {
            return $args;
        }

        // Check that configuration is not disabled
        $dont_override = (array) $this->rc->config->get('dont_override', array());
        $key           = 'kolab_addressbook_prio';

        if (!in_array('kolab_addressbook_prio', $dont_override) || !isset($_POST['_'.$key])) {
            $args['prefs'][$key] = (int) rcube_utils::get_input_value('_'.$key, rcube_utils::INPUT_POST);
        }

        return $args;
    }


    /**
     * Handler for plugin actions
     */
    public function book_actions()
    {
        $action = trim(rcube_utils::get_input_value('_act', rcube_utils::INPUT_GPC));

        if ($action == 'create') {
            $this->ui->book_edit();
        }
        else if ($action == 'edit') {
            $this->ui->book_edit();
        }
        else if ($action == 'delete') {
            $this->book_delete();
        }
    }


    /**
     * Handler for address book create/edit form submit
     */
    public function book_save()
    {
        $prop = array(
            'name'    => trim(rcube_utils::get_input_value('_name', rcube_utils::INPUT_POST)),
            'oldname' => trim(rcube_utils::get_input_value('_oldname', rcube_utils::INPUT_POST, true)), // UTF7-IMAP
            'parent'  => trim(rcube_utils::get_input_value('_parent', rcube_utils::INPUT_POST, true)), // UTF7-IMAP
            'type'    => 'contact',
            'subscribed' => true,
        );

        $result = $error = false;
        $type = strlen($prop['oldname']) ? 'update' : 'create';
        $prop = $this->rc->plugins->exec_hook('addressbook_'.$type, $prop);

        if (!$prop['abort']) {
            if ($newfolder = kolab_storage::folder_update($prop)) {
                $folder = $newfolder;
                $result = true;
            }
            else {
                $error = kolab_storage::$last_error;
            }
        }
        else {
            $result = $prop['result'];
            $folder = $prop['name'];
        }

        if ($result) {
            $kolab_folder = kolab_storage::get_folder($folder);

            // get folder/addressbook properties
            $abook = new rcube_kolab_contacts($folder);
            $props = $this->abook_prop(kolab_storage::folder_id($folder, true), $abook);
            $props['parent'] = kolab_storage::folder_id($kolab_folder->get_parent(), true);

            $this->rc->output->show_message('kolab_addressbook.book'.$type.'d', 'confirmation');
            $this->rc->output->command('set_env', 'delimiter', $delimiter);
            $this->rc->output->command('book_update', $props, kolab_storage::folder_id($prop['oldname'], true));
            $this->rc->output->send('iframe');
        }

        if (!$error)
            $error = $plugin['message'] ? $plugin['message'] : 'kolab_addressbook.book'.$type.'error';

        $this->rc->output->show_message($error, 'error');
        // display the form again
        $this->ui->book_edit();
    }

    /**
     *
     */
    public function book_search()
    {
        $results = array();
        $query = rcube_utils::get_input_value('q', rcube_utils::INPUT_GPC);
        $source = rcube_utils::get_input_value('source', rcube_utils::INPUT_GPC);

        kolab_storage::$encode_ids = true;
        $search_more_results = false;
        $this->sources = array();
        $this->folders = array();

        // find unsubscribed IMAP folders that have "event" type
        if ($source == 'folders') {
            foreach ((array)kolab_storage::search_folders('contact', $query, array('other')) as $folder) {
                $this->folders[$folder->id] = $folder;
                $this->sources[$folder->id] = new rcube_kolab_contacts($folder->name);
            }
        }
        // search other user's namespace via LDAP
        else if ($source == 'users') {
            $limit = $this->rc->config->get('autocomplete_max', 15) * 2;  // we have slightly more space, so display twice the number
            foreach (kolab_storage::search_users($query, 0, array(), $limit * 10) as $user) {
                $folders = array();
                // search for contact folders shared by this user
                foreach (kolab_storage::list_user_folders($user, 'contact', false) as $foldername) {
                    $folders[] = new kolab_storage_folder($foldername, 'contact');
                }

                if (count($folders)) {
                    $userfolder = new kolab_storage_folder_user($user['kolabtargetfolder'], '', $user);
                    $this->folders[$userfolder->id] = $userfolder;
                    $this->sources[$userfolder->id] = $userfolder;

                    foreach ($folders as $folder) {
                        $this->folders[$folder->id] = $folder;
                        $this->sources[$folder->id] = new rcube_kolab_contacts($folder->name);;
                        $count++;
                    }
                }

                if ($count >= $limit) {
                    $search_more_results = true;
                    break;
                }
            }
        }

        $delim = $this->rc->get_storage()->get_hierarchy_delimiter();

        // build results list
        foreach ($this->sources as $id => $source) {
            $folder = $this->folders[$id];
            $imap_path = explode($delim, $folder->name);

            // find parent
            do {
              array_pop($imap_path);
              $parent_id = kolab_storage::folder_id(join($delim, $imap_path));
            }
            while (count($imap_path) > 1 && !$this->folders[$parent_id]);

            // restore "real" parent ID
            if ($parent_id && !$this->folders[$parent_id]) {
                $parent_id = kolab_storage::folder_id($folder->get_parent());
            }

            $prop = $this->abook_prop($id, $source);
            $prop['parent'] = $parent_id;

            $html = $this->addressbook_list_item($id, $prop, $jsdata, true);
            unset($prop['group']);
            $prop += (array)$jsdata[$id];
            $prop['html'] = $html;

            $results[] = $prop;
        }

        // report more results available
        if ($search_more_results) {
            $this->rc->output->show_message('autocompletemore', 'info');
        }

        $this->rc->output->command('multi_thread_http_response', $results, rcube_utils::get_input_value('_reqid', rcube_utils::INPUT_GPC));
    }

    /**
     *
     */
    public function book_subscribe()
    {
        $success = false;
        $id = rcube_utils::get_input_value('_source', rcube_utils::INPUT_GPC);

        if ($id && ($folder = kolab_storage::get_folder(kolab_storage::id_decode($id)))) {
            if (isset($_POST['_permanent']))
                $success |= $folder->subscribe(intval($_POST['_permanent']));
            if (isset($_POST['_active']))
                $success |= $folder->activate(intval($_POST['_active']));

            // list groups for this address book
            if (!empty($_POST['_groups'])) {
                $abook = new rcube_kolab_contacts($folder->name);
                foreach ((array)$abook->list_groups() as $prop) {
                    $prop['source'] = $id;
                    $prop['id'] = $prop['ID'];
                    unset($prop['ID']);
                    $this->rc->output->command('insert_contact_group', $prop);
                }
            }
        }

        if ($success) {
            $this->rc->output->show_message('successfullysaved', 'confirmation');
        }
        else {
            $this->rc->output->show_message($this->gettext('errorsaving'), 'error');
        }

        $this->rc->output->send();
    }


    /**
     * Handler for address book delete action (AJAX)
     */
    private function book_delete()
    {
        $folder = trim(rcube_utils::get_input_value('_source', rcube_utils::INPUT_GPC, true, 'UTF7-IMAP'));

        if (kolab_storage::folder_delete($folder)) {
            $storage = $this->rc->get_storage();
            $delimiter = $storage->get_hierarchy_delimiter();

            $this->rc->output->show_message('kolab_addressbook.bookdeleted', 'confirmation');
            $this->rc->output->set_env('pagecount', 0);
            $this->rc->output->command('set_rowcount', rcmail_get_rowcount_text(new rcube_result_set()));
            $this->rc->output->command('set_env', 'delimiter', $delimiter);
            $this->rc->output->command('list_contacts_clear');
            $this->rc->output->command('book_delete_done', kolab_storage::folder_id($folder, true));
        }
        else {
            $this->rc->output->show_message('kolab_addressbook.bookdeleteerror', 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Returns value of kolab_addressbook_prio setting
     */
    private function addressbook_prio()
    {
        // Load configuration
        if (!$this->config_loaded) {
            $this->load_config();
            $this->config_loaded = true;
        }

        $abook_prio = (int) $this->rc->config->get('kolab_addressbook_prio');

        // Make sure any global addressbooks are defined
        if ($abook_prio == 0 || $abook_prio == 2) {
            $ldap_public = $this->rc->config->get('ldap_public');
            $abook_type  = $this->rc->config->get('address_book_type');

            if (empty($ldap_public) || $abook_type != 'ldap') {
                $abook_prio = 1;
            }
        }

        return $abook_prio;
    }

    /**
     * Hook for (contact) folder deletion
     */
    function prefs_folder_delete($args)
    {
        // ignore...
        if ($args['abort'] && !$args['result']) {
            return $args;
        }

        $this->_contact_folder_rename($args['name'], false);
    }

    /**
     * Hook for (contact) folder renaming
     */
    function prefs_folder_rename($args)
    {
        // ignore...
        if ($args['abort'] && !$args['result']) {
            return $args;
        }

        $this->_contact_folder_rename($args['oldname'], $args['newname']);
    }

    /**
     * Hook for (contact) folder updates. Forward to folder_rename handler if name was changed
     */
    function prefs_folder_update($args)
    {
        // ignore...
        if ($args['abort'] && !$args['result']) {
            return $args;
        }

        if ($args['record']['name'] != $args['record']['oldname']) {
            $this->_contact_folder_rename($args['record']['oldname'], $args['record']['name']);
        }
    }

    /**
     * Apply folder renaming or deletion to the registered birthday calendar address books
     */
    private function _contact_folder_rename($oldname, $newname = false)
    {
        $update = false;
        $delimiter = $this->rc->get_storage()->get_hierarchy_delimiter();
        $bday_addressbooks = (array)$this->rc->config->get('calendar_birthday_adressbooks', array());

        foreach ($bday_addressbooks as $i => $id) {
            $folder_name = kolab_storage::id_decode($id);
            if ($oldname === $folder_name || strpos($folder_name, $oldname.$delimiter) === 0) {
                if ($newname) {  // rename
                    $new_folder = $newname . substr($folder_name, strlen($oldname));
                    $bday_addressbooks[$i] = kolab_storage::id_encode($new_folder);
                }
                else {  // delete
                    unset($bday_addressbooks[$i]);
                }
                $update = true;
            }
        }

        if ($update) {
            $this->rc->user->save_prefs(array('calendar_birthday_adressbooks' => $bday_addressbooks));
        }
    }

}
