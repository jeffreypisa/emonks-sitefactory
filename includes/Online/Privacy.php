<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

final class Privacy {
    public static function managed(): bool { return (bool)get_site_meta(get_current_blog_id(), 'emsf_managed', true); }
    public static function member(): bool { return is_super_admin() || (is_user_logged_in() && is_user_member_of_blog(get_current_user_id(), get_current_blog_id())); }
    public static function visitorAllowed(): bool {
        $site=get_current_blog_id();$info=get_site($site);
        if(!self::managed()||!$info||$info->archived||$info->deleted||$info->spam||get_site_meta($site,'emsf_state',true)!=='ready'){return false;}
        // Public proposals expose only the same read-only frontend/media surface as codes.
        return Preview::allowed() || (Preview::mode($site)==='public' && is_ssl()
            && in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true) && Preview::frontend());
    }
    public static function boot(): void {
        add_action('template_redirect',static function(){if(self::managed()&&!self::member()&&(is_feed()||is_trackback()||!self::visitorAllowed())){wp_die('Geen toegang tot dit voorstel.','Geen toegang',['response'=>403]);}},-100);
        add_filter('upload_dir', [self::class, 'uploads']);
        add_action('init', [self::class, 'gate'], -100);
        add_filter('rest_authentication_errors', static function($error) {
            if (self::managed() && (!self::member() || self::unavailable())) { return new \WP_Error('emsf_private', 'Geen toegang tot dit voorstel.', ['status'=>403]); }
            return $error;
        }, 100);
        add_filter('acf/settings/load_json', static function($paths) {
            if (self::managed()) { $paths[] = Store::root().'/models/'.get_current_blog_id(); }
            return $paths;
        }, 999);
        add_filter('acf/settings/save_json', static fn($path) => self::managed() ? Store::root().'/models/'.get_current_blog_id() : $path, 999);
        add_filter('pre_option_blog_public', static fn($value) => self::managed() ? 0 : $value);
    }
    public static function unavailable(): bool {
        $state = get_site_meta(get_current_blog_id(), 'emsf_state', true);
        return $state !== 'ready' && !($state === 'archived' && is_super_admin());
    }
    public static function uploads(array $uploads): array {
        if (!self::managed()) { return $uploads; }
        $uploads['basedir'] = Store::root().'/uploads/'.get_current_blog_id();
        $uploads['baseurl'] = home_url('/emsf-media');
        $uploads['path'] = $uploads['basedir'].$uploads['subdir'];
        $uploads['url'] = $uploads['baseurl'].$uploads['subdir'];
        return $uploads;
    }
    public static function gate(): void {
        if (!self::managed() || (defined('WP_CLI') && WP_CLI) || wp_doing_cron()) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (!defined('DONOTCACHEDB')) { define('DONOTCACHEDB', true); }
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        Preview::captureShare();
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script === 'wp-login.php') { return; }
        if (self::unavailable()) {
            if (Preview::frontend()) { Preview::screen(); }
            wp_die('Dit voorstel is tijdelijk offline.', 'Geen toegang', ['response'=>403]);
        }
        if (!self::member() && !self::visitorAllowed()) {
            $path=(string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
            if (Preview::frontend() && !str_contains(rawurldecode($path),'/emsf-media/')) { Preview::screen(); }
            wp_die('Dit voorstel is afgeschermd.', 'Geen toegang', ['response'=>403]);
        }
        $path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
        $prefix = (string)parse_url(home_url('/emsf-media/'), PHP_URL_PATH);
        if (!str_starts_with($path, $prefix)) { return; }
        $relative = substr($path, strlen($prefix));
        if (str_contains($relative, '..') || str_contains($relative, '\\') || str_contains($relative, "\0")) { status_header(404); exit; }
        $root = realpath(Store::root().'/uploads/'.get_current_blog_id());
        $file = $root ? realpath($root.'/'.$relative) : false;
        $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','avif'=>'image/avif','ico'=>'image/x-icon','svg'=>'image/svg+xml','pdf'=>'application/pdf','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','otf'=>'font/otf','css'=>'text/css','txt'=>'text/plain','json'=>'application/json'];
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!$root || !$file || !str_starts_with($file, $root.'/') || !is_file($file) || !isset($mime[$ext])) { status_header(404); exit; }
        header('Content-Type: '.$mime[$ext]);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
        header('Content-Length: '.filesize($file));
        readfile($file); exit;
    }
}
