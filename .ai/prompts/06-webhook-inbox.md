# Prompt 06 — Verified webhook inbox and inbound sync

Read AGENTS.md, .ai/STATE.md, .ai/PROJECT.md and docs/ARCHITECTURE.md first.
Inspect the installed SDK and current tests. Implement only this milestone using
Pest TDD, explicit types and model-independent contracts. Preserve PHP 8.4+ and
Laravel 12/13. Run focused tests, then composer check; report checks and limitations.
Update README, CHANGELOG and .ai/STATE.md with actual implemented behavior.
Do not tag, publish, call production Mautic or change host applications/upstream repos.

Add opt-in webhook ingress and a durable inbox, then process allowed inbound events
through the Contact mapping and ownership policies. Verify actual event payloads
and signature algorithm against the supported Mautic version.

Acceptance criteria:
- Verify the raw request body using the connection-specific secret and constant-time
  comparison. Signature is base64 HMAC-SHA256 if confirmed by the target server.
- Reject missing/invalid signature before processing. Handle invalid JSON, body
  bounds, unknown events, multiple event types and multiple items per delivery.
- Persist durably before acknowledging success; report persistence failure so the
  sender can retry. Processing is asynchronous and recoverable after restart.
- Separate delivery deduplication from semantic event identity. Do not assume every
  event has a UUID or timestamp. Preserve legitimate repeated identical events.
- Signature proves authenticity, not freshness: document replay limits and chosen
  retention/deduplication strategy instead of claiming exactly-once delivery.
- Handle out-of-order events, duplicates, consent/DNC distinctions and per-connection
  mapping; prevent inbound/outbound loops using ownership and change detection.
- No echo writes from generic Eloquent observers; no full customer payload logging.
- Tests cover invalid signatures, batches, duplicates, stale events, loops, tenant
  isolation, inbox failure and reprocessing after a crash.

No automatically exposed route unless explicitly enabled/configured by the host.
Document CSRF exemption and host routing requirements narrowly for this endpoint.
