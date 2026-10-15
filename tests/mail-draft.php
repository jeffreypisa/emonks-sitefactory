<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable only');}
require_once dirname(__DIR__).'/emonks-site-factory.php';
use Emonks\Factory\Online\{Admin,Accounts,MailDraft,MailTemplate,Preview,Store};
wp_set_current_user(1);$original=get_site_option('emsf_mail_template',null);$n=0;$check=static function($ok,$label)use(&$n){if(!$ok){throw new RuntimeException('FAIL '.$label);}++$n;echo "PASS $label\n";};
try{
 update_site_option('emsf_mail_template',MailTemplate::defaults());$mailCount=$GLOBALS['test_mail_count']??0;
 $source=wp_generate_uuid4();$short=substr($source,0,8);$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/two-mails-'.$short.'/','user_id'=>1,'title'=>'Studio Test','meta'=>['emsf_managed'=>1,'emsf_state'=>'failed','emsf_source'=>$source,'emsf_mail_mode'=>'off']]);
 $input=['scope'=>'online','site_action'=>'mail_draft','draft_step'=>'intro','draft_first_name'=>'Anne','draft_company'=>'Studio Test','draft_price'=>'950','draft_monthly'=>'25','draft_screenshot'=>1,'draft_email'=>'anne@example.invalid'];
 $intro=Admin::run($id,$input)['mail_draft'];$check(str_contains($intro['body'],'Hoi Anne,')&&!Preview::enabled($id)&&!get_site_meta($id,'emsf_customer_id',true),'First mail works before publication without creating code or account');
 $follow=array_replace($input,['draft_step'=>'follow','draft_screenshot'=>0]);
 try{Admin::run($id,$follow);throw new LogicException('Unpublished follow-up accepted');}catch(RuntimeException $e){$check(!Preview::enabled($id),'Follow-up blocked before successful publication');}
 update_site_meta($id,'emsf_state','ready');Store::write('link-'.$source,['site'=>$id,'source'=>$source]);
 Accounts::provision($id,$source,['name'=>'Anne Test','username'=>'twomail_'.$short,'email'=>'twomail_'.$short.'@example.invalid','confirmed'=>true,'layout_access'=>false]);$uid=(int)get_site_meta($id,'emsf_customer_id',true);
 Preview::manage($id);$draft=Admin::run($id,$follow)['mail_draft'];$preview=Preview::details($id);
 $check(str_contains($draft['body'],$preview['code'])&&str_contains($draft['body'],$preview['url']),'Follow-up includes actual site URL and stable preview code');
 $check(!str_contains($draft['body'],'wp-login')&&!get_userdata($uid)->user_activation_key,'No backend access requested: no reset link generated');
 $withAccess=array_replace($follow,['draft_include_backend'=>1,'draft_email'=>'twomail_'.$short.'@example.invalid']);$draft=Admin::run($id,$withAccess)['mail_draft'];
 preg_match('~https?://[^\s]+action=rp[^\s]+~',$draft['body'],$m);parse_str(parse_url($m[0]??'',PHP_URL_QUERY)??'',$params);$user=check_password_reset_key($params['key']??'',$params['login']??'');
 $check(!is_wp_error($user)&&$user->ID===$uid&&$draft['expires_at']!=='','Optional invitation sets password for correct customer with expiry');
 $key=get_userdata($uid)->user_activation_key;$again=Admin::run($id,$input)['mail_draft'];$check(get_userdata($uid)->user_activation_key===$key&&!str_contains($again['body'],$preview['code']),'Returning to first mail does not rotate reset link or leak access');
 $saved=(array)get_blog_option($id,'emsf_mail_draft');$check(!str_contains(wp_json_encode($saved),$preview['code'])&&!str_contains(wp_json_encode($saved),'action=rp')&&$saved['price']==='950','Only reusable customer and price fields stored');
 update_user_meta($uid,'emsf_access_initialized',1);$draft=Admin::run($id,$withAccess)['mail_draft'];$check(str_contains($draft['body'],'bestaande account')&&!str_contains($draft['body'],'action=rp')&&get_userdata($uid)->user_activation_key===$key,'Existing account gets login URL without resetting credentials');
 $check(Preview::details($id)['code']===$preview['code'],'Preview code stays fixed across steps');
 $check(($GLOBALS['test_mail_count']??0)===$mailCount,'No email sent');
 $_GET=['id'=>$id,'tab'=>'email'];ob_start();Admin::page();$html=ob_get_clean();$check(str_contains($html,'Mails maken')&&str_contains($html,'name="draft_price" value="950"')&&!str_contains($html,'name="draft_costs"'),'Site UI contains shared structured fields, not old cost prose');
 $draft['body']='</textarea><script>alert(1)</script>';$check(str_contains(MailDraft::result($draft),'&lt;/textarea&gt;&lt;script&gt;'),'Draft escapes untrusted content');
 wp_set_current_user(0);try{Admin::run($id,$input);throw new LogicException('Unauthorized');}catch(RuntimeException $e){$check(true,'Nonadmin cannot compose');}wp_set_current_user(1);
 file_put_contents('/private/tmp/emsf-twomails/draft-ui.html','<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{font:16px system-ui;background:#f0f0f1;margin:24px}.large-text{width:100%;box-sizing:border-box}textarea,input,select,button{font:inherit;padding:8px}'.file_get_contents(dirname(__DIR__).'/assets/admin.css').'</style>'.$html.'<script>'.file_get_contents(dirname(__DIR__).'/assets/admin.js').'</script></html>');
 echo "TOTAL $n draft checks\n";
}finally{if($original===null){delete_site_option('emsf_mail_template');}else{update_site_option('emsf_mail_template',$original);}}
