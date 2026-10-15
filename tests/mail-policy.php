<?php
use Emonks\Factory\Online\{Accounts,Mail,Store};
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only.');}
global $mailChecks,$wpdb;$mailChecks=0;
function mailCheck($ok,$label){global $mailChecks;if(!$ok){throw new RuntimeException('FAIL: '.$label);}++$mailChecks;echo "PASS: $label\n";}
wp_set_current_user(1);
$accepted=[];$discarded=0;
$transport=static function($pre,$args)use(&$accepted,&$discarded){if($pre===null){$accepted[]=$args['subject'];}else{$discarded++;}return true;};
// Remove only the test transport's blanket mute; keep the production Mail gate.
remove_all_filters('pre_wp_mail');Mail::boot();
add_filter('wp_mail',static function($args)use($transport){remove_filter('pre_wp_mail',$transport,PHP_INT_MAX);add_filter('pre_wp_mail',$transport,PHP_INT_MAX,2);return $args;});
$a=Accounts::silent(static fn()=>wp_insert_site(['domain'=>get_network()->domain,'path'=>'/mail-off/','user_id'=>1,'title'=>'Mail off','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready','emsf_mail_mode'=>'off','emsf_source'=>'mail-a']]));
$b=Accounts::silent(static fn()=>wp_insert_site(['domain'=>get_network()->domain,'path'=>'/mail-on/','user_id'=>1,'title'=>'Mail on','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready','emsf_mail_mode'=>'on','emsf_source'=>'mail-b']]));
$ua=Accounts::silent(static fn()=>wpmu_create_user('mail_a',wp_generate_password(),'mail-a@example.invalid'));add_user_to_blog($a,$ua,'editor');
$ub=Accounts::silent(static fn()=>wpmu_create_user('mail_b',wp_generate_password(),'mail-b@example.invalid'));add_user_to_blog($b,$ub,'editor');
$uc=Accounts::silent(static fn()=>wpmu_create_user('mail_shared',wp_generate_password(),'mail-shared@example.invalid'));add_user_to_blog($a,$uc,'editor');add_user_to_blog($b,$uc,'editor');
$accepted=[];$discarded=0;
switch_to_blog($a);wp_mail('arbitrary@example.invalid','discarded contact','Test');
mailCheck(!$accepted&&$discarded===1,'Mail-off discards WordPress mail to every recipient');
mailCheck(is_wp_error($r=wp_remote_post('https://provider.invalid/send'))&&$r->get_error_code()==='emsf_demo_offline','Off blocks provider APIs through WordPress HTTP');
$types=apply_filters('fluentform/global_notification_active_types',['email_notifications','slack']);mailCheck($types===[],'Off disables Fluent Forms integration queue selection');
// Actual Fluent Forms submission and notification classes, not a mock form implementation.
$formId=\FluentForm\App\Models\Form::insertGetId(['title'=>'Mail policy fixture','status'=>'published','form_fields'=>wp_json_encode(['fields'=>[]]),'has_payment'=>0,'type'=>'form','created_by'=>1]);
$validationBlocked=false;
try{(new \FluentForm\App\Services\Form\SubmissionHandlerService())->handleSubmission([],$formId);}catch(\FluentForm\Framework\Validator\ValidationException $e){$validationBlocked=true;}
mailCheck($validationBlocked && (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions WHERE form_id=".(int)$formId)===0,'Actual Fluent Forms submission stops before storage/queue while off');
$cronBefore=get_option('cron');$before=count($accepted);
Mail::set($a,'on');mailCheck(count($accepted)===$before&&get_option('cron')===$cronBefore,'Turning on schedules and replays no suppressed mail');
mailCheck(apply_filters('fluentform/validation_errors',[])===[],'On allows normal form validation');
$notification=new \FluentForm\App\Services\FormBuilder\Notifications\EmailNotification(wpFluentForm());
$notification->notify(['fromEmail'=>'test@example.invalid','fromName'=>'Test','replyTo'=>'','asPlainText'=>'yes','message'=>'Mail policy test','subject'=>'Fluent form on','sendTo'=>['type'=>'email','email'=>'never@example.invalid']],[],\FluentForm\App\Models\Form::find($formId));
mailCheck(in_array('Fluent form on',$accepted,true),'Actual Fluent Forms notification reaches intercepted transport when on');
$before=count($accepted);Accounts::silent(static fn()=>wp_mail('never@example.invalid','Publication must stay quiet','Test'));
mailCheck(count($accepted)===$before,'Publication/account quiet scope overrides mail-on');
Mail::set($a,'off');$before=count($accepted);$notification->notify(['fromEmail'=>'test@example.invalid','fromName'=>'Test','replyTo'=>'','asPlainText'=>'yes','message'=>'Mail policy test','subject'=>'Fluent form off','sendTo'=>['type'=>'email','email'=>'never@example.invalid']],[],\FluentForm\App\Models\Form::find($formId));
mailCheck(count($accepted)===$before,'Even direct Fluent Forms notifier is discarded while off');
restore_current_blog();
$before=count($accepted);wp_mail('network@example.invalid','Network unaffected','Test');mailCheck(count($accepted)===$before+1,'Unmanaged main-site mail remains allowed');
switch_to_blog($b);$before=count($accepted);wp_mail('other@example.invalid','Other site unaffected','Test');mailCheck(count($accepted)===$before+1,'Another customer site with mail-on is unaffected');restore_current_blog();
$_REQUEST=[];$before=count($accepted);retrieve_password('mail_a');mailCheck(count($accepted)===$before,'Network/main-site reset respects single-site mail-off customer');
retrieve_password('mail_b');mailCheck(count($accepted)===$before+1,'Network reset works for mail-on customer');
$_REQUEST=['emsf_site'=>$a,'emsf_context'=>Mail::signature($a)];$before=count($accepted);retrieve_password('mail_b');mailCheck(count($accepted)===$before+1,'Off-site context cannot suppress unrelated customer reset');
$before=count($accepted);retrieve_password('mail_shared');mailCheck(count($accepted)===$before,'Signed off-site context protects shared-account reset');
$_REQUEST=['emsf_site'=>$b,'emsf_context'=>Mail::signature($b)];retrieve_password('mail_shared');mailCheck(count($accepted)===$before+1,'Signed on-site context allows shared-account reset');
$_REQUEST=['emsf_site'=>$b,'emsf_context'=>'forged'];$before=count($accepted);retrieve_password('mail_a');mailCheck(count($accepted)===$before,'Forged context cannot bypass single-site mail-off policy');
$_REQUEST=['action'=>'lostpassword','user_login'=>'unknown'];$unknown=apply_filters('login_message','');$_REQUEST['user_login']='mail_a';mailCheck($unknown===apply_filters('login_message',''),'Public network reset explanation does not reveal submitted account state');
$_REQUEST=[];Mail::set($a,'on');$before=count($accepted);retrieve_password('mail_a');mailCheck(count($accepted)===$before+1,'New password reset works after explicit per-site mail-on');
mailCheck(!in_array('discarded contact',$accepted,true)&&!in_array('Fluent form off',$accepted,true),'Previously discarded messages are never replayed');
echo "TOTAL: $mailChecks mail-policy assertions passed; all allowed messages intercepted, no external delivery.\n";
