# Security policy

This project is pre-release. No production support commitment or security support
window exists until a stable release establishes one.

Report vulnerabilities privately using GitHub's "Report a vulnerability" action if
available. If private reporting is unavailable, contact the maintainer through the
contact details listed on their GitHub profile. Do not put credentials, customer
data or exploit details into a public issue.

## Review priorities

Review connection/tenant isolation, API credentials, OAuth token lifecycle, webhook
authenticity, replay handling, contact identity, consent/DNC and accidental exposure
of personal data. The host application owns customer authorization and identity
verification; the package must document that boundary.

Never log Authorization headers, access/refresh tokens or full contact payloads by
default. HTTPS, certificate verification and bounded HTTP requests are mandatory
production defaults. Explicit local HTTP configuration is a development exception.

The SDK exposes raw write/delete operations. They are an expert escape hatch, not
an authorization policy. Changes to authentication, error handling or synchronization
must include negative-path tests. Do not treat a valid webhook signature as proof
of freshness or exactly-once delivery.
