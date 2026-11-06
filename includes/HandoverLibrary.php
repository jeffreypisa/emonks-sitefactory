<?php
declare(strict_types=1);
namespace Emonks\Factory;
use Emonks\Factory\Online\Store;

/** Per-site immutable archives. All callers must hold the site's handover lock for writes. */
final class HandoverLibrary {
    private const OPTION='emsf_handover_packages';
    public const TRASH_DAYS=30;
    public static function operation(string $operation): string {
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$operation)){throw new \RuntimeException('Ongeldig pakket.');}return $operation;
    }
    public static function all(): array {
        $records=(array)get_option(self::OPTION,[]);$legacy=(array)get_option('emsf_handover',[]);
        if(!empty($legacy['operation'])&&!isset($records[$legacy['operation']])){$records[$legacy['operation']]=$legacy;}
        $id=get_current_blog_id();
        $records=array_filter($records,static fn($r)=>is_array($r)&&($r['format']??'')===Handover::FORMAT&&(int)($r['site']??0)===$id&&!empty($r['operation']));
        uasort($records,static fn($a,$b)=>(strtotime($b['created_at']??'')?:0)<=>(strtotime($a['created_at']??'')?:0));
        return $records;
    }
    public static function available(): array {return array_filter(self::all(),static fn($r)=>empty($r['deleted_at']));}
    public static function find(string $selector,bool $deleted=false): array {
        foreach(self::all() as $record){if(($record['operation']===$selector||($record['fingerprint']??'')===$selector)&&($deleted||empty($record['deleted_at']))){self::path($record);return $record;}}
        throw new \RuntimeException('Dit pakket is niet beschikbaar.');
    }
    public static function destination(string $operation): string {
        self::operation($operation);$root=Store::root().'/handovers';$site=$root.'/'.get_current_blog_id();$dest=$site.'/'.$operation;
        foreach([$root,$site,$dest] as $dir){if(is_link($dir)||(!is_dir($dir)&&!wp_mkdir_p($dir))||!chmod($dir,0700)){throw new \RuntimeException('Pakketopslag is niet schrijfbaar.');}}
        return $dest;
    }
    /** Accept only this site's canonical folder or its verified legacy package folder. */
    public static function path(array $record): string {
        $operation=self::operation((string)($record['operation']??''));$id=get_current_blog_id();
        if(($record['format']??'')!==Handover::FORMAT||(int)($record['site']??0)!==$id){throw new \RuntimeException('Dit pakket hoort bij een andere website.');}
        $root=Store::root();$dir=(string)($record['dir']??'');$canonical=$root.'/handovers/'.$id.'/'.$operation;
        if($dir===$canonical){foreach([$root.'/handovers',$root.'/handovers/'.$id,$dir] as $p){if(is_link($p)){throw new \RuntimeException('Onveilige pakketmap.');}}return $dir;}
        if(dirname($dir)!==$root||!preg_match('/^handover-[a-f0-9-]{36}$/D',basename($dir))||is_link($dir)){throw new \RuntimeException('Onveilige pakketmap.');}
        if(is_dir($dir)){
            $file=$dir.'/handover.json';
            if(!is_file($file)){
                $manifest=$dir.'/manifest.json';$meta=is_file($manifest)?json_decode((string)file_get_contents($manifest),true):[];
                if(($record['state']??'')==='prepared'&&is_file($manifest)&&!is_link($manifest)&&hash_equals((string)($record['manifest_sha256']??''),hash_file('sha256',$manifest))&&($meta['source']??'')===($record['source']??'')&&($record['source']??'')===get_home_url($id)){return $dir;}
                throw new \RuntimeException('De herkomst van dit oudere pakket kan niet worden gecontroleerd.');
            }
            if(is_link($file)){throw new \RuntimeException('Onveilige pakketgegevens.');}
            $meta=json_decode((string)file_get_contents($file),true);
            if(($meta['operation']??'')!==$operation||(int)($meta['site']??0)!==$id){throw new \RuntimeException('Dit pakket hoort bij een andere website.');}
        }
        return $dir;
    }
    private static function persist(array $records): void {
        if(!update_option(self::OPTION,$records,false)&&get_option(self::OPTION,[])!==$records){throw new \RuntimeException('Het pakketoverzicht kon niet worden opgeslagen.');}
    }
    public static function save(array $record): void {
        self::path($record);$records=self::all();if(isset($records[$record['operation']])){$records[$record['operation']]=$record;}else{$records=[$record['operation']=>$record]+$records;}self::persist($records);
    }
    public static function log(string $action,string $message,array $record=[]): void {
        $log=(array)get_option('emsf_handover_log',[]);
        array_unshift($log,['at'=>gmdate('c'),'action'=>$action,'message'=>$message,'operation'=>$record['operation']??'','target'=>$record['target']??'','software'=>$record['software']??[]]);
        update_option('emsf_handover_log',array_slice($log,0,50),false);
    }
    public static function remove(string $operation): array {
        self::operation($operation);$record=self::find($operation);$record['deleted_at']=gmdate('c');$record['deleted_by']=get_current_user_id();
        $records=self::all();$records[$operation]=$record;self::persist($records);self::log('deleted','Pakket verwijderd.',$record);return $record;
    }
    public static function restore(string $operation): array {
        self::operation($operation);$record=self::find($operation,true);
        if(empty($record['deleted_at'])||!empty($record['purged_at'])||!is_dir(self::path($record))){throw new \RuntimeException('Dit pakket kan niet worden teruggezet.');}
        unset($record['deleted_at'],$record['deleted_by']);$records=self::all();$records[$operation]=$record;self::persist($records);self::log('restored','Pakket teruggezet.',$record);return $record;
    }
    /** Lazy trash cleanup; never touches visible archives or source website files. */
    public static function purge(): void {
        $records=self::all();$changed=false;
        foreach($records as $key=>$record){
            if(empty($record['deleted_at'])||!empty($record['purged_at'])||strtotime($record['deleted_at'])>time()-self::TRASH_DAYS*86400){continue;}
            $dir=self::path($record);
            if(is_dir($dir)){
                foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir()&&!$file->isLink()){if(!rmdir($file->getPathname())){throw new \RuntimeException('Pakketopslag opruimen mislukt.');}}elseif(!unlink($file->getPathname())){throw new \RuntimeException('Pakketopslag opruimen mislukt.');}}
                if(!rmdir($dir)){throw new \RuntimeException('Pakketopslag opruimen mislukt.');}
            }
            $records[$key]['purged_at']=gmdate('c');$changed=true;
        }
        if($changed){self::persist($records);}
    }
}
