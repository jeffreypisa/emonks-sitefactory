<?php
/** Disposable Multisite only: real WordPress option creation, POST/save/reload and independent site choices. */
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')||!is_multisite()){throw new RuntimeException('Disposable Multisite only');}
$source=getenv('EMSF_NOTICE_SOURCE')?:dirname(__DIR__);
if(getenv('EMSF_NOTICE_FULL_ROUTE')==='yes'){require_once $source.'/emonks-site-factory.php';}
foreach(['Sites','Store','NotificationSettings','Activity','Admin']as$module){require_once $source.'/includes/Online/'.$module.'.php';}
use Emonks\Factory\Online\{Sites,NotificationSettings,Admin};
wp_set_current_user(1);if(Sites::local()){throw new RuntimeException('Online fixture required');}
$option='emsf_show_website_notices';$old=get_site_option($option,null);$ids=[];$beforePost=$_POST;$beforeRequest=$_REQUEST;$beforeMethod=$_SERVER['REQUEST_METHOD']??'GET';
$routeSource=null;
$count=0;$expect=static function($ok,$message)use(&$count){if(!$ok){throw new RuntimeException($message);}++$count;};
$uncache=static function()use($option){wp_cache_delete(get_current_network_id().':'.$option,'site-options');};
try {
 delete_site_option($option);
 $expect(NotificationSettings::websiteNoticesDefault(),'Missing setting defaults to show');
 update_site_option($option,false);$uncache();
 $expect(get_site_option($option,null)===null,'Reproduced WordPress first false save losing the option');
 $_POST=['settings_section'=>'website','_wpnonce'=>wp_create_nonce('emsf_notifications')];$_REQUEST=$_POST;$_SERVER['REQUEST_METHOD']='POST';
 ob_start();NotificationSettings::page();$saved=ob_get_clean();
 $expect(str_contains($saved,'Instellingen opgeslagen.')&&!str_contains($saved,'name="show_website_notices" value="1" checked'),'Unchecked POST renders unchecked after saving');
 $uncache();$expect(get_site_option($option)==='0'&&!NotificationSettings::websiteNoticesDefault(),'First off value persists through actual database reread');
 $_POST=[];$_REQUEST=[];$_SERVER['REQUEST_METHOD']='GET';
 ob_start();NotificationSettings::page();$reloaded=ob_get_clean();
 $expect(!str_contains($reloaded,'name="show_website_notices" value="1" checked'),'A new page load keeps checkbox off');
 foreach(['a','b']as$suffix){$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/notice-'.wp_generate_uuid4().$suffix.'/','user_id'=>1,'title'=>'Notice test','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready']]);if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}$ids[]=(int)$id;}
 [$a,$b]=$ids;
 NotificationSettings::saveSiteWebsiteNotices($a,['website_notices_mode'=>'show']);
 $expect(NotificationSettings::showWebsiteNotices($a)&&!NotificationSettings::showWebsiteNotices($b),'Show override affects only selected site');
 NotificationSettings::saveSiteWebsiteNotices($a,['website_notices_mode'=>'hide']);NotificationSettings::saveWebsiteNotices(['show_website_notices'=>1]);$uncache();
 $expect(!NotificationSettings::showWebsiteNotices($a)&&NotificationSettings::showWebsiteNotices($b),'Hide override survives a central change; other site follows it');
 $panel=new ReflectionMethod(Admin::class,'activityPanel');
 ob_start();$panel->invoke(null,$a,true,['website_notices'=>NotificationSettings::websiteNotices($a)]);$html=ob_get_clean();
 $expect(str_contains($html,'name="site_action" value="website_notices"')&&str_contains($html,'Standaard volgen (Tonen)')&&str_contains($html,'value="hide"  selected='),'Actual site panel renders independent form and effective standard');
 NotificationSettings::saveSiteWebsiteNotices($a,['website_notices_mode'=>'inherit']);
 $expect(get_site_meta($a,'emsf_website_notices',true)===''&&NotificationSettings::showWebsiteNotices($a),'Returning to standard removes override');
 $recipient=get_site_option('emsf_notifications',[]);NotificationSettings::saveWebsiteNotices([]);
 $expect(get_site_option('emsf_notifications',[])===$recipient,'Display save preserves recipient and notification preferences');
 if(getenv('EMSF_NOTICE_FULL_ROUTE')==='yes'){
  $routeSource=wp_generate_uuid4();update_site_meta($a,'emsf_source',$routeSource);update_site_meta($a,'emsf_mail_mode','off');
  \Emonks\Factory\Online\Store::write('link-'.$routeSource,['site'=>$a,'source'=>$routeSource]);
  $result=Admin::run($a,['site_action'=>'website_notices','scope'=>'online','website_notices_mode'=>'show']);
  $expect(($result['activity']['website_notices']['mode']??'')==='show'&&NotificationSettings::showWebsiteNotices($a),'Actual Admin/Receiver route saves and returns override');
  $result=Admin::run($a,['site_action'=>'website_notices','scope'=>'online','website_notices_mode'=>'inherit']);
  $expect(($result['activity']['website_notices']['mode']??'')==='inherit'&&!NotificationSettings::showWebsiteNotices($a),'Actual route restores inheritance');
  $expect(get_site_meta($a,'emsf_mail_mode',true)==='off'&&get_site_meta($a,'emsf_activity_policy',true)==='','Actual route preserves mail and activity policies');
 }
 echo "PASS: $count real WordPress notice persistence, POST/reload and per-site checks.\n";
} finally {
 if($routeSource!==null){\Emonks\Factory\Online\Store::write('link-'.$routeSource,[]);}
 if($old===null){delete_site_option($option);}else{update_site_option($option,$old);}
 $_POST=$beforePost;$_REQUEST=$beforeRequest;$_SERVER['REQUEST_METHOD']=$beforeMethod;
 require_once ABSPATH.'wp-admin/includes/ms.php';foreach($ids as$id){wpmu_delete_blog($id,true);}
}
