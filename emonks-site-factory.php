<?php
/**
 * Plugin Name: Emonks Site Factory
 * Description: Lokale prospectsites met Skeletor, geïsoleerde projectmodellen en overdraagbare inhoud.
 * Version: 0.13.1
 * Network: true
 * Requires PHP: 8.1
 */
declare(strict_types=1);
namespace Emonks\Factory;
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/includes/Factory.php';
require_once __DIR__.'/includes/Package.php';
require_once __DIR__.'/includes/ThemeRelease.php';
require_once __DIR__.'/includes/Build.php';
foreach (['Store','Software','NotificationSettings','Activity','Preview','Optimizer','Privacy','Snapshot','Accounts','Mail','Receiver','Client','Pull','Sites','FollowUp','ContentChanges','Publication','MailTemplate','MailDraft','Admin'] as $module) { require_once __DIR__.'/includes/Online/'.$module.'.php'; }
if (defined('EMSF_ONLINE') && EMSF_ONLINE) { Online\Privacy::boot(); Online\Optimizer::boot(); Online\Receiver::boot(); }
Online\Accounts::boot();
Online\Mail::boot();
Online\Activity::boot();
Online\Admin::boot();
Online\ContentChanges::boot();
Online\Publication::boot();
if (defined('EMSF_LOCAL') && EMSF_LOCAL) { Factory::boot(); }
if (defined('WP_CLI') && WP_CLI) { require_once __DIR__.'/includes/CLI.php'; }
