---
name: gaffer-form
description: Create or change a form in a Gaffer theme (a Gravity Forms form with the theme's own markup in views/forms/{name}.twig, posted to the Form ajax action). Use for any contact, newsletter or other form on the site.
---

# Gaffer form

1. The form lives in Gravity Forms: its title gives the name ("Nieuwsbrief" →
   `nieuwsbrief`). Single fields and File Upload fields (no Name/Address
   with sub-inputs, no pages). Give every field an **Admin Field Label**: that's the
   name the template sends. Required, validation messages, the confirmation,
   notifications and add-on feeds are set there, not in the theme. If you
   can't edit Gravity Forms yourself, ask for it. A Date field the template
   sends as `<input type="date">` is a Date Picker with the format
   yyyy-mm-dd (`ymd_dash`: what the browser sends); the other date types
   have sub-inputs.
2. `php gaffer forms:show {name}`: the fields and labels the template will
   send.
3. Copy the closest template in `views/forms/` to `views/forms/{name}.twig`:
   the wrapper `id="form-{name}"` (the answer replaces it: `hx-target="#form-{name}"
   hx-swap="outerHTML"` on the `<form>` itself, so nothing depends on inheritance), the
   `form.sent` branch with `form.message|raw`, `hx-post="{{ ajax_url('Form') }}"`,
   Gaffer's partial `@gaffer/forms/meta.twig` (form name, honeypot, Turnstile), and per field
   `name="fields[label]"`, `value="{{ form.value('label') }}"` and
   `form.error('label')`. Show `form.failed` too (with `form.message` when there is one).
   ```twig
   <div id="form-contact">
       {% if form.sent %}{{ form.message|raw }}{% else %}
       <form hx-post="{{ ajax_url('Form') }}" hx-target="#form-contact" hx-swap="outerHTML" hx-disable="find button" novalidate>
           {{ include('@gaffer/forms/meta.twig', { form: form, turnstile: { appearance: 'interaction-only' } }, with_context = false) }}
           ...fields: name="fields[naam]", value="{{ form.value('naam') }}", error: form.error('naam')
       </form>
       {% endif %}
   </div>
   ```
   `form` (`Gaffer\Forms\FormState`): `value(field)`, `checked(field, choice)`,
   `error(field)`, `sent`, `failed` (the whole form failed: the Gravity Forms
   form wasn't found, or Gravity Forms refused it), `message` (with `sent`:
   the confirmation, HTML, `|raw`; with `failed`: the reason as plain text,
   null when there is none: `{{ form.message ?: 'Er is iets misgegaan.'|t }}`). Checkboxes
   send `fields[x][]`. The examples are htmx 4 (`hx-disable`); a theme still on htmx 2
   (`package.json`) writes `hx-disabled-elt` instead. The form name, the honeypot and the Turnstile widget
   are Gaffer's: always `@gaffer/forms/meta.twig` inside the `<form>`, never
   written by hand (Gaffer checks them and may change them in an update;
   `doctor` errors on a form without it, and on `name="website"`,
   `cf-turnstile` or Turnstile's script in theme code). `turnstile` (optional)
   takes the widget's `appearance`, `language`, `theme`, `size` and a `class`.
   Gaffer loads Cloudflare's script and renders widgets, also after htmx swaps.
4. **Files** (only for a form with File Upload fields). The field's settings in Gravity Forms are the rules: allowed
   extensions, maximum size, "Enable Multi-File Upload" with a maximum number,
   required (`forms:show` prints them after the type). Nothing about limits in
   the theme.
   ```twig
   <form hx-post="{{ ajax_url('Form') }}" hx-target="#form-schade" hx-swap="outerHTML" hx-encoding="multipart/form-data" hx-disable="find button" novalidate>
       ...
       <input type="file" name="files[fotos][]" multiple accept="image/*" data-gaffer-resize="2000">
       {% for file in form.files('fotos') %}
           <span>{{ file.name }}<input type="hidden" name="kept_files[fotos][]" value="{{ file.value }}">
               <button type="button" @click="$el.parentElement.remove()" aria-label="{{ 'Verwijderen'|t }}">×</button></span>
       {% endfor %}
       {{ form.error('fotos') }}
   ```
   - The `<form>` tag with `hx-encoding` may sit in a partial that several
     form templates include (doctor follows the include).
   - `files[x][]` + `multiple` for a multi-file field, `files[x]` for one
     file. `accept` only filters the picker. No `capture`: phones then offer
     camera and library.
   - After errors, `form.files('x')` are the files Gravity Forms kept
     (`name`, `value`): list them and send each `value` back as
     `kept_files[x][]` (removing the input drops the file). A field that has
     an error itself loses its new files (its message names the refused
     ones); the visitor picks them again.
   - **Shrinking photos:** once in the theme's JS, `import { shrinkUploads }
     from "@gaffer/forms.js"; shrinkUploads();`. An input with
     `data-gaffer-resize="2000"` sends photos larger than 2000 px as JPEG at
     2000 px (HEIC always as JPEG, the name ends in `.jpg`: allow `jpg` in
     the field). PDFs and small images go as they are. The form has
     `aria-busy="true"` while it shrinks (`aria-busy:` in Tailwind).
   - Progress: `x-data="{ progress: 0 }"` with
     `@htmx:xhr:progress="progress = $event.detail.loaded / ($event.detail.total || 1)"`.
     A request above PHP's `post_max_size` gets a 413 and no swap: show a
     message with `@htmx:response-error="tooLarge = $event.detail.xhr.status === 413"`.
   - The web server's PHP limits apply: `upload_max_filesize` (per file:
     Gravity Forms' size message), `post_max_size` (the whole request),
     `max_file_uploads` (20: files beyond it are dropped silently).
   - Gravity Forms stores the files under `uploads/gravity_forms/` with the
     entry. Don't attach them to notification e-mails unless the site asks
     (photos show people and plates): link to the entry.
5. Render it from PHP with `FormState::blank('{name}')` passed in (a block's
   `functions.php`, `footer.php`, ...) and include it with
   `with_context = false`.
6. The theme has one `app/Ajax/Form/Form.php` (`class Form extends
   \Gaffer\Forms\FormAction {}`) for every form; create it only if it's
   missing.
7. Run `php gaffer doctor --wp` (form exists, only known fields, every
   required one sent, fields without an Admin Field Label, files sent as
   `files[x]` from a multipart form, the meta partial; outside production it
   also submits to a throwaway Gravity Forms form to check that Gravity Forms
   still stores Gaffer's submissions right), then submit it
   for real through the web server: a valid entry (use an e-mail address on a real domain), one with errors,
   and check the confirmation and the notification. A confirmation alone
   isn't proof: check the response has no `X-Gaffer-Spam` header and that the
   entry and the e-mail exist (a script gets no Turnstile token, so with
   Turnstile on, test valid entries in a browser or ask the user). Delete your
   test entries by ID afterwards. Files: `curl -F 'files[fotos][]=@photo.jpg'
   -F form_name=... -H 'HX-Request: true'` to the action's URL (`ajax:call` sends
   no files); test shrinking in a browser.
