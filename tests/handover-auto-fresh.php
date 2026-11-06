<?php
use Emonks\Factory\Standalone;
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration-handover-')||is_multisite()||get_option('home')!=='https://handover.example.test'){throw new RuntimeException('Disposable staged handover fixture required.');}
wp_set_current_user(1);$_SERVER['HTTPS']='on';$r=Standalone::state();
if(!empty($r['committed'])||$r['state']!=='staged'){throw new RuntimeException('Run handover-integration.php with EMSF_TEST_STOP_AFTER_STAGE=yes first.');}
ob_start();Standalone::page();$html=ob_get_clean();
if(!str_contains($html,'Website importeren en activeren')||!str_contains($html,'activeren we deze automatisch')){throw new RuntimeException('Import action must announce automatic activation.');}
$report=Standalone::importWebsite($r['operation']);
if(!$report['ok']||$report['state']!=='released'||$report['release_mode']!=='automatic'||$report['checks']!==[]){throw new RuntimeException('Fresh import was not automatically activated.');}
if(is_file(WPMU_PLUGIN_DIR.'/emonks-handover-guard.php')||(int)get_option('blog_public')!==1){throw new RuntimeException('Protection was not cleared.');}
$map=get_option('emsf_import_map');if(!get_post($map['post:page:home'])||get_post_meta($map['post:handover_service:advice'],'description',true)!=='Custom field content'){throw new RuntimeException('Imported content missing.');}
if(Standalone::importWebsite($r['operation'])!==$report){throw new RuntimeException('Retry changed the receipt.');}
echo "5 fresh import and automatic activation checks passed.\n";
