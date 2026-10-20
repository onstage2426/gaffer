<?php

declare(strict_types=1);

namespace Gaffer\Console\Checks;

use Closure;
use Gaffer\BlockFields;
use Gaffer\FieldGroups;
use Gaffer\Config;
use Gaffer\Console\Report;
use Gaffer\Console\ThemeFiles;
use Gaffer\Console\WordPress;
use Gaffer\Forms\FormTemplates;
use Gaffer\Forms\GravityForm;
use Gaffer\Mcp\Mcp;
use Gaffer\Mcp\Tool;
use Gaffer\Paths;
use Gaffer\Types\Image;
use RuntimeException;
use WP_Block_Type_Registry;

/**
 * What WordPress actually has: registered blocks, block names and ACF field keys
 * used in content, ACF JSON vs database, ACF field types, WooCommerce template
 * names, form templates vs Gravity Forms, menu locations, the MCP server.
 * Needs WordPress loaded.
 */
final class WordPressCheck implements Check
{
    #[\Override]
    public function run(Report $report): void
    {
        $this->theme($report);
        $this->blocks($report);
        $this->acf_sync($report);
        $this->acf_orphans($report);
        $this->acf_database_only($report);
        $this->field_types($report);
        $this->field_groups($report);
        $this->woocommerce_templates($report);
        $this->forms($report);
        $this->menus($report);
        $this->image_sizes($report);
        $this->mcp($report);
    }

    /**
     * A srcset only offers registered sizes up to max_srcset_image_width, plus the original when
     * it fits under that: with WordPress's 1536 and 2048 sizes removed, a large original's widest
     * candidate can be 1024px, blurry on wide or high-density screens (and in cropped boxes).
     */
    private function image_sizes(Report $report): void
    {
        $original = 4000; // a large upload
        $cap = (int) \apply_filters('max_srcset_image_width', 2048, [$original, $original]);
        $widths = array_filter(
            array_map(static fn(array $size): int => (int) ($size['width'] ?? 0), \wp_get_registered_image_subsizes()),
            static fn(int $width): bool => $width > 0 && $width <= $cap,
        );
        $widest = $widths === [] ? 0 : max($widths);
        if ($widest < 1536) {
            $report->warning('wp', "srcset candidates for large uploads stop at {$widest}px wide (registered image sizes up to max_srcset_image_width {$cap}px)", null, null,
                "Keep WordPress's 1536x1536 and 2048x2048 sizes, or register a wide size; raising max_srcset_image_width only helps originals up to that width.");
        }
    }

    /**
     * The other checks describe the loaded site: it must be the one that runs this theme.
     */
    private function theme(Report $report): void
    {
        if (!WordPress::is_theme_active()) {
            $report->error('wp', 'The loaded site (' . \home_url('/') . ') runs the theme "' . \get_stylesheet() . '", not this one', null, null,
                'Set SITE_URL in .env to the site that uses this theme (`php gaffer init`); the checks below describe the other site.');
        }
    }

    /**
     * Gaffer's MCP server answers an agent's start command with every tool (catches
     * MCP Adapter updates that break it, and WP-CLI missing from the PATH).
     */
    private function mcp(Report $report): void
    {
        if (!Mcp::enabled()) {
            return;
        }
        $expected = array_map(static fn(Tool $tool): string => "gaffer-{$tool->name()}", Mcp::tools());

        try {
            $served = Mcp::served_tools();
        } catch (RuntimeException $e) {
            $report->error('mcp', $e->getMessage(), null, null,
                'Agents get no gaffer tools. Check that `wp` runs from the theme directory; after an MCP Adapter update, check its changelog for create_server() changes.');
            return;
        }

        $missing = array_diff($expected, $served);
        if ($missing !== []) {
            $report->error('mcp', 'The MCP server "gaffer" lacks ' . implode(', ', $missing) . ' (serves: ' . (implode(', ', $served) ?: 'nothing') . ')', null, null,
                'Run the start command from .mcp.json by hand and look at stderr; abilities may have failed to register.');
            return;
        }
        $report->info('mcp', 'MCP server "gaffer" serves its ' . count($expected) . ' tools'
            . (\wp_get_environment_type() === 'production' ? ' on production (ai.mcp_production: turn it off after the migration)' : ''));
    }

