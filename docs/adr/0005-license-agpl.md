# ADR 0005 — License: AGPL-3.0

**Status:** Accepted
**Date:** 2026-05-27

## Context

The repository is public. Without a license file, the source is technically "all rights reserved" by default — no one (including the maintainer's future collaborators) can legally use, modify, or distribute it. A license must be chosen and committed.

BmsSiteOps is built on top of:

- Tactical RMM (MIT) — used via REST API, no source incorporation
- Laravel (MIT)
- SvelteKit (MIT)
- Python ecosystem (mostly MIT, BSD, or Apache 2.0)

None of these impose a copyleft obligation on derivative work. The license choice for BmsSiteOps itself is therefore unconstrained.

The product is intended to support a commercial path. The risk of an open-source release is that a competitor forks the code, runs a hosted service, and captures the market while contributing nothing back. This risk is real for any infrastructure-style SaaS — see MongoDB → AWS DocumentDB, Elastic → AWS OpenSearch, etc.

## Decision

**GNU Affero General Public License v3.0 (AGPL-3.0)** for the BmsSiteOps source tree.

Rationale:

1. The AGPL covers the "network use" case. If someone forks BmsSiteOps and runs it as a hosted service, they are required to make their source modifications available to the service's users under the same license. A pure GPL would not extend to network use; this is the gap AGPL closes and the reason it was created.

2. The AGPL does not prevent commercial use, internal use, modification, or redistribution. It only requires that modifications shipped over the network be made available.

3. The AGPL is OSI-approved and FSF-approved. It is widely understood by counsel and by developers.

4. The maintainer retains copyright. A future decision to dual-license (AGPL + commercial) remains available — common pattern for sustainable open source, used by Grafana, MongoDB (pre-SSPL), and many others.

## Consequences

**Positive**

- A competitor cannot legally take BmsSiteOps, host it, and refuse to publish their changes.
- Internal use by any organization (a customer running BmsSiteOps on their own infrastructure) is permitted without obligation, since AGPL only triggers when the service is offered over a network to others.
- Future commercial licensing (for customers who want to embed BmsSiteOps in proprietary products without AGPL obligations) is straightforward — the maintainer holds copyright and can offer separate terms.

**Negative**

- Some companies have policies against AGPL software, full stop, due to over-cautious legal interpretations. Those customers may pass on adoption. Acceptable given the commercial-licensing escape hatch.
- Outside contributors must agree their contributions are AGPL-licensed. Standard for any open-source project; we will rely on the implicit license of a pull request to the AGPL repo, with an explicit CONTRIBUTING.md note.

## Alternatives considered

- **MIT** — most permissive, attractive for adoption, but offers no protection against fork-and-host competition. Wrong tradeoff for an operations product the maintainer wants to commercialize.
- **Apache 2.0** — slightly more protective than MIT around patents but otherwise equivalent permissive license. Same problem as MIT for the commercial-protection axis.
- **SSPL (Server Side Public License)** — stronger than AGPL but not OSI-approved, increasingly viewed with suspicion by the open-source community. Avoid.
- **Business Source License (BSL)** — time-delayed open source. Tempting but adds complexity (multiple license states over time, conversion date management) without clearly better protection than AGPL.
- **Proprietary, source-available** (e.g., Elastic License v2) — sacrifices the open-source credibility and contributor-attraction benefits.

## See also

- [LICENSE](../../LICENSE) — the full AGPL-3.0 text
- [ADR 0004 — Public repo security posture](./0004-public-repo-security.md)
