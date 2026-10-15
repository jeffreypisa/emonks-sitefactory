<?php
/** Public GitHub releases only; no credentials or unreleased branch snapshots. */
use YahnisElsts\PluginUpdateChecker\v5p7\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api;

return static function (string $pluginFile) {
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
    $checker = PucFactory::buildUpdateChecker(
        'https://github.com/jeffreypisa/emonks-sitefactory', $pluginFile, 'emonks-sitefactory'
    );
    $checker->setBranch('master');
    $checker->getVcsApi()->enableReleaseAssets('/^emonks-site-factory-\d+\.\d+\.\d+\.zip$/', Api::REQUIRE_RELEASE_ASSETS);
    add_filter($checker->getUniqueName('vcs_update_detection_strategies'), static function (array $strategies): array {
        return array_intersect_key($strategies, [Api::STRATEGY_LATEST_RELEASE => true]);
    });
    return $checker;
};
