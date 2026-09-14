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

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
// Renamed facturerec.class.php -> facture-rec.class.php in newer Dolibarr releases.
if (file_exists(DOL_DOCUMENT_ROOT . '/compta/facture/class/facture-rec.class.php')) {
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture-rec.class.php';
} else {
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facturerec.class.php';
}

/**
 * API class for recurring invoices (FactureRec)
 *
 * Exposes REST endpoints to create and manage recurring invoice templates
 * programmatically, filling a long-standing gap in Dolibarr's core REST API.
 *
 * @smart-auto-routing false
 */
class DoliRecurringApi extends DolibarrApi
{
    /**
     * @var array $FIELDS Cleaned fields exposed in API responses
     */
    public static $FIELDS = array(
        'id',
        'title',
        'socid',
        'total_ht',
        'total_tva',
        'total_ttc',
        'frequency',
        'unit_frequency',
        'date_when',
        'nb_gen_max',
        'auto_validate',
    );

    /**
     * Constructor
     */
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * List recurring invoice templates
     *
     * @param string $sortfield Sort field (default 't.rowid') {@from query}
     * @param string $sortorder Sort order (default 'ASC') {@from query}
     * @param int    $limit     Number of records to return {@from query}
     * @param int    $page      Page index (starts at 0) {@from query}
     *
     * @url GET /templates
     *
     * @return array List of recurring templates
     * @throws RestException 403, 500
     */
    public function getTemplates($sortfield = 't.rowid', $sortorder = 'ASC', $limit = 100, $page = 0)
    {
        if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
            throw new RestException(403, 'Permission denied: facture->lire required');
        }

        // FactureRec has no fetchAll() (checked in Dolibarr 23.0 and 24.0), so
        // list the same way core's GET /invoices/templates does: select the
        // template ids, then fetch each one.
        $sql = "SELECT t.rowid";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture_rec AS t";
        $sql .= " WHERE t.entity IN (" . getEntity('invoice') . ")";
        $sql .= $this->db->order($sortfield, $sortorder);
        if ($limit) {
            if ($page < 0) {
                $page = 0;
            }
            $sql .= $this->db->plimit((int) $limit, (int) $limit * (int) $page);
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(503, 'Error when retrieving recurring invoice templates: ' . $this->db->lasterror());
        }

        $templates = array();
        while ($row = $this->db->fetch_object($resql)) {
            $template = new FactureRec($this->db);
            if ($template->fetch((int) $row->rowid) > 0) {
                $templates[] = $this->_cleanObjectDatas($template);
            }
        }

