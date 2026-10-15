<?php
use Emonks\Factory\Online\{Preview,Privacy,Receiver,Store,Admin};
use Emonks\Factory\Package;
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only.');}
global $n;$n=0;function pv($ok,$name){global $n;if(!$ok){throw new RuntimeException('FAIL: '.$name);}++$n;echo "PASS: $name\n";}
function pvDenied($fn,$name){try{$fn();}catch(Throwable $e){pv(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name);}
wp_set_current_user(1);$_SERVER['HTTPS']='on';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REMOTE_ADDR']='192.0.2.1';
$sites=[];foreach(['code-first','code-other']as$slug){$sites[]=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/'.$slug.'/','user_id'=>1,'title'=>$slug,'meta'=>['emsf_managed'=>1,'emsf_state'=>'ready','emsf_source'=>$slug,'emsf_mail_mode'=>'off']]);}
[$a,$b]=$sites;
$code=Preview::manage($a);$policy=get_site_meta($a,'emsf_preview',true);
pv(strlen($code['code'])===4&&preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/D',$code['code']),'Code contains four random readable characters');
pv($policy['hash']!==$code['code']&&wp_check_password($code['code'],$policy['hash'])&&!isset($policy['code']),'No plaintext code is stored');
do{$otherCode=Preview::manage($b,false,true);}while($otherCode['code']===$code['code']);switch_to_blog($a);$_SERVER['REQUEST_URI']='/code-first/';
pv(Preview::attempt(strtolower($code['code']))['ok'],'Code accepts lowercase');
$token=Preview::token();$challenge=Preview::token('challenge');
pv(Preview::valid($token)&&Preview::valid($challenge,'challenge'),'Session and form challenge validate');
pv(!Preview::valid($challenge)&&!Preview::valid($token,'challenge'),'Challenge cannot become a preview session');
pv(!Preview::valid(substr($token,0,-1).($token[-1]==='a'?'b':'a')),'Tampered session rejected');
pv(!Preview::valid('1000000000.'.explode('.',$token)[1].'.'.explode('.',$token)[2]),'Expired/forged expiry rejected');
$_COOKIE[Preview::cookieName()]=$token;wp_set_current_user(0);
pv(Preview::allowed()&&!Privacy::member()&&!is_user_logged_in(),'Preview grants read-only viewing without logging in');
foreach(['/code-first/wp-admin/','/code-first/wp-json/wp/v2/pages','/code-first/%77p-admin/','/code-first/feed/']as$path){$_SERVER['REQUEST_URI']=$path;pv(!Preview::allowed(),'Preview denies protected route '.$path);}
$_SERVER['REQUEST_URI']='/code-first/';$_GET['rest_route']='/wp/v2/pages';pv(!Preview::allowed(),'Query REST route denied');$_GET=[];
foreach(['POST','PUT','DELETE','PATCH']as$method){$_SERVER['REQUEST_METHOD']=$method;pv(!Preview::allowed(),'Preview rejects '.$method.' mutation');}$_SERVER['REQUEST_METHOD']='GET';
foreach(['archived','importing','restoring','failed']as$state){update_site_meta($a,'emsf_state',$state);pv(!Preview::valid($token),'Preview fails closed in '.$state);}update_site_meta($a,'emsf_state','ready');
$_SERVER['HTTPS']='off';pv(!Preview::valid($token),'Session cannot authenticate over HTTP');$_SERVER['HTTPS']='on';
restore_current_blog();switch_to_blog($b);pv(!Preview::valid($token),'Other subsite rejects copied session');pv(!Preview::attempt($code['code'])['ok'],'Other site rejects first site code');restore_current_blog();
wp_set_current_user(1);$next=Preview::manage($a,false,true);switch_to_blog($a);pv(!Preview::valid($token)&&$next['code']!==$code['code'],'Rotation revokes old sessions and uses a different code');
$newToken=Preview::token();restore_current_blog();Preview::manage($a,true);switch_to_blog($a);pv(!Preview::valid($newToken)&&!Preview::enabled($a),'Revocation disables sessions and code');restore_current_blog();
$again=Preview::manage($a);switch_to_blog($a);$_SERVER['REMOTE_ADDR']='192.0.2.10';for($i=0;$i<5;$i++){Preview::attempt('!!!!');}
pv(Preview::attempt($again['code'])['limited'],'Five failed guesses lock that visitor even with correct code');
for($i=0;$i<15;$i++){$_SERVER['REMOTE_ADDR']='198.51.100.'.($i+1);Preview::attempt('!!!!');}
$_SERVER['REMOTE_ADDR']='203.0.113.1';$_SERVER['HTTP_X_FORWARDED_FOR']='203.0.113.2';pv(Preview::attempt($again['code'])['limited'],'Site-wide twenty-failure limit blocks rotating IPs');
$rate=Store::read('preview-rate-'.$a);pv(!isset($rate['code'])&&!str_contains(json_encode($rate),'192.0.2.10'),'Rate journal stores neither code nor raw visitor IP');
$rate['until']=time()-1;Store::write('preview-rate-'.$a,$rate);pv(Preview::attempt($again['code'])['ok'],'Rate window expires');
Package::export(Store::root().'/preview-export');$manifest=file_get_contents(Store::root().'/preview-export/manifest.json');pv(!str_contains($manifest,'emsf_preview')&&!str_contains($manifest,$policy['hash']),'Content export excludes preview credentials and sessions');restore_current_blog();
$editor=wpmu_create_user('preview_editor',wp_generate_password(),'preview-editor@example.invalid');add_user_to_blog($a,$editor,'editor');wp_set_current_user($editor);switch_to_blog($a);pv(Privacy::member(),'Personal editor access remains available');restore_current_blog();pvDenied(fn()=>Preview::manage($a),'Editor cannot generate a preview code');pvDenied(fn()=>Admin::run($a,['scope'=>'online','site_action'=>'preview_revoke','confirm'=>'code-first']),'Editor cannot revoke through admin action');
pv(get_site_meta($a,'emsf_mail_mode',true)==='off'&&get_site_meta($b,'emsf_mail_mode',true)==='off','Code operations preserve both sites mail-off');
echo "TOTAL: $n preview security assertions passed.\n";
