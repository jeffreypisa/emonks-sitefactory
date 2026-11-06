<?php
/** Run via wp eval-file in a fresh disposable single-site fixture, never a real site. */
use Emonks\Factory\{Standalone,StandaloneContent,Handover,Package};
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration-handover-')||is_multisite()){throw new RuntimeException('Disposable standalone fixture required.');}
$checks=0;$assert=static function($v,$message)use(&$checks){if(!$v){throw new RuntimeException('FAIL: '.$message);} $checks++;};
$reject=static function(callable $fn,string $text)use($assert){try{$fn();}catch(Throwable $e){$assert(str_contains($e->getMessage(),$text),'Expected '.$text.', received '.$e->getMessage());return;}$assert(false,'Expected rejection: '.$text);};
wp_set_current_user(1);
// Fluent Forms creates a sample form on activation in this disposable fixture.
global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}fluentform_forms"); $wpdb->query("DELETE FROM {$wpdb->prefix}fluentform_form_meta");
$root=Standalone::storage();$dir=$root.'/incoming-'.wp_generate_uuid4();wp_mkdir_p($dir.'/files/media');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');file_put_contents($dir.'/files/media/test.png',$png);
$base=['post_status'=>'publish','post_date'=>'2020-01-01 12:00:00','menu_order'=>0,'post_mime_type'=>'','post_excerpt'=>''];
$m=['format'=>Package::FORMAT,'version'=>1,'theme'=>'skeletor','created_at'=>gmdate('c'),'source'=>'https://proposal.example.test/demo','dependencies'=>['theme_version'=>'1.0.0'],'models'=>[
 ['key'=>'post_type_handover_service','post_type'=>'handover_service','title'=>'Services','active'=>true,'public'=>true,'show_ui'=>true,'supports'=>['title','editor'],'rewrite'=>['slug'=>'services']],
 ['key'=>'taxonomy_handover_kind','taxonomy'=>'handover_kind','title'=>'Service groups','active'=>true,'public'=>true,'object_type'=>['handover_service']],
 ['key'=>'group_handover_fields','title'=>'Service information','active'=>true,'fields'=>[['key'=>'field_handover_text','name'=>'description','label'=>'Description','type'=>'text']],'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'handover_service']]]]
], 'posts'=>[
 ['ref'=>'post:page:home','post'=>$base+['post_type'=>'page','post_name'=>'home','post_title'=>'Home','post_content'=>'<a href="{{emsf:url:post:handover_service:advice}}">Advice</a>'],'parent'=>0,'meta'=>[],'terms'=>[]],
 ['ref'=>'post:handover_service:advice','post'=>$base+['post_type'=>'handover_service','post_name'=>'advice','post_title'=>'Advice','post_content'=>'Our service.'],'parent'=>0,'meta'=>['description'=>['Custom field content'],'_description'=>['field_handover_text'],'_thumbnail_id'=>[['$ref'=>'post:attachment:test']]],'terms'=>['handover_kind'=>[['$ref'=>'term:handover_kind:consulting']]]],
 ['ref'=>'post:attachment:test','post'=>array_replace($base,['post_type'=>'attachment','post_name'=>'test','post_title'=>'Picture','post_content'=>'','post_status'=>'inherit','post_mime_type'=>'image/png']),'parent'=>0,'meta'=>['_wp_attachment_image_alt'=>['Test image']],'terms'=>[],'file'=>'media/test.png']
], 'terms'=>[['ref'=>'term:handover_kind:consulting','name'=>'Consulting','slug'=>'consulting','taxonomy'=>'handover_kind','description'=>'','parent'=>0]],'menus'=>[['ref'=>'menu:main','name'=>'Main','slug'=>'main','items'=>[['source_id'=>22,'parent_source_id'=>0,'title'=>'Advice','type'=>'post_type','object'=>'handover_service','object_ref'=>['$ref'=>'post:handover_service:advice'],'url'=>'{{emsf:url:post:handover_service:advice}}','target'=>'','classes'=>[],'xfn'=>'','position'=>1]]]],'options'=>['blogname'=>'Handover fixture','show_on_front'=>'page','page_on_front'=>['$ref'=>'post:page:home'],'permalink_structure'=>'/%postname%/'],'menu_locations'=>['primary'=>['$ref'=>'menu:main']],'forms'=>[],'files'=>['media/test.png'=>['sha256'=>hash('sha256',$png),'size'=>strlen($png)]],'unsupported'=>[]];
$h=['format'=>Handover::FORMAT,'operation'=>wp_generate_uuid4(),'source'=>$m['source'],'target'=>'https://planned.example.test','approved_at'=>gmdate('c'),'approved_by'=>1,'fingerprint'=>Handover::fingerprint($m)];
Package::json($dir.'/manifest.json',$m);$h['manifest_sha256']=hash_file('sha256',$dir.'/manifest.json');Package::json($dir.'/handover.json',$h);
$zipfile=$root.'/fixture.zip';$zip=new ZipArchive();$zip->open($zipfile,ZipArchive::CREATE|ZipArchive::OVERWRITE);foreach(['manifest.json','handover.json','files/media/test.png'] as $file){$zip->addFile($dir.'/'.$file,$file);}$zip->close();

$originalSample=get_post(2)->post_content;
wp_update_post(['ID'=>2,'post_content'=>str_replace('href="','href="https://changed.example.test/?original=',$originalSample)]);
$assert(!StandaloneContent::inventory()['samples_only'],'A changed link in a default page is not mistaken for untouched sample content');
wp_update_post(['ID'=>2,'post_content'=>$originalSample]);
update_post_meta(2,'custom_customer_field','Do not remove as sample');
$assert(!StandaloneContent::inventory()['samples_only'],'Custom fields on a default page require full replacement');delete_post_meta(2,'custom_customer_field');
$originalComment=get_comment(1)->comment_content;
wp_update_comment(['comment_ID'=>1,'comment_content'=>'Edited default comment']);
$assert(!StandaloneContent::inventory()['samples_only'],'Edited default comment is not removed as sample content');
$wpdb->update($wpdb->comments,['comment_content'=>$originalComment],['comment_ID'=>1]);clean_comment_cache(1);
update_option('home','https://previous.example.test');
$inventory=StandaloneContent::inventory();
$assert($inventory['samples_only'] && count($inventory['sample_ids'])===3,'Only untouched WordPress sample content is recognized');
$before=hash('sha256',serialize($wpdb->get_results("SELECT * FROM {$wpdb->posts}",ARRAY_A)));
$r=Standalone::stage($zipfile,true);
$assert($r['target']==='https://handover.example.test' && $r['package_target']==='https://planned.example.test','Actual and package addresses kept separately');
$assert($before===hash('sha256',serialize($wpdb->get_results("SELECT * FROM {$wpdb->posts}",ARRAY_A))),'Staging never deletes content');
$reject(static fn()=>Standalone::apply($r['operation']),'bevat inhoud');
$confirm=['mode'=>'samples','inventory'=>$inventory['fingerprint'],'replace_confirmed'=>'1','use_destination'=>'1'];
$bad=$confirm;$bad['inventory']='stale';$reject(static fn()=>Standalone::apply($r['operation'],$bad),'inhoudsoverzicht');
$bad=$confirm;unset($bad['use_destination']);$reject(static fn()=>Standalone::apply($r['operation'],$bad),'dit adres');
wp_update_post(['ID'=>2,'post_title'=>'Edited sample']);$reject(static fn()=>Standalone::apply($r['operation'],$confirm),'meer dan');wp_update_post(['ID'=>2,'post_title'=>'Sample Page']);
$confirm['inventory']=StandaloneContent::inventory()['fingerprint'];
$fail=static function($data){if(($data['post_type']??'')==='handover_service'){throw new RuntimeException('Injected replacement failure');}return $data;};add_filter('wp_insert_post_data',$fail);
$reject(static fn()=>Standalone::apply($r['operation'],$confirm),'Injected replacement failure');remove_filter('wp_insert_post_data',$fail);
$assert(get_post(1)!==null && get_post(2)!==null && get_comment(1)!==null,'Failed import rolls back sample removal');
$assert(!empty(Standalone::state()['backup']) && empty(Standalone::state()['committed']),'Failure keeps a verified backup and no committed receipt');
$user_count=count(get_users());$report=Standalone::apply($r['operation'],$confirm);
$assert($report['ok'] && $report['target']==='https://handover.example.test','Sample replacement succeeds on detected address');
$assert(get_post(1)===null && get_post(2)===null,'Sample posts removed');
$assert(count(get_users())===$user_count,'Accounts preserved during replacement');
$map=get_option('emsf_import_map');$home=$map['post:page:home'];$service=$map['post:handover_service:advice'];
$assert(str_contains(get_post($home)->post_content,'https://handover.example.test/'),'Internal URLs use installation address');
$assert(!str_contains(get_post($home)->post_content,'planned.example.test'),'Package address is not inserted into content');
$assert(get_option('home')==='https://handover.example.test','WordPress home address is aligned to the installation address');
$count=$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");Standalone::apply($r['operation']);$assert($count===$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),'Committed retry is idempotent');
$additional=wp_create_user('preserved-editor',wp_generate_password(), 'editor@example.test');
Standalone::restore($r['operation']);$assert(get_post(1)!==null && get_post(2)!==null && get_comment(1)!==null,'Backup restores original posts and comments');
$assert(get_user_by('id',$additional)!==false,'Restore preserves accounts created after the backup');
$assert(empty(Standalone::state()['committed']),'Restored job can be reviewed again');
$original=wp_insert_post(['post_type'=>'page','post_title'=>'Existing business page','post_content'=>'Original business content','post_status'=>'publish']);
$uploads=wp_upload_dir();wp_mkdir_p($uploads['basedir']);$old_file=$uploads['basedir'].'/original-business.txt';file_put_contents($old_file,'Original file bytes');
$media=wp_insert_attachment(['post_title'=>'Original attachment','post_mime_type'=>'text/plain','post_status'=>'inherit'],$old_file);update_attached_file($media,$old_file);
$wpdb->insert($wpdb->prefix.'fluentform_forms',['title'=>'Existing form','status'=>'published','created_by'=>1,'form_fields'=>'{}','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);$form=(int)$wpdb->insert_id;
$wpdb->insert($wpdb->prefix.'fluentform_submissions',['form_id'=>$form,'serial_number'=>1,'response'=>'{}','status'=>'unread','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);$submission=(int)$wpdb->insert_id;$assert($submission>0,'Synthetic submission created');
$inventory=StandaloneContent::inventory();$assert(!$inventory['samples_only'] && $inventory['counts']['media']===1 && $inventory['counts']['forms']===1 && $inventory['counts']['submissions']===1,'Business page, media, forms and submissions are inventoried');
$reject(static fn()=>Standalone::apply($r['operation'],['mode'=>'samples']),'meer dan');
$confirm=['mode'=>'replace','inventory'=>$inventory['fingerprint'],'replace_confirmed'=>'1','use_destination'=>'1'];
if(getenv('EMSF_REPLACEMENT_PHASE')==='stage-crash'){
 Package::json($root.'/replacement-crash.json',['confirmation'=>$confirm,'operation'=>$r['operation'],'original'=>$original,'file'=>$old_file,'form'=>$form,'additional'=>$additional]);
 echo "Replacement crash fixture staged.\n";return;
}
add_filter('wp_insert_post_data',$fail);$reject(static fn()=>Standalone::apply($r['operation'],$confirm),'Injected replacement failure');remove_filter('wp_insert_post_data',$fail);
$assert(get_post($original)!==null && is_file($old_file) && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",$form)),'Failure preserves business content, media and forms');
$report=Standalone::apply($r['operation'],$confirm);$assert($report['ok'],'Full replacement report succeeds');
$assert((bool)term_exists((int)get_option('default_category'),'category'),'WordPress default category remains valid after term replacement');
$assert(get_post($original)===null && get_post($media)===null && !is_file($old_file),'Old content and original media removed after commit');
$assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",$form)),'Old form removed');
$assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_submissions WHERE id=%d",$submission)),'Old submission removed');
$receipt=Standalone::state()['backup'];$verified=StandaloneContent::verify($receipt);
$assert(!isset($verified['tables'][$wpdb->users]) && !isset($verified['tables'][$wpdb->usermeta]),'Recovery archive excludes account tables');
$archive=StandaloneContent::archive($receipt);$recoveryZip=new ZipArchive();$assert($recoveryZip->open($archive)===true && $recoveryZip->locateName('backup.json')!==false && $recoveryZip->locateName('uploads/original-business.txt')!==false,'Downloadable recovery ZIP contains database manifest and media');$recoveryZip->close();unlink($archive);
$backup_file=Standalone::storage().'/'.$receipt['id'].'/uploads/original-business.txt';$assert(file_get_contents($backup_file)==='Original file bytes','Private media backup retains original bytes');
file_put_contents($backup_file,'corrupt');$reject(static fn()=>Standalone::restore($r['operation']),'beschadigd');file_put_contents($backup_file,'Original file bytes');
Standalone::restore($r['operation']);$assert(get_post($original)!==null && get_post($media)!==null && file_get_contents($old_file)==='Original file bytes','Full backup restores business page and media');
$assert($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",$form)) && get_user_by('id',$additional)!==false,'Forms restored and accounts retained');
$assert((int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_submissions WHERE id=%d",$submission))===$submission,'Form submission restored');
echo "$checks standalone replacement and recovery integration checks passed.\n";
