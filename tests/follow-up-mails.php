<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable only');}
require_once dirname(__DIR__).'/emonks-site-factory.php';
use Emonks\Factory\Online\{FollowUp,Admin,MailDraft};
wp_set_current_user(1);$n=0;$check=static function($ok,$label)use(&$n){if(!$ok){throw new RuntimeException('FAIL '.$label);}++$n;echo "PASS $label\n";};
$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/phases-'.substr(wp_generate_uuid4(),0,8).'/','user_id'=>1,'title'=>'Phases test','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready']]);
$data=['scope'=>'site','site_action'=>'follow_save','follow_stage'=>'new','follow_notes'=>'Bel volgende week','follow_price'=>'995','follow_date'=>wp_date('Y-m-d')];Admin::run($id,$data);
Admin::run($id,['scope'=>'site','site_action'=>'follow_mark_first_sent']);$first=FollowUp::get($id);$check($first['stage']==='first_sent'&&$first['first_sent_at']&&!$first['follow_sent_at'],'First mail gets its own phase and timestamp');
$check($first['notes']===$data['follow_notes']&&$first['price']==='995'&&$first['date']===$data['follow_date'],'Notes, price and follow-up date preserved');
Admin::run($id,array_replace($data,['follow_stage'=>'interested']));Admin::run($id,['scope'=>'site','site_action'=>'follow_mark_first_sent']);$check(FollowUp::get($id)['stage']==='interested'&&FollowUp::get($id)['first_sent_at']===$first['first_sent_at'],'Repeated first-mail click preserves later phase and timestamp');
Admin::run($id,array_replace($data,['follow_stage'=>'preparing']));Admin::run($id,['scope'=>'site','site_action'=>'follow_mark_follow_sent']);$follow=FollowUp::get($id);$check($follow['stage']==='follow_sent'&&$follow['follow_sent_at']&&$follow['first_sent_at']===$first['first_sent_at'],'Follow-up mail keeps separate timestamp');
Admin::run($id,array_replace($data,['follow_stage'=>'accepted']));Admin::run($id,['scope'=>'site','site_action'=>'follow_mark_follow_sent']);$check(FollowUp::get($id)['stage']==='accepted'&&!FollowUp::due(FollowUp::get($id)),'Mail button never reopens accepted customer');
update_site_meta($id,'emsf_follow_up',['stage'=>'sent','sent_at'=>'2026-01-01T10:00:00+00:00']);$old=FollowUp::get($id);$check(!$old['first_sent_at']&&!$old['follow_sent_at']&&$old['sent_at']==='2026-01-01T10:00:00+00:00','Old mail stays unclassified');
ob_start();FollowUp::panel($id);$html=ob_get_clean();$check(str_contains($html,'mailtype onbekend')&&str_contains($html,'Websitevoorstel uitwerken')&&str_contains($html,'Interesse ontvangen'),'UI shows new phases and honest legacy timestamp');
$before=FollowUp::get($id);MailDraft::prepare($id,['draft_step'=>'intro','draft_first_name'=>'Anne','draft_company'=>'Test','draft_price'=>'995','draft_monthly'=>'60']);$check(FollowUp::get($id)===$before,'Generating a draft does not mark anything sent');
wp_set_current_user(0);try{Admin::run($id,['scope'=>'site','site_action'=>'follow_mark_first_sent']);throw new LogicException('Unauthorized accepted');}catch(RuntimeException $e){$check(FollowUp::get($id)===$before,'Customer cannot mark mail sent');}
echo "TOTAL $n checks\n";
