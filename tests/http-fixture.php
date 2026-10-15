<?php
use Emonks\Factory\Online\Store;
if (getenv('EMSF_DISPOSABLE_TEST') !== 'yes' || !str_contains(ABSPATH,'emsf-integration')) { throw new RuntimeException('Disposable test only.'); }
$site=wp_insert_site(['domain'=>get_network()->domain,'path'=>'/privacy/','user_id'=>1,'title'=>'Private fixture','public'=>0,'meta'=>['emsf_managed'=>1,'emsf_state'=>'ready','emsf_source'=>'http-fixture']]);
$customer=get_user_by('login','first_customer');add_user_to_blog($site,$customer->ID,'editor');
$outsiderId=wpmu_create_user('http_outsider',wp_generate_password(),'http-outsider@example.invalid');$outsider=get_userdata($outsiderId);
switch_to_blog($site);switch_theme('skeletor');wp_insert_post(['post_title'=>'PRIVATE SENTINEL','post_content'=>'PRIVATE SENTINEL','post_type'=>'page','post_status'=>'publish']);$u=wp_upload_dir();wp_mkdir_p($u['basedir']);file_put_contents($u['basedir'].'/private.txt','PRIVATE MEDIA SENTINEL');restore_current_blog();
foreach(['customer'=>$customer->ID,'outsider'=>$outsider->ID] as $name=>$id){
 $expiry=time()+3600;$token=WP_Session_Tokens::get_instance($id)->create($expiry);
 $cookie=LOGGED_IN_COOKIE.'='.wp_generate_auth_cookie($id,$expiry,'logged_in',$token).'; '.SECURE_AUTH_COOKIE.'='.wp_generate_auth_cookie($id,$expiry,'secure_auth',$token);
 file_put_contents('/private/tmp/emsf-integration/'.$name.'.cookie',$cookie);chmod('/private/tmp/emsf-integration/'.$name.'.cookie',0600);
}
file_put_contents('/private/tmp/emsf-integration/site-id',(string)$site);
echo "Private HTTP fixture ready; mail disabled.\n";
