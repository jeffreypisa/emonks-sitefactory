<?php
use Emonks\Factory\Online\{Admin,Sites,Store};
use Emonks\Factory\Factory;
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only.');}
global $n;$n=0;function overview_check($ok,$label){global $n;if(!$ok){throw new RuntimeException('FAIL: '.$label);}++$n;echo "PASS: $label\n";}
if(!class_exists(Admin::class)){require_once dirname(__DIR__).'/emonks-site-factory.php';}
define('EMSF_LOCAL',true);define('EMSF_REMOTE_URL','https://receiver.example.invalid');
register_post_type(Factory::TYPE,['public'=>false]);wp_set_current_user(1);
$requests=0;add_filter('pre_http_request',static function()use(&$requests){$requests++;return new WP_Error('test_offline','Blocked');},PHP_INT_MAX);
$p=Sites::createLocal(['name'=>'Overview & fixture','slug'=>'overview-'.substr(wp_generate_uuid4(),0,8)]);$id=(int)$p['site_id'];
$_GET=[];ob_start();Admin::page();$html=ob_get_clean();
overview_check(str_contains($html,'Overview &amp; fixture'),'Newly created site appears, escaped');
overview_check(str_contains($html,'Aangemaakt')&&str_contains($html,'Laatst gewijzigd')&&str_contains($html,'Lokaal concept'),'Dates and local status visible');
overview_check(str_contains($html,'admin.php?page=emsf-site'),'Links use dedicated Factory menu');
Store::write('pending-'.$p['uuid'],['operation'=>wp_generate_uuid4(),'action'=>'create','customer'=>[]]);
$_GET=[];ob_start();Admin::page();$html=ob_get_clean();
overview_check(str_contains($html,'Publicatie hervatten'),'Pending publication exposes resume');
$_GET=['id'=>$id];ob_start();Admin::page();$detail=ob_get_clean();
overview_check(str_contains($detail,'Skeletor-versie')&&str_contains($detail,'Het opgeslagen pakket is niet leesbaar'),'Missing package shows recovery guidance, does not crash');
overview_check(str_contains($detail,'name="operation" value="'.Store::read('pending-'.$p['uuid'])['operation'].'"'),'Pending cancellation has operation prefilled');
overview_check(Admin::visitSummary([])==='Nog niet opgehaald','Unknown activity never claims no customer visit');
$event=['kind'=>'preview_view','at'=>time()];
overview_check(str_contains(Admin::visitSummary(['events'=>[$event]]),'Anonieme preview'),'Anonymous preview not called customer');
$event['kind']='customer_front';overview_check(str_contains(Admin::visitSummary(['events'=>[$event]]),'klantaccount'),'Account visit is distinguished');
$event['at']=time()-31*DAY_IN_SECONDS;overview_check(str_contains(Admin::visitSummary(['events'=>[$event]]),'Geen bezoek geregistreerd'),'Expired cached activity excluded');
overview_check(Admin::visitSummary(['policy'=>['enabled'=>false]])==='Registratie uitgeschakeld','Paused recording explicit');
$status=\Emonks\Factory\Online\Receiver::status(['source'=>$p['uuid']]);
overview_check(isset($status['theme']['digest'],$status['theme']['version']),'Receiver reports installed theme build and version');
$dir=Store::root().'/out-'.Store::read('pending-'.$p['uuid'])['operation'];wp_mkdir_p($dir);
file_put_contents($dir.'/manifest.json',wp_json_encode(['format'=>\Emonks\Factory\Package::FORMAT,'version'=>1,'theme'=>'skeletor','files'=>[],'posts'=>[],'terms'=>[],'menus'=>[],'forms'=>[],'options'=>[],'dependencies'=>['theme_version'=>'old','theme_digest'=>'old']]));
ob_start();Admin::page();$stale=ob_get_clean();
overview_check(str_contains($stale,'Dit pakket is gemaakt met een andere themaversie'),'Stale package explains cancel and recreate instead of silent replacement');
overview_check($requests===0,'Overview and detail never publish or make remote requests');
file_put_contents('/private/tmp/emsf-overview/overview.html','<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/plugin/assets/admin.css"><style>body{font:14px system-ui;background:#f0f0f1;padding:20px}table{width:100%;text-align:left;border-collapse:collapse}td,th{padding:14px;background:white;vertical-align:top}a{color:#2271b1}td{border-top:1px solid #ddd}</style>'.$html.'</html>');
echo "TOTAL: $n overview checks passed\n";
