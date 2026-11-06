<?php
use Emonks\Factory\Standalone;
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||(!str_contains(ABSPATH,'emsf-integration-handover-')||is_multisite()||get_option('home')!=='https://handover.example.test')){throw new RuntimeException('Disposable fixture required.');}
$_SERVER['HTTPS']='on';$r=Standalone::state();$checks=[];
if(!Standalone::testing($r['operation'])['test_mode']){throw new RuntimeException('Administrator test mode failed.');}
if(Standalone::testing($r['operation'])['test_mode']){throw new RuntimeException('Administrator test mode disabling failed.');}
add_filter('wp_revisions_to_keep',static fn()=>0);
$home=(int)get_option('page_on_front');$content=get_post_field('post_content',$home);wp_update_post(['ID'=>$home,'post_content'=>'Source leak: '.$r['source']]);
try{
 try{Standalone::release($r['operation']);throw new RuntimeException('Automatic validation was bypassed.');}
 catch(Throwable $e){if(!str_contains($e->getMessage(),'Achtergebleven bronadres')){throw $e;}}
 if(Standalone::state()['state']==='released'||!is_file(WPMU_PLUGIN_DIR.'/emonks-handover-guard.php')||(int)get_option('blog_public')!==0){throw new RuntimeException('Failed activation changed protections.');}
}finally{wp_update_post(['ID'=>$home,'post_content'=>$content]);}
$report=Standalone::release($r['operation']);
if(($report['checks']??null)!==[]||($report['release_mode']??'')!=='manual'||$report['state']!=='released'||is_file(WPMU_PLUGIN_DIR.'/emonks-handover-guard.php')||(int)get_option('blog_public')!==1){throw new RuntimeException('Release failed.');}
if(Standalone::release($r['operation'],$checks)!==$report){throw new RuntimeException('Release retry changed report.');}
echo "6 separate launch / automatic validation / administrator test checks passed.\n";
