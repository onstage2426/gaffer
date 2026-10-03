<?php

declare(strict_types=1);

namespace Gaffer\Forms;

use Gaffer\AjaxAction;
use Gaffer\Turnstile;
use Gaffer\View;

/**
 * The one ajax action every form posts to (the theme's ajax/Form/Form.php
 * extends it). Checks the honeypot and Turnstile, submits to the Gravity Forms
 * form named like the template, and answers with views/forms/{form}.twig
 * re-rendered (errors, or sent), or an HX-Redirect for a confirmation page.
 */
abstract class FormAction extends AjaxAction
{
    public const string METHOD = 'POST';

    /**
     * @param string $form the template (views/forms/{form}.twig) and Gravity Forms form (title slug)
     * @param array<string, mixed> $fields the values, by field name (fields[naam])
     * @param string $turnstile Turnstile's token (data-response-field-name="turnstile")
     * @param string $website the honeypot: hidden, so only bots fill it in
     */
    public function run(string $form, array $fields = [], string $turnstile = '', string $website = ''): void
    {
        if (!preg_match('/^[a-z0-9-]+$/', $form) || !View::env()->getLoader()->exists("forms/{$form}.twig")) {
            http_response_code(404);
            return;
        }

        $gravity = GravityForm::find($form);
        if ($gravity === null) {
            error_log("Gaffer forms: no active Gravity Forms form titled like \"{$form}\"");
            $this->render(new FormState($form, $fields, failed: true));
            return;
        }

        $spam = match (true) {
            $website !== '' => 'honeypot',
            Turnstile::enabled() && !Turnstile::verify($turnstile) => 'turnstile',
            default => null,
        };
        if ($spam !== null) {
            Turnstile::log_spam($form, $spam);
            $fake = $gravity->default_confirmation(); // spam must look like it worked
            $this->answer($form, $fields, $fake['message'], $fake['redirect']);
            return;
        }

        $result = $gravity->submit($fields);
        if (!$result['valid']) {
            $this->render(new FormState($form, $fields, $result['errors']));
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
