<?php
use Emonks\Factory\{Standalone,StandaloneContent,DeliverySoftware};
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration-handover-software-improvements-')||is_multisite()){throw new RuntimeException('Disposable software replacement fixture required.');}
wp_set_current_user(1);$phase=getenv('EMSF_SOFTWARE_PHASE');
if($phase==='stage'){
 if(function_exists('get_field')||DeliverySoftware::find('acf')!==null)throw new RuntimeException('Fixture must start without ACF');
 $r=Standalone::stage(getenv('EMSF_SOFTWARE_PACKAGE'),true);
 if($r['state']!=='software_pending'||!get_post(1)||!get_post(2))throw new RuntimeException('Upload changed existing content');
 echo "PASS: software package staged with existing content and no installed dependencies.\n";
}elseif($phase==='software'){
 $r=Standalone::state();$r=Standalone::installSoftware($r['operation']);
 if($r['state']!=='software_ready'||empty($r['backup'])||!get_post(1)||get_stylesheet()!=='skeletor')throw new RuntimeException('Software or pre-install backup failed');
 StandaloneContent::verify($r['backup']);echo "PASS: real plugin/theme installation, original content preserved and pre-install backup verified.\n";
}elseif($phase==='content'){
 $r=Standalone::state();$inventory=StandaloneContent::inventory();$report=Standalone::apply($r['operation'],['mode'=>'replace','inventory'=>$inventory['fingerprint'],'replace_confirmed'=>'1','use_destination'=>'1']);
 if(!$report['ok']||get_post(1)!==null||!get_option('page_on_front'))throw new RuntimeException('Actual content replacement failed');
 echo "PASS: content imported with installed dependencies after confirmation.\n";
}elseif($phase==='restore'){
 $r=Standalone::state();Standalone::restore($r['operation']);
 if(!get_post(1)||!get_post(2)||get_stylesheet()!=='twentytwentyfive'||is_plugin_active('advanced-custom-fields-pro/acf.php')||!get_user_by('id',1))throw new RuntimeException('Original theme/plugin choices, content or administrator not restored');
 echo "PASS: original website, active theme and plugin choices restored; administrator preserved.\n";
}else{throw new RuntimeException('Select a phase');}
