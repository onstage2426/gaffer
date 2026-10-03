## Forms (Gravity Forms, headless)

The theme writes each form's markup; Gravity Forms does the rest: validation
(its messages, in the site's language), the entry, notifications, the
confirmation, and add-on feeds (Mailchimp, Brevo, Zoho, HubSpot, ...). Its own
frontend, styles and captcha add-ons are not used.

- **One template per form:** `views/forms/{name}.twig`, where `{name}` is the
  slug of the Gravity Forms form's title ("Nieuwsbrief" → `nieuwsbrief`).
  Each field's **Admin Field Label** in Gravity Forms is the name the template
  sends: `fields[naam]`. No form or field IDs in the theme.
- **Every form posts to the theme's `Form` action** (`ajax/Form/Form.php`:
  `class Form extends \Gaffer\Forms\FormAction {}`) with htmx and is replaced by
  its answer: the same template re-rendered with errors, or sent with
  Gravity Forms' confirmation message, or an `HX-Redirect` when the
  confirmation is a page or URL.
  ```twig
  <div id="form-contact" hx-target="this" hx-swap="outerHTML">
      {% if form.sent %}{{ form.message|raw }}{% else %}
      <form hx-post="{{ ajax_url('Form') }}" hx-disabled-elt="find button" novalidate>
          {{ include('components/form/meta.twig', { form: form }, with_context = false) }}
          ...fields: name="fields[naam]", value="{{ form.value('naam') }}", error: form.error('naam')
      </form>
      {% endif %}
  </div>
  ```
- **`form` is a `Gaffer\Forms\FormState`:** `value(field)`, `checked(field, choice)`,
  `error(field)` (`error('')`: about the whole form), `sent`, `message`
  (HTML: `|raw`), `failed` (the Gravity Forms form wasn't found). The first
  render gets `FormState::blank('contact')` from PHP.
- **Spam is handled before Gravity Forms:** a honeypot (`website`, hidden) and
  Turnstile (`data-response-field-name="turnstile"`, rendered with
  `render=explicit`; widgets in swapped-in content need rendering again).
  Spam gets the normal confirmation (it must look like it worked) and a line in
  `storage/logs/spam.log`. No other captcha.
- **Field types:** single-value fields (text, e-mail, phone, textarea,
  select, radio, number, hidden, website), checkboxes (`fields[x][]`) and
  consent. Use single fields in Gravity Forms (not Name/Address with
  sub-inputs); no file uploads or multi-page forms.
- `php gaffer forms:show [name]` lists Gravity Forms' forms and fields as the
  theme sees them. `doctor --wp` checks each template: its Gravity Forms form
  exists, it only sends fields the form has, it sends every required one, and
  fields without an Admin Field Label.
