<?php
use Emonks\Factory\Online\{Preview,Accounts};
if(getenv('EMSF_DISPOSABLE_TEST')!=='yes'||!str_contains(ABSPATH,'emsf-integration')){throw new RuntimeException('Disposable test only.');}
wp_set_current_user(1);$ids=[];
foreach(['preview-http','preview-other']as$slug){
 $id=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/'.$slug.'/','user_id'=>1,'title'=>'Uw nieuwe website','meta'=>['emsf_managed'=>1,'emsf_state'=>'ready','emsf_source'=>$slug,'emsf_mail_mode'=>'off']]);$ids[]=$id;
 switch_to_blog($id);switch_theme('skeletor');$page=wp_insert_post(['post_title'=>'Preview','post_content'=>'PREVIEW CONTENT SENTINEL','post_type'=>'page','post_status'=>'publish']);update_option('show_on_front','page');update_option('page_on_front',$page);update_option('permalink_structure','/%postname%/');$u=wp_upload_dir();wp_mkdir_p($u['basedir']);foreach(['photo.png','photo-150x150.png','font.woff2']as$file){file_put_contents($u['basedir'].'/'.$file,'PRIVATE PREVIEW MEDIA');}restore_current_blog();
}
$access=Preview::manage($ids[0]);Preview::manage($ids[1]);
// Preserve coverage for links shared before 0.5.7. New sharing uses the ordinary URL.
$share=bin2hex(random_bytes(24));$legacy=get_site_meta($ids[0],'emsf_preview',true);$legacy['share_hash']=hash('sha256',$share);update_site_meta($ids[0],'emsf_preview',$legacy);$access['share_url']=add_query_arg('emsf_share',$share,$access['url']);
file_put_contents('/private/tmp/emsf-integration/preview-http.json',wp_json_encode(['site'=>$ids[0],'other'=>$ids[1],'code'=>$access['code'],'share_url'=>$access['share_url']]));chmod('/private/tmp/emsf-integration/preview-http.json',0600);
echo "Synthetic preview HTTP fixture prepared; no customer data or external calls.\n";
