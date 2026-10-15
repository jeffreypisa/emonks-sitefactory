<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

/** Small, bounded proposal activity log. No IP analytics or content payloads. */
final class Activity {
    private static array $loginTokens=[];
    private static ?array $mail=null;
    private static array $changes=[];
    public static function policy(int $site): array {
        return (array)get_site_meta($site,'emsf_activity_policy',true)+NotificationSettings::defaults();
    }
    public static function configure(int $site,array $input): array {
        Sites::requireAdmin();Sites::site($site);
        if(!empty($input['activity_inherit'])){delete_site_meta($site,'emsf_activity_policy');return self::snapshot($site);}
        $recipient=trim((string)($input['activity_recipient']??''));$view=!empty($input['notify_view']);$login=!empty($input['notify_login']);$content=!empty($input['notify_content']);
        if(($recipient!==''&&(!is_email($recipient)||sanitize_email($recipient)!==$recipient))||(($view||$login||$content)&&($recipient===''||empty($input['activity_confirmed'])))){throw new \RuntimeException('Vul de bedoelde beheerderontvanger in en bevestig dit adres voordat je meldingen aanzet.');}
        update_site_meta($site,'emsf_activity_policy',['enabled'=>!empty($input['activity_enabled']),'recipient'=>$recipient,'notify_view'=>$view,'notify_login'=>$login,'notify_content'=>$content]);
        // Changing policy never sends/replays old notifications.
        return self::snapshot($site);
    }
    public static function labels(): array { return ['preview_view'=>'Voorstel bekeken · anonieme preview','customer_front'=>'Voorstel bekeken · klantaccount','customer_login'=>'Klantaccount succesvol ingelogd','customer_dashboard'=>'Dashboard geopend · klantaccount','customer_content'=>'Klant heeft inhoud gewijzigd']; }
    private static function prune(array $data): array {
        $after=time()-30*DAY_IN_SECONDS;
        $data['events']=array_values(array_filter($data['events']??[],static fn($event)=>$event['at']>=$after));
        $data['events']=array_slice($data['events'],-100);
        $data['seen']=array_slice(array_filter($data['seen']??[],static fn($at)=>$at>=$after),-500,null,true);
        $data['notifications']=$data['notifications']??[];return $data;
    }
    public static function snapshot(int $site): array {
        return Store::locked('activity-'.$site,static function()use($site){
            $old=Store::read('activity-'.$site);$data=self::prune($old);
            if($old&&$old!==$data){Store::write('activity-'.$site,$data);}
            return ['policy'=>self::policy($site),'events'=>$data['events'],'notifications'=>$data['notifications'],'timezone'=>'UTC','retention_days'=>30,'max_events'=>100];
        });
    }
    private static function customer(int $site,int $user): bool {
        return $user>0&&!is_super_admin($user)&&$user===(int)get_site_meta($site,'emsf_customer_id',true)&&is_user_member_of_blog($user,$site);
    }
    public static function record(int $site,string $kind,string $session,int $user=0,bool $shared=false): void {
        if(!isset(self::labels()[$kind])||$session===''||is_super_admin()||!get_site_meta($site,'emsf_managed',true)||get_site_meta($site,'emsf_state',true)!=='ready'||!self::policy($site)['enabled']){return;}
        if($kind==='preview_view'?$user!==0:!self::customer($site,$user)){return;}
        $key=hash_hmac('sha256',$site.'|'.$kind.'|'.$session,wp_salt('nonce'));
        try{
            $notice=Store::locked('activity-'.$site,static function()use($site,$kind,$key,$user,$shared){
                $data=self::prune(Store::read('activity-'.$site));if(isset($data['seen'][$key])){return null;}
                $event=['at'=>time(),'kind'=>$kind,'source'=>$shared?'share_link':($kind==='preview_view'?'preview_code':'personal_account')];
                if($user){$event['user_id']=$user;$event['account']=get_userdata($user)->user_login;}
                $data['seen'][$key]=time();$data['events'][]=$event;$data=self::prune($data);
                $family=in_array($kind,['preview_view','customer_front'],true)?'view':(in_array($kind,['customer_login','customer_dashboard'],true)?'login':($kind==='customer_content'?'content':''));$policy=self::policy($site);$notice=null;
                if($family&&!empty($policy['notify_'.$family])&&is_email($policy['recipient'])&&time()-(int)($data['notifications'][$family]['at']??0)>=HOUR_IN_SECONDS){
                    // Reserve before transport: one attempt per family/hour, including uncertain delivery.
                    $data['notifications'][$family]=['at'=>time(),'status'=>'reserved'];$notice=['family'=>$family,'event'=>$event,'recipient'=>$policy['recipient']];
                }
                Store::write('activity-'.$site,$data);return $notice;
            });
            if($notice){self::notify($site,$notice);}
        }catch(\Throwable $e){/* Activity must never reveal secrets or break a visitor response. No replay. */}
    }
    private static function notify(int $site,array $notice): void {
        $subject='Site Factory: '.(['view'=>'voorstel bekeken','login'=>'klant actief in beheer','content'=>'inhoud gewijzigd'][$notice['family']]).' — '.get_blog_option($site,'blogname');
        $event=$notice['event'];$message=self::labels()[$event['kind']]."\nVoorstel: ".get_home_url($site)."\nTijd (UTC): ".gmdate('Y-m-d H:i:s',$event['at'])."\n";
        if(isset($event['account'])){$message.='Account: '.$event['account']."\n";}else{$message.="Anonieme preview; deellink/code bewijst niet wie heeft gekeken.\n";}
        $message.="\nActiviteit en site beheren: ".network_admin_url('admin.php?page=emsf-site&id='.$site.'&tab=activiteit')."\n\nJe ontvangt maximaal één melding per soort per site per uur. Tussentijdse acties blijven in het activiteitenlog staan.";
        self::$mail=['to'=>$notice['recipient'],'subject'=>$subject,'message'=>$message];
        switch_to_blog($site);$sent=false;
        try{$sent=wp_mail($notice['recipient'],$subject,$message);}finally{self::$mail=null;restore_current_blog();}
        Store::locked('activity-'.$site,static function()use($site,$notice,$sent){$data=Store::read('activity-'.$site);$data['notifications'][$notice['family']]=['at'=>time(),'status'=>$sent?'submitted':'failed'];Store::write('activity-'.$site,$data);});
    }
    public static function contentChanged(): void {
        $site=get_current_blog_id();$user=get_current_user_id();
        if((defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)||!self::customer($site,$user)){return;}
        self::$changes[$site]=$user;
    }
    public static function flushChanges(): void {
        $changes=self::$changes;self::$changes=[];
        foreach($changes as $site=>$user){self::record($site,'customer_content',wp_generate_uuid4(),$user);}
    }
    public static function sending(): bool { return self::$mail!==null; }
    public static function permittedMail(array $atts): bool {
        return self::$mail!==null&&($atts['to']??null)===self::$mail['to']&&($atts['subject']??null)===self::$mail['subject']&&($atts['message']??null)===self::$mail['message']&&empty($atts['headers'])&&empty($atts['attachments']);
    }
    public static function cleanup(): void {
        try{
            foreach(glob(Store::root().'/activity-*.json')?:[] as $file){$key=basename($file,'.json');if(preg_match('/^activity-([0-9]+)$/D',$key,$match)){try{self::snapshot((int)$match[1]);}catch(\Throwable $e){/* Retry on the next daily run. */}}}
            foreach(glob(Store::root().'/remote-*.json')?:[] as $file){$key=basename($file,'.json');try{Store::locked($key,static function()use($key){$data=Store::read($key);if(isset($data['activity']['events'])){$data['activity']['events']=self::prune(['events'=>$data['activity']['events']])['events'];Store::write($key,$data);}});}catch(\Throwable $e){/* A publication may hold this journal. */}}
        }catch(\Throwable $e){/* Missing private storage is reported by management actions. */}
    }
    public static function boot(): void {
        add_action('emsf_activity_cleanup',[self::class,'cleanup']);
        if(is_main_site()&&defined('EMSF_STATE_DIR')&&!wp_next_scheduled('emsf_activity_cleanup')){wp_schedule_event(time()+DAY_IN_SECONDS,'daily','emsf_activity_cleanup');}
        if(!defined('EMSF_ONLINE')||!EMSF_ONLINE){return;}
        add_action('shutdown',[self::class,'flushChanges'],5);
        add_action('set_logged_in_cookie',static function($cookie,$expire,$expiration,$uid,$scheme,$token){self::$loginTokens[(int)$uid]=$token;},10,6);
        add_action('wp_login',static function($login,$user){
            if(is_super_admin($user->ID)){return;}$site=get_current_blog_id();
            if(!self::customer($site,$user->ID)){
                $redirect=wp_unslash($_REQUEST['redirect_to']??'');$site=0;
                if(is_string($redirect)){foreach(get_blogs_of_user($user->ID)as$blog){$candidate=(int)$blog->userblog_id;$admin=get_admin_url($candidate);if(self::customer($candidate,$user->ID)&&($redirect===$admin||str_starts_with($redirect,$admin))){$site=$candidate;break;}}}
            }
            if($site){self::record($site,'customer_login',self::$loginTokens[$user->ID]??wp_get_session_token(),$user->ID);}
        },20,2);
        add_action('admin_head',static function(){
            if(!is_network_admin()&&!wp_doing_ajax()&&($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&self::customer(get_current_blog_id(),get_current_user_id())){self::record(get_current_blog_id(),'customer_dashboard',wp_get_session_token(),get_current_user_id());}
        });
        add_action('wp_footer',static function(){
            if(!Privacy::managed()||Privacy::unavailable()||is_404()||is_feed()||($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){return;}
            $site=get_current_blog_id();$uid=get_current_user_id();
            if(self::customer($site,$uid)){self::record($site,'customer_front',wp_get_session_token(),$uid);}
            elseif(!is_super_admin()&&Preview::allowed()){self::record($site,'preview_view',Preview::session(),0,Preview::attributed());}
            if(self::policy($site)['enabled']){echo '<p style="padding:12px;text-align:center;font:12px/1.6 system-ui;color:inherit">Voorstelomgeving · Bekijken, klantlogin en inhoudswijzigingen worden aan de maker gemeld. Een gedeelde code bewijst geen identiteit.</p>';}
        },99);
        add_filter('login_message',static function($message){if(Privacy::managed()&&self::policy(get_current_blog_id())['enabled']){$message.='<p class="message">Deze voorstelomgeving registreert klantlogin en dashboardopening voor de maker.</p>';}return $message;});
    }
}
