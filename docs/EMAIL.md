# Email Attachment Contract (NFS-e Module)

**Last Updated:** 2026-04-15  
**Status:** Implemented (Cycle 1)  
**PR:** LibreCodeCoop/akaunting-nfse#114

## Overview
Defines how DANFSE artifacts (PDF and XML) are attached to invoice emails after NFS-e issuance, implementing email distribution channel as part of Fase 5 (Hardening & UX).

## 1. Email Notification Flow

### Trigger Points
1. **Manual Issuance Complete** — User submits emission, NFS-e issued successfully
   - UI shows email preferences modal (toggles + custom recipient/subject/body)
   - User confirms → `InvoiceController::handlePostEmitEmail()` called
   
2. **Reissuance (Reemit)** — User reissues after correction
   - Same flow as manual issuance
   - Attachments respect current settings + request overrides

3. **Post-Emission Notification** — Background job (future scheduled issuance)
   - Respects company-level default settings
   - No UI interaction needed

### Notification Class
- **Class:** `Modules\Nfse\Notifications\NfseIssued` (extends `App\Abstracts\Notification`)
- **Constructor Parameters:**
  - `Invoice $invoice` — Target invoice (holds contact/recipient)
  - `NfseReceipt $receipt` — Fiscal metadata (nfse_number, chave_acesso, artifact paths)
  - `bool $attachDanfse = true` — Attach DANFSE PDF?
  - `bool $attachXml = true` — Attach NFS-e XML?
  - `array $customMail = []` — Custom email parameters (to, subject, body)

## 2. Attachment Specifications

### DANFSE PDF Attachment
- **Source:** WebDAV artifact path stored in `NfseReceipt.danfse_webdav_path`
- **Format:** PDF binary (Content-Type: `application/pdf`)
- **Filename:** `nfse-{nfse_number}.pdf` (or `nfse.pdf` if number unavailable)
- **Retrieval:** `WebDavClient::get(path)` — May throw `Throwable` (handled gracefully)
- **Error Handling:** Attachment failure never blocks email delivery

### XML Document Attachment
- **Source:** WebDAV artifact path stored in `NfseReceipt.xml_webdav_path`
- **Format:** XML text (Content-Type: `application/xml`)
- **Filename:** `nfse-{nfse_number}.xml` (or `nfse.xml` if number unavailable)
- **Retrieval:** `WebDavClient::get(path)` — May throw `Throwable`
- **Error Handling:** Graceful failure (email still sent)

### Attachment Failure Resilience
```php
try {
    $content = $this->makeWebDavClient()->get((string) $this->receipt->danfse_webdav_path);
    if ($content !== '') {
        $message->attach(Attachment::fromData(...));
    }
} catch (\Throwable) {
    // Attachment failure must never block email delivery.
}
```

## 3. Email Template Integration

### Template Alias
- **Alias:** `invoice_nfse_issued_customer`
- **Source:** `App\Models\Setting\EmailTemplate`
- **Fallback:** If template not found, use empty subject/body defaults

### Placeholder Tags (Tag Replacement)
Supported placeholder tags in email subject/body:

| Tag | Replacement | Example |
|-----|-------------|---------|
| `{cnpj}` | Company CNPJ | `29842527000145` |
| `{nfse_issue_year}` | Issuance year | `2026` |
| `{nfse_issue_month}` | Issuance month (2-digit) | `04` |
| `{nfse_issue_day}` | Issuance day (2-digit) | `15` |
| `{nfse_issue_month_name}` | Month name (pt-BR) | `abril` |
| `{invoice_number}` | Invoice document number | `INV-001` |
| `{nfse_number}` | NFS-e number | `123456` |
| `{chave_acesso}` | Access key (50 digits) | `12345...12345` |
| `{customer_name}` | Service recipient name | `Acme Corp` |
| `{company_name}` | Issuing company name | `LibreCode` |
| `{company_contact_name}` | Primary contact name | `João` |
| `{company_contact_email}` | Contact email | `joao@librecodecoop.org.br` |
| `{company_contact_phone}` | Contact phone | `+55 21 99999-9999` |
| `{nfse_issue_date}` | Full date (dd/mm/yyyy) | `15/04/2026` |

### UI Settings Form
- **Field:** `nfse_send_email_on_emit` (boolean, default: false)
- **Field:** `nfse_email_attach_danfse_on_emit` (boolean, default: true)
- **Field:** `nfse_email_attach_xml_on_emit` (boolean, default: true)
- **Storage:** Application settings (persisted per user preference)

## 4. Controller Implementation

### InvoiceController Methods

#### `servicePreviewEmailDefaults(Invoice $invoice): array`
Prepares email defaults for UI modal display.

**Returns:**
```php
[
    'send_email'    => bool,              // Current setting for "send email?"
    'recipient'     => string,            // Suggested recipient from invoice contact
    'subject'       => string,            // Template subject (empty if no template)
    'body'          => string,            // Template body (empty if no template)
    'attach_danfse' => bool,              // Current PDF attachment preference
    'attach_xml'    => bool,              // Current XML attachment preference
]
```

#### `handlePostEmitEmail(?Request $request, Invoice $invoice, NfseReceipt $receipt): void`
Processes email request after emission and dispatches notification.

**Request Parameters:**
- `nfse_send_email` (boolean) — Should email be sent?
- `nfse_email_to` (string) — Custom recipient address
- `nfse_email_subject` (string) — Custom subject (overrides template)
- `nfse_email_body` (string) — Custom body (overrides template)
- `nfse_email_attach_danfse` (boolean) — Attach DANFSE PDF?
- `nfse_email_attach_xml` (boolean) — Attach XML?
- `nfse_email_save_default` (boolean) — Save as default preferences?

