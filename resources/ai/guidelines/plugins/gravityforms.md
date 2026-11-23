## Forms (Gravity Forms, headless)

The theme writes each form's markup; Gravity Forms validates, stores the
entry, sends notifications and runs add-on feeds. Its frontend, styles and
captchas aren't used. To add or change a form, follow the `gaffer-form` skill.

- **One template per form:** `views/forms/{title slug}.twig`; fields are sent
  by **Admin Field Label** (`fields[naam]`). No form or field IDs in the theme.
- Every form posts to the theme's one `Form` action with htmx and is replaced
  by its answer; `form` in the template is a `Gaffer\Forms\FormState`.
- **Gaffer writes what it checks:** `{{ include('@gaffer/forms/meta.twig', { form: form }, with_context = false) }}`
  inside every `<form>` (form name, honeypot, Turnstile widget); Gaffer loads
  Turnstile's script. Never write these by hand.
- **A theme's own form** (not Gravity Forms) includes the same partial (with
  `FormState::blank('name')`), and its action calls `Forms\Spam::check('name')` (the reason or
  null) instead of reading those fields.
- **Spam** (honeypot, Turnstile) gets the normal confirmation and a line in
  `uploads/gaffer/logs/spam.log`; outside production its answer has an
  `X-Gaffer-Spam` header (the reason), so a confirmation is no proof a test went through.
- Single-value fields, checkboxes, consent and File Upload fields (`fields[x]`
  like the rest, multipart); no Name/Address fields, no multi-page forms.
