<?php
declare(strict_types=1);
namespace Emonks\Factory;

/** Private, administrator-bound upload transport; never a content importer. */
final class UploadTransport {
    public const CHUNK=4*1024*1024;
    public const TTL=86400;
    private static function path(string $token): string {
        if(!preg_match('/^[a-f0-9-]{36}$/D',$token)){throw new \RuntimeException('Ongeldige uploadcode. Kies het pakket opnieuw.');}
        $dir=Standalone::storage().'/upload-'.$token;if(is_link($dir)){throw new \RuntimeException('Onveilige uploadmap.');}return $dir;
    }
    private static function save(string $dir,array $record): void {
        $tmp=tempnam($dir,'.state-');if(!$tmp){throw new \RuntimeException('Uploadstatus opslaan mislukt.');}
        try{$data=json_encode($record,JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$data)!==strlen($data)||!chmod($tmp,0600)||!rename($tmp,$dir.'/state.json')){throw new \RuntimeException('Uploadstatus opslaan mislukt.');}}finally{if(is_file($tmp)){unlink($tmp);}}
    }
    public static function begin(string $name,int $size): array {
        Standalone::authorize();if(!preg_match('/\.zip$/iD',$name)||$size<1||$size>FullSite::LIMIT){throw new \RuntimeException('Kies een geldig opleverpakket (.zip), maximaal 16 GiB.');}
        $root=Standalone::storage();$free=disk_free_space($root);if($free===false||$free<$size*4+64*1024*1024){throw new \RuntimeException('Onvoldoende vrije private opslag voor upload, uitpakken en importeren.');}
        $active=0;foreach(glob($root.'/upload-*',GLOB_ONLYDIR)?:[] as $dir){if(is_link($dir)||!preg_match('/^upload-[a-f0-9-]{36}$/D',basename($dir))){continue;}$state=json_decode((string)@file_get_contents($dir.'/state.json'),true);if(($state['user']??null)!==get_current_user_id()){continue;}if(($state['updated']??0)<time()-self::TTL){self::discard($dir);}elseif(empty($state['result'])){$active++;}}
        if($active>=3){throw new \RuntimeException('Er staan al drie uploads klaar. Hervat je eerdere upload of probeer het morgen opnieuw.');}
        $token=wp_generate_uuid4();$dir=self::path($token);if(!mkdir($dir,0700)){throw new \RuntimeException('Private uploadmap maken mislukt.');}
        $file=fopen($dir.'/package.part','xb');if(!$file){throw new \RuntimeException('Uploadbestand maken mislukt.');}fclose($file);chmod($dir.'/package.part',0600);
        $r=['token'=>$token,'name'=>sanitize_file_name($name),'size'=>$size,'offset'=>0,'user'=>get_current_user_id(),'site'=>site_url(),'updated'=>time()];self::save($dir,$r);return ['token'=>$token,'offset'=>0,'size'=>$size,'chunk_size'=>self::CHUNK];
    }
    private static function discard(string $dir): void {
        if(dirname($dir)!==Standalone::storage()||is_link($dir)||!preg_match('/^upload-[a-f0-9-]{36}$/D',basename($dir))){throw new \RuntimeException('Ongeldige uploadmap.');}
        $lock=fopen($dir.'/upload.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock){fclose($lock);}return;}
        try{foreach(glob($dir.'/*')?:[] as $file){if(is_file($file)&&!is_link($file)&&basename($file)!=='upload.lock'){unlink($file);}}unlink($dir.'/upload.lock');rmdir($dir);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function locked(string $token,callable $fn) {
        $dir=self::path($token);if(!is_dir($dir)){throw new \RuntimeException('Deze upload is niet meer beschikbaar. Kies het pakket opnieuw.',410);}
        $lock=fopen($dir.'/upload.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock){fclose($lock);}throw new \RuntimeException('De upload wordt nog verwerkt. Wacht even en probeer opnieuw.');}chmod($dir.'/upload.lock',0600);
        try{
            $r=json_decode((string)file_get_contents($dir.'/state.json'),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($r)||($r['user']??0)!==get_current_user_id()||($r['site']??'')!==site_url()||($r['updated']??0)<time()-self::TTL){throw new \RuntimeException('Deze upload is verlopen of hoort bij een andere beheerder. Kies het pakket opnieuw.',410);}
            if(is_link($dir.'/package.part')||is_link($dir.'/state.json')){throw new \RuntimeException('Onveilig uploadbestand.');}
            if(isset($r['result'])){return $fn($dir,$r);}
            clearstatcache(true,$dir.'/package.part');$size=filesize($dir.'/package.part');if($size<$r['offset']){throw new \RuntimeException('Uploadbestand is onvolledig. Start de upload opnieuw.');}
            // A killed request can leave bytes beyond the durable offset; discard only that uncommitted tail.
            if($size>$r['offset']){$file=fopen($dir.'/package.part','c+b');try{if(!ftruncate($file,$r['offset'])){throw new \RuntimeException('Upload herstellen mislukt.');}}finally{fclose($file);}}
            return $fn($dir,$r);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function status(string $token): array {
        return self::locked($token,static function($dir,$r){return array_merge(['token'=>$r['token'],'offset'=>$r['offset'],'size'=>$r['size'],'chunk_size'=>self::CHUNK],$r['result']??[]);});
    }
    /** Controller verifies is_uploaded_file; this helper also supports real filesystem regression tests. */
    public static function append(string $token,int $offset,string $incoming): array {
        return self::locked($token,static function($dir,$r)use($offset,$incoming){
            if(isset($r['result'])){throw new \RuntimeException('Dit pakket staat al klaar. Vernieuw de pagina.');}
            $length=filesize($incoming);if($length<1||$length>self::CHUNK||$offset<0||$offset+$length>$r['size']){throw new \RuntimeException('Ongeldig uploaddeel.');}
            $file=fopen($dir.'/package.part','c+b');if(!$file){throw new \RuntimeException('Uploadbestand openen mislukt.');}
            try{
                if($offset<$r['offset']){
                    // Lost acknowledgements may retry a completed part, but never replace existing bytes.
                    if($offset+$length>$r['offset']){throw new \RuntimeException('Uploadvolgorde wijkt af. Hervat de upload.');}fseek($file,$offset);$existing=fread($file,$length);if(!hash_equals(hash_file('sha256',$incoming),hash('sha256',$existing))){throw new \RuntimeException('Eerder uploaddeel wijkt af. Kies het oorspronkelijke bestand.');}
                    return ['offset'=>$r['offset'],'size'=>$r['size']];
                }
                if($offset!==$r['offset']){throw new \RuntimeException('Uploadvolgorde wijkt af. Hervat de upload.');}
                fseek($file,$offset);$in=fopen($incoming,'rb');if(!$in){throw new \RuntimeException('Uploaddeel lezen mislukt.');}try{$copied=stream_copy_to_stream($in,$file,self::CHUNK+1);}finally{fclose($in);}
                if($copied!==$length||!fflush($file)){throw new \RuntimeException('Uploaddeel opslaan mislukt.');}
                $r['offset']+=$length;$r['updated']=time();self::save($dir,$r);return ['offset'=>$r['offset'],'size'=>$r['size']];
            }finally{fclose($file);}
        });
    }
    public static function finish(string $token): array {
        $result=self::locked($token,static function($dir,$r){
            if(isset($r['result'])){return $r['result'];}
            if($r['offset']!==$r['size']){throw new \RuntimeException('De upload is nog niet compleet. Hervat eerst het uploaden.');}
            // Keep processing if a proxy closes the browser connection. A retry can reuse the private upload.
            ignore_user_abort(true);if(function_exists('set_time_limit')){@set_time_limit(0);}wp_raise_memory_limit('admin');
            $record=Standalone::stage($dir.'/package.part',true);
            $r['result']=['redirect'=>admin_url('tools.php?page=emsf-handover'),'operation'=>$record['operation']];$r['updated']=time();self::save($dir,$r);unlink($dir.'/package.part');return $r['result'];
        });
        return $result;
    }
    public static function request(): void {
        try{
            Standalone::authorize();if(!check_ajax_referer('emsf_handover_import','_wpnonce',false)){throw new \RuntimeException('Je sessie is verlopen. Vernieuw de pagina en hervat de upload.');}
            $phase=(string)($_POST['phase']??'');$token=(string)($_POST['token']??'');
            if($phase==='start'){
                if(($_POST['hosting_private']??'')!=='1'){throw new \RuntimeException('Bevestig dat website en media via de hosting zijn afgeschermd.');}
                $result=self::begin((string)($_POST['name']??''),(int)($_POST['size']??0));
            }elseif($phase==='status'){$result=self::status($token);}
            elseif($phase==='chunk'){$f=$_FILES['chunk']??[];if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name'])){throw new \RuntimeException('Dit uploaddeel is geweigerd door de hosting. Controleer de uploadlimiet en probeer opnieuw.');}$result=self::append($token,(int)($_POST['offset']??-1),$f['tmp_name']);}
            elseif($phase==='finish'){$result=self::finish($token);}
            else{throw new \RuntimeException('Onbekende uploadactie.');}
            wp_send_json_success($result);
        }catch(\Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],$e->getCode()===410?410:400);}
    }
}