        return $templates;
    }

    /**
     * Get details of a recurring invoice template by ID
     *
     * @param int $id Template record ID
     *
     * @url GET /templates/{id}
     *
     * @return array Template details
     * @throws RestException 403, 404, 500
     */
    public function getTemplate($id)
    {
        if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
            throw new RestException(403, 'Permission denied: facture->lire required');
        }

        $obj = new FactureRec($this->db);
        $result = $obj->fetch((int) $id);
        if ($result < 0) {
            throw new RestException(500, 'Failed to fetch template: ' . $obj->error);
        }
        if ($result == 0) {
            throw new RestException(404, 'Recurring invoice template not found: ' . $id);
        }

        return $this->_cleanObjectDatas($obj);
    }

    /**
     * Create a recurring invoice template from an existing invoice (draft or validated).
     *
     * Clones all header fields, line items, taxes, discounts, and extrafields into
     * a native FactureRec record.
     *
     * @param int    $invoice_id    Source invoice ID to convert into a template {@from body}
     * @param string $title         Optional title for the recurring template {@from body}
     * @param int    $frequency     Frequency number (e.g. 1 for every 1 month/year) {@from body}
     * @param string $unit          Frequency unit: 'm' (month), 'y' (year), 'd' (day) {@from body}
     * @param int    $auto_validate 1 to auto-validate generated invoices, 0 for draft {@from body}
     * @param int    $nb_gen_max    Maximum number of generations (0 = unlimited) {@from body}
     * @param string $date_when     First execution date (YYYY-MM-DD), default NOW + frequency {@from body}
     *
     * @url POST /from-invoice
     * @url POST /create-from-invoice
     *
     * @return array Created template details including ID and next execution date
     * @throws RestException 400, 403, 404, 500
     */
    public function createFromInvoice($invoice_id, $title = '', $frequency = 1, $unit = 'm', $auto_validate = 1, $nb_gen_max = 0, $date_when = '')
    {
        if (!DolibarrApiAccess::$user->hasRight('facture', 'creer')) {
            throw new RestException(403, 'Permission denied: facture->creer required');
        }

        if (empty($invoice_id)) {
            throw new RestException(400, 'Parameter invoice_id is required');
        }

        $facture = new Facture($this->db);
        $result = $facture->fetch((int) $invoice_id);
        if ($result <= 0) {
            throw new RestException(404, 'Source invoice not found: ' . $invoice_id);
        }

        $facturerec = new FactureRec($this->db);

        // FactureRec::create($user, $facid) copies the customer and every line
        // (products, qty, prices, taxes, discounts) from the source invoice
        // itself, so only the template's own settings are set here.
        $facturerec->title             = !empty($title) ? $title : ($facture->ref . ' - Recurring');
        $facturerec->titre             = $facturerec->title; // deprecated alias, still read by older releases
        $facturerec->fk_project        = $facture->fk_project;
        $facturerec->frequency         = (int) $frequency > 0 ? (int) $frequency : 1;
        $facturerec->unit_frequency    = in_array($unit, array('d', 'm', 'y')) ? $unit : 'm';
        $facturerec->auto_validate     = (int) $auto_validate;
        $facturerec->nb_gen_max        = (int) $nb_gen_max;
        $facturerec->cond_reglement_id = $facture->cond_reglement_id;
        $facturerec->mode_reglement_id = $facture->mode_reglement_id;
        $facturerec->fk_account        = $facture->fk_account;
        $facturerec->note_public       = $facture->note_public;
        $facturerec->note_private      = $facture->note_private;
        $facturerec->model_pdf         = $facture->model_pdf;

        // Next execution date
        if (!empty($date_when)) {
            $facturerec->date_when = strtotime($date_when);
        } else {
            // Default: today + frequency
            $facturerec->date_when = dol_time_plus_duree(dol_now(), $facturerec->frequency, $facturerec->unit_frequency);
        }

        // Copy extrafields (options_primary_representative, etc.)
        if (!empty($facture->array_options)) {
            $facturerec->array_options = $facture->array_options;
        }

        $template_id = $facturerec->create(DolibarrApiAccess::$user, (int) $facture->id);
        if ($template_id <= 0) {
            throw new RestException(500, 'Failed to create recurring invoice template: ' . $facturerec->error);
        }

        return array(
            'success'        => true,
            'id'             => (int) $template_id,
            'title'          => $facturerec->title,
            'socid'          => (int) $facturerec->socid,
            'frequency'      => (int) $facturerec->frequency,
            'unit_frequency' => $facturerec->unit_frequency,
            'auto_validate'  => (int) $facturerec->auto_validate,
            'date_when'      => dol_print_date($facturerec->date_when, 'day'),
        );
    }
}

/**
 * Alias for Dolibarr's per-call API router.
 *
 * For /api/index.php/dolirecurringapi/..., api/index.php strips the trailing
 * "api" to find this file (class/api_dolirecurring.class.php), then looks for
 * ucwords('dolirecurringapi') . 'Api' = DolirecurringapiApi. The API explorer
 * derives "Dolirecurring" from the file name and looks for DolirecurringApi,
 * which DoliRecurringApi above already is: PHP class names are
 * case-insensitive, so declaring DolirecurringApi separately is a fatal
 * "Cannot declare class" error.
 */
class DolirecurringapiApi extends DoliRecurringApi
{
}
