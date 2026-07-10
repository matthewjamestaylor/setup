# Legends Global — New Hire Onboarding

A secure, single-purpose web app that lets new hires submit their onboarding
information online. On submit, everything is packaged into one **AES‑256
encrypted ZIP** (a branded PDF summary + the uploaded documents) and emailed to
Human Resources. **Nothing is stored** on the web server — uploaded files live
only in a per‑request temp directory that is shredded the moment the email is
sent.

Lives at: **https://setup.rclegends.ca**

---

## How it works

```
New hire → multi‑step form → submit.php
     → validate (server-side, authoritative)
     → generate branded PDF (mirrors the paper "Employee Information" form)
     → bundle PDF + uploaded documents into an AES‑256 encrypted ZIP
     → email the encrypted ZIP to HR over authenticated TLS SMTP
     → shred all temporary files
```

- **No database.** The app is completely stateless.
- **Encryption at rest & in transit.** The email body carries *no* sensitive
  data (no SIN, no banking, no medical). All of that is inside the encrypted
  attachment, opened by HR with a shared passphrase.
- **Bot protection.** Cloudflare Turnstile + a hidden honeypot + an HMAC‑signed
  time trap.

---

## Requirements

- **PHP 8.1+** (built and tested on 8.4).
- PHP extensions: `zip` (with AES — standard on modern libzip), `openssl`,
  `mbstring`, `fileinfo`, `iconv`, `json`; `gd` and `curl` recommended.
- No Composer needed — **PHPMailer** and **FPDF** are bundled in `vendor/`.

Run the readiness check on the host (over SSH):

```bash
php tools/diagnostics.php
```

---

## Deploy to Namecheap (cPanel)

1. **Create the subdomain** `setup.rclegends.ca` in cPanel.

2. **Point its Document Root at the `public/` folder.**
   In *Domains → set Document Root* (or when creating the subdomain), set it to
   `…/setup/public`. This keeps the application code (`app/`, `vendor/`,
   `config/`) outside the web‑served area.
   *(If you cannot change the Document Root, the included `.htaccess` files still
   block direct access to those folders as a fallback.)*

3. **Upload the project** (Git clone, or upload a ZIP and extract) so the tree
   looks like:

   ```
   setup/
     public/      ← Document Root (index.php, submit.php, assets/)
     app/         ← application code (protected)
     vendor/      ← bundled PHPMailer + FPDF (protected)
     config/      ← configuration (protected)
     storage/     ← transient only (protected)
     tools/ tests/
   ```

4. **Create the config file:**

   ```bash
   cp config/config.sample.php config/config.php
   ```

   Then edit `config/config.php` (see **Configuration** below).
   `config/config.php` is git‑ignored — it holds your secrets and is never
   committed.

5. **Raise PHP upload limits** if needed (cPanel → *MultiPHP INI Editor*):

   ```
   upload_max_filesize = 10M
   post_max_size       = 30M
   max_file_uploads    = 30
   ```

6. **Test before going live** with the `log` mail transport (see below), then
   switch to `smtp`.

---

## Configuration (`config/config.php`)

| Setting | What to put |
|---|---|
| `hr.email` | The HR head's inbox (where submissions are sent). |
| `mail.transport` | `smtp` to send for real; `log` to write a preview `.eml` without sending. |
| `mail.host / port / secure / username / password` | Your mailbox SMTP details. Namecheap Private Email: `mail.privateemail.com`, port `465` (`ssl`) or `587` (`tls`); username is the full address. |
| `mail.from_email / from_name` | The "From" the message is sent as. |
| `package.passphrase` | The passphrase HR uses to open the encrypted ZIP. **Generate a strong one** and share it with HR out‑of‑band (in person / by phone — never by email). |
| `turnstile.site_key / secret` | Free keys from the Cloudflare dashboard → Turnstile. Set `enabled` to `false` only for local testing. |

Generate a passphrase:

```bash
php -r "echo bin2hex(random_bytes(12)).PHP_EOL;"
```

Any setting can alternatively be provided via an environment variable of the
same UPPER_SNAKE_CASE name (e.g. `MAIL_PASSWORD`), which overrides the file.

---

## How HR opens a submission

1. HR receives an email titled **“New Hire Submission — <Name> — <date>”** with
   an encrypted `.zip` attachment.
2. They open the ZIP with the **shared passphrase**.
   - **Windows:** use **7‑Zip** (free) — the built‑in Explorer cannot open
     AES‑256 ZIPs.
   - **macOS:** use **Keka** or **The Unarchiver** (the built‑in Archive Utility
     cannot open AES‑256 ZIPs).
3. Inside: `Employee-Information.pdf` (full summary) + each uploaded document +
   a `README.txt` manifest.

> If a host ever lacks AES‑ZIP support, the app automatically falls back to an
> OpenSSL AES‑256‑GCM envelope (`.zip.enc`). Decrypt those with
> `php tools/decrypt.php <file>.zip.enc` (see the file's header for usage).
> `tools/diagnostics.php` tells you which mode a host will use.

---

## Testing / previewing

Set `mail.transport = 'log'` **and** `app.env = 'development'` in config.
Submissions then write the complete message (with the encrypted attachment) to
`storage/mail/*.eml` **without sending**. Inspect that file to confirm
formatting, then set `mail.transport = 'smtp'` and `app.env = 'production'`.
(Log mode persists PII to disk, so it is refused when `app.env = 'production'`.)

A CLI harness exercises the whole pipeline without a browser:

```bash
php tests/submit_harness.php valid     # happy path → writes an .eml
php tests/submit_harness.php missing   # required-field errors
php tests/submit_harness.php sin9      # 9-series SIN → permit block required
php tests/submit_harness.php badfile   # rejects a fake PDF
```

> Note: PHP's built‑in dev server (`php -S`) mis‑parses multipart uploads; use
> the CLI harness above (or a real Apache/PHP‑FPM host) to test file uploads.

---

## Security notes

- All input is validated **server‑side** (`app/Validator.php`) — the client‑side
  checks are only for UX. SIN is Luhn‑checked; a SIN beginning with `9` requires
  the work/study permit block.
- Uploads are checked by real MIME type (not just extension); images are
  re‑encoded/downscaled; per‑file and total size caps are enforced.
- Sensitive data never appears in the email body or in logs.
- Security headers + CSP are set in `public/.htaccess`; HTTPS is forced.
- Delete or ignore `tools/diagnostics.php` after setup (web access to it is
  gated by a key derived from your passphrase).

---

## Project layout

| Path | Purpose |
|---|---|
| `public/index.php` | The multi‑step form UI. |
| `public/submit.php` | POST handler: validate → package → email → shred. |
| `public/assets/` | CSS + JS (step nav, validation, signature pad, uploads). |
| `app/FieldMap.php` | Single source of truth for every field. |
| `app/Validator.php` | Authoritative validation + upload processing. |
| `app/PdfBuilder.php` | Branded PDF (extends FPDF). |
| `app/Packager.php` | AES‑256 encrypted ZIP (OpenSSL fallback). |
| `app/Mailer.php` | PHPMailer SMTP / log transport. |
| `app/Turnstile.php` | Cloudflare Turnstile verification. |
| `config/config.sample.php` | Copy to `config/config.php` and fill in. |
| `tools/diagnostics.php` | Host readiness check. |