**Side Effects:**
- Persists attachment preferences if `nfse_email_save_default` is true
- Updates template subject/body if provided
- Calls `sendNfseIssuedNotification()`

#### `sendNfseIssuedNotification(Invoice $invoice, NfseReceipt $receipt, bool $attachDanfse, bool $attachXml, array $customMail): void`
Dispatches NfseIssued notification to invoice contact or custom recipient.

**Logic:**
1. Resolve notifiable entity (invoice.contact or custom email)
2. Instantiate `NfseIssued` with flags and custom mail config
3. Dispatch via `$notifiable->notify()` or `Notification::route('mail', email)`

## 5. WebDAV Artifact Resolution

### Path Contract
- **DANFSE WebDAV Path:** Stored in `NfseReceipt.danfse_webdav_path`
- **XML WebDAV Path:** Stored in `NfseReceipt.xml_webdav_path`
- **Format:** Full WebDAV resource path (ready for direct GET)
- **Example:** `/nfse/2026-04-15/{nfse_number}/danfse.pdf`

### WebDavClient Configuration
Sourced from application settings:
- `nfse.webdav_url` — Base URL (e.g., `http://webdav:8086`)
- `nfse.webdav_username` — Auth username
- `nfse.webdav_password` — Auth password

### Error Scenarios
- **Path not found:** WebDAV returns 404 → attachment skipped
- **Network timeout:** Throwable caught → attachment skipped
- **Auth failure:** WebDAV returns 401/403 → attachment skipped
- **Content empty:** Empty string returned → attachment skipped (no error logged)

## 6. Internationalization (i18n)

### Supported Locales
- **pt-BR:** Portuguese (Brazil) — day/month names
- **en-GB:** English (UK) — English month names

### Month Name Resolution
```php
monthNameByNumber(int $number): string
```
Returns localized month name for 1-12 input; falls back to `"Mês {$number}"` if unavailable.

## 7. State Machine (Scenarios)

### Scenario 1: Manual Issuance + Email
```
User emits NFS-e → UI shows modal → User checks "send email" + fills recipient
→ Controller invokes handlePostEmitEmail → NfseIssued notification dispatched
→ Notification retrieves DANFSE + XML from WebDAV
→ Email sent with attachments → User receives PDF + optional XML
```

### Scenario 2: Reissuance (Reemit)
```
User reissues NFS-e → Same flow as Scenario 1
→ Custom description comes from request (not template)
```

### Scenario 3: Scheduled Issuance (Future)
```
Scheduler triggers batch issuance → Uses default company settings (no UI modal)
→ Default send_email + attachment preferences applied
→ One email per NFS-e issued
```

### Scenario 4: Attachment Failure
```
WebDAV server down during email dispatch
→ Notification catches exception
→ Email sent without attachment (no log, no error)
→ User can manually retrieve DANFSE from invoice list
```

## 8. Testing Strategy

### Unit Tests (NfseIssuedTest)
- ✅ `testToMailAttachesDanfseWhenAttachDanfseTrueAndPathSet`
- ✅ `testToMailDoesNotAttachWhenAttachDanfseFalse`
- ✅ `testToMailAttachesBothDanfseAndXmlWhenBothEnabled`
- ✅ `testGetTagsReturnsAllExpectedTags`
- ✅ `testGetTagsReplacementMapsFieldsFromInvoiceAndReceipt`
- ✅ `testToMailOverridesNotifiableEmailWhenCustomMailToProvided`
- ✅ `testViaReturnsMailOnlyWhenCustomRecipientProvided`
- ✅ `testGetSubjectReplacesPlaceholdersWhenCustomSubjectProvided`
- ✅ `testGetBodyReplacesPlaceholdersWhenCustomBodyProvided`

### Integration Tests (InvoiceControllerTest)
- ✅ Email defaults populated correctly
- ✅ Settings persisted when "save default" checked
- ✅ Custom email recipient honored
- ✅ Attachment flags passed to notification
- ✅ Reemit sends email with correct flags
- ✅ Notification called with expected parameters

### E2E Tests (Behat - future)
- Email template rendering with placeholder tags
- WebDAV artifact retrieval in Mailpit inbox
- Custom recipient override
- Attachment filename generation

## 9. Operational Notes

### Email Delivery
- **Provider:** Laravel Mail (configured in app)
- **Channel:** Determined by `via($notifiable)` method
- **Fallback:** Returns parent `via()` if no custom recipient provided

### Audit Trail
- Each email send logged in application notification history
- No credential/password data logged
- Artifact retrieval failures silent (no alarming)

### Performance Considerations
- WebDAV calls are synchronous (blocking)
- Large PDF files (>100MB) may slow email dispatch
- Consider async queue for production at scale

### Security
- PFX passwords never included in email
- WebDAV credentials used only for artifact retrieval
- Custom email recipients validated (trimmed, checked non-empty)
- No plaintext secrets in logs

## 10. References

- **Decision:** fiscal-module-plan.md (Architecture)
- **Notification Base:** `App\Abstracts\Notification` (Akaunting core)
- **Template Model:** `App\Models\Setting\EmailTemplate`
- **WebDAV Client:** `Modules\Nfse\Support\WebDavClient`
- **Artifact Storage:** STORAGE.md (WebDAV path contract)
- **Package Dependency:** `LibreCodeCoop\NfsePHP\Dto\ReceiptData`

---
**End of Email Contract**
