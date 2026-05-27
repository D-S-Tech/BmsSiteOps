# Security policy

## Supported versions

BmsSiteOps is in pre-alpha. There are no released versions, and there is no support commitment. The `main` branch is the only branch that receives any security attention.

## Reporting a vulnerability

If you believe you have found a security vulnerability in BmsSiteOps, please report it privately. Do **not** open a public issue.

Use GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability) feature on this repository.

If GitHub's private reporting is unavailable, email the maintainer at the contact address listed in [boldmech.com](https://boldmech.com) with the subject line `[BMSSITEOPS SECURITY]`.

Please include:

- A description of the vulnerability and its impact
- Steps to reproduce
- A suggested fix, if you have one
- Whether you wish to be credited in any future advisory

You can expect an acknowledgement within five business days.

## Scope

In scope:

- The source code in this repository
- The reference deployment described in `infra/`
- The MCP endpoint and its authentication

Out of scope:

- Third-party services BmsSiteOps integrates with (Tactical RMM, Tridium Niagara, Anthropic API). Report those to their respective maintainers.
- Issues in unmodified upstream dependencies. Report those upstream first; we will track upstream advisories via Dependabot.

## What is *not* a security issue

- Missing security headers that do not affect a credential or data-confidentiality path
- Lack of rate limiting on endpoints that have no abuse potential
- Self-XSS that requires the victim to paste an attacker-controlled string into a developer console
- Vulnerabilities requiring physical access to the server
- Reports from automated scanners without a demonstrated impact
