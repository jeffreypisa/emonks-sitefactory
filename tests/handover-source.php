<?php
use Emonks\Factory\{Handover,Package,Factory};
use Emonks\Factory\Online\{Admin,Store};
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration-handover-source-')||!is_multisite()){throw new RuntimeException('Disposable multisite fixture required.');}
wp_set_current_user(1);global $wpdb;
$n=0;$assert=static function($v,$m)use(&$n){if(!$v){throw new RuntimeException('FAIL: '.$m);} $n++;};$reject=static function($fn,$text)use($assert){try{$fn();}catch(Throwable $e){$assert(str_contains($e->getMessage(),$text),'Expected '.$text.', got '.$e->getMessage());return;}$assert(false,'Expected '.$text);};
$id=wpmu_create_blog('source.example.test','/proposal-'.substr(wp_generate_uuid4(),0,8).'/','Proposal',1,['public'=>0],get_current_network_id());if(is_wp_error($id)){throw new RuntimeException($id->get_error_message());}
update_site_meta($id,'emsf_managed',true);update_site_meta($id,'emsf_state','ready');
switch_to_blog($id);switch_theme('skeletor');foreach(get_posts(['post_type'=>'any','post_status'=>'any','numberposts'=>-1]) as $p){wp_delete_post($p->ID,true);}$wpdb->query("DELETE FROM {$wpdb->comments}");
$home=wp_insert_post(['post_type'=>'page','post_title'=>'Approved home','post_name'=>'home','post_status'=>'publish','post_content'=>'<p>Original approved copy.</p>']);
update_option('show_on_front','page');update_option('page_on_front',$home);update_option('permalink_structure','/%postname%/');restore_current_blog();
$reject(static fn()=>Handover::prepare($id,'https://handover.example.test',false),'definitieve inhoud');
$r=Handover::prepare($id,'https://handover.example.test',true);$assert($r['state']==='ready','Prepared version is immediately ready');
$assert(is_file(Handover::archive($id,$r['fingerprint'])),'Download available immediately after preparation');
switch_to_blog($id);wp_update_post(['ID'=>$home,'post_content'=>'Changed since snapshot']);restore_current_blog();
$assert(is_file(Handover::archive($id,$r['operation'])),'Existing snapshot remains downloadable after source changes');
$r=Handover::prepare($id,'https://handover.example.test',true);$assert($r['state']==='ready'&&isset($r['ready_at'],$r['ready_by'])&&!isset($r['approved_at']),'Preparation records provenance without inventing approval');
$zip=Handover::archive($id,$r['operation']);$assert(is_file($zip),'Ready export ZIP exists');$z=new ZipArchive();$z->open($zip);$assert($z->locateName('handover.json')!==false,'Ready provenance included');$assert($z->locateName('manifest.json')!==false,'Content manifest included');$m=json_decode($z->getFromName('manifest.json'),true);$assert(!isset($m['prospect']),'Commercial dossier excluded');$assert(isset($m['options']['timezone_string']),'Site timezone included');$z->close();
$assert(is_file(Handover::importerArchive()),'Temporary importer downloadable');
$_GET=['id'=>$id,'page'=>'emsf-site','tab'=>'oplevering'];ob_start();Admin::page();$html=ob_get_clean();$assert(str_contains($html,'id="oplevering" role="tabpanel"'),'Oplevering tab rendered');$assert(str_contains($html,'Downloaden'),'Ready package download button rendered');$assert(!str_contains($html,'Keur pakket goed'),'No separate approval action');$assert(count(Handover::packages($id))===2,'Both prepared snapshots retained');
$records=array_values(Handover::packages($id));$newest=$records[0]['operation'];$older=$records[1]['operation'];
$assert($newest===$r['operation'],'Newest package appears first');
Handover::remove($id,$older);$assert(count(Handover::packages($id))===1,'Delete hides only the selected package');
$reject(static fn()=>Handover::archive($id,$older),'niet beschikbaar');
Handover::restore($id,$older);$assert(count(Handover::packages($id))===2&&is_file(Handover::archive($id,$older)),'Undo restores the stored snapshot');
file_put_contents(Store::root().'/source-ui.html',$html);Package::json(Store::root().'/source-result.json',['zip'=>$zip,'checks'=>$n,'site'=>$id,'fingerprint'=>$r['fingerprint']]);
switch_to_blog($id);wp_update_post(['ID'=>$home,'post_content'=>'Later edit']);restore_current_blog();$assert(is_file(Handover::archive($id,$r['operation'])),'Existing snapshot remains downloadable after source changes');
echo "$n handover source checks passed.\n";
