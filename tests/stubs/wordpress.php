<?php

// Stand-ins for the few WordPress functions unit tests touch.
// Excluded from phpstan, which has the real WordPress stubs.

if (!function_exists('get_pagenum_link')) {
    function get_pagenum_link(int $page = 1): string
    {
        return "/page/{$page}/";
    }
}

// Nothing exists in unit tests: lookups find nothing (the 404 path).
if (!function_exists('get_post')) {
    function get_post(mixed $post = null): null
    {
        return null;
    }
}

if (!function_exists('get_term')) {
    function get_term(mixed $term = null): null
    {
        return null;
    }
}

// Gravity Forms' field class, for GravityForm's input mapping tests.
if (!class_exists('GF_Field')) {
    class GF_Field
    {
        public $id;
        public $type;
        public $label = '';
        public $adminLabel = '';
        public $isRequired = false;
        public $inputs = null;
        public $choices = null;
    }
}
