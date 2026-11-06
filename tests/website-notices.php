<?php
/** Standalone regression test: synthetic options/hooks, no database or transport. */
namespace Emonks\Factory\Online {
    final class Sites {
        public static bool $local=false;
        public static bool $admin=true;
        public static function site(int $site): void {self::requireAdmin();if($site<2){throw new \RuntimeException("Invalid site");}}
        public static function local(): bool {return self::$local;}
        public static function requireAdmin(): void {if(!self::$admin){throw new \RuntimeException('Denied');}}
    }
    final class Privacy {
        public static function managed(): bool {return true;}
        public static function unavailable(): bool {return false;}
    }
    final class Preview {
        public static function allowed(): bool {return true;}
        public static function session(): string {return 'synthetic-session';}
        public static function attributed(): bool {return false;}
    }
    final class Store {
        public static array $data=[];
        public static function locked($key,$callback) {return $callback();}
        public static function read($key): array {return self::$data[$key]??[];}
        public static function write($key,$data): void {self::$data[$key]=$data;}
    }
}
namespace {
    define('EMSF_ONLINE',true);define('DAY_IN_SECONDS',86400);define('HOUR_IN_SECONDS',3600);
    $options=[];$hooks=[];$siteMeta=[];
    function get_site_option($key,$default=false) {return $GLOBALS['options'][$key]??$default;}
    function update_site_option($key,$value) {if(($GLOBALS['options'][$key]??false)===$value){return false;}$GLOBALS['options'][$key]=$value;return true;}
    function add_filter($key,$callback,$priority=10,...$unused) {$GLOBALS['hooks'][$key][$priority][]=$callback;}
    function add_action(...$args) {add_filter(...$args);}
    function apply_filters($key,$value,...$args) { $groups=$GLOBALS['hooks'][$key]??[];ksort($groups);foreach($groups as $callbacks){foreach($callbacks as $cb){$value=$cb($value,...$args);}}return $value;}
    function do_action($key) {$groups=$GLOBALS['hooks'][$key]??[];ksort($groups);foreach($groups as $callbacks){foreach($callbacks as $cb){$cb();}}}
    function is_main_site() {return false;}
    function get_main_site_id() {return 1;}
    function get_current_blog_id() {return 2;}
    function get_current_user_id() {return 0;}
    function update_site_meta($id,$key,$value) {$GLOBALS['siteMeta'][$id][$key]=$value;}
    function delete_site_meta($id,$key) {unset($GLOBALS['siteMeta'][$id][$key]);}
    function get_site_meta($id,$key,$single=true) {if(array_key_exists($key,$GLOBALS['siteMeta'][$id]??[])){return $GLOBALS['siteMeta'][$id][$key];}return ['emsf_managed'=>true,'emsf_state'=>'ready','emsf_mail_mode'=>'off','emsf_activity_policy'=>[]][$key]??'';}
    function is_super_admin(...$unused) {return false;}
    function is_404() {return false;}
    function is_feed() {return false;}
    function wp_salt($key) {return 'test-only';}
    function esc_html($value) {return htmlspecialchars($value,ENT_QUOTES);}
    function esc_attr($value) {return htmlspecialchars($value,ENT_QUOTES);}
    function checked($a,$b,$echo=false) {return $a==$b?'checked="checked"':'';}
    function wp_nonce_field(...$unused) {}
    function submit_button($label) {echo '<button>'.$label.'</button>';}
    use Emonks\Factory\Online\{NotificationSettings,Mail,Activity,Sites,Store};
    require __DIR__.'/../includes/Online/NotificationSettings.php';
    require __DIR__.'/../includes/Online/Mail.php';
    require __DIR__.'/../includes/Online/Activity.php';
    $count=0;
    function expect($ok,$message) {if(!$ok){throw new RuntimeException($message);}++$GLOBALS['count'];}
    expect(NotificationSettings::showWebsiteNotices(),'Existing networks keep notices');
    $options['emsf_notifications']=['enabled'=>true,'recipient'=>'maker@example.test','notify_view'=>false];$original=$options['emsf_notifications'];
    Mail::boot();Activity::boot();
    ob_start();do_action('wp_footer');$visible=ob_get_clean();
    expect(str_contains($visible,Mail::NOTICE)&&str_contains($visible,'Voorstelomgeving'),'Both footer messages visible');
    NotificationSettings::saveWebsiteNotices([]);
    expect(!NotificationSettings::showWebsiteNotices(),'Unchecked checkbox persists false');
    expect($options['emsf_notifications']===$original,'Recipient and notifications preserved');
    Store::$data=[];
    ob_start();do_action('wp_footer');$hidden=ob_get_clean();
    expect($hidden==='','Both footer messages hidden');
    expect(count(Store::$data['activity-2']['events'])===1,'Hidden notice still records actual preview activity');
    expect(Mail::off(),'Mail remains blocked');
    expect(apply_filters('pre_wp_mail',null,['to'=>'customer@example.test'])===true,'WordPress mail still intercepted');
    expect(isset(apply_filters('fluentform/validation_errors',[])['emsf_mail_off']),'Form validation still blocks mail');
    ob_start();NotificationSettings::page();$page=ob_get_clean();
    expect(str_contains($page,'name="settings_section" value="website"')&&str_contains($page,'Weergave opslaan'),'Separate display form available');
    expect($options['emsf_show_website_notices']==='0','First unchecked save stores an explicit value');
    NotificationSettings::saveSiteWebsiteNotices(2,['website_notices_mode'=>'show']);
    expect(NotificationSettings::showWebsiteNotices(2)&&!NotificationSettings::showWebsiteNotices(3),'Site override does not affect another site');
    expect(!NotificationSettings::websiteNotices(2)['default'],'Site override retains the central default');
    NotificationSettings::saveWebsiteNotices(['show_website_notices'=>'1']);
    expect(NotificationSettings::showWebsiteNotices(2)&&NotificationSettings::showWebsiteNotices(3),'Inherited site follows a later central change');
    NotificationSettings::saveSiteWebsiteNotices(2,['website_notices_mode'=>'hide']);
    expect(!NotificationSettings::showWebsiteNotices(2)&&NotificationSettings::websiteNoticesDefault(),'Explicit hide overrides central show');
    ob_start();NotificationSettings::page();$central=ob_get_clean();
    expect(str_contains($central,'name="show_website_notices" value="1" checked'),'Central form shows central setting regardless of current site override');
    NotificationSettings::saveWebsiteNotices([]);
    expect(!NotificationSettings::showWebsiteNotices(2),'Explicit hide survives later default changes');
    NotificationSettings::saveSiteWebsiteNotices(2,['website_notices_mode'=>'inherit']);
    expect(NotificationSettings::websiteNotices(2)['mode']==='inherit'&&!NotificationSettings::showWebsiteNotices(2),'Returning to inheritance deletes override');
    $rejected=false;try{NotificationSettings::saveSiteWebsiteNotices(2,['website_notices_mode'=>['show']]);}catch(RuntimeException $e){$rejected=true;}
    expect($rejected&&NotificationSettings::websiteNotices(2)['mode']==='inherit','Malformed site mode rejected without mutation');
    expect(isset(Activity::snapshot(2)['website_notices']['default']),'Online status includes choice, default and effective display');
    NotificationSettings::saveWebsiteNotices(['show_website_notices'=>'1']);
    expect(NotificationSettings::showWebsiteNotices(),'Notices can be restored');
    Sites::$admin=false;$denied=false;try{NotificationSettings::saveWebsiteNotices([]);}catch(RuntimeException $e){$denied=true;}
    expect($denied&&NotificationSettings::showWebsiteNotices(),'Unauthorized change denied');
    Sites::$admin=true;Sites::$local=true;$denied=false;try{NotificationSettings::saveWebsiteNotices([]);}catch(RuntimeException $e){$denied=true;}
    expect($denied&&NotificationSettings::showWebsiteNotices(),'Local network cannot alter online setting');
    echo "$count website notice checks passed\n";
}
