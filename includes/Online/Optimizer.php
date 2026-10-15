<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

/** Compatibility with SiteGround Optimizer and private uploads outside ABSPATH. */
final class Optimizer {
    public const OPTIONS = [
        'optimize_javascript', 'optimize_css', 'combine_javascript', 'combine_css',
        'optimize_javascript_async', 'optimize_web_fonts', 'lazyload_images',
        'file_caching', 'logged_in_cache',
    ];
    public static function boot(): void {
        // Runtime only: preserve settings for other sites and after leaving Factory.
        foreach (self::OPTIONS as $option) {
            add_filter('pre_option_siteground_optimizer_'.$option, [self::class, 'option'], PHP_INT_MAX);
        }
        // SG may load before a network plugin. Remove callbacks already registered
        // then, as well as builder-dependent callbacks registered on init.
        add_action('plugins_loaded', [self::class, 'detach'], PHP_INT_MAX);
        add_action('init', [self::class, 'detach'], -200);
        add_action('wp', [self::class, 'detach'], PHP_INT_MAX);
    }
    public static function option($value) {
        return Privacy::managed() ? 0 : $value;
    }
    public static function detach(): void {
        if (!Privacy::managed()) { return; }
        global $wp_filter;
        foreach ($wp_filter as $tag => $hook) {
            foreach ($hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $entry) {
                    $callback = $entry['function'];
                    if (!is_array($callback) || !is_object($callback[0])) { continue; }
                    $class = get_class($callback[0]);
                    $method = $callback[1];
                    $remove = ($class === 'SiteGround_Optimizer\\Minifier\\Minifier' && in_array($method, ['minify_scripts','minify_styles'], true))
                        || ($class === 'SiteGround_Optimizer\\Front_End_Optimization\\Front_End_Optimization' && in_array($method, ['prepare_scripts_for_async_load','add_async_attribute'], true))
                        || (str_starts_with($class, 'SiteGround_Optimizer\\Lazy_Load\\') && in_array($method, ['filter_html','load_scripts'], true));
                    if ($remove) { remove_filter($tag, $callback, $priority); }
                }
            }
        }
    }
}
