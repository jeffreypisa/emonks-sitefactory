<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;
use Emonks\Factory\Factory;
use Emonks\Factory\Package;

final class Client {
    private static bool $sending = false;
    public static function isSending(): bool { return self::$sending; }
    public static function endpoint(): string {
        $url = defined('EMSF_REMOTE_URL') ? rtrim(EMSF_REMOTE_URL, '/') : '';
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { throw new \RuntimeException('Configureer een vaste HTTPS-netwerk-URL in wp-config.php.'); }
        return $url;
    }
    public static function request(string $route, array $payload, string $method='POST'): array {
        Factory::local();
        if (!defined('EMSF_REMOTE_USER') || !defined('EMSF_REMOTE_PASSWORD')) { throw new \RuntimeException('Online applicatiewachtwoord ontbreekt.'); }
        $payload['theme_slug'] = \Emonks\Factory\ThemeRelease::remoteSlug();
        $url = self::endpoint().'/wp-json/emsf/v1/'.$route;
        $args = ['method'=>$method,'timeout'=>120,'redirection'=>0,'sslverify'=>true,'headers'=>['Authorization'=>'Basic '.base64_encode(EMSF_REMOTE_USER.':'.EMSF_REMOTE_PASSWORD),'Content-Type'=>'application/json']];
        if ($method === 'GET') { $url = add_query_arg($payload, $url); } else { $args['body'] = wp_json_encode($payload); }
        self::$sending = true;
        try { $response = wp_safe_remote_request($url, $args); } finally { self::$sending = false; }
        if (is_wp_error($response)) { throw new \RuntimeException('Verbinding onderbroken. Controleer status; dezelfde operatie blijft bewaard voor hervatten.'); }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !is_array($body)) { throw new \RuntimeException(is_array($body) ? (string)($body['message'] ?? 'Online verzoek geweigerd.') : 'Online antwoord niet leesbaar; controleer status.', (int)wp_remote_retrieve_response_code($response)); }
        return $body;
    }
    public static function status(string $slug): array {
        Factory::local(); $p = Factory::find($slug);
        $bound = Store::read('remote-'.$p['uuid']);
        if ($bound && ($bound['endpoint'] ?? '') !== self::endpoint()) { throw new \RuntimeException('Deze site is aan een ander online netwerk gekoppeld; laat de koppeling eerst bewust migreren.'); }
        $pending = Store::read('pending-'.$p['uuid']);
        try{$result = self::request('status', ['source'=>$p['uuid'],'operation'=>$pending['operation'] ?? ''], 'GET');}
        catch(\Throwable $e){$cached=Store::read('status-'.$p['uuid']);$cached['error_at']=gmdate('Y-m-d H:i:s');$cached['error']='Online niet bereikbaar; laatst bekende gegevens blijven staan.';Store::write('status-'.$p['uuid'],$cached);throw $e;}
        Store::write('status-'.$p['uuid'],['checked_at'=>gmdate('Y-m-d H:i:s'),'theme'=>$result['theme']??[],'environment'=>$result['environment']??[],'plugin_version'=>$result['plugin_version']??'','operation'=>$result['operation']??[]]);
        if (!empty($result['link'])) { Store::write('remote-'.$p['uuid'],$result['link']+['endpoint'=>self::endpoint(),'customer'=>$result['customer']??[],'activity'=>$result['activity']??[]]); }
        if($pending&&($result['operation']['id']??'')===$pending['operation']&&($result['operation']['status']??'')==='complete'){Store::write('pending-'.$p['uuid'],[]);Store::write('check-'.$p['uuid'],[]);$pending=[];Publication::progress($p['uuid'],'complete','Publicatie voltooid.');}
        if ($pending && empty($result['operation'])) { $result['local_operation'] = $pending['operation']; }
        return $result;
    }
    public static function checkPackage(string $source,array $manifest): array {return self::request('check',['source'=>$source,'manifest'=>$manifest]);}
    public static function push(string $slug, string $action, string $confirm='', array $customer=[],array $review=[]): array {
        Factory::local();if(function_exists('wp_raise_memory_limit')){wp_raise_memory_limit('admin');}
        if (!in_array($action, ['create','replace'], true)) { throw new \RuntimeException('Ongeldige publicatieactie.'); }
        $p = Factory::find($slug);
        if (empty($p['site_id'])) { throw new \RuntimeException('Maak eerst een lokale subsite.'); }
        return Store::locked('client-'.$p['uuid'], static function() use ($p, $action, $confirm, $customer,$review) {
            Publication::progress($p['uuid'],'checking','Online verbinding en publicatie controleren…');
            $key = 'pending-'.$p['uuid']; $pending = Store::read($key);
            if (!$pending) {
                $customer=$action==='create' ? Accounts::optional($customer) : [];
                $status = self::status($p['slug']);
                $checks=Publication::environmentChecks($status);
                foreach($checks as $check){if(!$check['ok']){throw new \RuntimeException($check['label'].': '.$check['action']);}}
                if ($action==='create' && !$customer && empty($status['optional_customer'])) { throw new \RuntimeException('Installeer Site Factory 0.8.0 ook online om zonder opdrachtgever te publiceren.'); }
                if ($action==='create' && empty($status['optional_preview'])) { throw new \RuntimeException('Installeer Site Factory 0.9.0 ook online voor de nieuwe standaard: toegankelijk zonder code.'); }
                if (empty($status['publication_check'])) { throw new \RuntimeException('Installeer Site Factory 0.6.0 ook online vóór publiceren.'); }
                if (($status['protocol']??0)<2) { throw new \RuntimeException('Werk eerst de online plugin bij: stille klantaccountaanmaak wordt nog niet ondersteund.'); }
                if ($action === 'create' && !empty($status['link'])) { throw new \RuntimeException('Al gekoppeld. Kies expliciet Online inhoud vervangen.'); }
                if ($action === 'replace' && (empty($status['link']['site']) || $confirm !== ($status['link']['name'] ?? null))) { throw new \RuntimeException('Bevestig vervanging met de exacte online sitenaam.'); }
                $operation = wp_generate_uuid4(); $dir = Store::root().'/out-'.$operation;
                try{
                Publication::progress($p['uuid'],'exporting','Lokale inhoud en media verzamelen…');
                switch_to_blog((int)$p['site_id']);
                try { Package::export($dir); } finally { restore_current_blog(); }
                $manifest = Package::read($dir);
                if (!empty($manifest['unsupported'])) { throw new \RuntimeException('Publicatie geblokkeerd: '.implode('; ', $manifest['unsupported'])); }
                $report=self::checkPackage($p['uuid'],$manifest);Store::write('check-'.$p['uuid'],$report+['checked_at'=>gmdate('c')]);
                if(empty($report['ok'])){throw new \RuntimeException(implode(' ', $report['issues']??['Publicatiecontrole mislukt.']));}
                if($action==='replace'){$changes=$report['changes'];if(($review['expected_revision']??'')!==$changes['token']||((!$changes['known']||$changes['changed'])&&empty($review['replace_changes']))){throw new \RuntimeException('Controleer eerst de online wijzigingen en bevestig daarna bewust het vervangen. De controle is bijgewerkt; open Publiceren opnieuw.');}}
                $pending = ['theme_slug'=>\Emonks\Factory\ThemeRelease::remoteSlug(), 'operation'=>$operation, 'source'=>$p['uuid'], 'slug'=>$p['slug'], 'action'=>$action, 'confirm'=>$confirm, 'endpoint'=>self::endpoint(), 'preview_mode'=>!empty($review['preview_protected'])?'code':'public', 'customer'=>$customer,'expected_revision'=>$review['expected_revision']??'','replace_changes'=>!empty($review['replace_changes'])];
                Store::write($key, $pending);
                }catch(\Throwable $e){if(!Store::read($key)){Publication::discardExport($dir);}throw $e;}
            }
            if ($action==='create' && Accounts::optional($customer)!==($pending['customer']??[])) { throw new \RuntimeException('Klantgegevens wijken af van de openstaande publicatie. Annuleer eerst de upload om andere gegevens te kiezen.'); }
            if($action==='create'&&isset($pending['preview_mode'])&&$pending['preview_mode']!==(!empty($review['preview_protected'])?'code':'public')){throw new \RuntimeException('De toegangskeuze wijkt af van de openstaande publicatie. Hervat met dezelfde keuze of annuleer eerst de upload.');}
            if (isset($pending['theme_slug']) && $pending['theme_slug'] !== \Emonks\Factory\ThemeRelease::remoteSlug()) { throw new \RuntimeException('De online themamap is gewijzigd tijdens deze upload. Herstel de oorspronkelijke instelling of annuleer de upload vóór opnieuw publiceren.'); }
            if ($pending['endpoint'] !== self::endpoint() || $pending['action'] !== $action || $pending['confirm'] !== $confirm) { throw new \RuntimeException('Er staat een andere operatie open. Controleer eerst de status en herstel zo nodig.'); }
            $dir = Store::root().'/out-'.$pending['operation']; $manifest = Package::read($dir);
            foreach(array_keys($manifest['files'])as$name){try{Receiver::assetName($name);}catch(\Throwable $e){throw new \RuntimeException('Het openstaande pakket bevat een ongeldige bestandsnaam. Controleer eerst de online status. Annuleer daarna de upload onder Beheer met operatie-ID '.$pending['operation'].' en de exacte sitenaam, en start opnieuw om veilige bestandsnamen te exporteren. Het bestaande pakket is niet gewijzigd.');}}
            Publication::progress($p['uuid'],'preparing','Pakket gecontroleerd; upload voorbereiden…');
            $response = self::request('prepare', $pending+['manifest'=>$manifest]);
            if (in_array($response['status'], ['restored','cancelled'], true)) { Store::write($key, []); throw new \RuntimeException('Vorige operatie hersteld. Start opnieuw om de actuele lokale inhoud te publiceren.'); }
            if ($response['status'] === 'receiving') {
                $done=0;$total=count($manifest['files']);
                foreach ($manifest['files'] as $name=>$meta) {
                    Publication::progress($p['uuid'],'uploading','Bestanden uploaden…',$done,$total);
                    if ($meta['size'] > 8*1024*1024) { throw new \RuntimeException('Mediabestand groter dan 8 MiB. Verklein het bestand.'); }
                    self::request('asset', $pending+['name'=>$name, 'bytes'=>base64_encode((string)file_get_contents($dir.'/files/'.$name))]);$done++;
                }
            }
            Publication::progress($p['uuid'],'applying','Upload gereed. Online herstelkopie maken en inhoud toepassen…');
            $result = self::request('execute', $pending);
            if (($result['operation']['status'] ?? '') === 'complete') {
                Store::write('remote-'.$p['uuid'], $result['link']+['endpoint'=>self::endpoint(),'operation'=>$pending['operation'],'customer'=>$result['customer']??[],'activity'=>$result['activity']??[]]);
                Store::write($key, []);
                Store::write('check-'.$p['uuid'],[]);
                Publication::progress($p['uuid'],'complete','Publicatie voltooid.');
            }
            return $result;
        });
    }
    public static function manage(string $slug, string $action, string $confirm, array $extra=[]): array {
        Factory::local(); $p = Factory::find($slug);
        $bound=Store::read('remote-'.$p['uuid']);
        if($bound && ($bound['endpoint']??'')!==self::endpoint()){throw new \RuntimeException('Deze site hoort bij een ander online netwerk.');}
        if(in_array($action,['users_get','customer_set','user_create'],true) && empty(self::status($slug)['customer_management'])){throw new \RuntimeException('Installeer Site Factory 0.8.0 ook online om gebruikers en opdrachtgevers te beheren.');}
        if($action==='preview_public' && empty(self::status($slug)['optional_preview'])){throw new \RuntimeException('Installeer Site Factory 0.9.0 ook online om de website zonder code toegankelijk te maken.');}
        if($action==='activity_settings' && (self::status($slug)['protocol']??0)<4){throw new \RuntimeException('Werk eerst de online Site Factory-plugin bij naar 0.4.0 voor activiteit.');}
        if(in_array($action,['preview_get','preview_generate','preview_rotate','preview_revoke'],true) && empty(self::status($slug)['persistent_preview'])){throw new \RuntimeException('Installeer Site Factory 0.5.7 ook online om de vaste previewcode te bekijken en delen.');}
        if ($action === 'cancel') {
            $pending = Store::read('pending-'.$p['uuid']);
            if ($pending && ($extra['operation'] ?? '') === $pending['operation']) {
                $status = self::status($slug);
                if (empty($status['operation']) && $confirm === ($status['link']['name'] ?? $p['name'])) {
                    Store::write('pending-'.$p['uuid'], []); return ['cancelled'=>true];
                }
            }
        }
        $result = self::request('manage', ['source'=>$p['uuid'],'action'=>$action,'confirm'=>$confirm]+$extra);
        if (!empty($result['link'])) { Store::write('remote-'.$p['uuid'],$result['link']+['endpoint'=>self::endpoint(),'customer'=>$result['customer']??[],'activity'=>$result['activity']??[]]); }
        if (in_array($action, ['restore','cancel'], true)) { Store::write('pending-'.$p['uuid'], []);Store::write('check-'.$p['uuid'],[]); }
        if($action==='retry_models' && ($result['operation']['status']??'')==='complete'){Store::write('pending-'.$p['uuid'],[]);Store::write('check-'.$p['uuid'],[]);}
        return $result;
    }
}
