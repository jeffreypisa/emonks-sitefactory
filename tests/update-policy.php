<?php
/** Exercise the real vendored release selector with synthetic responses; no network or database. */
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function is_wp_error($value) { return $value instanceof Throwable; }
$GLOBALS['test_filters'] = [];
function add_filter($tag, $callback, ...$unused) { $GLOBALS['test_filters'][$tag][] = $callback; }
function apply_filters($tag, $value, ...$args) { foreach ($GLOBALS['test_filters'][$tag] ?? [] as $callback) $value = $callback($value, ...$args); return $value; }
require_once __DIR__ . '/../includes/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\{Api, GitHubApi};
final class FixtureApi extends GitHubApi {
    public $fixture;
    public $requests = [];
    protected function api($url, $queryParams = []) { $this->requests[] = $url; return $this->fixture; }
}
$count = 0;
$expect = static function ($ok, $message) use (&$count) { if (!$ok) throw new RuntimeException($message); ++$count; };
$root = dirname(__DIR__);
$dashboard = is_file($root . '/websitebeheer.php');
$repo = $dashboard ? 'emonks-dashboard' : 'emonks-sitefactory';
$prefix = $dashboard ? 'emonks-dashboard' : 'emonks-site-factory';
$api = new FixtureApi('https://github.com/jeffreypisa/' . $repo);
$api->enableReleaseAssets('/^' . $prefix . '-\d+\.\d+\.\d+\.zip$/', Api::REQUIRE_RELEASE_ASSETS);
$release = (object) [
 'tag_name' => 'v9.8.7', 'draft' => false, 'prerelease' => false,
 'created_at' => '2026-10-15T10:00:00Z', 'zipball_url' => 'https://api.github.com/source.zip',
 'body' => '', 'assets' => [(object) [
  'name' => $prefix . '-9.8.7.zip', 'download_count' => 0,
  'browser_download_url' => 'https://github.com/jeffreypisa/' . $repo . '/releases/download/v9.8.7/' . $prefix . '-9.8.7.zip',
 ]],
];
$api->fixture = $release;
$reference = $api->getLatestRelease();
$expect($reference && $reference->version === '9.8.7', 'Stable release not detected.');
$expect($reference->downloadUrl === $release->assets[0]->browser_download_url, 'Wrong installation package.');
$release->draft = true;
$expect($api->getLatestRelease() === null, 'Draft release offered.');
$release->draft = false; $release->prerelease = true;
$expect($api->getLatestRelease() === null, 'Prerelease offered.');
$release->prerelease = false; $release->assets = [];
$expect($api->getLatestRelease() === null, 'Source archive offered when installation ZIP is missing.');
$release->assets = [(object) ['name' => 'source.zip', 'download_count' => 0]];
$expect($api->getLatestRelease() === null, 'Unrelated asset offered.');
$api->fixture = new RuntimeException('Network unavailable');
$expect($api->getLatestRelease() === null, 'Network failure is not handled.');
// Capture our actual configuration without constructing a WordPress scheduler.
$configure = file_get_contents($root . '/includes/Updater.php');
$expect(str_contains($configure, "'https://github.com/jeffreypisa/$repo'"), 'Wrong repository.');
$expect(str_contains($configure, 'Api::REQUIRE_RELEASE_ASSETS'), 'Runtime ZIP is not required.');
$expect(str_contains($configure, '[Api::STRATEGY_LATEST_RELEASE => true]'), 'Branch/tag fallback is not disabled.');
if (!$dashboard) {
 require_once $root . '/includes/Factory.php';
 foreach ([
  'https://api.github.com/repos/jeffreypisa/emonks-dashboard/releases/latest',
  'https://api.github.com/repos/jeffreypisa/emonks-sitefactory/contents/emonks-site-factory.php?ref=v0.14.0',
  'https://github.com/jeffreypisa/emonks-sitefactory/releases/download/v0.14.0/emonks-site-factory-0.14.0.zip',
 ] as $url) $expect(Emonks\Factory\Factory::pluginUpdateRequest($url), 'Update request blocked: ' . $url);
 foreach ([
  'http://api.github.com/repos/jeffreypisa/emonks-dashboard/releases/latest',
  'https://api.github.com/repos/other/repo/releases/latest',
  'https://api.github.com.evil.test/repos/jeffreypisa/emonks-dashboard/releases/latest',
  'https://github.com/jeffreypisa/emonks-dashboard/archive/refs/heads/master.zip',
  'https://github.com/jeffreypisa/emonks-dashboard/releases/download/v1.0.0/secrets.zip',
  'https://user:password@api.github.com/repos/jeffreypisa/emonks-dashboard/releases/latest',
  'https://api.github.com:8443/repos/jeffreypisa/emonks-dashboard/releases/latest',
 ] as $url) $expect(!Emonks\Factory\Factory::pluginUpdateRequest($url), 'Unrelated request allowed: ' . $url);
 $expect(!Emonks\Factory\Factory::pluginUpdateRequest('https://api.github.com/repos/jeffreypisa/emonks-dashboard/releases/latest', ['method' => 'POST']), 'Outgoing POST is allowed.');
}
echo "$repo: $count release and update-policy checks passed.\n";
