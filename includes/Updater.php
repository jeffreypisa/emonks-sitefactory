<?php
/** Public GitHub releases only; no credentials or unreleased branch snapshots. */
use YahnisElsts\PluginUpdateChecker\v5p7\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api;

return static function (string $pluginFile) {
    $isGitCheckout = file_exists(dirname($pluginFile) . '/.git');
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
    $checker = PucFactory::buildUpdateChecker(
        'https://github.com/jeffreypisa/emonks-sitefactory', $pluginFile, 'emonks-sitefactory', $isGitCheckout ? 0 : 12
    );
    $checker->setBranch('master');
    $checker->getVcsApi()->enableReleaseAssets('/^emonks-site-factory-\d+\.\d+\.\d+\.zip$/', Api::REQUIRE_RELEASE_ASSETS);
    add_filter($checker->getUniqueName('vcs_update_detection_strategies'), static function (array $strategies): array {
        return array_intersect_key($strategies, [Api::STRATEGY_LATEST_RELEASE => true]);
    });
    // A local Git checkout can be shared by symlinks; WordPress must not replace its .git directory.
    if ($isGitCheckout) {
        // No scheduled/manual requests or stale manual-check notices for development sources.
        $checker->removeHooks();
        add_filter($checker->getUniqueName('pre_inject_update'), static fn($update) => null);
        add_filter('auto_update_plugin', static function ($update, $item) use ($checker) {
            return isset($item->plugin) && $item->plugin === $checker->pluginFile ? false : $update;
        }, 10, 2);
    }
    return $checker;
};
