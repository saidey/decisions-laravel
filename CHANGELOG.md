# Changelog

## 0.2.4

- With `capture.bindings=true`, binding values are scrubbed before they are inlined into the SQL: emails, phone numbers, tokens and long secrets are masked, and password hashes (bcrypt, argon2) become `[redacted]`. Numbers are kept.
- `capture.payloads=true` now sends the request body (`payload`) on request records, without uploaded files. Any field whose name says it is a credential or payment detail (password, secret, token, key, auth, card, cvv, iban, pin, otp…) is redacted at any depth.

## 0.2.3

- Outgoing HTTP records mask every query-string value, not only ones that look secret, so `DECISIONS_SAMPLE_HTTP` can be turned on without sending customer data from third-party calls. Parameter names and paths are kept.

## 0.2.2

- Security capture keeps the request IP on security and request records even when the app's `redact.fields` names `ip`; capture is the explicit opt-in to send it.
- `security.token_models` defaults to `null`, meaning Sanctum's configured token model (`Sanctum::usePersonalAccessTokenModel()`), read after boot, so apps with their own token model are seen.

## 0.2.1

- Fix a memory leak: the shutdown hook held each app's request lifecycle (with its request and router) alive, about 3 MiB per booted app in test suites. It now holds it weakly.

## 0.2.0

- Add opt-in security capture for auth/Fortify events, denied/expired/throttled/unauthenticated responses, probe paths, configurable token and privileged model lifecycle events, and hourly application posture.
- Hash failed-login identifiers with the environment token; exclude credentials, token values, and changed attribute values. Include request IP and truncated user agent only when `DECISIONS_SECURITY_CAPTURE=true`.

## 0.1.6

- With `user_details` enabled, request records carry `user_name` (not the email), so monitoring can show names for every signed-in user, not only those who hit an exception.

## 0.1.5

- Measure request lifecycle phases with monotonic offsets, cold-request bootstrap timing, and sparse phases where framework boundaries are unavailable. Defer each request record until termination; retain a partial-record fallback for missing termination.
- Add request-relative start offsets and phase identities to queries, outgoing HTTP, cache operations and job dispatches. Queries include the connection driver; request-scoped queue dispatch records use job status `queued`.
- Reset lifecycle state for long-lived workers. Sending is a framework handoff envelope on the canonical HTTP kernel, not network transfer latency; unverified Octane sending boundaries are omitted.

## 0.1.4

- Mask URL query values by name pattern as well as known field names: any parameter whose name mentions password, secret, token, key, credential, auth, signature, session, cookie or jwt, plus `code`, `state`, `otp`, `pin`, `nonce`, `expires` and `sig`.
- Mask query values by shape whatever their name: long opaque strings (24+ characters), JWTs, emails, phone numbers and other values the log scrubber would mask. UUIDs are kept as ids.

## 0.1.2

- Drop URL fragments from every exported URL (outgoing requests, request paths, URLs in log and exception context); fragments never reach a server and can carry tokens.

## 0.1.1

- Hash cache keys by default with an app-specific HMAC-SHA256; add `capture.cache_keys` / `DECISIONS_CAPTURE_CACHE_KEYS` to opt into scrubbed keys while keeping session keys private.
- Scrub all nested log and exception context strings, including absolute URLs and signed URL query parameters.
- Mask phone-shaped values alongside emails, credentials, tokens, JWTs, and long hex/base64 strings.
- Apply recursive, case-insensitive sensitive-field redaction to every record before buffering.
