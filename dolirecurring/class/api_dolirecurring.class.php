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
 * Restler reads THIS docblock (the one immediately above the class). The two
 * annotations below are what make Dolibarr authenticate the call and populate
 * DolibarrApiAccess::$user -- without them every method fatals with
 * "Call to a member function hasRight() on null" (1.0.0-1.0.3 all lacked
 * them; the routing 404 hid it until 1.0.4).
 *
 * Routing (1.0.4): api/index.php strips a trailing "api" from the URL segment
 * to find custom/dolirecurring/class/api_dolirecurring.class.php, registers
 * ucwords('dolirecurringapi') -- this class, case-insensitively -- and Restler
 * prefixes the routes with the lower-cased class name it was given. So the
 * live base path is /api/index.php/dolirecurringapi/... and this file must
 * declare exactly ONE API class: the "DolirecurringapiApi" alias of 1.0.1-1.0.3
 * won the class_exists($classname.'Api') check and was registered under the
 * prefix "dolirecurringapiapi", so every real call 404'd ("Not Found") while
 * the explorer, which registers by file name, still listed the routes.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
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
     * @param int    $source_invoice Only templates created from this invoice id (see create-from-invoice) {@from query}
     *
     * @url GET /templates
     *
     * @return array List of recurring templates
     * @throws RestException 403, 500
     */
    public function getTemplates($sortfield = 't.rowid', $sortorder = 'ASC', $limit = 100, $page = 0, $source_invoice = 0)
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
        if ((int) $source_invoice > 0) {
            // create-from-invoice stamps the source id into the template's
            // private note (facture_rec has no column for it).
            $sql .= " AND t.note_private LIKE '%" . $this->db->escape(self::sourceMarker((int) $source_invoice)) . "%'";
        }
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
        // FactureRec::fetch() returns -1 with "... not found" for a missing
        // row (not 0), so map that to 404 and keep 500 for real DB errors.
        if ($result <= 0 && ($result == 0 || stripos((string) $obj->error, 'not found') !== false)) {
            throw new RestException(404, 'Recurring invoice template not found: ' . $id);
        }
        if ($result < 0) {
            throw new RestException(500, 'Failed to fetch template: ' . $obj->error);
        }

        return $this->_cleanObjectDatas($obj);
    }

    /**
     * Delete a recurring invoice template.
     *
     * Dolibarr's core API can list templates (GET /invoices/templates) but
     * cannot delete one; without this an API-created template can only be
     * removed in the UI. Invoices already generated from it are untouched.
     *
     * @param int $id ID of the recurring invoice template
     * @return array
     *
     * @url DELETE /templates/{id}
     *
     * @throws RestException 403, 404, 500
     */
    public function deleteTemplate($id)
    {
        if (!DolibarrApiAccess::$user->hasRight('facture', 'supprimer')) {
            throw new RestException(403, 'Permission denied: facture->supprimer required');
        }

        $obj = new FactureRec($this->db);
        $result = $obj->fetch((int) $id);
        // FactureRec::fetch() returns -1 with "... not found" for a missing
        // row (not 0), so map that to 404 and keep 500 for real DB errors.
        if ($result <= 0 && ($result == 0 || stripos((string) $obj->error, 'not found') !== false)) {
            throw new RestException(404, 'Recurring invoice template not found: ' . $id);
        }
        if ($result < 0) {
            throw new RestException(500, 'Failed to fetch template: ' . $obj->error);
        }

        if ($obj->delete(DolibarrApiAccess::$user) <= 0) {
            throw new RestException(500, 'Failed to delete recurring invoice template: ' . $obj->error);
        }

        return array('success' => array('code' => 200, 'message' => 'Recurring invoice template ' . $id . ' deleted'));
    }

    /**
     * Marker written into a template's private note by create-from-invoice.
     *
     * @param int $invoiceId Source invoice id
     * @return string
     */
    private static function sourceMarker($invoiceId)
    {
        return '[source-invoice:' . (int) $invoiceId . ']';
    }

    /**
     * Classify a validated, unpaid invoice as abandoned (Dolibarr's own
     * "Classify abandoned" action, close code "abandon").
     *
     * Core's API can validate, set paid/unpaid and set back to draft, but has
     * no route for abandoning. Needed for the recurring flow: a checkout that
     * creates its invoice and template before payment must be able to
     * withdraw both if the customer never pays.
     *
     * @param int    $id         Invoice id
     * @param string $close_note Reason recorded on the invoice {@from body}
     * @return array
     *
     * @url POST /invoices/{id}/abandon
     *
     * @throws RestException 400, 403, 404, 500
     */
    public function abandonInvoice($id, $close_note = '')
    {
        if (!DolibarrApiAccess::$user->hasRight('facture', 'creer')) {
            throw new RestException(403, 'Permission denied: facture->creer required');
        }

        $facture = new Facture($this->db);
        $result = $facture->fetch((int) $id);
        if ($result <= 0) {
            throw new RestException(404, 'Invoice not found: ' . $id);
        }
        if ((int) $facture->statut !== Facture::STATUS_VALIDATED) {
            throw new RestException(400, 'Only a validated invoice can be abandoned (status is ' . $facture->statut . ')');
        }
        if (!empty($facture->paye)) {
            throw new RestException(400, 'Invoice is already paid');
        }

        if ($facture->setCanceled(DolibarrApiAccess::$user, Facture::CLOSECODE_ABANDONED, (string) $close_note) <= 0) {
            throw new RestException(500, 'Failed to abandon invoice: ' . $facture->error);
        }

        return array('success' => array('code' => 200, 'message' => 'Invoice ' . $facture->ref . ' classified abandoned'));
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
     * @param int    $cond_reglement_id Payment term id for generated invoices; default: source invoice's, else the customer's default, else 1 (due upon receipt) {@from body}
     * @param int    $mode_reglement_id Payment mode id for generated invoices; default: source invoice's, else the customer's default {@from body}
     * @param string $note_public   Public note for the template and every invoice generated from it; default: copied from the source invoice. Pass it when the source note carries something invoice-specific (a pay-online link for THAT invoice) that must not repeat on every recurrence {@from body}
     * @param array  $lines         Optional replacement lines for the template (1.0.6). When given, the lines copied from the source invoice are replaced by these, so the recurring charge can differ from the first invoice (an introductory price, a first month covered by a voucher). Each line: qty and subprice (unit price excluding tax) are required; fk_product, desc, tva_tx, remise_percent, product_type and label are optional. tva_tx defaults to the source line with the same fk_product, else the product's own rate; a line with neither fk_product nor tva_tx is refused {@from body}
     *
     * @url POST /from-invoice
     * @url POST /create-from-invoice
     *
     * @return array Created template details including ID and next execution date
     * @throws RestException 400, 403, 404, 500
     */
    public function createFromInvoice($invoice_id, $title = '', $frequency = 1, $unit = 'm', $auto_validate = 1, $nb_gen_max = 0, $date_when = '', $cond_reglement_id = 0, $mode_reglement_id = 0, $note_public = null, $lines = array())
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

        // Checked before anything is written, so a bad line is a 400 and
        // not a half-made template.
        $newLines = $this->normaliseLines($lines, $facture);

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
        // llx_facture_rec.fk_cond_reglement is NOT NULL, but an API-created
        // source invoice often has no payment term (neither of XTen's own
        // clients set one, and "Column 'fk_cond_reglement' cannot be null"
        // was the live failure on 2026-09-15). Fall back: explicit parameter,
        // source invoice, customer's default, then 1 = due upon receipt.
        $facture->fetch_thirdparty();
        $thirdpartyCond = !empty($facture->thirdparty->cond_reglement_id) ? (int) $facture->thirdparty->cond_reglement_id : 0;
        $thirdpartyMode = !empty($facture->thirdparty->mode_reglement_id) ? (int) $facture->thirdparty->mode_reglement_id : 0;
        $facturerec->cond_reglement_id = (int) $cond_reglement_id > 0 ? (int) $cond_reglement_id
            : ((int) $facture->cond_reglement_id > 0 ? (int) $facture->cond_reglement_id : ($thirdpartyCond > 0 ? $thirdpartyCond : 1));
        $facturerec->mode_reglement_id = (int) $mode_reglement_id > 0 ? (int) $mode_reglement_id
            : ((int) $facture->mode_reglement_id > 0 ? (int) $facture->mode_reglement_id : $thirdpartyMode);
        $facturerec->fk_account        = $facture->fk_account;
        $facturerec->note_public       = $note_public !== null ? (string) $note_public : $facture->note_public;
        // Link back to the source invoice so it can be found (and deleted)
        // later: GET /templates?source_invoice=<id>.
        $facturerec->note_private      = trim((string) $facture->note_private . "\n" . self::sourceMarker((int) $facture->id));
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

        // One transaction around the create and the line replacement: a
        // template must never be left holding the source invoice's prices
        // when the caller asked for different ones.
        $this->db->begin();

        $template_id = $facturerec->create(DolibarrApiAccess::$user, (int) $facture->id);
        if ($template_id <= 0) {
            $this->db->rollback();
            throw new RestException(500, 'Failed to create recurring invoice template: ' . $facturerec->error);
        }

        if (!empty($newLines)) {
            $error = $this->replaceLines((int) $template_id, $newLines);
            if ($error !== '') {
                $this->db->rollback();
                throw new RestException(500, 'Failed to set the template lines, nothing was created: ' . $error);
            }
        }

        $this->db->commit();

        $created = new FactureRec($this->db);
        $created->fetch((int) $template_id);

        return array(
            'success'        => true,
            'id'             => (int) $template_id,
            'title'          => $facturerec->title,
            'socid'          => (int) $facturerec->socid,
            'frequency'      => (int) $facturerec->frequency,
            'unit_frequency' => $facturerec->unit_frequency,
            'auto_validate'  => (int) $facturerec->auto_validate,
            'date_when'      => dol_print_date($facturerec->date_when, 'day'),
            'lines_replaced' => !empty($newLines),
            'total_ht'       => (float) $created->total_ht,
            'total_ttc'      => (float) $created->total_ttc,
        );
    }

    /**
     * Validate the optional replacement lines and fill each line's tax
     * rate. Returns an empty array when none were given.
     *
     * @param mixed   $lines   The request's lines value
     * @param Facture $facture Source invoice (its lines supply default tax rates)
     * @return array<int, array<string, mixed>>
     * @throws RestException 400
     */
    private function normaliseLines($lines, $facture)
    {
        if (empty($lines)) {
            return array();
        }

        if (!is_array($lines)) {
            throw new RestException(400, 'lines must be an array of line objects');
        }

        if (empty($facture->lines)) {
            $facture->fetch_lines();
        }

        $sourceRates = array();
        foreach ((array) $facture->lines as $sourceLine) {
            if (!empty($sourceLine->fk_product) && !isset($sourceRates[(int) $sourceLine->fk_product])) {
                $sourceRates[(int) $sourceLine->fk_product] = (float) $sourceLine->tva_tx;
            }
        }

        $out = array();
        foreach (array_values($lines) as $i => $line) {
            $n    = $i + 1;
            $line = (array) $line;

            if (!isset($line['qty']) || !is_numeric($line['qty']) || (float) $line['qty'] <= 0) {
                throw new RestException(400, "lines[$n]: qty is required and must be greater than 0");
            }
            if (!isset($line['subprice']) || !is_numeric($line['subprice']) || (float) $line['subprice'] < 0) {
                throw new RestException(400, "lines[$n]: subprice (unit price excluding tax) is required and cannot be negative");
            }
            if (isset($line['remise_percent']) && (!is_numeric($line['remise_percent']) || (float) $line['remise_percent'] < 0 || (float) $line['remise_percent'] > 100)) {
                throw new RestException(400, "lines[$n]: remise_percent must be between 0 and 100");
            }

            $fkProduct = !empty($line['fk_product']) ? (int) $line['fk_product'] : 0;
            $desc      = isset($line['desc']) ? trim((string) $line['desc']) : '';
            $label     = isset($line['label']) ? trim((string) $line['label']) : '';
            $type      = isset($line['product_type']) ? (int) $line['product_type'] : 0;
            $rate      = null;

            if ($fkProduct > 0) {
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $product = new Product($this->db);
                if ($product->fetch($fkProduct) <= 0) {
                    throw new RestException(400, "lines[$n]: product $fkProduct not found");
                }
                if (!isset($line['product_type'])) {
                    $type = (int) $product->type;
                }
                $rate = isset($sourceRates[$fkProduct]) ? $sourceRates[$fkProduct] : (float) $product->tva_tx;
            } elseif ($desc === '') {
                throw new RestException(400, "lines[$n]: give fk_product or desc");
            }

            if (isset($line['tva_tx'])) {
                if (!is_numeric($line['tva_tx']) || (float) $line['tva_tx'] < 0) {
                    throw new RestException(400, "lines[$n]: tva_tx must be a number");
                }
                $rate = (float) $line['tva_tx'];
            }
            if ($rate === null) {
                throw new RestException(400, "lines[$n]: tva_tx is required on a line without fk_product");
            }

            $out[] = array(
                'fk_product'     => $fkProduct,
                'desc'           => $desc,
                'label'          => $label,
                'qty'            => (float) $line['qty'],
                'subprice'       => (float) $line['subprice'],
                'tva_tx'         => $rate,
                'remise_percent' => isset($line['remise_percent']) ? (float) $line['remise_percent'] : 0,
                'product_type'   => $type,
            );
        }

        return $out;
    }

    /**
     * Replace a template's lines. The caller owns the transaction.
     *
     * @param int                              $templateId Template id
     * @param array<int, array<string, mixed>> $newLines   From normaliseLines()
     * @return string Empty on success, else what failed
     */
    private function replaceLines($templateId, array $newLines)
    {
        $template = new FactureRec($this->db);
        if ($template->fetch($templateId) <= 0) {
            return 'template ' . $templateId . ' could not be read back: ' . $template->error;
        }
        $template->fetch_thirdparty();

        foreach ((array) $template->lines as $copied) {
            if ($copied->delete(DolibarrApiAccess::$user) < 0) {
                return 'a copied line could not be removed: ' . implode('; ', (array) $copied->errors);
            }
        }
        $template->lines = array();

        foreach ($newLines as $rang => $line) {
            $result = $template->addline(
                $line['desc'],
                $line['subprice'],
                $line['qty'],
                $line['tva_tx'],
                0,
                0,
                $line['fk_product'],
                $line['remise_percent'],
                'HT',
                0,
                0,
                0,
                $line['product_type'],
                $rang + 1,
                0,
                $line['label']
            );
            if ($result <= 0) {
                return 'line ' . ($rang + 1) . ' could not be added: ' . $template->error;
            }
        }

        return '';
    }
}
