<?php

/**
 * Kolab Contact model class
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_format_contact extends kolab_format
{
    public $CTYPE = 'application/x-vnd.kolab.contact';

    protected $xmltype = 'contact';

    public static $fulltext_cols = array('name', 'firstname', 'surname', 'middlename', 'email');

    public $phonetypes = array(
        'home'    => 'home1',
        'work'    => 'business1',
        'text'    => 'text',
        'main'    => 'primary',
        'homefax' => 'homefax',
        'workfax' => 'businessfax',
        'mobile'  => 'mobile',
        'isdn'    => 'isdn',
        'pager'   => 'pager',
        'car'     => 'car',
        'company' => 'company',
        'radio'   => 'radio',
        'telex'   => 'telex',
        'ttytdd'  => 'ttytdd',
        'other'   => 'other',
        'assistant' => 'assistant',
        'callback'  => 'callback',
    );

    public $addresstypes = array(
        'home' => 'home',
        'work' => 'business',
        'other' => 'other',
        'office' => 0,
    );

    // old Kolab 2 format field map
    private $kolab2_fieldmap = array(
      // kolab       => roundcube
      'nick-name'    => 'nickname',
      'organization' => 'organization',
      'department'   => 'department',
      'job-title'    => 'jobtitle',
      'birthday'     => 'birthday',
      'anniversary'  => 'anniversary',
      'phone'        => 'phone',
      'im-address'   => 'im',
      'web-page'     => 'website',
      'profession'   => 'profession',
      'manager-name' => 'manager',
      'assistant'    => 'assistant',
      'spouse-name'  => 'spouse',
      'children'     => 'children',
      'body'         => 'notes',
      'free-busy-url' => 'freebusyurl',
      'picture'       => 'photo',
    );
    private $kolab2_fieldmap_name = array(
        'full-name'    => 'name',
        'given-name'   => 'firstname',
        'middle-names' => 'middlename',
        'last-name'    => 'surname',
        'prefix'       => 'prefix',
        'suffix'       => 'suffix',
    );
    private $kolab2_phonetypes = array(
        'home1' => 'home',
        'business1' => 'work',
        'business2' => 'work',
        'businessfax' => 'workfax',
    );
    private $kolab2_addresstypes = array(
        'business' => 'work'
    );
    private $kolab2_arrays = array(
        'web-page' => 'url',
        'im-address' => true,
        'manager-name' => true,
        'assistant' => true,
        'children' => true,
    );
    private $kolab2_gender = array(0 => 'male', 1 => 'female');


    /**
     * Default constructor
     */
    function __construct($xmldata = null, $subtype = null)
    {
        parent::__construct($xmldata, $subtype);
    }

    /**
     * Set contact properties to the kolabformat object
     *
     * @param array  Contact data as hash array
     */
    public function set(&$object)
    {
        $this->init();

        if ($object['uid'])
            $this->kolab_object['uid'] = $object['uid'];

        $object['changed'] = new DateTime();

        // map name fields rcube => $kolab
        foreach ($this->kolab2_fieldmap_name as $kolab => $rcube) {
            $this->kolab_object['name'][$kolab] = $object[$rcube];
        }

        // map fields rcube => $kolab
        foreach ($this->kolab2_fieldmap as $kolab => $rcube) {
            $this->kolab_object[$kolab] = $object[$rcube];
        }

        // map gener values
        if (isset($object['gender'])) {
            $gender_map = array_flip($this->kolab2_gender);
            $this->kolab_object['gender'] = $gender_map[$object['gender']];
        }

        // format dates
        foreach (array('birthday','anniversary') as $col) {
            if (!empty($object[$col])) {
                try {
                    $tz = new DateTimeZone(rcube::get_instance()->config->get('timezone'));
                    $dt = new DateTime($object[$col], $tz);
                    $this->kolab_object[$col] = $dt;
                }
                catch (Exception $e) {
                    rcube::raise_error($e, true);
                }
            }
            else
                $this->kolab_object[$col] = null;
        }

        // make sure these attributes are single string values
        foreach ($this->kolab2_arrays as $col => $field) {
            if (!is_array($this->kolab_object[$col]))
                continue;
            if ($field === true) {
                $values = $this->kolab_object[$col];
            }
            else {
                $values = array();
                foreach ($this->kolab_object[$col] as $v)
                    $values[] = $v[$field];
            }
            $this->kolab_object[$col] = join('; ', $values);
        }

        // save email addresses as simple-person attributes
        $emails = array();
        foreach ((array)$object['email'] as $email) {
            if (!empty($email))
                $emails[] = array('smtp-address' => $email);
        }
        $this->kolab_object['email'] = $emails;

        // map phone types
        foreach ((array)$this->kolab_object['phone'] as $i => $phone) {
            if ($type = $this->phonetypes[$phone['type']])
                $this->kolab_object['phone'][$i]['type'] = $type;
        }

        // save addresses (how weird is that?!)
        $this->kolab_object['address'] = array();
        foreach ((array)$object['address'] as $adr) {
            if ($type = $this->addresstypes[$adr['type']]) {
                $this->kolab_object['address'][] = array(
                    'type'     => $type,
                    'street'   => $adr['street'],
                    'locality' => $adr['locality'],
                    'postal-code' => $adr['code'],
                    'region'   => $adr['region'],
                    'country'  => $adr['country'],
                );
            }
            else if ($adr['type'] == 'office') {
                $this->kolab_object['office-location'] = $adr['locality'];
            }
        }

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    /**
     *
     */
    public function is_valid()
    {
        return !empty($this->data['uid']);
    }

    /**
     * Callback for kolab_storage_cache to get words to index for fulltext search
     *
     * @return array List of words to save in cache
     */
    public function get_words()
    {
        $data = '';
        foreach (self::$fulltext_cols as $col) {
            $val = is_array($this->data[$col]) ? join(' ', $this->data[$col]) : $this->data[$col];
            if (strlen($val))
                $data .= $val . ' ';
        }

        return array_unique(rcube_utils::normalize_string($data, true));
    }

    /**
     * Load data from old Kolab2 format
     *
     * @param array Hash array with object properties
     */
    public function fromkolab2($record)
    {
        $object = array(
          'uid' => $record['uid'],
          'changed' => $record['last-modification-date'],
          'email' => array(),
          'phone' => array(),
        );

        // map name fields rcube => $kolab
        foreach ($this->kolab2_fieldmap_name as $kolab => $rcube) {
            $object[$rcube] = $record['name'][$kolab];
        }

        foreach ($this->kolab2_fieldmap as $kolab => $rcube) {
            if (is_object($record[$kolab]) && is_a($record[$kolab], 'DateTime')) {
                $object[$rcube] = $record[$kolab];
            }
            else if (is_array($record[$kolab]) || strlen($record[$kolab])) {
                $object[$rcube] = $record[$kolab];

                // split pseudo-arry values
                if ($field = $this->kolab2_arrays[$kolab]) {
                    if ($field === true) {
                        $object[$rcube] = explode('; ', $record[$kolab]);
                    }
                    else {
                        $values = array();
                        foreach (explode('; ', $record[$kolab]) as $v)
                            $values[] = array($field => $v);
                        $object[$rcube] = $values;
                    }
                }
            }
        }

        if (isset($record['gender']))
            $object['gender'] = $this->kolab2_gender[$record['gender']];

        foreach ((array)$record['email'] as $i => $email)
            $object['email'][] = $email['smtp-address'];

        if (!$record['email'] && $record['emails'])
            $object['email'] = preg_split('/,\s*/', $record['emails']);

        if (is_array($record['address'])) {
            $kolab2_addresstypes = array_flip($this->addresstypes);
            foreach ($record['address'] as $i => $adr) {
                $object['address'][] = array(
                    'type' => $kolab2_addresstypes[$adr['type']] ? $kolab2_addresstypes[$adr['type']] : $adr['type'],
                    'street' => $adr['street'],
                    'locality' => $adr['locality'],
                    'code' => $adr['postal-code'],
                    'region' => $adr['region'],
                    'country' => $adr['country'],
                );
            }
        }

        // map Kolab format phone types to Roundcube types
        if (!empty($object['phone'])) {
            $kolab2_phonetypes = array_merge(array_flip($this->phonetypes), $this->kolab2_phonetypes);
            foreach ($object['phone'] as $i => $phone) {
                if ($type = $kolab2_phonetypes[$phone['type']])
                    $object['phone'][$i]['type'] = $type;
            }
        }

        // office location goes into an address block
        if ($record['office-location'])
            $object['address'][] = array('type' => 'office', 'locality' => $record['office-location']);

        // merge initials into nickname
        if ($record['initials'])
            $object['nickname'] = trim($object['nickname'] . ', ' . $record['initials'], ', ');

        // remove empty fields
        $this->data = array_filter($object);
    }

}
