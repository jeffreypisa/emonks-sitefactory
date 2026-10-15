<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;

/** Low-friction, read-only proposal access; never a WordPress user/session. */
final class Preview {
    public const TTL = 8 * HOUR_IN_SECONDS;
    private const META = 'emsf_preview';
    public static function enabled(int $site): bool { return !empty(self::record($site)['hash']); }
    /** Existing sites without a mode remain closed; never expose them on upgrade. */
    public static function mode(int $site): string {
        if(self::enabled($site)){return 'code';}
        return get_site_meta($site,'emsf_preview_mode',true)==='public'?'public':'closed';
    }
    public static function makePublic(int $site): array {
        Sites::requireAdmin();Sites::site($site);
        if(!get_site_meta($site,'emsf_managed',true)||get_site_meta($site,'emsf_state',true)!=='ready'){throw new \RuntimeException('Wijzig de toegang wanneer het online voorstel gereed is.');}
        return Store::locked('preview-'.$site,static function()use($site){
            // Clearing the code also invalidates all old preview sessions.
            delete_site_meta($site,self::META);
            update_site_meta($site,'emsf_preview_mode','public');
            return self::details($site);
        });
    }
    private static function record(int $site): array { return (array)get_site_meta($site,self::META,true); }
    /** Readable only by network admins; excluded from status, journals and exports. */
    public static function details(int $site): array {
        Sites::requireAdmin(); Sites::site($site);
        $record=self::record($site);
        $result=['enabled'=>!empty($record['hash']),'mode'=>self::mode($site),'url'=>get_home_url($site,'/')];
        if(empty($record['hash'])){return $result;}
        $blob=base64_decode((string)($record['sealed_code']??''),true);
        $code=$blob!==false&&strlen($blob)>28&&function_exists('openssl_decrypt')
            ?openssl_decrypt(substr($blob,28),'aes-256-gcm',self::key($site),OPENSSL_RAW_DATA,substr($blob,0,12),substr($blob,12,16),'emsf-preview-'.$site):false;
        if(!is_string($code)||!preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/D',$code)||!wp_check_password($code,$record['hash'])){return $result+['legacy'=>true];}
        return $result+['code'=>$code,'session_hours'=>8];
    }
    private static function key(int $site): string {
        return hash_hmac('sha256','emsf-preview|'.get_current_network_id().'|'.$site,wp_salt('auth'),true);
    }
    public static function manage(int $site, bool $revoke=false, bool $renew=false): array {
        Sites::requireAdmin(); Sites::site($site);
        if (!get_site_meta($site,'emsf_managed',true)) { throw new \RuntimeException('Previewcodes gelden voor beheerde online voorstellen.'); }
        if (!$revoke && get_site_meta($site,'emsf_state',true)!=='ready') { throw new \RuntimeException('Maak een code wanneer het online voorstel gereed is.'); }
        return Store::locked('preview-'.$site,static function() use($site,$revoke,$renew) {
            if ($revoke) { update_site_meta($site,'emsf_preview_mode','closed');delete_site_meta($site,self::META); return self::details($site)+['revoked'=>true]; }
            $old=self::record($site);
            update_site_meta($site,'emsf_preview_mode','code');
            if(!$renew&&!empty($old['hash'])){return self::details($site);}
            if(!function_exists('openssl_encrypt')){throw new \RuntimeException('OpenSSL is nodig om de vaste code veilig te bewaren.');}
            $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$code='';
            do{$code='';for($i=0;$i<4;$i++){$code.=$alphabet[random_int(0,strlen($alphabet)-1)];}}while(!empty($old['hash'])&&wp_check_password($code,$old['hash']));
            $iv=random_bytes(12);$tag='';
            $sealed=openssl_encrypt($code,'aes-256-gcm',self::key($site),OPENSSL_RAW_DATA,$iv,$tag,'emsf-preview-'.$site);
            if($sealed===false){throw new \RuntimeException('De code kon niet veilig worden opgeslagen. Probeer opnieuw.');}
            update_site_meta($site,self::META,['hash'=>wp_hash_password($code),'version'=>bin2hex(random_bytes(16)),'sealed_code'=>base64_encode($iv.$tag.$sealed)]);
            return self::details($site);
        });
    }
    public static function cookieName(string $purpose='session'): string {
        return '__Secure-emsf_'.$purpose.'_'.get_current_network_id().'_'.get_current_blog_id();
    }
    private static function signature(string $payload,string $purpose,array $record): string {
        return hash_hmac('sha256',$purpose.'|'.get_current_network_id().'|'.get_current_blog_id().'|'.$record['version'].'|'.$payload,wp_salt('auth'));
    }
    public static function token(string $purpose='session'): string {
        $record=self::record(get_current_blog_id());if(empty($record['hash'])){return '';}
        $expiry=time()+(in_array($purpose,['session','attribution'],true)?self::TTL:15*MINUTE_IN_SECONDS);
        $payload=$expiry.'.'.bin2hex(random_bytes(16));
        return $payload.'.'.self::signature($payload,$purpose,$record);
    }
    public static function valid(string $token,string $purpose='session'): bool {
        $site=get_current_blog_id();$record=self::record($site);$info=get_site($site);
        if(!is_ssl()||!$info||$info->archived||$info->deleted||$info->spam||!Privacy::managed()||get_site_meta($site,'emsf_state',true)!=='ready'||empty($record['hash'])){return false;}
        if(!preg_match('/^(\d{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D',$token,$match)){return false;}
        $ttl=in_array($purpose,['session','attribution'],true)?self::TTL:15*MINUTE_IN_SECONDS;
        return (int)$match[1]>time()&&(int)$match[1]<=time()+$ttl&&hash_equals(self::signature($match[1].'.'.$match[2],$purpose,$record),$match[3]);
    }
    public static function frontend(): bool {
        $script=basename($_SERVER['SCRIPT_NAME']??'');
        $path=rawurldecode((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH));
        return !is_admin()&&!wp_doing_ajax()&&!(defined('REST_REQUEST')&&REST_REQUEST)
            &&!in_array($script,['wp-login.php','xmlrpc.php','wp-comments-post.php','wp-trackback.php','wp-cron.php','wp-signup.php','wp-activate.php'],true)
            &&!preg_match('~/(?:wp-admin|wp-json)(?:/|$)~i',$path)
            &&!preg_match('~/(?!index\.php(?:/|$))[^/]+\.php(?:/|$)~i',$path)
            &&!isset($_GET['rest_route'])&&!isset($_GET['feed'])&&!isset($_GET['tb'])
            &&!preg_match('~/(?:feed|trackback)(?:/|$)~i',$path);
    }
    private static function cookieValue(string $purpose='session'): string {
        $value=$_COOKIE[self::cookieName($purpose)]??'';return is_string($value)?$value:'';
    }
    public static function allowed(): bool {
        return in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)&&self::frontend()
            &&self::valid(self::cookieValue());
    }
    /** Counters are serialized under a per-site lock; client-supplied proxy headers are ignored. */
    public static function attempt(string $code): array {
        $site=get_current_blog_id();
        if(!is_ssl()||get_site_meta($site,'emsf_state',true)!=='ready'||!Privacy::managed()||!self::enabled($site)){return ['ok'=>false,'limited'=>false];}
        return Store::locked('preview-'.$site,static function()use($site,$code){
            $now=time();$key='preview-rate-'.$site;$rate=Store::read($key);
            if(($rate['until']??0)<=$now){$rate=['until'=>$now+HOUR_IN_SECONDS,'count'=>0,'visitors'=>[]];}
            $ip=hash_hmac('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'),wp_salt('nonce'));
            $visitors=array_filter($rate['visitors']??[],static fn($v)=>$v['until']>$now);
            $visitor=$visitors[$ip]??['until'=>$now+15*MINUTE_IN_SECONDS,'count'=>0];
            if($rate['count']>=20||$visitor['count']>=5){return ['ok'=>false,'limited'=>true];}
            $record=self::record($site);$normalized=strtoupper(trim($code));
            $ok=strlen($normalized)===4&&wp_check_password($normalized,$record['hash']);
            if(!$ok){$rate['count']++;$visitor['count']++;$visitors[$ip]=$visitor;}
            $rate['visitors']=$visitors;Store::write($key,$rate);
            return ['ok'=>$ok,'limited'=>false];
        });
    }
    private static function setCookie(string $value,string $purpose): void {
        setcookie(self::cookieName($purpose),$value,['expires'=>$value===''?time()-3600:time()+(in_array($purpose,['session','attribution'],true)?self::TTL:15*MINUTE_IN_SECONDS),'path'=>(string)(parse_url(home_url('/'),PHP_URL_PATH)?:'/'),'secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    }
    public static function session(): string { return self::cookieValue(); }
    public static function attributed(): bool { return self::valid(self::cookieValue('attribution'),'attribution'); }
    public static function shareValid(string $token): bool {
        $record=self::record(get_current_blog_id());return preg_match('/^[a-f0-9]{48}$/D',$token)&&!empty($record['share_hash'])&&hash_equals($record['share_hash'],hash('sha256',$token));
    }
    public static function captureShare(): void {
        if(!self::frontend()||($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||!isset($_GET['emsf_share'])){return;}
        $token=is_string($_GET['emsf_share'])?wp_unslash($_GET['emsf_share']):'';
        if(is_ssl()&&get_site_meta(get_current_blog_id(),'emsf_state',true)==='ready'&&self::shareValid($token)){self::setCookie(self::token('share'),'share');}
        wp_safe_redirect(home_url('/'),303);exit;
    }
    public static function screen(): void {
        $site=get_current_blog_id();$ready=get_site_meta($site,'emsf_state',true)==='ready';$enabled=$ready&&self::enabled($site)&&is_ssl();
        $message='';$status=403;
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$enabled){
            $posted=wp_unslash($_POST);$challenge=is_string($posted['emsf_challenge']??null)?$posted['emsf_challenge']:'';
            if(!isset($posted['emsf_preview_code'])||!is_string($posted['emsf_preview_code'])||!hash_equals(self::cookieValue('challenge'),$challenge)||!self::valid($challenge,'challenge')){
                $message='Vernieuw deze pagina en probeer het opnieuw.';
            }else{
                try{$result=self::attempt($posted['emsf_preview_code']);}catch(\Throwable $e){$result=['ok'=>false,'limited'=>true];}
                if($result['ok']){self::setCookie(self::valid(self::cookieValue('share'),'share')?self::token('attribution'):'','attribution');self::setCookie(self::token(),'session');wp_safe_redirect(home_url('/'),303);exit;}
                $status=$result['limited']?429:403;
                $message=$result['limited']?'Er zijn te veel pogingen gedaan. Probeer het over een uur opnieuw.':'Deze code klopt niet. Controleer de vier tekens en probeer opnieuw.';
            }
        }
        status_header($status);nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        if($status===429){header('Retry-After: 3600');}
        $challenge=$enabled?self::token('challenge'):'';if($enabled){self::setCookie($challenge,'challenge');}
        $style=plugins_url('assets/preview.css',dirname(__DIR__,2).'/emonks-site-factory.php');
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Uw websitevoorstel · Emonks</title><link rel="stylesheet" href="'.esc_url($style).'"></head><body><main class="preview"><a class="wordmark" href="'.esc_url(home_url('/')).'" aria-label="Emonks websitevoorstel">emonks<span>.</span></a><p class="eyebrow">EEN VOORSTEL VOOR '.esc_html(get_bloginfo('name')).'</p><h1>Een eerste blik.<br>Een nieuw begin.</h1>';
        if(!$ready){echo '<p>Dit voorstel is tijdelijk niet beschikbaar. Probeer het later opnieuw.</p>';}
        elseif(!$enabled){echo '<p>Toegang met een previewcode is nog niet beschikbaar. Vraag uw contactpersoon om toegang.</p>';}
        else{
            echo '<p>Uw nieuwe website staat klaar om te bekijken.<br>Vul de vier tekens in die u van ons heeft ontvangen.</p><form method="post" action="'.esc_url(home_url('/')).'"><input type="hidden" name="emsf_challenge" value="'.esc_attr($challenge).'"><label for="preview-code">Toegangscode</label><input id="preview-code" name="emsf_preview_code" type="text" maxlength="4" minlength="4" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="AB2C" aria-describedby="code-help'.($message?' code-error':'').'">';
            if($message){echo '<p class="error" id="code-error" role="alert">'.esc_html($message).'</p>';}
            echo '<button type="submit">Bekijk het voorstel <span aria-hidden="true">↗</span></button><p class="help" id="code-help">Hoofdletters of kleine letters: allebei goed.</p></form>';
        }
        if(Activity::policy($site)['enabled']){echo '<p class="help">De maker ziet wanneer dit voorstel wordt bekeken of een klantaccount inlogt. Een gedeelde code of link bewijst geen identiteit.</p>';}
        echo '<div class="editor"><span>Ook teksten aanpassen?</span> <a href="'.esc_url(wp_login_url(get_admin_url($site))).'">Inloggen als redacteur</a></div><footer>Met aandacht gemaakt door Emonks.</footer></main></body></html>';exit;
    }
}
