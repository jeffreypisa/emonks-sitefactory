<?php
use Emonks\Factory\Standalone;
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration-handover-')||is_multisite()||get_option('home')!=='https://handover.example.test'){throw new RuntimeException('Disposable fixture required.');}
$archive=getenv('EMSF_HANDOVER_PACKAGE');if(!$archive||!str_contains($archive,'/emsf-handover-source-state-')){throw new RuntimeException('Synthetic source package required.');}
$r=Standalone::stage($archive,true);if(($r['state']??'')==='software_pending'){Standalone::installSoftware($r['operation']);}$report=Standalone::apply($r['operation']);
$home=get_post((int)get_option('page_on_front'));
if(!$report['ok']||!$home||$home->post_content!=='Changed since snapshot'||$report['source']===$report['target']||(int)get_option('blog_public')!==0){throw new RuntimeException('Source-to-standalone roundtrip failed.');}
ob_start();Standalone::page();$html=ob_get_clean();file_put_contents(Standalone::storage().'/import-ui.html',$html);
echo "5 real source-to-standalone roundtrip checks passed.\n";
