# Changelog

## 0.1.1

- Hash cache keys by default with an app-specific HMAC-SHA256; add `capture.cache_keys` / `DECISIONS_CAPTURE_CACHE_KEYS` to opt into scrubbed keys while keeping session keys private.
- Scrub all nested log and exception context strings, including absolute URLs and signed URL query parameters.
- Mask phone-shaped values alongside emails, credentials, tokens, JWTs, and long hex/base64 strings.
- Apply recursive, case-insensitive sensitive-field redaction to every record before buffering.
