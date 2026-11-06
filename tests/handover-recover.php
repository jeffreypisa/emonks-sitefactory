<?php
use Emonks\Factory\Standalone;
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||(!str_contains(ABSPATH,'emsf-integration-handover-')||is_multisite()||get_option('home')!=='https://handover.example.test')){throw new RuntimeException('Disposable fixture required.');}
$r=Standalone::state();if(!empty($r['committed'])){throw new RuntimeException('Crash must not commit.');}
if(get_posts(['post_type'=>'any','post_status'=>'any','numberposts'=>-1])){throw new RuntimeException('Killed transaction left content rows.');}
$result=Standalone::apply($r['operation']);if(!$result['ok']){throw new RuntimeException('Retry failed.');}
echo "3 hard interruption / recovery checks passed.\n";
