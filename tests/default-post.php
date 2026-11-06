<?php
/** php tests/default-post.php: isolated new-site cleanup, no WordPress/database writes. */
$posts=[];$deleted=[];
function get_post($id){global $posts;return $posts[$id]??null;}
function wp_delete_post($id,$force=false){global $posts,$deleted;$deleted[]=[$id,$force];$post=$posts[$id]??null;unset($posts[$id]);return $post;}
require dirname(__DIR__).'/includes/Factory.php';
$method=new ReflectionMethod(Emonks\Factory\Factory::class,'remove_new_site_default_post');
$posts=[1=>(object)['ID'=>1,'post_type'=>'post'],2=>(object)['ID'=>2,'post_type'=>'post']];
$method->invoke(null);
if($deleted!==[[1,true]]||isset($posts[1])||!isset($posts[2]))throw new RuntimeException('Only the generated first post must be permanently removed.');
$method->invoke(null);
if(count($deleted)!==1)throw new RuntimeException('Repeated cleanup must do nothing.');
$posts[1]=(object)['ID'=>1,'post_type'=>'page'];$method->invoke(null);
if(!isset($posts[1])||count($deleted)!==1)throw new RuntimeException('Other content types must be preserved.');
echo "PASS: permanent deletion of generated post, repeatability and preservation of other content.\n";
