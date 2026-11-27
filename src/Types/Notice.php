<?php

declare(strict_types=1);

namespace Gaffer\Types;

use Twig\Markup;

/**
 * A WooCommerce notice as a notice template shows it. WooCommerce passes the notices of one type
 * to its `notices/error.php`, `success.php` and `notice.php` as `$notices`; a theme's override
 * hands them to its Twig template:
 *
 *   View::render('components/notices.twig', ['type' => 'error', 'notices' => Notice::list($notices)]);
 *
 * and the template keeps only the markup:
 *
 *   <li{{ notice.attrs }}>{{ notice.message }}</li>
 */
final class Notice
{
    /** @param array{notice?: mixed, data?: mixed} $notice */
    private function __construct(private readonly array $notice)
    {
    }

    /**
     * The notices WooCommerce passed to a notices template, in its order.
     *
     * @param array<array{notice?: mixed, data?: mixed}> $notices
     * @return list<self>
     */
    public static function list(array $notices): array
    {
        return array_values(array_map(static fn(array $notice): self => new self($notice), $notices));
    }

    /**
     * The message as HTML, filtered the way WooCommerce's own templates do (`wc_kses_notice()`:
     * links, emphasis, the "View cart" button).
     */
    public function message(): Markup
    {
        $message = $this->notice['notice'] ?? '';

        return new Markup(\wc_kses_notice(is_scalar($message) ? (string) $message : ''), 'UTF-8'); // trusted: wc_kses_notice()
    }

    /**
     * The notice's data as escaped `data-*` attributes with a leading space, or nothing: WooCommerce's
     * checkout script reads them (`data-id`: the field an error is about) to mark the field and
     * scroll to it. Write them on the element that holds the message.
     */
    public function attrs(): Markup
    {
        return new Markup((string) \wc_get_notice_data_attr($this->notice), 'UTF-8'); // trusted: wc_get_notice_data_attr() escapes
    }
}
