# Security Policy

**⚠️ IMPORTANT NOTE:**

This project follows **best-practice security**, but **cannot guarantee 100% protection** against zero-day exploits or highly targeted attacks.
For **enterprise-grade security requirements**, use **commercially supported solutions** with dedicated threat intelligence.

---

## Threat model

**Considered attack vectors** (prioritized by likelihood/risk):

1. **SQL injection**
2. **Supply chain**
3. **Input sanitization bypass**
4. **Sensitive data exposure**
5. **XSS**
6. **Network attacks**
7. **Social engineering**
8. **Dependency updating**
9. **Business logic bypass**

---

## SQL injection

**Current protection:**

- The module **does not execute raw SQL queries** — all database operations use PrestaShop's `Configuration` API.
- PrestaShop's ORM and prepared statements provide baseline protection against SQL injection.

**Limitations:**

- ORM is **not a 100% guarantee** against SQL injection if misused.

**Additional mitigation:**

- Data that this module writes to the database goes through either:
  - the module’s own normalization for external API payloads, or
  - PrestaShop’s configuration and model APIs, which apply their usual validation and escaping.

---

## Supply chain security

Every release is built via GitHub Actions with CI runner hardening enabled.
Each release artifact ships with a **SLSA Build Level 3** provenance file
and a **SHA-256 checksum** — both are published on the release page and can
be used to verify the integrity of the ZIP archive.

### Dependency locking

- GitHub Actions
  - **Status:** **Not SHA-pinned**
  - **Details:** Actions are referenced by version tags instead of commit SHAs; risk is mitigated by runtime monitoring via Harden Runner (see “CI hardening”).

---

## Input sanitization

The design goal is to **sanitize only at the trust boundary with external services**, while relying on PrestaShop’s built-in input validation and escaping for data coming from the shop itself.

**External API responses**:

- The module applies **lightweight, context-aware sanitization**.

**PrestaShop internal data**:

- **Not additionally sanitized by this module** — customer, order and configuration data is obtained via PrestaShop’s own APIs and form handling, which apply their usual validation and escaping.
- The module relies on these existing protections instead of trying to re-filter every internal value.

---

## Sensitive data exposure

**Known risks:**

1. **Bot Token visibility in admin UI:**
   - Currently stored and displayed as **plain text** in the configuration form.
   - Any admin user with access to the module can view the full bot token.
   - **Planned improvement:** Implement password-style field with show/hide toggle (requires Symfony form integration).

2. **Customer/Order/Employee data transmitted to Telegram:**
   - Notifications contain **sensitive personal data**: customer names, emails, IP addresses, phone numbers, order details, admin login IPs, etc.
   - This data is sent to **Telegram Bot API** over HTTPS.
   - **Anyone with access to the configured Telegram chats** can see this data.
   - If all available placeholders are used, recipients could potentially reconstruct portions of the store's database.

**Mitigation recommendations:**

- Restrict Telegram chat access to trusted personnel only.
- Use separate Chat IDs for different notification types (e.g., separate chats for orders vs. admin logins).
- Remove unnecessary placeholders from message templates to minimize data exposure.
- Regularly review who has access to notification chats.

---

## XSS (Cross-Site Scripting)

**Input sources:**

- PrestaShop internal data (customer names, order comments, etc.) — sanitized by PrestaShop itself.
- External API responses (country names, version tags) — sanitized by the module’s own helpers before use (control characters stripped, whitespace normalized, allowed character sets enforced).

**Output context:**

- Telegram messages use `parse_mode=HTML`, which allows a limited subset of HTML tags (`<b>`, `<i>`, `<a>`, etc.).
- Telegram clients **block dangerous attributes** (e.g., `<a href="javascript:...">`) and restrict allowed tags.

**Current protection:**

- External data is sanitized and normalized before being inserted into templates; no raw HTML from external APIs is trusted or parsed.
- PrestaShop sanitization prevents XSS in admin-controlled data (message templates, chat IDs, etc.).

---

## Network security

All external HTTP communications use TLS where possible.

The built-in cURL wrapper enforces:

- **Timeouts**: Connection (5s), Total request (10s).
- **Response size limit**: Hard cap of **256 KiB** to prevent large payloads (uses `CURLOPT_WRITEFUNCTION`).

---

## CI hardening

All CI jobs are protected by StepSecurity Harden Runner, which monitors
outbound network and process activity on the runner at runtime.

Third-party Actions are referenced by tag rather than commit SHA.
SHA pinning is intentionally not used — it only provides strong guarantees
when combined with manual review of every upstream commit, which this
single-developer project cannot sustain. Runtime monitoring via Harden Runner
is the primary supply-chain control instead.

---

## Security scanning

Security scans (SCA/SAST/IAST) covering the codebase, its dependencies, and the CI/GitHub Actions pipeline are run regularly: SCA/SAST checks are triggered automatically on every commit and on a daily schedule, while deeper IAST scans (using AI agents) are launched manually during major refactors or upon request.

---

## Social engineering

**Message splitting attack:**

If a customer includes a very long comment or address in an order (approaching the 4096-character Telegram message limit), the notification will be split into multiple separate messages. An attacker could craft the input such that the second message starts with text that mimics an update notification:

```text
[Message 2]:
...end of customer data...

🎉 TelegramNotifier update available!
Download: https://github.com/a1ex2276564/TelegramNotifier
```

This could trick recipients into believing the module is prompting them to download an update from a malicious repository.

**Mitigation:**

- **User awareness:** Train notification recipients to verify updates only through trusted channels (PrestaShop admin panel, official GitHub repository).
- **Update verification:** Always verify the repository URL (`alex2276564`, not lookalikes like `a1ex` or `alex22765564`).
- **SLSA provenance:** Use the SHA-256 checksums and SLSA provenance files published with each release to verify authenticity.
- **PrestaShop Addons:** Note that PrestaShop Addons does not verify third-party modules with cryptographic signatures or SLSA attestations.

**This is not a vulnerability in the module itself**, but rather a risk inherent to any notification system that includes user-controlled content.

---

## Dependency updating

Dependencies are kept up to date using [Renovate](https://github.com/renovatebot/renovate).

- **Regular (non-security) updates:**
  - Checked at least **weekly**.
  - A `minimumReleaseAge` of **3 days** is applied, so only versions that have been out for a while are adopted for normal updates.

- **Security-related updates:**
  - Processed **without artificial delay** — security patches are not held back by `minimumReleaseAge`.
  - Renovate security advisories and/or GitHub’s security alerts are handled as soon as possible once available.

---

## Reporting a vulnerability

If you discover a security vulnerability, please use the
[Security tab](https://github.com/alex2276564/TelegramNotifier/security/advisories) to report it privately.  
Do **not** disclose security vulnerabilities publicly before they have been addressed.
