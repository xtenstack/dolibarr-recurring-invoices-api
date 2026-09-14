# Dolibarr Recurring Invoices REST API Module (`dolirecurringapi`)

A lightweight, native Dolibarr ERP/CRM module that exposes REST API endpoints for creating and managing recurring invoice templates (`FactureRec`).

---

## The Problem

In standard Dolibarr ERP/CRM (v18 through v24+), the core REST API provides `GET /invoices/templates` to read recurring templates, but **completely lacks a `POST` endpoint to create recurring invoice templates programmatically**.

This module solves that gap by providing first-class REST API endpoints that plug directly into Dolibarr's native Luracast Restler engine (`/api/index.php`). It uses Dolibarr's own `FactureRec::create()`, which copies the customer and every line from the source invoice, so tax rules, discounts, line items, project linkage, extrafields and auto-validation settings carry over exactly as Dolibarr's own "convert to recurring" screen does.

---

## Features

- **Programmatic Recurring Invoice Creation:** Clone any draft or validated invoice into a native `FactureRec` recurring template via a single API call.
- **Flexible Recurrence Scheduling:** Configure frequency (`daily`, `monthly`, `annual`), auto-validation status, maximum generation count, and first execution date.
- **Full Extrafield & Rep Preservation:** Clones invoice options, including representative attribution (`options_primary_representative`) for commission tracking.
- **First-Class Swagger Documentation:** Automatically appears under the `/dolirecurringapi` tag in Dolibarr's native API explorer (`/api/index.php/explorer`).
- **Standard Authentication:** Uses native Dolibarr API key authentication (`DOLAPIKEY`).

---

## Compatibility

- Checked against Dolibarr 23.0 source (the target install runs 23.0.3); older releases are supported via a fallback include path but untested
- PHP 7.4, 8.0, 8.1, 8.2, 8.3+

---

## Installation

### Method 1: Upload via Dolibarr Web Interface (Recommended)
1. Build `dolirecurringapi-1.0.1.zip` with `scripts/package.sh` (Dolibarr's installer requires the `modulename-x.y.z.zip` name).
2. In Dolibarr, go to **Home → Setup → Modules/Applications → Deploy/install external module**.
3. Upload `dolirecurringapi-1.0.1.zip` and click **Install**.
4. In the **Financial Modules** section, locate **DoliRecurringApi** and toggle it to **ON**.

### Method 2: Manual Installation via Filesystem / SSH
1. Copy or extract the `dolirecurringapi` folder into your Dolibarr `custom/` directory:
   ```bash
   cp -r dolirecurringapi /var/www/dolibarr/htdocs/custom/
   ```
2. Ensure permissions allow your web server to read the files.
3. Log into Dolibarr as an Administrator.
4. Go to **Home → Setup → Modules/Applications**.
5. Find **DoliRecurringApi** and enable it.

---

## API Endpoints

All endpoints require the `DOLAPIKEY` HTTP header.

### 1. Create Recurring Template From Existing Invoice

Create an active recurring subscription blueprint from an existing invoice:

```http
POST /api/index.php/dolirecurringapi/create-from-invoice
```
*(Also available at `/api/index.php/dolirecurringapi/from-invoice`)*

#### Request Headers
```http
DOLAPIKEY: your-api-key-here
Content-Type: application/json
```

#### Request Body
```json
{
  "invoice_id": 20,
  "title": "Featured Listing Monthly Subscription — Acme Pty Ltd",
  "frequency": 1,
  "unit": "m",
  "auto_validate": 1,
  "nb_gen_max": 0,
  "date_when": "2026-10-14"
}
```

| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `invoice_id` | `int` | **Yes** | Database ID of the source invoice. |
| `title` | `string` | No | Title for the recurring template (defaults to `{invoice_ref} - Recurring`). |
| `frequency` | `int` | No | Recurrence multiplier (default `1`). |
| `unit` | `string` | No | Frequency unit: `'d'` (day), `'m'` (month), `'y'` (year). Default `'m'`. |
| `auto_validate` | `int` | No | `1` to automatically validate generated recurring invoices, `0` for draft status. Default `1`. |
| `nb_gen_max` | `int` | No | Maximum number of generations (`0` for unlimited). Default `0`. |
| `date_when` | `string` | No | Date of first recurring execution (`YYYY-MM-DD`). Default: current date + frequency. |

#### Response (`200 OK`)
```json
{
  "success": true,
  "id": 4,
  "title": "Featured Listing Monthly Subscription — Acme Pty Ltd",
  "socid": 18,
  "frequency": 1,
  "unit_frequency": "m",
  "auto_validate": 1,
  "date_when": "2026-10-14"
}
```

---

### 2. List Recurring Templates

```http
GET /api/index.php/dolirecurringapi/templates?limit=50&page=0
```

#### Response (`200 OK`)
Returns an array of existing `FactureRec` templates.

---

### 3. Get Template Details

```http
GET /api/index.php/dolirecurringapi/templates/{id}
```

---

## Automated Invoice Generation (Dolibarr Native Cron)

Once templates are created, Dolibarr’s native **Scheduled Jobs** (`cron`) engine automatically generates the renewal invoices on their execution dates:

1. Ensure the **Scheduled Jobs** module is active (**Home → Setup → Modules → Scheduled Jobs**).
2. Check that the job **"Generation of recurring invoices"** (`cron_recurring_invoices.php`) is active.
3. Configure your server's crontab or an external uptime pinger to call the Dolibarr cron runner periodically:
   ```bash
   # CLI Crontab:
   */15 * * * * php /var/www/dolibarr/scripts/cron/cron_run_jobs.php security_key admin > /dev/null 2>&1

   # Or via Web Cron (URL ping):
   curl -s "https://your-dolibarr-domain/public/cron/cron_run_jobs.php?securitykey=your-security-key"
   ```

---

## Client Integration Examples

### PHP / cURL
```php
$payload = json_encode([
    'invoice_id'    => 20,
    'title'         => 'Featured Listing - Annual',
    'frequency'     => 1,
    'unit'          => 'y',
    'auto_validate' => 1,
]);

$ch = curl_init('https://accts.example.com/dolibarr/api/index.php/dolirecurringapi/create-from-invoice');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'DOLAPIKEY: ' . $apiToken,
        'Content-Type: application/json',
    ],
]);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);
```

### Python / Requests
```python
import requests

url = "https://accts.example.com/dolibarr/api/index.php/dolirecurringapi/create-from-invoice"
headers = {
    "DOLAPIKEY": "your_api_token",
    "Content-Type": "application/json"
}
payload = {
    "invoice_id": 20,
    "title": "Prominent Listing Monthly",
    "frequency": 1,
    "unit": "m",
    "auto_validate": 1
}

res = requests.post(url, json=payload, headers=headers)
template = res.json()
print(f"Created Recurring Template ID: {template['id']}")
```

---

## License

GNU General Public License v3.0 (GPL-3.0). See [LICENSE](LICENSE) for details.

Published by **XTen Stack** (<https://xten.au>).
