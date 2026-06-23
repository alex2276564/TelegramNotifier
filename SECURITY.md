# Security Policy

**⚠️ IMPORTANT NOTE:**

This project follows **best-practice security**, but **cannot guarantee 100% protection** against zero-day exploits or highly targeted attacks.
For **enterprise-grade security requirements**, use **commercially supported solutions** with dedicated threat intelligence.

---

## Threat model

**Considered attack vectors** (prioritized by likelihood/risk):

1. **Supply chain**
2. **Input sanitization bypass**
3. **Network attacks**
4. **Sensitive data exposure**
5. **xss**
6. **Business logic bypass**

## Supply chain security

Every release is built via GitHub Actions with CI runner hardening enabled.
Each release artifact ships with a **SLSA Build Level 3** provenance file
and a **SHA-256 checksum** — both are published on the release page and can
be used to verify the integrity of the ZIP archive.

## CI hardening

All CI jobs are protected by StepSecurity Harden Runner, which monitors
outbound network and process activity on the runner at runtime.

Third-party Actions are referenced by tag rather than commit SHA.
SHA pinning is intentionally not used — it only provides strong guarantees
when combined with manual review of every upstream commit, which this
single-developer project cannot sustain. Runtime monitoring via Harden Runner
is the primary supply-chain control instead.

## Security scanning

The codebase and its dependencies are continuously monitored, with automated
SCA/SAST/IAST scans triggered on every commit and executed automatically on a daily schedule.

**Note:** IAST scans using AI agents are conducted manually ~2-4 times/year during major refactoring or upon request.

## Reporting a vulnerability

If you discover a security vulnerability, please use the
[Security tab](https://github.com/alex2276564/MMOSpawnPoint/security/advisories) to report it privately.  
Do **not** disclose security vulnerabilities publicly before they have been addressed.
