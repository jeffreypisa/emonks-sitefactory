<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable only');}
require_once dirname(__DIR__).'/emonks-site-factory.php';
use Emonks\Factory\Online\{Activity,NotificationSettings,ContentChanges,Receiver,Store,Mail,Accounts};
$n=0;$ok=static function($test,$label)use(&$n){if(!$test){throw new RuntimeException('FAIL '.$label);}++$n;echo "PASS $label\n";};
wp_set_current_user(1);$suffix=substr(wp_generate_uuid4(),0,8);$old=get_site_option('emsf_notifications',[]);
$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/notify-'.$suffix.'/','user_id'=>1,'title'=>'Notify fixture','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready','emsf_mail_mode'=>'off']]);
$uid=Accounts::silent(fn()=>wpmu_create_user('notice_'.$suffix,wp_generate_password(),'notice_'.$suffix.'@example.invalid'));add_user_to_blog($id,$uid,'editor');update_site_meta($id,'emsf_customer_id',$uid);
remove_all_filters('pre_wp_mail');Mail::boot();$mails=[];$delivery=true;add_filter('pre_wp_mail',static function($pre,$atts)use(&$mails,&$delivery){if($pre===null){$mails[]=$atts;return $delivery;}return $pre;},PHP_INT_MAX,2);
NotificationSettings::save(['recipient'=>'owner@example.invalid','notify_view'=>1,'notify_login'=>1,'notify_content'=>1]);
$ok(Activity::policy($id)['notify_content']&&Activity::policy($id)['recipient']==='owner@example.invalid','Existing and new sites inherit central settings');$ok(!$mails,'Saving sends no mail');
try{NotificationSettings::save(['recipient'=>'bad']);throw new LogicException('accepted');}catch(RuntimeException $e){$ok(true,'Invalid recipient rejected');}
switch_to_blog($id);$page=wp_insert_post(['post_title'=>'Page','post_type'=>'page','post_status'=>'publish']);Activity::flushChanges();$ok(!$mails,'Own administrator changes excluded');
wp_set_current_user(0);Activity::record($id,'preview_view','one');$ok(count($mails)===1&&str_contains($mails[0]['message'],'Anonieme preview'),'Preview notification does not claim client identity');
Activity::record($id,'preview_view','one');Activity::record($id,'preview_view','two');$ok(count($mails)===1&&count(Activity::snapshot($id)['events'])===2,'Session deduplication and hourly limit');
$data=Store::read('activity-'.$id);$data['notifications']['view']['at']=time()-3601;Store::write('activity-'.$id,$data);Activity::record($id,'preview_view','three');$ok(count($mails)===2,'New activity after an hour triggers a new notice');
wp_set_current_user($uid);do_action('set_logged_in_cookie','',0,0,$uid,'logged_in','test-login');do_action('wp_login','notice_'.$suffix,get_userdata($uid));Activity::record($id,'customer_dashboard','test-login',$uid);$ok(count($mails)===3,'Login and dashboard share hourly limit');
wp_update_post(['ID'=>$page,'post_content'=>'Changed by customer']);update_post_meta($page,'test_field','Changed');Activity::flushChanges();Activity::flushChanges();$ok(count($mails)===4&&str_contains($mails[3]['subject'],'inhoud gewijzigd'),'Post and fields in one save create one content notice');
wp_update_post(['ID'=>$page,'post_content'=>'Changed again']);Activity::flushChanges();$ok(count($mails)===4,'Later edits within hour logged without another email');
$events=Activity::snapshot($id)['events'];$ok(count(array_filter($events,fn($e)=>$e['kind']==='customer_content'))===2,'Separate content saves are visible in activity log');
Receiver::$importing=true;ContentChanges::touch('Import');Receiver::$importing=false;Activity::flushChanges();$ok(count(Activity::snapshot($id)['events'])===count($events),'Publication imports excluded');
wp_mail('client@example.invalid','Ordinary mail','Must remain blocked');$ok(count($mails)===4,'Only owner notification bypasses mail-off gate');
wp_set_current_user(1);Activity::configure($id,['activity_enabled'=>1,'activity_recipient'=>'owner@example.invalid']);$ok(!Activity::policy($id)['notify_content'],'Per-site override can disable content notices');Activity::configure($id,['activity_inherit'=>1]);$ok(Activity::policy($id)['notify_content'],'Per-site reset restores central defaults');
wp_set_current_user($uid);try{NotificationSettings::save(['recipient'=>'attacker@example.invalid']);throw new LogicException('accepted');}catch(RuntimeException $e){$ok(true,'Customer cannot change recipient');}
wp_set_current_user(1);ob_start();NotificationSettings::page();$html=ob_get_clean();$ok(str_contains($html,'owner@example.invalid')&&str_contains($html,'notify_content')&&str_contains($html,'name="_wpnonce"'),'Central settings form renders recipient, controls and nonce');
$delivery=false;wp_set_current_user(0);$data=Store::read('activity-'.$id);$data['notifications']['view']['at']=time()-3601;Store::write('activity-'.$id,$data);$before=count($mails);Activity::record($id,'preview_view','failed-one');Activity::record($id,'preview_view','failed-two');$ok(count($mails)===$before+1&&Activity::snapshot($id)['notifications']['view']['status']==='failed','Failed transport visible and not retried within hour');
wp_set_current_user($uid);define('DOING_AUTOSAVE',true);$before=count(Activity::snapshot($id)['events']);ContentChanges::touch('Autosave');Activity::flushChanges();$ok(count(Activity::snapshot($id)['events'])===$before,'Autosave creates no activity notification');
restore_current_blog();update_site_option('emsf_notifications',$old);echo "TOTAL $n checks; no real mail sent\n";
