<?php
use Emonks\Factory\{Standalone,DeliverySoftware};
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration-bundle-')||is_multisite()||get_option('home')!=='https://handover.example.test'){throw new RuntimeException('Disposable bundle fixture required.');}
$phase=getenv('EMSF_BUNDLE_PHASE');wp_set_current_user(1);global $wpdb;
if($phase==='stage'){
 if(defined('ACF_PRO')||DeliverySoftware::find('acf')!==null){throw new RuntimeException('ACF must be absent before upload.');}
 $archive=getenv('EMSF_BUNDLE_ARCHIVE');if(!$archive||!is_file($archive)){throw new RuntimeException('Provide a trusted private test bundle via EMSF_BUNDLE_ARCHIVE.');}
 $r=Standalone::stage($archive,true);
 if($r['state']!=='software_pending'||DeliverySoftware::find('acf')!==null){throw new RuntimeException('Staging must accept bundled software but not execute it.');}
 echo "3 staging checks passed without preinstalled ACF or theme.\n";
}elseif($phase==='software'){
 $r=Standalone::state();$result=Standalone::installSoftware($r['operation']);
 if($result['state']!=='software_ready'||!is_plugin_active('advanced-custom-fields-pro/acf.php')){throw new RuntimeException('Software installation failed.');}
 $again=Standalone::installSoftware($r['operation']);if($again['operation']!==$r['operation']){throw new RuntimeException('Software retry changed operation.');}
 if($wpdb->get_var("SELECT id FROM {$wpdb->prefix}fluentform_forms LIMIT 1")){throw new RuntimeException('Vendor demo form must not be created.');}
 echo "4 actual software installation/retry checks passed.\n";
}elseif($phase==='content'){
 $r=Standalone::state();$m=Standalone::read($r['dir']);DeliverySoftware::confirm($m['software']);Standalone::preflight($m);$result=Standalone::apply($r['operation']);
 if(!$result['ok']){throw new RuntimeException('Content import failed: '.implode('; ',$result['issues']));}
 if(!defined('ACF_PRO')||!ACF_PRO||!defined('FLUENTFORMPRO')||get_stylesheet()!=='skeletor'){throw new RuntimeException('Full software stack not active.');}
 if(!$wpdb->get_var("SELECT id FROM {$wpdb->prefix}fluentform_forms LIMIT 1")){throw new RuntimeException('Source forms missing.');}
 if(!(int)get_option('page_on_front')){throw new RuntimeException('Source home missing.');}
 echo "5 full-source content/activation checks passed.\n";
}elseif($phase==='independent'){
 if(class_exists('Emonks\\Factory\\Factory',false)){throw new RuntimeException('Importer must be deactivated.');}
 if(!function_exists('get_field')||!defined('FLUENTFORMPRO')||get_stylesheet()!=='skeletor'||!get_post((int)get_option('page_on_front'))){throw new RuntimeException('Independent site failed.');}
 echo "4 full-theme independence checks passed without importer.\n";
}else{throw new RuntimeException('Choose fixture phase.');}
