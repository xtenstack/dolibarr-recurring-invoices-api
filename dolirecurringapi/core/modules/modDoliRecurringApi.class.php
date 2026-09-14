<?php
/* Copyright (C) 2026 XTen Stack <dev@xten.au>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \defgroup   dolirecurringapi  Module DoliRecurringApi
 * \brief      Dolibarr module providing REST API endpoints for recurring invoice templates.
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Descriptor class for DoliRecurringApi module
 */
class modDoliRecurringApi extends DolibarrModules
{
    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;
        $this->numero = 500300; // Unique module ID within Dolibarr ecosystem
        $this->rights_class = 'dolirecurringapi';
        $this->family = 'financial';
        $this->module_position = '50';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Provides REST API endpoints for creating and managing recurring invoice templates (FactureRec)";
        $this->editor_name = "XTen Stack";
        $this->editor_url = "https://xten.au";
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'bill';

        // Parts of module
        $this->module_parts = array(
            'api' => 1,
        );

        $this->dirs = array();

        // Dependencies
        $this->depends = array('modFacture');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array();

        // Constants
        $this->const = array();

        // Permissions
        $this->rights = array();
        $r = 0;

        $r++;
        $this->rights[$r][0] = $this->numero . '1';
        $this->rights[$r][1] = 'Read recurring invoice templates via API';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'read';

        $r++;
        $this->rights[$r][0] = $this->numero . '2';
        $this->rights[$r][1] = 'Create recurring invoice templates via API';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'create';
    }
}