    private function blocks(Report $report): void
    {
        $registry = WP_Block_Type_Registry::get_instance();

        foreach (BlocksCheck::names() as $dir => $name) {
            if (!$registry->is_registered($name)) {
                $report->error('wp', "Block {$name} is not registered", Paths::blocks() . "/{$dir}/block.json");
            }
        }

        // ACF block names used in content that nothing registers (e.g. after a rename).
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT ID, post_type, post_content FROM {$wpdb->posts}
             WHERE post_content LIKE '%<!-- wp:acf/%' AND post_type <> 'revision' AND post_status <> 'trash'",
        );

        $orphans = [];
        foreach ($rows as $row) {
            preg_match_all('#<!-- wp:(acf/[a-z0-9-]+)#', $row->post_content, $m);
            foreach (array_unique($m[1]) as $name) {
                if (!$registry->is_registered($name)) {
                    $orphans[$name][] = "{$row->post_type} {$row->ID}";
                }
            }
        }
        foreach ($orphans as $name => $posts) {
            $report->error('wp', "Content uses unregistered block {$name}: " . implode(', ', $posts), null, null,
                'Renamed block? php gaffer migrate:block <old> <new>.');
        }

        $this->stale_fields($report, $rows);
    }

    /**
     * Block data whose field keys no ACF field has (a field renamed or removed).
     *
     * @param array<object> $rows
     */
    private function stale_fields(Report $report, array $rows): void
    {
        if (!function_exists('acf_get_field')) {
            return;
        }

        $stale = [];
        $walk = function (array $blocks, string $post) use (&$walk, &$stale): void {
            foreach ($blocks as $block) {
                foreach ($block['attrs']['data'] ?? [] as $name => $key) {
                    if (is_string($name) && str_starts_with($name, '_') && is_string($key) && str_starts_with($key, 'field_') && !acf_get_field($key)) {
                        $stale[$block['blockName']][substr($name, 1)][$post] = true;
                    }
                }
                $walk($block['innerBlocks'] ?? [], $post);
            }
        };
        foreach ($rows as $row) {
            $walk(parse_blocks($row->post_content), "{$row->post_type} {$row->ID}");
        }

        foreach ($stale as $block => $fields) {
            foreach ($fields as $field => $posts) {
                $report->warning('wp', "{$block}: content stores \"{$field}\" for a field that no longer exists: " . implode(', ', array_keys($posts)), null, null,
                    'Renamed field? php gaffer migrate:field <block> <old> <new>. Removed on purpose? php gaffer migrate:remove-field <block> <field>.');
            }
        }
    }

    private function acf_sync(Report $report): void
    {
        if (!function_exists('acf_get_field_group_post')) {
            return;
        }

        foreach (glob(Paths::storage() . '/acf-json/group_*.json') ?: [] as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            if (!is_array($json) || !isset($json['key'])) {
                continue; // AcfJsonCheck reports invalid files
            }

            $post = acf_get_field_group_post($json['key']);
            if (!$post) {
                $report->warning('wp', "ACF field group \"{$json['title']}\": only in JSON (sync it in ACF → Field Groups)", $file);
                continue;
            }
            // By content, not by timestamp: ACF's own sync leaves the database newer than an identical JSON file.
            $database = self::database_group($json['key']);
            if ($database !== null && self::normalized($database) == self::normalized($json)) {
                continue;
            }

            $report->warning('wp', "ACF field group \"{$json['title']}\": the JSON and the database differ, " . (
                ($json['modified'] ?? 0) >= strtotime("{$post->post_modified_gmt} UTC")
                    ? 'the JSON is newer (sync it in ACF → Field Groups)'
                    : 'the database is newer (re-save the group in ACF to write the JSON)'
            ), $file);
        }
    }

    /**
     * The database copy of a field group with its fields, read with local JSON
     * switched off the way ACF's own sync does.
     *
     * @return array<string, mixed>|null
     */
    private static function database_group(string $key): ?array
    {
        acf_disable_filter('local');
        try {
            $group = acf_get_field_group($key);
            if (!is_array($group)) {
                return null;
            }
            $group['fields'] = acf_get_fields($group);

            return $group;
        } finally {
            acf_enable_filter('local');
        }
    }

    /**
     * A field group with ACF's defaults filled in and in its export shape, so an
     * older JSON file (without settings a newer ACF added) still equals its
     * database copy.
     *
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private static function normalized(array $group): array
    {
        $fields = static function (array $list) use (&$fields): array {
            return array_map(static function (array $field) use ($fields): array {
                $field = acf_validate_field($field);
                if (is_array($field['sub_fields'] ?? null)) {
                    $field['sub_fields'] = $fields($field['sub_fields']);
                }
                return $field;
            }, $list);
        };
        $group = acf_validate_field_group($group);
        $group['fields'] = $fields(is_array($group['fields'] ?? null) ? $group['fields'] : []);
        $group = acf_prepare_field_group_for_export($group);
        unset($group['modified']);

        return $group;
    }

    /**
     * Every type in blocks/*\/fields.php is a field type ACF has registered (asked at
     * runtime, so new ACF versions and plugin field types need no Gaffer update).
     */
    private function field_types(Report $report): void
    {
        if (!function_exists('acf_get_field_type')) {
            return;
        }

        $check = function (array $fields, string $file) use (&$check, $report): void {
            foreach ($fields as $field) {
                if (!acf_get_field_type((string) $field['type'])) {
                    $report->error('wp', "Unknown ACF field type \"{$field['type']}\" (field \"" . ($field['name'] ?? $field['label'] ?? '?') . '")', $file);
                }
                if (is_array($field['sub_fields'] ?? null)) {
                    $check($field['sub_fields'], $file);
                }
            }
        };
        foreach (glob(Paths::blocks() . '/*/fields.php') ?: [] as $file) {
            try {
                $check(BlockFields::group(basename(dirname($file)))['fields'], $file);
            } catch (\Throwable) {
                continue; // BlocksCheck reports invalid fields.php
            }
        }
        foreach (FieldGroups::targets() as $target => $file) {
            try {
                $check(FieldGroups::group($target)['fields'], $file);
            } catch (\Throwable) {
                continue; // FieldGroupsCheck reports it
            }
        }
    }

    /**
     * What's made in the ACF UI but only in the database, not in storage/acf-json/ (ACF names
     * each file after its key, the post's post_name): not in git, so another checkout or a
     * fresh site doesn't have it.
     */
    private function acf_database_only(Report $report): void
    {
        global $wpdb;

        $kinds = ['acf-field-group' => 'field group', 'acf-post-type' => 'post type', 'acf-taxonomy' => 'taxonomy', 'acf-ui-options-page' => 'options page'];
        $rows = $wpdb->get_results(
            "SELECT post_type, post_name, post_title FROM {$wpdb->posts}
             WHERE post_type IN ('" . implode("', '", array_keys($kinds)) . "') AND post_status IN ('publish', 'acf-disabled') ORDER BY post_type, post_title",
        );
        foreach ($rows as $row) {
            if (is_file(Paths::storage() . "/acf-json/{$row->post_name}.json")) {
                continue;
            }
            $kind = $kinds[$row->post_type] ?? $row->post_type;
            $code = $row->post_type === 'acf-field-group' ? self::code_target((string) $row->post_name) : null;
            $report->warning('wp', "ACF {$kind} \"{$row->post_title}\" is only in the database, not in storage/acf-json/", null, null, match (true) {
                $code !== null => "Its target defines its fields in code ({$code}): php gaffer migrate:fields " . (str_starts_with($code, 'blocks/') ? self::block_of($code) : substr($code, strlen('fields/'), -4)) . ', then delete this group in ACF.',
                $row->post_type === 'acf-field-group' => "Move its fields to code (fields/ or a block's fields.php, gaffer-fields skill); until then save it once in ACF so it writes storage/acf-json/{$row->post_name}.json, and commit that.",
                default => "Open it in ACF and save it once: ACF writes storage/acf-json/{$row->post_name}.json; commit that.",
            });
        }
    }

    /**
     * The code file that already defines the fields of this field group's target, if any:
     * "blocks/contentFaq/fields.php" or "fields/post_type/product.php".
     */
    private static function code_target(string $key): ?string
    {
        $group = function_exists('acf_get_field_group') ? acf_get_field_group($key) : false;
        if (!is_array($group)) {
            return null;
        }
        $target = FieldGroups::target_of($group['location'] ?? null);
        if ($target !== null && isset(FieldGroups::targets()[$target])) {
            return "fields/{$target}.php";
        }
        $blocks = array_flip(BlocksCheck::names());
        foreach (is_array($group['location'] ?? null) ? $group['location'] : [] as $and) {
            foreach (is_array($and) ? $and : [] as $rule) {
                $dir = is_array($rule) && ($rule['param'] ?? null) === 'block' ? ($blocks[$rule['value'] ?? ''] ?? null) : null;
                if ($dir !== null && is_file(Paths::blocks() . "/{$dir}/fields.php")) {
                    return "blocks/{$dir}/fields.php";
                }
            }
        }

        return null;
    }

    /** blocks/contentFaq/fields.php → acf/content-faq */
    private static function block_of(string $file): string
    {
        return (string) (BlocksCheck::names()[basename(dirname($file))] ?? basename(dirname($file)));
    }

    /**
     * acf-field posts whose group or parent field no longer exists (left behind when a group
     * was deleted outside ACF): invisible in the ACF UI, never loaded, only clutter.
     */
    private function acf_orphans(Report $report): void
    {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT f.ID FROM {$wpdb->posts} f LEFT JOIN {$wpdb->posts} p ON p.ID = f.post_parent
             WHERE f.post_type = 'acf-field' AND (p.ID IS NULL OR p.post_type NOT IN ('acf-field-group', 'acf-field')) ORDER BY f.ID",
        );
        if ($ids !== []) {
            $report->warning('wp', 'ACF field posts belong to a field group or field that no longer exists', null, null,
                count($ids) . ' post(s): ' . implode(', ', $ids) . ". Delete them (wp post delete --force) after checking they're not a group you still need.");
        }
    }

    /**
     * Every fields/ file targets a registered post type, taxonomy or options page, and no
     * stored reference points to a derived key its file no longer has (a field renamed or
     * removed in code: ACF then returns that value unformatted).
     */
    private function field_groups(Report $report): void
    {
        global $wpdb;

        $keys = [];
        foreach (FieldGroups::targets() as $target => $file) {
            [$param, $value] = explode('/', $target, 2);
            $exists = match ($param) {
                'post_type' => post_type_exists($value),
                'taxonomy' => taxonomy_exists($value),
                default => !function_exists('acf_get_options_page') || acf_get_options_page($value) !== false,
            };
            if (!$exists && is_multisite()) {
                // One theme, several sites: a WooCommerce attribute or post type may exist on only some of them.
                $report->warning('wp', 'Targets ' . str_replace('_', ' ', $param) . " \"{$value}\", which isn't registered on this site", $file, null,
                    'Fine when another site of the network has it; check the site that should.');
            } elseif (!$exists) {
                $report->error('wp', 'Targets ' . str_replace('_', ' ', $param) . " \"{$value}\", which isn't registered", $file);
            }
            try {
                $walk = static function (array $fields) use (&$walk, &$keys): void {
                    foreach ($fields as $field) {
                        $keys[$field['key']] = true;
                        $walk(is_array($field['sub_fields'] ?? null) ? $field['sub_fields'] : []);
                    }
                };
                $walk(FieldGroups::group($target)['fields']);
            } catch (\Throwable) {
                continue;
            }

            $like = $wpdb->esc_like(FieldGroups::prefix($target) . '__') . '%';
            $stale = [];
            foreach ([[$wpdb->postmeta, 'meta_key', 'meta_value'], [$wpdb->termmeta, 'meta_key', 'meta_value'], [$wpdb->options, 'option_name', 'option_value']] as [$table, $name, $column]) {
                foreach ($wpdb->get_results($wpdb->prepare(
                    "SELECT {$column} AS k, COUNT(*) AS n FROM {$table} WHERE {$name} LIKE '\\_%%' AND {$column} LIKE %s GROUP BY {$column}",
                    $like,
                )) as $row) {
                    if (!isset($keys[$row->k])) {
                        $stale[$row->k] = ($stale[$row->k] ?? 0) + (int) $row->n;
                    }
                }
            }
            foreach ($stale as $key => $count) {
                $report->warning('wp', "Stored values point to {$key}, which fields/{$target}.php no longer has", $file, null,
                    "{$count} stored value(s). Renamed or removed a field? Restore its name, or delete the old values.");
            }
        }
    }

    /**
     * Every file in woocommerce/ overrides a template WooCommerce has (else
     * WooCommerce never loads it by itself, and a renamed or removed template
     * silently stops being overridden), and no custom woocommerce.php router.
     */
    private function woocommerce_templates(Report $report): void
    {
        if (!function_exists('WC')) {
            return;
        }

        $dir = Paths::base('woocommerce');
        $files = is_dir($dir) ? array_map(static fn(string $f): string => substr($f, strlen($dir) + 1), ThemeFiles::find(['php'], $dir)) : [];
        $core = WC()->plugin_path() . '/templates/';
        // Besides its template files, WooCommerce's loader looks for per-taxonomy and per-product page templates
        $loader = '/^(?:taxonomy-(?:' . implode('|', array_map(static fn(string $t): string => preg_quote($t, '/'), get_object_taxonomies('product'))) . ')(?:-[^\/]+)?|single-product-[^\/]+)\.php$/';
        foreach (self::unknown_templates($files, static fn(string $relative): bool => is_file($core . $relative) || preg_match($loader, $relative) === 1) as $relative) {
            $report->warning('wp', "woocommerce/{$relative} is not a WooCommerce template: WooCommerce never loads it by itself", "{$dir}/{$relative}", null,
                'Use the name of the WooCommerce template it should replace, or render the view from that template.');
        }

        if (is_file(Paths::base('woocommerce.php'))) {
            $report->warning('wp', 'woocommerce.php replaces WooCommerce\'s own template choice for every shop page', Paths::base('woocommerce.php'), null,
                'Delete it and use WooCommerce\'s hierarchy: single-product.php, archive-product.php, taxonomy-product_cat.php in woocommerce/.');
        }
    }

    /**
     * @param list<string> $files paths relative to woocommerce/
     * @param Closure(string): bool $exists whether WooCommerce has a template at that path
     * @return list<string>
     */
    public static function unknown_templates(array $files, Closure $exists): array
    {
        return array_values(array_filter($files, static fn(string $file): bool => !$exists($file)));
    }

    /**
     * Each views/forms/{name}.twig has an active Gravity Forms form titled like it,
     * sends only fields the form has, and sends every required one.
     */
    private function forms(Report $report): void
    {
        $templates = FormTemplates::all();
        if ($templates === []) {
            return;
        }
        if (!class_exists('GFAPI')) {
            $report->error('forms', 'views/forms/ has form templates, but Gravity Forms is not active');
            return;
        }
        if (!is_file(Paths::ajax() . '/Form/Form.php')) {
            $report->error('forms', 'No app/Ajax/Form/Form.php: forms post to it', null, null,
                'class Form extends \\Gaffer\\Forms\\FormAction {} in namespace Theme\\Ajax\\Form.');
        }

        foreach ($templates as $name => $file) {
            $gravity = GravityForm::find($name);
            if ($gravity === null) {
                $report->error('forms', "No active Gravity Forms form titled like \"{$name}\"", $file, null,
                    'The form\'s title must slug to the template name (php gaffer forms:show).');
                continue;
            }
            $fields = $gravity->fields();
            $sent = FormTemplates::field_names($file);
            foreach (array_diff($sent, array_keys($fields)) as $unknown) {
                $report->error('forms', "Sends \"{$unknown}\", which {$gravity->title()} has no field for", $file, null,
                    'Set it as the Admin Field Label of the field in Gravity Forms, or fix the name.');
            }
            foreach ($fields as $field_name => $field) {
                if ($field->isRequired && !in_array($field_name, $sent, true)) {
                    $report->error('forms', "Doesn't send \"{$field_name}\", which {$gravity->title()} requires", $file);
                }
            }
            foreach ($gravity->unnamed() as $field) {
                $report->warning('forms', "{$gravity->title()}: field \"{$field->label}\" has no Admin Field Label, so the theme can't send it", $file);
            }
        }
    }

    private function menus(Report $report): void
    {
        $assigned = get_nav_menu_locations();

        foreach (get_registered_nav_menus() as $location => $label) {
            $menu = $assigned[$location] ?? 0;
            if (!$menu) {
                $report->warning('wp', "Menu location \"{$location}\" ({$label}) has no menu assigned", null, null,
                    'Appearance → Menus → Manage Locations.');
            } elseif (!wp_get_nav_menu_object($menu)) {
                $report->error('wp', "Menu location \"{$location}\" points at menu {$menu}, which doesn't exist");
            }
        }
    }
}
