<?php
/** wp eval-file. Intercept all HTTP; only temporary extracted directories are modified. */
if (!defined('ABSPATH')) throw new RuntimeException('Run using wp eval-file.');
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
$root = dirname(__DIR__);
$dashboard = is_file($root . '/websitebeheer.php');
$main = $dashboard ? 'websitebeheer.php' : 'emonks-site-factory.php';
$repo = $dashboard ? 'emonks-dashboard' : 'emonks-sitefactory';
$prefix = $dashboard ? 'emonks-dashboard' : 'emonks-site-factory';
$package = "https://github.com/jeffreypisa/$repo/releases/download/v9.8.7/$prefix-9.8.7.zip";
$requests = [];
$http = static function ($pre, $args, $url) use ($root, $main, $repo, $prefix, $package, &$requests) {
    $requests[] = $url;
    $path = wp_parse_url($url, PHP_URL_PATH);
    if ($path === "/repos/jeffreypisa/$repo/releases/latest") {
        $body = ['tag_name' => 'v9.8.7', 'draft' => false, 'prerelease' => false, 'created_at' => '2026-10-15T10:00:00Z', 'zipball_url' => 'https://api.github.com/source.zip', 'body' => 'A controlled test release.', 'assets' => [['name' => "$prefix-9.8.7.zip", 'download_count' => 0, 'browser_download_url' => $package]]];
    } elseif ($path === "/repos/jeffreypisa/$repo/contents/$main") {
        $source = preg_replace('/(Version:\s*)\S+/', '${1}9.8.7', file_get_contents($root . '/' . $main), 1);
        $body = ['encoding' => 'base64', 'content' => base64_encode($source)];
    } else {
        return new WP_Error('test_no_network', 'No external requests in this test.');
    }
    return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
};
add_filter('pre_http_request', $http, PHP_INT_MAX, 3);
$checks = 0;
$expect = static function ($ok, $message) use (&$checks) { if (!$ok) throw new RuntimeException($message); ++$checks; };
global $wp_filesystem;
$old_filesystem = $wp_filesystem;
$temp = get_temp_dir() . 'emonks-updater-' . wp_generate_uuid4();
try {
    $checker = (require $root . '/includes/Updater.php')($root . '/' . $main);
    $expect($checker->pluginFile === plugin_basename($root . '/' . $main), 'Plugin identity changed.');
    $expect($checker->directoryName === basename($root), 'Installation directory is not preserved.');
    $update = $checker->requestUpdate();
    $expect($update && $update->version === '9.8.7', 'New release not recognized inside WordPress.');
    $native = $update->toWpFormat();
    $expect($native->package === $package && $native->new_version === '9.8.7', 'Native WordPress update data is incorrect.');
    $expect($native->plugin === $checker->pluginFile, 'Native update targets the wrong plugin.');
    $expect(!str_contains(implode(' ', $requests), '/branches/'), 'An unreleased branch was requested.');
    // Exercise directory correction with the actual WordPress upgrader and filesystem.
    $wp_filesystem = new WP_Filesystem_Direct([]);
    mkdir($temp); mkdir($temp . '/package');
    file_put_contents($temp . '/package/' . $main, '<?php // Synthetic update file');
    $skin = new class(['plugin' => $checker->pluginFile]) extends Plugin_Upgrader_Skin { public function feedback($feedback, ...$args) {} };
    $upgrader = new Plugin_Upgrader($skin);
    $source = $checker->fixDirectoryName($temp . '/package/', $temp . '/', $upgrader);
    $expect(!is_wp_error($source) && $source === $temp . '/' . basename($root) . '/', 'Update changed the installed plugin directory.');
    $expect(is_file($source . $main), 'The main plugin file was lost during extraction correction.');
    echo "$repo: $checks WordPress update and directory checks passed; no plugin installed.\n";
} finally {
    remove_filter('pre_http_request', $http, PHP_INT_MAX);
    if (is_dir($temp)) { (new WP_Filesystem_Direct([]))->delete($temp, true); }
    $wp_filesystem = $old_filesystem;
}
