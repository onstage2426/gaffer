<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Ajax;
use Gaffer\Console\Ajax\Session;
use Gaffer\Console\Command;
use Gaffer\Console\Render\HtmlDiff;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WP_Http_Cookie;

/**
 * Calls one ajax action of this theme over HTTP, the way htmx in a browser does: through
 * the site and its dispatcher, with the action's own method and `HX-Request`. With --session
 * the visitor's cookies are kept between calls (Console\Ajax\Session), so a sequence (add to
 * cart, open the drawer, update, remove) runs as one visitor; ajax:end cleans up. This writes
 * what the action writes: a cart, a session, a form entry and its e-mails.
 */
#[AsCommand('ajax:call', 'Call an ajax action over HTTP as a visitor (--session keeps the visitor between calls)')]
final class AjaxCall extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'The action, e.g. CartAdd');
        $this->addArgument('params', InputArgument::IS_ARRAY, 'Parameters as key=value, e.g. product=65 quantity=2 "attributes[pa_size]=xl"');
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'Keep this visitor\'s cookies under a name between calls, e.g. cart-test (end with ajax:end)');
        $this->addOption('select', null, InputOption::VALUE_REQUIRED, 'Only the elements matching this CSS selector');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $json = (bool) $input->getOption('json');
        $fail = static function (string $error) use ($output, $json): int {
            $output->writeln($json ? (string) json_encode(['error' => $error], JSON_UNESCAPED_SLASHES) : "<error>{$error}</error>");
            return self::FAILURE;
        };

        $class = in_array($action, Ajax::actions(), true) ? Ajax::action_class($action) : null;
        if ($class === null) {
            return $fail("No ajax action {$action} (actions: " . implode(', ', Ajax::actions()) . ')');
        }
        try {
            $params = self::params(array_values(array_map('strval', (array) $input->getArgument('params'))));
            $session = is_string($name = $input->getOption('session')) ? Session::load($name) : null;
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }

        $method = $class::METHOD;
        $url = Ajax::url($action);
        if ($method === 'GET' && $params !== '') {
            $url .= "&{$params}";
        }
        $started = hrtime(true);
        $response = \wp_remote_request($url, [
            'method' => $method,
            'timeout' => 30,
            'redirection' => 0,
            'headers' => ['HX-Request' => 'true', 'HX-Current-URL' => \home_url('/'), ...($method === 'POST' ? ['Content-Type' => 'application/x-www-form-urlencoded'] : [])],
            'body' => $method === 'POST' ? $params : null,
            'cookies' => $session !== null ? array_map(
                static fn(string $name, string $value): WP_Http_Cookie => new WP_Http_Cookie(['name' => $name, 'value' => $value]),
                array_keys($session->cookies()),
                $session->cookies(),
            ) : [],
        ]);
        if (\is_wp_error($response)) {
            return $fail("The request failed: {$response->get_error_message()}");
        }

        if ($session !== null) {
            $session->update(array_map(static fn(WP_Http_Cookie $c): array => [
                'name' => (string) $c->name,
                'value' => (string) $c->value,
                'expires' => is_numeric($c->expires) ? (int) $c->expires : null,
            ], array_values(\wp_remote_retrieve_cookies($response))));
            $session->save();
        }

        $headers = [];
        foreach (\wp_remote_retrieve_headers($response) as $header => $value) {
            if (preg_match('/^(hx-|x-gaffer-|location$)/i', (string) $header)) {
                $headers[strtolower((string) $header)] = is_array($value) ? implode(', ', $value) : (string) $value;
            }
        }
        $html = \wp_remote_retrieve_body($response);
        $result = [
            'action' => $action,
            'method' => $method,
            'status' => (int) \wp_remote_retrieve_response_code($response),
            'time_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'headers' => (object) $headers,
        ];
        if (is_string($select = $input->getOption('select'))) {
            try {
                $result['matches'] = HtmlDiff::select_all($html, $select);
            } catch (\InvalidArgumentException $e) {
                return $fail($e->getMessage());
            }
        } else {
            $result['html'] = $html;
        }

        if ($json) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }
        $output->writeln("<info>{$method} {$action}</info> → {$result['status']} ({$result['time_ms']} ms)" . ($session !== null ? " session {$session->name}" : ''));
        foreach ($headers as $header => $value) {
            $output->writeln("{$header}: {$value}");
        }
        $output->writeln('');
        $output->writeln(isset($result['matches']) ? ($result['matches'] !== [] ? implode("\n", $result['matches']) : '(no element matches)') : $html, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * key=value arguments as a query string; keys may be PHP array keys (attributes[pa_size]).
     *
     * @param list<string> $pairs
     * @throws \InvalidArgumentException on an argument without "="
     */
    public static function params(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as $pair) {
            $at = strpos($pair, '=');
            if ($at === false || $at === 0) {
                throw new \InvalidArgumentException("Parameters are key=value, not \"{$pair}\"");
            }
            $parts[] = urlencode(substr($pair, 0, $at)) . '=' . urlencode(substr($pair, $at + 1));
        }

        return implode('&', $parts);
    }
}
