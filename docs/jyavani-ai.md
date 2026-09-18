# Optional Jyavani AI Integration

Content Translation 1.17.0 can use Jyavani AI 0.4.0 or newer to create an
Article or Page body-translation draft. Jyavani AI is optional and is not a
manifest dependency. Content Translation continues to install, activate, edit,
and save normally when Jyavani AI is absent or inactive.

The action appears only when all of these conditions are true:

- Jyavani AI is active and at least version 0.4.0.
- The current user may use `plugin.jyavani-ai.assistant.generate`.
- The user may edit the selected Content Translation locale.
- Jyavani AI's resource authorization accepts the canonical source operation.
- The source is an Article or Page. Theme Template packages are excluded.

The browser sends the canonical source body to Jyavani AI's documented JSON
generation endpoint after an explicit user action. That endpoint remains
responsible for CSRF validation, AI permission and Core resource authorization,
rate limiting, request limits, provider access, output sanitization, and usage
recording. Content Translation never calls provider internals or receives
provider credentials.

The response updates only the mounted editor draft. A captured editor revision
rejects stale responses if the editor changed while generation was running. The
editor switches to CodeMirror if generated HTML cannot be represented by Quill.
The user must still review and save normally; Content Translation's locale
authorization, optimistic translation state, publication checks, media policy,
and save-time sanitization remain authoritative.

The current Jyavani AI endpoint returns one HTML document, so this integration
translates body content only. Title, slug, metadata, Theme Section semantic
fields, and media metadata remain manually reviewed fields.
