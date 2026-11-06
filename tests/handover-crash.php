<?php
use Emonks\Factory\Standalone;
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||(!str_contains(ABSPATH,'emsf-integration-handover-')||is_multisite()||get_option('home')!=='https://handover.example.test')){throw new RuntimeException('Disposable fixture required.');}
$r=Standalone::state();if(!empty($r['committed'])){throw new RuntimeException('Fixture must be staged.');}
add_filter('wp_insert_post_data',static function($data){if(($data['post_type']??'')==='handover_service'){exit(73);}return $data;});
Standalone::apply($r['operation']);throw new RuntimeException('Crash hook not reached');
