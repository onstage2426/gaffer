---
name: gaffer-form
description: Create or change a form in a Gaffer theme (a Gravity Forms form with the theme's own markup in views/forms/{name}.twig, posted to the Form ajax action). Use for any contact, newsletter or other form on the site.
---

# Gaffer form

1. The form lives in Gravity Forms: its title gives the name ("Nieuwsbrief" →
   `nieuwsbrief`). Single fields only (no Name/Address with sub-inputs, no
   uploads or pages). Give every field an **Admin Field Label**: that's the
   name the template sends. Required, validation messages, the confirmation,
   notifications and add-on feeds are set there, not in the theme. If you
   can't edit Gravity Forms yourself, ask for it.
2. `php gaffer forms:show {name}`: the fields and labels the template will
   send.
3. Copy the closest template in `views/forms/` to `views/forms/{name}.twig`:
   the wrapper `id="form-{name}" hx-target="this" hx-swap="outerHTML"`, the
   `form.sent` branch with `form.message|raw`, `hx-post="{{ ajax_url('Form') }}"`,
   the theme's meta partial (form name, honeypot, Turnstile), and per field
   `name="fields[label]"`, `value="{{ form.value('label') }}"` and
   `form.error('label')`. Show `form.error('')` and `form.failed` too.
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
   `form` (`Gaffer\Forms\FormState`): `value(field)`, `checked(field, choice)`,
   `error(field)` (`error('')`: about the whole form), `sent`, `message`
   (HTML: `|raw`), `failed` (the Gravity Forms form wasn't found). Checkboxes
   send `fields[x][]`. Spam fields: the honeypot `website` (hidden) and
   Turnstile (`data-response-field-name="turnstile"`, rendered with
   `render=explicit`; widgets in swapped-in content need rendering again).
4. Render it from PHP with `FormState::blank('{name}')` passed in (a block's
   `functions.php`, `footer.php`, ...) and include it with
   `with_context = false`.
5. The theme has one `ajax/Form/Form.php` (`class Form extends
   \Gaffer\Forms\FormAction {}`) for every form; create it only if it's
   missing.
6. Run `php gaffer doctor --wp` (form exists, only known fields, every
   required one sent, fields without an Admin Field Label), then submit it
   for real through the web server: a valid entry (use an e-mail address on a real domain), one with errors,
   and check the confirmation and the notification. Delete your test entries
   by ID afterwards.
