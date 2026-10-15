<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable only');}
require_once dirname(__DIR__).'/emonks-site-factory.php';
use Emonks\Factory\Online\{MailTemplate,MailDraft};
wp_set_current_user(1);$original=get_site_option('emsf_mail_template',null);$n=0;$check=static function($ok,$label)use(&$n){if(!$ok){throw new RuntimeException('FAIL '.$label);}++$n;echo "PASS $label\n";};
try{
 update_site_option('emsf_mail_template',['body'=>'Mijn vorige template','costs'=>'Mijn vorige prijsafspraak']);
 $settings=MailTemplate::settings();$check($settings['body']==='Mijn vorige template'&&str_contains($settings['intro_body'],'Google Meet'),'Old text retained alongside new approved intro');
 MailTemplate::save(['price'=>'950,50','monthly'=>'25','vat'=>'exclusief btw']);$settings=MailTemplate::settings();$check($settings['price']==='950.50'&&$settings['body']==='Mijn vorige template','Saving new price defaults preserves old template');
 $input=['draft_step'=>'intro','draft_first_name'=>'Anne','draft_company'=>'Studio Test','draft_screenshot'=>1];$fields=MailDraft::fields($input);$intro=MailDraft::compose($fields,[],[]);
 $check(str_contains($intro['body'],'€ 950,50 exclusief btw')&&str_contains($intro['body'],'€ 25,00 exclusief btw per maand'),'Structured price, month and VAT expanded');
 $check(!str_contains($intro['body'],'[')&&!str_contains($intro['body'],'http')&&str_contains($intro['body'],'screenshot'),'First mail complete without access links');
 $check(MailDraft::fields($input+['draft_price'=>'0'])['price']==='0','Zero is a valid explicit price');
 $settings['price']='1200';MailTemplate::save($settings);$check(MailDraft::fields($input)['price']==='1200'&&MailDraft::fields($input+['draft_price'=>'700'])['price']==='700','Central prices update inherited values but not overrides');
 foreach([['intro_body'=>'Hoi [previewcode]'],['intro_body'=>'[onbekend]'],['price'=>'950 euro'],['monthly'=>'-1'],['days'=>'0']]as$bad){try{MailTemplate::save($bad);throw new LogicException('Invalid accepted');}catch(RuntimeException $e){$check(MailTemplate::settings()===$settings,'Invalid smart field/settings rejected atomically');}}
 MailTemplate::save(['vat'=>'inclusief btw']);$check(MailTemplate::settings()['vat']==='exclusief btw'&&MailDraft::fields($input+['draft_vat'=>'inclusief btw'])['vat']==='exclusief btw','VAT is always exclusive despite old settings or submitted value');
 $check(MailTemplate::expand('[voornaam]',['voornaam'=>'[previewcode]','previewcode'=>'ABCD'])==='[previewcode]','Field values are not recursively evaluated');
 wp_set_current_user(0);try{MailTemplate::save(['price'=>'5']);throw new LogicException('Unauthorized');}catch(RuntimeException $e){$check(true,'Only admin can save');}wp_set_current_user(1);
 $_SERVER['REQUEST_METHOD']='GET';ob_start();MailTemplate::page();$html=ob_get_clean();$check(str_contains($html,'template[intro_body]')&&str_contains($html,'template[follow_body]')&&!str_contains($html,'name="template[costs]"')&&!str_contains($html,'Vorige template en tekstvelden'),'UI has two editable mails without old reference block');
 file_put_contents('/private/tmp/emsf-twomails/template-ui.html','<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{font:16px system-ui;background:#f0f0f1;margin:24px}.large-text{width:100%;box-sizing:border-box}textarea,input,select,button{font:inherit;padding:8px}textarea{line-height:1.5}'.file_get_contents(dirname(__DIR__).'/assets/admin.css').'</style>'.$html.'<script>'.file_get_contents(dirname(__DIR__).'/assets/admin.js').'</script></html>');
 echo "TOTAL $n template checks\n";
}finally{if($original===null){delete_site_option('emsf_mail_template');}else{update_site_option('emsf_mail_template',$original);}}
