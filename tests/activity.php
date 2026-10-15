<?php
use Emonks\Factory\Online\{Activity,Preview,Store,Accounts,Mail};
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only.');}
global $ac;$ac=0;function ac($ok,$name){global $ac;if(!$ok){throw new RuntimeException('FAIL: '.$name);}++$ac;echo "PASS: $name\n";}
wp_set_current_user(1);$_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/index.php';
$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/activity-test/','user_id'=>1,'title'=>'Activity test','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready','emsf_source'=>'activity-test','emsf_mail_mode'=>'off']]);
$uid=Accounts::silent(fn()=>wpmu_create_user('activity_customer',wp_generate_password(),'customer@example.invalid'));add_user_to_blog($id,$uid,'editor');update_site_meta($id,'emsf_customer_id',$uid);
ac(Activity::policy($id)['recipient']===''&&!Activity::policy($id)['notify_view'],'Owner address blank and notifications off by default');
try{Activity::configure($id,['activity_enabled'=>1,'activity_recipient'=>'owner@example.invalid','notify_view'=>1]);throw new LogicException('Missing rejection');}catch(RuntimeException $e){ac(true,'Recipient confirmation required');}
// No actual mail: inspect the final mail gate, then return true before any transport.
remove_all_filters('pre_wp_mail');Mail::boot();
$attempts=[];$gate=[];$mailResult=true;add_filter('pre_wp_mail',static function($pre,$atts)use(&$attempts,&$gate,&$mailResult){$attempts[]=$atts;$gate[]=$pre;return $mailResult;},PHP_INT_MAX,2);
Activity::configure($id,['activity_enabled'=>1,'activity_recipient'=>'owner@example.invalid','notify_view'=>1,'notify_login'=>1,'activity_confirmed'=>1]);ac(count($attempts)===0,'Saving notification policy sends nothing');
switch_to_blog($id);$_SERVER['REQUEST_URI']='/activity-test/';$access=Preview::manage($id);$share=bin2hex(random_bytes(24));$legacy=get_site_meta($id,'emsf_preview',true);$legacy['share_hash']=hash('sha256',$share);update_site_meta($id,'emsf_preview',$legacy);
ac(strlen($share)===48&&Preview::shareValid($share)&&!str_contains($access['share_url'],'customer'),'Unpredictable opaque share token contains no customer identity');
ac(!str_contains(wp_json_encode(get_site_meta($id,'emsf_preview',true)),$share),'Share token stored hashed only');
ac(!Preview::valid($share)&&!Preview::allowed(),'Share token cannot replace code or authenticate a session');
$session=Preview::token();Activity::record($id,'preview_view',$session);ac(count(Activity::snapshot($id)['events'])===0,'Network administrator activity excluded');
wp_set_current_user(0);Activity::record($id,'preview_view',$session,0,true);$snap=Activity::snapshot($id);
ac(count($snap['events'])===1&&$snap['events'][0]['source']==='share_link'&&!isset($snap['events'][0]['account']),'Anonymous share visit records no account or IP');
ac(count($attempts)===1&&$attempts[0]['to']==='owner@example.invalid'&&$gate[0]===null,'Exact owner notification passes mail-off gate to intercepted transport');
Activity::record($id,'preview_view',$session,0,true);ac(count(Activity::snapshot($id)['events'])===1&&count($attempts)===1,'Reload and duplicate hook do not duplicate event or mail');
Activity::record($id,'preview_view','new-preview-session');ac(count(Activity::snapshot($id)['events'])===2&&count($attempts)===1,'New preview session is recorded without repeating first-view mail');
wp_mail('customer@example.invalid','Normal customer mail','Blocked');ac(end($gate)===true&&Mail::mode($id)==='off','Ordinary customer mail remains blocked with notifications enabled');
wp_set_current_user($uid);do_action('set_logged_in_cookie','',0,0,$uid,'logged_in','actual-login-token');do_action('wp_login','activity_customer',get_userdata($uid));
$snap=Activity::snapshot($id);ac(end($snap['events'])['kind']==='customer_login'&&end($snap['events'])['account']==='activity_customer','Successful personal login records known account');
ac(count($attempts)===3&&end($gate)===null,'First customer login sends one separate owner notification');
$token=WP_Session_Tokens::get_instance($uid)->create(time()+3600);$_COOKIE[LOGGED_IN_COOKIE]=wp_generate_auth_cookie($uid,time()+3600,'logged_in',$token);do_action('admin_head');do_action('admin_head');
$snap=Activity::snapshot($id);ac(count(array_filter($snap['events'],fn($e)=>$e['kind']==='customer_dashboard'))===1,'Actual dashboard render records once per authenticated session');
Activity::record($id,'customer_front',$token,$uid);Activity::record($id,'customer_front',$token,$uid);ac(count(Activity::snapshot($id)['events'])===5,'Customer front viewing distinct from login/dashboard, deduped within session');
Activity::record($id,'customer_login','second-login',$uid);ac(count($attempts)===3,'Later login remains visible without second notification');
wp_set_current_user(1);Activity::configure($id,['activity_recipient'=>'owner@example.invalid']);wp_set_current_user(0);Activity::record($id,'preview_view','paused-session');ac(count(Activity::snapshot($id)['events'])===6,'Paused tracking ignores new sessions');
wp_set_current_user(1);Activity::configure($id,['activity_enabled'=>1,'activity_recipient'=>'owner@example.invalid','notify_view'=>1,'activity_confirmed'=>1]);ac(count($attempts)===3,'Re-enabling never replays old notifications');
$next=Preview::manage($id,false,true);ac(!Preview::shareValid($share)&&!Preview::valid($session),'Rotation revokes old share link and preview session');
Preview::manage($id,true);ac(!Preview::enabled($id),'Revocation disables the fixed code too');ac(count(Activity::snapshot($id)['events'])===6,'Rotating/revoking access preserves activity history');
$data=Store::read('activity-'.$id);$data['events'][]=['at'=>time()-31*DAY_IN_SECONDS,'kind'=>'preview_view','source'=>'preview_code'];$data['seen']['expired']=time()-31*DAY_IN_SECONDS;Store::write('activity-'.$id,$data);Activity::snapshot($id);$data=Store::read('activity-'.$id);ac(count($data['events'])===6&&!isset($data['seen']['expired']),'Retention removes records and deduplication keys older than 30 days');
wp_set_current_user(0);for($i=0;$i<105;$i++){Activity::record($id,'preview_view','bounded-'.$i);}ac(count(Activity::snapshot($id)['events'])===100,'Activity log bounded to 100 events');
ac(!str_contains(wp_json_encode(Store::read('activity-'.$id)),$token),'Session tokens never stored in activity log');
wp_set_current_user(1);$failed=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/activity-failed/','user_id'=>1,'title'=>'Failed notification','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready']]);Activity::configure($failed,['activity_enabled'=>1,'activity_recipient'=>'owner@example.invalid','notify_view'=>1,'activity_confirmed'=>1]);
$mailResult=false;$before=count($attempts);wp_set_current_user(0);Activity::record($failed,'preview_view','failure-first');Activity::record($failed,'preview_view','failure-second');ac(count($attempts)===$before+1&&Activity::snapshot($failed)['notifications']['view']['status']==='failed','Failed owner transport is recorded and never retried');
$data=Store::read('activity-'.$failed);$data['events']=[['at'=>time()-31*DAY_IN_SECONDS,'kind'=>'preview_view']];Store::write('activity-'.$failed,$data);Store::write('remote-retention-test',['site'=>$failed,'activity'=>['events'=>$data['events']]]);Activity::cleanup();ac(Activity::snapshot($failed)['events']===[]&&Store::read('remote-retention-test')['activity']['events']===[],'Daily cleanup prunes inactive remote logs and local cached activity');
restore_current_blog();echo "TOTAL: $ac activity assertions passed; all mail intercepted.\n";
