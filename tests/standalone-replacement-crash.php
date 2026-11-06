<?php
use Emonks\Factory\{Standalone,StandaloneContent};
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration-handover-import-improvements-')||is_multisite()){throw new RuntimeException('Disposable replacement fixture required.');}
wp_set_current_user(1);global $wpdb;
$fixture=json_decode(file_get_contents(Standalone::storage().'/replacement-crash.json'),true,512,JSON_THROW_ON_ERROR);
if(getenv('EMSF_REPLACEMENT_PHASE')==='crash'){
 add_filter('wp_insert_post_data',static function($data){if(($data['post_type']??'')==='handover_service'){exit(73);}return $data;});
 Standalone::apply($fixture['operation'],$fixture['confirmation']);throw new RuntimeException('Crash hook was not reached.');
}
if(empty(Standalone::state()['backup'])||!empty(Standalone::state()['committed'])||!get_post($fixture['original'])||!is_file($fixture['file'])||!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE id=%d",$fixture['form']))){throw new RuntimeException('Original content, form, media or backup lost after process termination.');}
StandaloneContent::verify(Standalone::state()['backup']);
$report=Standalone::apply($fixture['operation'],$fixture['confirmation']);if(!$report['ok']){throw new RuntimeException('Import cannot resume after termination.');}
Standalone::restore($fixture['operation']);if(!get_post($fixture['original'])||!is_file($fixture['file'])||!get_user_by('id',$fixture['additional'])){throw new RuntimeException('Restore after resumed import failed.');}
echo "PASS: hard process interruption, database rollback, durable backup, resumed import and full restore with accounts preserved.\n";
