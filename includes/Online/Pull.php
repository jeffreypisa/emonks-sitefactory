<?php
declare(strict_types=1);
namespace Emonks\Factory\Online;
use Emonks\Factory\{Factory,Package};

/** Reverse transfer: content, media and project definitions, never executable code. */
final class Pull {
    public static function restore(string $slug,string $confirm,string $expected): array {
        Factory::local();$p=Factory::find($slug);Sites::site((int)$p['site_id']);
        if($confirm!==(string)get_blog_option($p['site_id'],'blogname')){throw new \RuntimeException('Bevestig met de exacte lokale sitenaam.');}
        return Store::locked('client-'.$p['uuid'],static function()use($p,$expected){
            $last=Store::read('pull-'.$p['uuid']);
            if(!$expected||($last['backup']??'')!==$expected){throw new \RuntimeException('Herstelkopie is gewijzigd. Open de pagina opnieuw.');}
            switch_to_blog((int)$p['site_id']);try{
                $before=Snapshot::localBackup();Snapshot::localRestore($expected);Mail::set((int)$p['site_id'],'off');
                Store::write('pull-'.$p['uuid'],['backup'=>$before,'state'=>'restored','at'=>gmdate('c')]);
                return ['local_state'=>'Lokale herstelkopie teruggezet. Online is niets gewijzigd.','local_backup'=>$before];
            }finally{restore_current_blog();}
        });
    }
    public static function run(string $slug,string $confirm): array {
        Factory::local();$p=Factory::find($slug);$site=Sites::site((int)$p['site_id']);
        if($confirm!==(string)get_blog_option($site->blog_id,'blogname')){throw new \RuntimeException('Typ de exacte lokale sitenaam om de lokale inhoud te vervangen.');}
        if($site->archived||$site->deleted){throw new \RuntimeException('Herstel eerst het lokale concept.');}
        return Store::locked('client-'.$p['uuid'],static function()use($p){
            if((Store::read('pull-'.$p['uuid'])['state']??'')==='applying'){throw new \RuntimeException('Een eerdere ophaalactie is onderbroken. Zet eerst de lokale herstelkopie terug.');}
            if(Store::read('pending-'.$p['uuid'])){throw new \RuntimeException('Rond de openstaande publicatie eerst af of annuleer deze.');}
            $status=Client::status($p['slug']);
            if(empty($status['content_pull'])){throw new \RuntimeException('Installeer Site Factory 0.10.0 ook online om inhoud op te halen.');}
            if(empty($status['link']['site'])||!empty($status['link']['deleted'])){throw new \RuntimeException('Deze site heeft geen online koppeling.');}
            $dir=Store::root().'/pull-'.wp_generate_uuid4();$backup='';
            try {
                $download=Client::request('download',['source'=>$p['uuid']]);$manifest=$download['manifest']??[];
                if(($manifest['prospect']['uuid']??'')!==$p['uuid']){throw new \RuntimeException('Online pakket hoort niet bij deze site.');}
                if(!empty($manifest['unsupported'])){throw new \RuntimeException(implode('; ',$manifest['unsupported']));}
                // No code is transferred. Stop rather than silently reproduce with a different release.
                \Emonks\Factory\ThemeRelease::requireVersion($manifest['dependencies']??[]);
                Package::check_models($manifest);
                $total=0;foreach($manifest['files']??[] as $name=>$meta){Receiver::assetName($name);if(!is_int($meta['size']??null)||$meta['size']<0||$meta['size']>8*1024*1024){throw new \RuntimeException('Ongeldig mediabestand.');}$total+=$meta['size'];}
                if($total>256*1024*1024){throw new \RuntimeException('Pakket groter dan 256 MiB.');}
                if(!wp_mkdir_p($dir.'/files')){throw new \RuntimeException('Downloadmap niet schrijfbaar.');}
                foreach($manifest['files'] as $name=>$meta){
                    $asset=Client::request('download_asset',['source'=>$p['uuid'],'download'=>$download['id'],'name'=>$name]);
                    $bytes=base64_decode($asset['bytes']??'',true);
                    if($bytes===false||strlen($bytes)!==$meta['size']||!hash_equals($meta['sha256']??'',hash('sha256',$bytes))){throw new \RuntimeException('Downloadcontrole mislukt: '.$name);}
                    if(!wp_mkdir_p(dirname($dir.'/files/'.$name))||file_put_contents($dir.'/files/'.$name,$bytes)===false){throw new \RuntimeException('Media opslaan mislukt.');}
                }
                Package::json($dir.'/manifest.json',$manifest);Package::read($dir);
                switch_to_blog((int)$p['site_id']);
                try {
                    global $wpdb;$table=$wpdb->prefix.'fluentform_submissions';
                    if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))&&(int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`")){throw new \RuntimeException('Lokale formulierinzendingen aanwezig. Stel deze eerst veilig voordat je de formulierdefinities vervangt.');}
                    if(!empty($manifest['forms'])&&!defined('FLUENTFORM_VERSION')){throw new \RuntimeException('Activeer Fluent Forms lokaal.');}
                    Package::import($dir,false,true);
                    $backup=Snapshot::localBackup();
                    Store::write('pull-'.$p['uuid'],['backup'=>$backup,'state'=>'applying','at'=>gmdate('c')]);
                    $previous=Receiver::$importing;Receiver::$importing=true;
                    try {Package::models($dir);Package::import($dir,true,true);}
                    catch(\Throwable $e){
                        try{Snapshot::localRestore($backup);Store::write('pull-'.$p['uuid'],['backup'=>$backup,'state'=>'restored','at'=>gmdate('c')]);}
                        catch(\Throwable $restore){throw new \RuntimeException('Ophalen gestopt; herstel vereist met kopie '.$backup.'. '.$restore->getMessage());}
                        throw new \RuntimeException('Ophalen mislukt; de lokale herstelkopie is teruggezet. '.$e->getMessage());
                    }finally{Receiver::$importing=$previous;}
                    // A pulled production site must remain an isolated local working copy.
                    Mail::set((int)$p['site_id'],'off');
                    Store::write('pull-'.$p['uuid'],['backup'=>$backup,'state'=>'complete','at'=>gmdate('c')]);
                    Store::write('check-'.$p['uuid'],[]);
                } finally {restore_current_blog();}
                return ['local_state'=>'Online inhoud opgehaald. E-mail en integraties staan lokaal uit.','local_backup'=>$backup];
            } finally {Publication::discardExport($dir);}
        });
    }
}
