<?php
use Emonks\Factory\FullSite;
require dirname(__DIR__).'/includes/FullSite.php';
$checks=0;$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$check(FullSite::hostingPlugins(['sg-security/sg-security.php','sg-cachepress/sg-cachepress.php','hello.php'])===['sg-security/sg-security.php','sg-cachepress/sg-cachepress.php'],'Active hosting plugins retained');
$check(FullSite::hostingPlugins(['fake-sg-security/foo.php'])===[],'Exact plugin paths');
$check(FullSite::hostingPlugins([])===[],'No plugins required');
$check(FullSite::hostingPlugins(['sg-security/sg-security.php'])===['sg-security/sg-security.php'],'Security alone retained');
echo "$checks hosting plugin preservation checks passed.\n";
