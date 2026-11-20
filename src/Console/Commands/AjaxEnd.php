<?php

declare(strict_types=1);

namespace Gaffer\Console\Commands;

use Gaffer\Console\Ajax\Session;
use Gaffer\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ends an ajax:call session: deletes its WooCommerce session (cart included) and forgets its
 * cookies, so tests leave no carts behind. What else the calls wrote (form entries, orders)
 * stays: those are the site's own data.
 */
#[AsCommand('ajax:end', 'End an ajax:call session: delete its WooCommerce session and cart, forget its cookies')]
final class AjaxEnd extends Command
{
    protected bool $wordpress = true;

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('session', InputArgument::REQUIRED, 'The session name given to ajax:call --session');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('session');
        try {
            if (!Session::exists($name)) {
                $output->writeln("<comment>No session {$name}.</comment>");
                return self::SUCCESS;
            }
            $session = Session::load($name);
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return self::FAILURE;
        }

        $customers = $session->woocommerce_customers();
        if ($customers !== [] && function_exists('WC') && class_exists('WC_Session_Handler')) {
            $handler = \WC()->session instanceof \WC_Session_Handler ? \WC()->session : new \WC_Session_Handler();
            foreach ($customers as $customer) {
                $handler->delete_session($customer);
            }
        }
        $session->forget();
        $output->writeln("Ended session {$name}" . ($customers !== [] ? ' (WooCommerce session and cart deleted)' : '') . '.');

        return self::SUCCESS;
    }
}
