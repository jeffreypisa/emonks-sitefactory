<?php
use Emonks\Factory\Standalone;
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration-handover-')||is_multisite()||get_option('home')!=='https://handover.example.test'){throw new RuntimeException('Disposable handover fixture required.');}
wp_set_current_user(1);global $wpdb;
$n=0;$assert=static function($ok,$message)use(&$n){if(!$ok){throw new RuntimeException($message);}++$n;};
$reject=static function(callable $fn,string $text)use($assert){try{$fn();}catch(Throwable $e){$assert(str_contains($e->getMessage(),$text),$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$text);};
$r=Standalone::state();$assert(!empty($r['committed'])&&$r['state']==='imported','Start after a committed fixture import.');
$guard=WPMU_PLUGIN_DIR.'/emonks-handover-guard.php';$assert(is_file($guard),'Protection present before finalization.');
$before=$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$reject(static fn()=>Standalone::importWebsite('different-operation'),'operatie');
$_SERVER['HTTPS']='off';
$reject(static fn()=>Standalone::importWebsite($r['operation']),'HTTPS');
$assert(Standalone::state()['state']==='imported'&&is_file($guard)&&(int)get_option('blog_public')===0,'HTTPS failure keeps protection.');
$_SERVER['HTTPS']='on';
$id=(int)get_option('page_on_front');$content=get_post_field('post_content',$id);add_filter('wp_revisions_to_keep',static fn()=>0);
wp_update_post(['ID'=>$id,'post_content'=>'Source leak: '.$r['source']]);
try{
    $reject(static fn()=>Standalone::importWebsite($r['operation']),'Achtergebleven bronadres');
    $assert(Standalone::state()['state']==='imported'&&is_file($guard)&&(int)get_option('blog_public')===0,'Validation failure keeps protection.');
}finally{wp_update_post(['ID'=>$id,'post_content'=>$content]);}
$original=file_get_contents($guard);file_put_contents($guard,$original."\n// Unexpected modification\n");
try{$reject(static fn()=>Standalone::importWebsite($r['operation']),'beveiliging wijkt af');$assert(Standalone::state()['state']==='imported','Modified guard blocks activation.');}
finally{file_put_contents($guard,$original);}
$report=Standalone::importWebsite($r['operation']);
$assert($report['ok']&&$report['state']==='released'&&$report['release_mode']==='automatic'&&$report['checks']===[],'Automatic release recorded without manual attestations.');
$assert(!is_file($guard)&&(int)get_option('blog_public')===1,'Guard removed and indexing enabled.');
$assert($before===$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),'Retry after commit did not reimport.');
$assert(Standalone::importWebsite($r['operation'])===$report,'Repeated import returns the same release receipt.');
ob_start();Standalone::page();$html=ob_get_clean();
$assert(str_contains($html,'Website is geactiveerd')&&!str_contains($html,'value="release"'),'Completion screen has no required activation step.');
echo "$n automatic activation checks passed.\n";
