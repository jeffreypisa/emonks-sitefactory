<?php
declare(strict_types=1);
namespace Emonks\Factory;
/** A build is data: existing ACF fields, WordPress pages, media and menus. */
final class Build {
    private static function remove_generated(string $path): void {
        $stem=pathinfo($path,PATHINFO_FILENAME);$ext=pathinfo($path,PATHINFO_EXTENSION);
        foreach(glob(dirname($path).'/'.$stem.'-*.'.$ext)?:[] as $size){if(preg_match('/-\d+x\d+\.[^.]+$/',$size)){@unlink($size);}}
        @unlink($path);
    }
    public static function field(array $field,$value): void {
        $name=$field['name'];$type=$field['type'];
        if(is_array($value)&&isset($value['$ref'])){if(!in_array($type,['image','file','post_object','relationship'],true)){throw new \RuntimeException('Onverwachte referentie in '.$name);}return;}
        if(in_array($type,['select','radio','button_group','checkbox'],true)){
            $choices=[];$rawChoices=$field['choices']??[];array_walk_recursive($rawChoices,static function($v,$k)use(&$choices){$choices[(string)$k]=true;});
            foreach(is_array($value)?$value:[$value] as $v){if($v!==''&&$choices&&!isset($choices[(string)$v])){throw new \RuntimeException('Ongeldige keuze voor '.$name.': '.(string)$v);}}
        }
        if(in_array($type,['number','range'],true)&&$value!==''&&(!is_numeric($value)||(isset($field['min'])&&$field['min']!==''&&$value<$field['min'])||(isset($field['max'])&&$field['max']!==''&&$value>$field['max']))){throw new \RuntimeException('Ongeldig getal in '.$name);}
        if($type==='color_picker'&&$value!==''&&!preg_match('/^#[a-f0-9]{6}$/i',(string)$value)){throw new \RuntimeException('Ongeldige kleur.');}
        if(in_array($type,['group','repeater','flexible_content'],true)){
            if(!is_array($value)){throw new \RuntimeException('Lijst verwacht in '.$name);}$rows=$type==='group'?[$value]:$value;
            foreach($rows as $row){$children=$field['sub_fields']??[];if($type==='flexible_content'){$layout=null;foreach($field['layouts']??[] as $candidate){if($candidate['name']===($row['acf_fc_layout']??'')){$layout=$candidate;break;}}if(!$layout){throw new \RuntimeException('Onbekende strook.');}$children=$layout['sub_fields'];}
                $lookup=[];foreach($children as $child){$lookup[$child['key']]=$child;$lookup[$child['name']]=$child;}
                foreach($row as $key=>$v){if($key==='acf_fc_layout'){continue;}if(!isset($lookup[$key])){throw new \RuntimeException('Onbekend strookveld: '.$key);}self::field($lookup[$key],$v);}
            }
        }
    }
    private static function fields(array $values): void {
        foreach($values as $key=>$value){if(!str_starts_with($key,'field_')){throw new \RuntimeException('Gebruik stabiele ACF-veldsleutels.');}$field=acf_get_field($key);if(!$field){throw new \RuntimeException('Onbekend ACF-veld: '.$key);}if(in_array($field['type'],['tab','accordion','message'],true)||$field['name']==='skeletor_theme_options_roles'){throw new \RuntimeException('Dit veld is niet schrijfbaar via een build.');}self::field($field,$value);}
    }
    public static function run(string $file,bool $apply=false): array {
        global $wpdb;
        Factory::local();if(is_main_site()){throw new \RuntimeException('Kies een prospectsite.');}
        $p=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(($p['format']??'')!=='emonks-site-build'||($p['version']??0)!==1||($p['prospect_uuid']??'')!==get_option('emsf_uuid')){throw new \RuntimeException('Build hoort niet bij deze prospectsite.');}
        self::fields($p['options']??[]);$keys=[];
        foreach($p['pages'] as $page){$key=$page['key'];if(!preg_match('/^[a-z0-9-]+$/',$key)||isset($keys['page:'.$key])){throw new \RuntimeException('Dubbele of ongeldige paginasleutel.');}$keys['page:'.$key]=true;self::fields($page['fields']??[]);}
        foreach($p['media']??[] as $media){$path=realpath($media['path']);$allowed=realpath(Factory::state().'/prospects');if(!$path||!$allowed||!str_starts_with($path,$allowed.'/')||!in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['jpg','jpeg','png','webp'],true)||!@getimagesize($path)){throw new \RuntimeException('Geen geldig lokaal prospectbeeld: '.$media['key']);}$keys['media:'.$media['key']]=true;}
        if(!isset($keys['media:favicon'])){throw new \RuntimeException('Favicon ontbreekt. Controleer eerst de originele site; gebruik het bronicoon of maak een initiaal op de merkkleur.');}
        $check=static function($v)use(&$check,$keys){if(is_array($v)){if(isset($v['$ref'])&&!isset($keys[$v['$ref']])){throw new \RuntimeException('Onbekende buildverwijzing: '.$v['$ref']);}foreach($v as $x){$check($x);}}elseif(is_string($v)&&preg_match_all('/\{\{emsf:url:([^}]+)\}\}/',$v,$m)){foreach($m[1] as $key){if(!isset($keys[$key])){throw new \RuntimeException('Onbekende paginalink: '.$key);}}}};$check($p);
        if(!isset($keys['page:'.$p['homepage']])){throw new \RuntimeException('Homepage ontbreekt.');}
        foreach($p['menu'] as $item){if(!isset($keys['page:'.$item['page']])){throw new \RuntimeException('Menupagina ontbreekt.');}}
        $result=['site'=>get_current_blog_id(),'pages'=>count($p['pages']),'media'=>count($p['media']??[]),'apply'=>$apply];if(!$apply){return $result;}
        $lock=fopen(Factory::state().'/site-'.get_current_blog_id().'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){throw new \RuntimeException('Deze site wordt al gebouwd.');}
        $map=(array)get_option('emsf_build_map',[]);$urls=['{{emsf:home}}'=>home_url()];$newfiles=[];$build=wp_generate_uuid4();$committed=false;$wpdb->query('START TRANSACTION');
        try{
            require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/file.php';
            foreach($p['pages'] as $page){$id=$map['page:'.$page['key']]??0;$r=wp_insert_post(wp_slash(['ID'=>$id,'post_type'=>'page','post_status'=>'publish','post_title'=>$page['title'],'post_name'=>$page['slug'],'post_content'=>$page['content']??'']),true);if(is_wp_error($r)){throw new \RuntimeException($r->get_error_message());}$map['page:'.$page['key']]=$r;}
            update_option('permalink_structure','/%postname%/');update_option('show_on_front','page');update_option('page_on_front',$map['page:'.$p['homepage']]);
            foreach($p['pages'] as $page){$urls['{{emsf:url:page:'.$page['key'].'}}']=get_permalink($map['page:'.$page['key']]);}
            foreach($p['media']??[] as $media){$ref='media:'.$media['key'];$hash=hash_file('sha256',$media['path']);$old=$map[$ref]??0;
                if(!$old||get_post_meta($old,'_emsf_hash',true)!==$hash){$tmp=wp_tempnam(basename($media['path']));copy($media['path'],$tmp);$id=media_handle_sideload(['name'=>basename($media['path']),'tmp_name'=>$tmp],0,$media['alt']);if(is_wp_error($id)){@unlink($tmp);throw new \RuntimeException($id->get_error_message());}$newfiles[]=get_attached_file($id);update_post_meta($id,'_emsf_hash',$hash);$map[$ref]=$id;}
                update_post_meta($map[$ref],'_wp_attachment_image_alt',sanitize_text_field($media['alt']));update_post_meta($map[$ref],'_emsf_source',esc_url_raw($media['source']??''));$urls['{{emsf:url:'.$ref.'}}']=wp_get_attachment_url($map[$ref]);
            }
            foreach($p['options']??[] as $key=>$v){$value=Package::decode($v,$map,$urls);$field=acf_get_field($key);if(($field['type']??'')==='true_false'){$value=(int)(bool)$value;}update_field($key,$value,'option');}
            foreach($p['pages'] as $page){$id=$map['page:'.$page['key']];foreach($page['fields']??[] as $key=>$v){update_field($key,Package::decode($v,$map,$urls),$id);}}
            $menu=$map['menu:main']??0;if(!$menu){$menu=wp_create_nav_menu('Hoofdnavigatie');if(is_wp_error($menu)){throw new \RuntimeException($menu->get_error_message());}$map['menu:main']=$menu;}
            $keep=[];foreach($p['menu'] as $index=>$item){$key='menu-item:'.$item['page'];$id=wp_update_nav_menu_item($menu,$map[$key]??0,['menu-item-title'=>$item['title'],'menu-item-object-id'=>$map['page:'.$item['page']],'menu-item-object'=>'page','menu-item-type'=>'post_type','menu-item-status'=>'publish','menu-item-position'=>$index+1]);if(is_wp_error($id)){throw new \RuntimeException($id->get_error_message());}$map[$key]=$id;$keep[]=$id;}
            foreach(wp_get_nav_menu_items($menu)?:[] as $item){if(!in_array($item->ID,$keep,true)){wp_trash_post($item->ID);}}
            set_theme_mod('nav_menu_locations',['header_menu'=>$menu,'mobile_menu'=>$menu,'footer_menu_1'=>$menu]);update_option('blogname',$p['name']);update_option('blogdescription',$p['description']??'');update_option('blog_public',0);
            if(isset($map['media:favicon'])){update_option('site_icon',$map['media:favicon']);update_field('field_brand_favicon',$map['media:favicon'],'option');}
            update_option('emsf_build_map',$map,false);update_option('emsf_last_build',$build,false);update_option('emsf_rules_version',$p['rules_version']??'v1',false);
            if($wpdb->last_error){throw new \RuntimeException('Databasefout: '.$wpdb->last_error);}$wpdb->query('COMMIT');$committed=true;flush_rewrite_rules(false);wp_cache_flush();Factory::save(['slug'=>get_option('emsf_slug'),'status'=>'BUILDING','last_build'=>$build,'rules_version'=>$p['rules_version']??'v1']);Package::json(Factory::state().'/build-'.$build.'.json',$result+['success'=>true,'manifest_sha256'=>hash_file('sha256',$file),'created_at'=>gmdate('c')]);return $result+['build'=>$build];
        }catch(\Throwable $e){if(!$committed){$wpdb->query('ROLLBACK');foreach($newfiles as $path){self::remove_generated($path);}}wp_cache_flush();Package::json(Factory::state().'/build-'.$build.'.json',['success'=>false,'site'=>get_current_blog_id(),'error'=>$e->getMessage()]);throw $e;}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
