<?php
// Run with php tests/menu-locations.php /path/to/wordpress. No database or network.
require dirname(__DIR__).'/includes/Package.php';
$core=$argv[1]??'';
if(!is_file($core.'/wp-includes/nav-menu.php'))throw new RuntimeException('Provide a WordPress path.');
require $core.'/wp-includes/nav-menu.php';
$options=[];$mods=[];$checks=0;
function get_option($key,$default=false){global $options;return $options[$key]??$default;}
function delete_option($key){global $options;unset($options[$key]);return true;}
function get_theme_mod($key,$default=false){global $mods;return $mods[$key]??$default;}
function set_theme_mod($key,$value){global $mods;$mods[$key]=$value;}
$_wp_registered_nav_menus=array_fill_keys(['header_menu','mobile_menu','footer_menu_1','footer_menu_2','subfooter'],'Menu');
function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$old=['header_menu'=>4,'mobile_menu'=>4,'footer_menu_1'=>5,'footer_menu_2'=>6];
$new=['header_menu'=>14,'mobile_menu'=>14,'footer_menu_1'=>15,'footer_menu_2'=>16];
$options['theme_switch_menu_locations']=$old;set_theme_mod('nav_menu_locations',$new);
_wp_menus_changed();
check(get_nav_menu_locations()===$old,'Regression fixture must reproduce stale IDs in WordPress core.');
foreach([$new,[],['header_menu'=>24,'mobile_menu'=>24]] as $locations){
    $options['theme_switch_menu_locations']=$old;
    $options['theme_switched']='previous-theme';
    $options['unrelated']='preserved';
    \Emonks\Factory\Package::importMenuLocations($locations);
    check(!isset($options['theme_switch_menu_locations']),'Stale mappings must be cleared.');
    check($options['theme_switched']==='previous-theme'&&$options['unrelated']==='preserved','Other theme activation work must remain pending.');
    _wp_menus_changed();
    check(get_nav_menu_locations()===$locations,'Next request must preserve all imported assignments, including intentional empties.');
    _wp_menus_changed();
    check(get_nav_menu_locations()===$locations,'Repeated callback must preserve assignments.');
}
echo "$checks menu import regression checks passed against WordPress core.\n";
