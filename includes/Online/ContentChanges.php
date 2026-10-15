<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;
/** Tracks standard WordPress editorial changes, not arbitrary direct database writes. */
final class ContentChanges {
    public static function boot(): void {
        add_action('save_post',static function($id,$post){if(!in_array($post->post_type,['revision','acf-field','acf-field-group'],true)&&$post->post_status!=='auto-draft'){self::touch('Inhoud: '.$post->post_title);}},100,2);
        add_action('before_delete_post',static function($id){if(!wp_is_post_revision($id)&&get_post_status($id)!=='auto-draft'){self::touch('Inhoud verwijderd');}});
        foreach(['added_post_meta','updated_post_meta','deleted_post_meta']as$hook){add_action($hook,static function($meta,$post,$key){if(!wp_is_post_revision($post)&&get_post_status($post)!=='auto-draft'&&!in_array($key,['_edit_lock','_edit_last'],true)&&!str_starts_with($key,'_transient')){self::touch('Inhoud of indeling aangepast');}},100,3);}
        foreach(['created_term','edited_term','delete_term','set_object_terms','wp_update_nav_menu','added_term_meta','updated_term_meta','deleted_term_meta']as$hook){add_action($hook,static fn()=>self::touch('Categorieën of menu aangepast'),100);}
        foreach(['added_option','updated_option','deleted_option']as$hook){add_action($hook,static function($name){if(preg_match('/^_?options_/',$name)||str_starts_with($name,'theme_mods_')||in_array($name,['blogname','blogdescription','page_on_front','page_for_posts','show_on_front','permalink_structure'],true)){self::touch('Site-instellingen aangepast');}},100);}
    }
    public static function touch(string $reason): void {
        $id=get_current_blog_id();
        if(!defined('EMSF_ONLINE')||!EMSF_ONLINE||!Privacy::managed()||Receiver::$importing||get_site_meta($id,'emsf_state',true)!=='ready'){return;}
        Activity::contentChanged();
        update_site_meta($id,'emsf_content_revision',['token'=>wp_generate_uuid4(),'at'=>gmdate('c'),'reason'=>$reason,'customer'=>!is_super_admin()&&get_current_user_id()>0]);
    }
    public static function status(int $id): array {
        $current=(array)get_site_meta($id,'emsf_content_revision',true);$baseline=(string)get_site_meta($id,'emsf_content_baseline',true);
        $token=$current['token']??'initial';
        return ['token'=>$token,'known'=>$baseline!=='','changed'=>$baseline!==''&&$token!==$baseline,'at'=>$current['at']??'','reason'=>$current['reason']??'','customer'=>!empty($current['customer'])];
    }
    public static function baseline(int $id): void {
        $token=wp_generate_uuid4();update_site_meta($id,'emsf_content_revision',['token'=>$token]);update_site_meta($id,'emsf_content_baseline',$token);
    }
    public static function guard(int $id,array $data): void {
        $state=self::status($id);
        if(!is_string($data['expected_revision']??null)||!hash_equals($state['token'],$data['expected_revision'])){throw new \RuntimeException('Online inhoud is gewijzigd sinds de controle. Controleer opnieuw vóór vervangen.');}
        if((!$state['known']||$state['changed'])&&empty($data['replace_changes'])){throw new \RuntimeException('Online wijzigingen of oudere inhoud zonder nulmeting: controleer en bevestig eerst dat deze mogen worden vervangen.');}
    }
}
