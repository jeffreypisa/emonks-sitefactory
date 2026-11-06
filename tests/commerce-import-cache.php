<?php
require dirname(__DIR__).'/includes/FullSite.php';
$deleted=[];$products=[];
function delete_transient($name){global $deleted;$deleted[]=$name;}
function wc_delete_product_transients($id=0){global $products;$products[]=$id;}
class WC_Cache_Helper {public static array $groups=[];public static function invalidate_cache_group($name){self::$groups[]=$name;}}
$wpdb=new class {public string $posts='wp_posts';public function get_col($sql){if(!str_contains($sql,"'product_variation'"))throw new RuntimeException('Variations excluded');return ['143','155'];}};
\Emonks\Factory\FullSite::invalidateCommerceCaches();
if($deleted!==['wc_attribute_taxonomies']||WC_Cache_Helper::$groups!==['woocommerce-attributes']||$products!==[0,143,155])throw new RuntimeException('Stale commerce caches retained');
$source=file_get_contents(dirname(__DIR__).'/includes/FullSite.php');
if(strpos($source,'self::invalidateCommerceCaches();')<strpos($source,"query('COMMIT')"))throw new RuntimeException('Caches invalidated before commit');
echo "4 commerce import cache checks passed.\n";
