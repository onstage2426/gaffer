<?php

declare(strict_types=1);

namespace Gaffer\Forms;

use Gaffer\AjaxAction;
use Gaffer\View;

/**
 * The one ajax action every form posts to (the theme's app/Ajax/Form/Form.php
 * extends it). Checks the honeypot and Turnstile, submits to the Gravity Forms
 * form named like the template, and answers with views/forms/{form}.twig
 * re-rendered (errors, or sent), or an HX-Redirect for a confirmation page.
 * Spam gets the same answer as a submission; outside production with an
 * X-Gaffer-Spam header with the reason (Spam::log()). The form key, the honeypot and
 * the Turnstile widget are written by Gaffer's @gaffer/forms/meta.twig, so both ends
 * change together. Files come as `files[field]` /
 * `files[field][]` (multipart) for File Upload fields; spam never reaches Gravity
 * Forms, so its files are never stored (PHP deletes a request's uploads).
 */
abstract class FormAction extends AjaxAction
{
    public const string METHOD = 'POST';

    /**
     * @param string $form_name the template (views/forms/{name}.twig) and Gravity Forms form (title slug)
     * @param array<string, mixed> $fields the values, by field name (fields[naam])
     * @param array<mixed> $kept_files files kept after errors, by field name (FormState::files())
     * @param string $form the name as @gaffer/forms/meta.twig sent it before 2026-11-20 (a page cache
     *        may still serve that markup). Not "form" any more: an input named "form" shadows the
     *        <form> element's own .form property, and htmx 4 then can't read the form.
     *
     * @internal The dispatcher calls it with the keys @gaffer/forms/meta.twig and the form
     *           template send (Gaffer's own pair): its parameters change with them.
     */
    final public function run(string $form_name = '', array $fields = [], array $kept_files = [], string $form = ''): void
    {
        $form = $form_name !== '' ? $form_name : $form;
        if (!preg_match('/^[a-z0-9-]+$/', $form) || !View::env()->getLoader()->exists("forms/{$form}.twig")) {
            http_response_code(404);
            header('HX-Reswap: none'); // htmx 4 swaps errors: an empty answer would wipe the form
            return;
        }

        $gravity = GravityForm::find($form);
        if ($gravity === null) {
            error_log("Gaffer forms: no active Gravity Forms form titled like \"{$form}\"");
            $this->render(new FormState($form, $fields, failed: true));
            return;
        }

        $spam = Spam::check($form);
        if ($spam !== null) {
            $fake = $gravity->default_confirmation(); // spam must look like it worked
            $this->answer($form, $fields, $fake['message'], $fake['redirect']);
            return;
        }

        $kept = [];
        foreach ($kept_files as $field => $values) {
            if (is_string($field) && is_array($values)) {
                $kept[$field] = array_values(array_filter($values, 'is_string'));
            }
        }
        $result = $gravity->submit($fields, Uploads::by_field($_FILES['files'] ?? null), $kept);
        if (!$result['valid']) {
            $this->render(new FormState($form, $fields, $result['errors'], message: $result['message'], failed: $result['failed'], files: $result['kept']));
            return;
        }

        $this->answer($form, $fields, $result['message'], $result['redirect']);
    }

    /** @param array<string, mixed> $fields */
    private function answer(string $form, array $fields, ?string $message, ?string $redirect): void
    {
        if ($redirect !== null && $redirect !== '') {
            header('HX-Redirect: ' . $redirect);
            return;
        }
        $this->render(new FormState($form, $fields, sent: true, message: $message));
    }

    private function render(FormState $state): void
    {
        View::render("forms/{$state->name}.twig", ['form' => $state]);
    }
}
