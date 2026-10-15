<?php
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable only');}
use Emonks\Factory\Online\{Optimizer,Privacy};
$id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/optimizer-'.substr(wp_generate_uuid4(),0,8).'/', 'user_id'=>1,'title'=>'Optimizer test']);
switch_to_blog($id);
foreach(['optimize_javascript','optimize_css','combine_javascript','combine_css','optimize_javascript_async','optimize_web_fonts','lazyload_images','file_caching','logged_in_cache'] as $key){update_option('siteground_optimizer_'.$key,1);}
$sg=getenv('EMSF_SG_PLUGIN');if(!$sg||!is_file($sg)){throw new RuntimeException('Set EMSF_SG_PLUGIN to real sg-cachepress.php');}require_once $sg;
global $siteground_optimizer_loader;
$siteground_optimizer_loader->add_minifier_hooks();
$siteground_optimizer_loader->add_lazy_load_hooks();
$siteground_optimizer_loader->add_front_end_optimization_hooks();
$n=0;$check=static function($ok,$label)use(&$n){if(!$ok){throw new RuntimeException('FAIL: '.$label);}echo 'PASS: '.$label."\n";++$n;};
$minifier=[$siteground_optimizer_loader->minifier,'minify_scripts'];
$check(has_action('wp_print_scripts',$minifier)!==false,'Real SG minifier registered before Factory');
require_once dirname(__DIR__).'/emonks-site-factory.php';
Optimizer::detach();
$check(has_action('wp_print_scripts',$minifier)!==false,'Unmanaged site retains real SG callbacks');
update_site_meta($id,'emsf_managed',1);update_site_meta($id,'emsf_state','ready');
Optimizer::detach();
$check(has_action('wp_print_scripts',$minifier)===false,'Managed site removes already registered real SG minifier');
$check(has_action('wp_enqueue_scripts',[$siteground_optimizer_loader->lazy_load,'load_scripts'])===false,'SG lazy loader removed');
foreach(Optimizer::OPTIONS as $key){$check(get_option('siteground_optimizer_'.$key)===0,'Runtime override: '.$key);}
$siteground_optimizer_loader->add_minifier_hooks();$siteground_optimizer_loader->add_lazy_load_hooks();$siteground_optimizer_loader->add_front_end_optimization_hooks();
$check(has_action('wp_print_scripts',$minifier)===false,'Real SG loaded after Factory does not register minifier');
$check(has_action('wp_enqueue_scripts',[$siteground_optimizer_loader->lazy_load,'load_scripts'])===false,'Real SG loaded after Factory does not register lazy load');
$image='<img src="https://online.example.test/private/emsf-media/photo.png" alt="Test" />';
$check(!str_contains(apply_filters('the_content',$image),'data-src='),'Image stays usable without SG JavaScript lazyloading');
wp_enqueue_style('factory-style','https://online.example.test/wp-content/themes/skeletor/style.css',[],null);ob_start();do_action('wp_print_styles');ob_end_clean();$check(wp_styles()->registered['factory-style']->src==='https://online.example.test/wp-content/themes/skeletor/style.css','Original stylesheet URL survives real print hook');
$original='https://online.example.test/wp-content/themes/skeletor/script.js';wp_enqueue_script('factory-fixture',$original,[],null);do_action('wp_print_scripts');
$check(wp_scripts()->registered['factory-fixture']->src===$original,'Original script URL survives real print hook');
$uploads=wp_upload_dir();$check(str_contains($uploads['baseurl'],'/emsf-media')&&!str_contains($uploads['baseurl'],'factory_private'),'Media URL remains protected route');
delete_site_meta($id,'emsf_managed');foreach(Optimizer::OPTIONS as $key){$check((int)get_option('siteground_optimizer_'.$key)===1,'Stored setting preserved: '.$key);}
restore_current_blog();echo "TOTAL: $n checks passed\n";
