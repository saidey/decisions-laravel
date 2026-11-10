# Changelog

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
