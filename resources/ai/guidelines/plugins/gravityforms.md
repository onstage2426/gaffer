## Forms (Gravity Forms, headless)

The theme writes each form's markup; Gravity Forms does the rest: validation
(its messages, in the site's language), the entry, notifications, the
confirmation and add-on feeds. Its own frontend, styles and captcha add-ons
are not used. To add or change a form, follow the `gaffer-form` skill.

- **One template per form:** `views/forms/{name}.twig`, `{name}` = the slug of
  the form's title. Each field's **Admin Field Label** is the name the
  template sends (`fields[naam]`). No form or field IDs in the theme.
- **Every form posts to the theme's one `Form` action** with htmx and is
  replaced by its answer (re-rendered with errors, the confirmation, or an
  `HX-Redirect`). `form` in the template is a `Gaffer\Forms\FormState`.
- **Spam is handled before Gravity Forms** (a honeypot and Turnstile) and gets
  the normal confirmation plus a line in `storage/logs/spam.log`. No other
  captcha.
- Single-value fields, checkboxes and consent only: no Name/Address fields
  with sub-inputs, no file uploads, no multi-page forms.
