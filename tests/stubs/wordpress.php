<?php

// Stand-ins for the few WordPress functions unit tests touch.
// Excluded from phpstan, which has the real WordPress stubs.

if (!function_exists('get_pagenum_link')) {
    function get_pagenum_link(int $page = 1): string
    {
        return "/page/{$page}/";
    }
}
