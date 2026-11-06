<?php
/**
 * Plugin Name: Emonks Site Importer
 * Description: Tijdelijke import van een klaargezet Site Factory-pakket naar zelfstandige WordPress.
 * Version: 0.25.2
 * Requires PHP: 8.1
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
if (class_exists('Emonks\\Factory\\Factory',false)) { return; }
define('EMSF_HANDOVER_IMPORTER',true);
foreach (['Factory','ThemeRelease','Package','HandoverLibrary','Handover','Online/Receiver','DeliverySoftware','FullSite','Standalone'] as $module) { require_once __DIR__.'/includes/'.$module.'.php'; }
\Emonks\Factory\Standalone::boot();
