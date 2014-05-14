<?php

/**
 * Class that represents a (virtual) folder in the 'other' namespace
 * implementing a subset of the kolab_storage_folder API.
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2014, Kolab Systems AG <contact@kolabsys.com>
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
class kolab_storage_user_folder extends kolab_storage_virtual_folder
{
    protected static $ldapcache = array();

    public $ldaprec;

    /**
     * Default constructor
     */
    public function __construct($name, $parent = '', $ldaprec = null)
    {
        parent::__construct($name, $name, 'other', $parent);

        if (!empty($ldaprec)) {
            self::$ldapcache[$name] = $this->ldaprec = $ldaprec;
        }
        // use value cached in memory for repeated lookups
        else if (array_key_exists($name, self::$ldapcache)) {
            $this->ldaprec = self::$ldapcache[$name];
        }
        // lookup user in LDAP and set $this->ldaprec
        else if ($ldap = kolab_storage::ldap()) {
            // get domain from current user
            list(,$domain) = explode('@', rcube::get_instance()->get_user_name());
            $this->ldaprec = $ldap->get_user_record(parent::get_foldername($this->name) . '@' . $domain, $_SESSION['imap_host']);
            if (!empty($this->ldaprec)) {
                $this->ldaprec['kolabtargetfolder'] = $name;
            }
            self::$ldapcache[$name] = $this->ldaprec;
        }
    }

    /**
     * Getter for the top-end folder name to be displayed
     *
     * @return string Name of this folder
     */
    public function get_foldername()
    {
        return $this->ldaprec ? ($this->ldaprec['displayname'] ?: $this->ldaprec['name']) :
            parent::get_foldername();
    }

    /**
     * Returns the owner of the folder.
     *
     * @return string  The owner of this folder.
     */
    public function get_owner()
    {
        return $this->ldaprec['mail'];
    }

    /**
     * Check activation status of this folder
     *
     * @return boolean True if enabled, false if not
     */
    public function is_active()
    {
        return kolab_storage::folder_is_active($this->name);
    }

    /**
     * Change activation status of this folder
     *
     * @param boolean The desired subscription status: true = active, false = not active
     *
     * @return True on success, false on error
     */
    public function activate($active)
    {
        return $active ? kolab_storage::folder_activate($this->name) : kolab_storage::folder_deactivate($this->name);
    }

    /**
     * Check subscription status of this folder
     *
     * @return boolean True if subscribed, false if not
     */
    public function is_subscribed()
    {
        return kolab_storage::folder_is_subscribed($this->name, true);
    }

    /**
     * Change subscription status of this folder
     *
     * @param boolean The desired subscription status: true = subscribed, false = not subscribed
     *
     * @return True on success, false on error
     */
    public function subscribe($subscribed)
    {
        return $subscribed ?
            kolab_storage::folder_subscribe($this->name, true) :
            kolab_storage::folder_unsubscribe($this->name, true);
    }

}