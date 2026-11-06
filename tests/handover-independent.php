<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||(!str_contains(ABSPATH,'emsf-integration-handover-')||is_multisite()||get_option('home')!=='https://handover.example.test')){throw new RuntimeException('Disposable fixture required.');}
if(class_exists('Emonks\\Factory\\Factory',false)){throw new RuntimeException('Importer must be deactivated.');}
$r=get_option('emsf_handover_import');$map=get_option('emsf_import_map');$id=$map['post:handover_service:advice'];
$checks=[!empty($r['committed']),post_type_exists('handover_service'),taxonomy_exists('handover_kind'),get_field('description',$id)==='Custom field content',is_file(get_attached_file($map['post:attachment:test'])),is_file(WPMU_PLUGIN_DIR.'/emonks-handover-guard.php')];
if(in_array(false,$checks,true)){throw new RuntimeException('Independence check failed: '.json_encode($checks));}
echo count($checks)." importer-independent checks passed in a fresh WordPress process.\n";
