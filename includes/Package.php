<?php
declare(strict_types=1);
namespace Emonks\Factory;
/** Versioned, local content transfer. Credentials and arbitrary options are never exported. */
final class Package {
    const FORMAT='emonks-site-package';
    private static array $urls=[];
    private static array $refs=[];
    private static function authorize_import(): void {
        if (Online\Receiver::$importing && defined('EMSF_ONLINE') && EMSF_ONLINE && is_super_admin() && Online\Privacy::managed()) { return; }
        Factory::local();
    }
    public static function theme_digest(): string {
        $root=wp_get_theme(ThemeRelease::slug())->get_stylesheet_directory(); $files=[];
        foreach (['src','views','acf-json','dist'] as $dir) {
            if (!is_dir($root.'/'.$dir)) { continue; }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                // Finder metadata is machine-local and never part of a theme release.
                if ($file->isFile() && $file->getFilename() !== '.DS_Store') { $files[substr($file->getPathname(),strlen($root)+1)]=hash_file('sha256',$file->getPathname()); }
            }
        }
        foreach (['style.css','functions.php'] as $name) { if (is_file($root.'/'.$name)) { $files[$name]=hash_file('sha256',$root.'/'.$name); } }
        ksort($files); return hash('sha256',json_encode($files));
    }
    private static function remove_generated(string $path): void {
        $stem=pathinfo($path,PATHINFO_FILENAME);$ext=pathinfo($path,PATHINFO_EXTENSION);
        foreach(glob(dirname($path).'/'.$stem.'-*.'.$ext)?:[] as $size){if(preg_match('/-\d+x\d+\.[^.]+$/',$size)){@unlink($size);}}
        @unlink($path);
    }
    public static function json(string $path,array $data): void {
        if(file_put_contents($path,wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n")===false){throw new \RuntimeException('Bestand niet schrijfbaar: '.$path);}
    }
    private static function ref(string $kind,int $id): array {
        if(!$id){return ['$literal'=>0];}
        $key=self::$refs[$kind.':'.$id]??null;if(!$key){throw new \RuntimeException('Onopgeloste bronverwijzing: '.$kind.':'.$id);}
        return ['$ref'=>$key];
    }
    public static function encode($value,?array $field=null,string $name='') {
        if($value===''||$value===null||$value===false){return $value;}
        $type=$field['type']??'';
        if ($type === 'user') { throw new \RuntimeException('Gebruikersvelden vereisen een expliciete accountadapter.'); }
        $kind=in_array($type,['image','file','gallery','post_object','relationship','page_link'],true)?'post':($type==='taxonomy'?'term':($type==='nav_menu'?'menu':''));
        if(!$kind && in_array($name,['_thumbnail_id','site_icon','custom_logo','teaser_fallback_image'],true)){$kind='post';}
        if(!$kind && preg_match('/(?:^|_)(?:menu|menu_id)$/',$name) && is_numeric($value)){$kind='menu';}
        if($kind){return is_array($value)?array_map(static fn($v)=>self::ref($kind,(int)$v),$value):self::ref($kind,(int)$value);}
        if(is_array($value)){foreach($value as $k=>&$v){$v=self::encode($v,null,(string)$k);}unset($v);return $value;}
        if(is_string($value)){
            if(preg_match('/^design:(\d+)$/',$value,$m)){return ['$design'=>self::ref('post',(int)$m[1])['$ref']];}
            if (($name === 'url' || $type === 'url') && str_starts_with($value, '/') && !str_starts_with($value, '//')) { $value=home_url($value); }
            $value=preg_replace_callback('~((?:href|src)=[\"\x27])(/(?!/)[^\"\x27]*)~i',static fn($m)=>$m[1].home_url($m[2]),$value);
            $value=strtr($value,self::$urls);
            $value=preg_replace_callback('/\[fluentform\b([^\]]*?)\bid=["\x27]?(\d+)["\x27]?([^\]]*)\]/',static fn($m)=>'[fluentform'.$m[1].'id="{{emsf:form:'.$m[2].'}}"'.$m[3].']',$value);
        }
        return $value;
    }
    public static function decode($value,array $map,array $urls) {
        if(is_array($value)){
            if(array_key_exists('$literal',$value)){return $value['$literal'];}
            if(isset($value['$ref'])){if(!isset($map[$value['$ref']])){throw new \RuntimeException('Ontbrekende verwijzing: '.$value['$ref']);}return $map[$value['$ref']];}
            if(isset($value['$design'])){if(!isset($map[$value['$design']])){throw new \RuntimeException('Ontbrekend teaserontwerp.');}return 'design:'.$map[$value['$design']];}
            foreach($value as &$v){$v=self::decode($v,$map,$urls);}unset($v);return $value;
        }
        if(is_string($value)){$value=strtr($value,$urls);if(str_contains($value,'{{emsf:')){throw new \RuntimeException('Onopgeloste URL of formulierverwijzing.');}}
        return $value;
    }
    /** Known scalar/layout metadata missed by ACF's dynamically registered field lookup. */
    private static function layoutMeta(string $type,string $key,array $values): bool {
        $values=array_map('maybe_unserialize',$values);
        if($key==='_stroken_layout_meta'){
            foreach($values as $value){
                if(!is_array($value)||array_diff(array_keys($value),['disabled','renamed'])){return false;}
                foreach(['disabled','renamed'] as $part){if(isset($value[$part])&&!is_array($value[$part])){return false;}}
                foreach($value['disabled']??[] as $index){if(!is_int($index)||$index<0){return false;}}
                foreach($value['renamed']??[] as $index=>$label){if(!ctype_digit((string)$index)||!is_string($label)){return false;}}
            }return true; // These numbers are layout row offsets, not database IDs.
        }
        if(preg_match('/^stroken_[0-9]+_section_alignment$/D',$key)){
            return count(array_filter($values,static fn($v)=>!in_array($v,['','theme','start','center'],true)))===0;
        }
        if($type==='referentie'&&$key==='beoordeling'){
            return count(array_filter($values,static fn($v)=>!is_numeric($v)||$v<0||$v>5))===0;
        }
        if($type==='skeletor_teaser'&&$key==='field_teaser_hover'){
            return count(array_filter($values,static fn($v)=>!in_array($v,['','none','lift','zoom'],true)))===0;
        }
        if($type==='skeletor_teaser'&&$key==='_field_teaser_hover'){
            return count(array_filter($values,static fn($v)=>!in_array($v,['','field_teaser_hover'],true)))===0;
        }
        if($type==='attachment'&&$key==='_skeletor_crops'){
            foreach($values as $value){
                if(!is_array($value)||!is_array($value['ratios']??null)||count($value['ratios'])>40){return false;}
                $choices=array_values($value['ratios']);if(isset($value['focus'])){$choices[]=$value['focus'];}
                foreach(array_keys($value['ratios']) as $ratio){if(!preg_match('/^[1-9][0-9]*x[1-9][0-9]*$/D',(string)$ratio)){return false;}}
                foreach($choices as $choice){if(!is_array($choice)){return false;}foreach(['x'=>[0,1],'y'=>[0,1],'zoom'=>[1,8]] as $name=>[$min,$max]){if(!isset($choice[$name])||!is_numeric($choice[$name])||$choice[$name]<$min||$choice[$name]>$max){return false;}}}
            }return true;
        }
        return false;
    }
    /** Canonical ASCII media basename: WordPress can retain invisible Unicode characters. */
    private static function mediaName(string $name): string {
        $name=remove_accents(sanitize_file_name($name));
        $name=preg_replace('/[^a-zA-Z0-9_.-]+/','-',$name);
        $name=preg_replace('/\.{2,}/','-',$name);
        $name=ltrim($name,'.-');
        return $name!==''?$name:'asset';
    }
    public static function export(string $dir): array {
        global $wpdb;
        if(is_dir($dir)&&glob($dir.'/*')){throw new \RuntimeException('Pakketmap moet leeg zijn.');}wp_mkdir_p($dir.'/files');
        self::$refs=[];self::$urls=[];
        $models=array_merge(acf_get_raw_post_types(),acf_get_raw_taxonomies()); foreach(glob(get_stylesheet_directory().'/acf-json/*.json') as $file){$d=json_decode(file_get_contents($file),true);if(isset($d['post_type'])||isset($d['taxonomy'])){$models[]=$d;}}
        // A network-admin request switches blogs after ACF init. Load this site's project
        // schema before resolving fields; never infer relationship types from raw integers.
        foreach(glob(Factory::model_dir().'/*.json')?:[] as $file){$model=json_decode(file_get_contents($file),true);if(is_array($model)){$models[]=$model;if(str_starts_with($model['key']??'','group_')){acf_add_local_field_group($model);}}}
        self::register_models(['models'=>$models]);
        $sections=acf_get_field('field_67471255a9ebc');
        if(is_array($sections)){$sections=apply_filters('acf/load_field/name=stroken',$sections);}
        $types=array_values(get_post_types(['public'=>true])); foreach($models as $model){if(!empty($model['post_type'])){$types[]=$model['post_type'];}}$types=array_unique(array_merge($types,['attachment','skeletor_teaser','skeletor_sections','strook']));
        $posts=get_posts(['post_type'=>$types,'post_status'=>['publish','draft','private','inherit','pending'],'numberposts'=>-1,'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
        foreach($posts as $p){self::$refs['post:'.$p->ID]='post:'.$p->post_type.':'.($p->post_name?:'source-'.$p->ID);}
        $taxonomies=get_taxonomies(['public'=>true]);foreach($models as $model){if(!empty($model['taxonomy'])){if(!taxonomy_exists($model['taxonomy'])){register_taxonomy($model['taxonomy'],$model['object_type']??[],['public'=>false]);}$taxonomies[]=$model['taxonomy'];}}
        $terms=get_terms(['taxonomy'=>array_unique($taxonomies),'hide_empty'=>false]);if(is_wp_error($terms)){$terms=[];}
        foreach($terms as $t){self::$refs['term:'.$t->term_id]='term:'.$t->taxonomy.':'.$t->slug;}
        $menus=wp_get_nav_menus();foreach($menus as $m){self::$refs['menu:'.$m->term_id]='menu:'.$m->slug;}
        $package=['format'=>self::FORMAT,'version'=>1,'created_at'=>gmdate('c'),'source'=>home_url(),'theme'=>'skeletor','models'=>[],'posts'=>[],'terms'=>[],'menus'=>[],'options'=>[],'files'=>[],'forms'=>[],'menu_locations'=>[], 'unsupported'=>[]];
        $package['dependencies']=['theme_version'=>ThemeRelease::version(),'factory_version'=>ThemeRelease::pluginVersion(),'acf_version'=>defined('ACF_VERSION')?ACF_VERSION:null];
        $package['dependencies']['theme_digest']=self::theme_digest();
        $package['dependencies']['fluentform_version']=defined('FLUENTFORM_VERSION')?FLUENTFORM_VERSION:null;
        if (class_exists('WooCommerce') || function_exists('pll_current_language')) { $package['unsupported'][]='WooCommerce/Polylang actief'; }
        $runtime=['actionscheduler_actions','actionscheduler_claims','actionscheduler_groups','actionscheduler_logs','ff_scheduled_actions'];
        $known=['posts','postmeta','terms','termmeta','term_taxonomy','term_relationships','comments','commentmeta','options','links'];
        foreach ($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix).'%')) as $table) {
            $suffix=substr($table,strlen($wpdb->prefix));
            if(in_array($suffix,$runtime,true)){$package['excluded_runtime_tables'][$suffix]=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`");continue;}
            if (!in_array($suffix,$known,true) && !str_starts_with($suffix,'fluentform_')) { $package['unsupported'][]='Onbekende tabel: '.$suffix; }
        }
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->termmeta}")) { $package['unsupported'][]='Termmetadata aanwezig'; }
        foreach ($posts as $post) {
            if (preg_match('/wp-image-\d+|<!-- wp:[^>]*"(?:id|ids|ref)"\s*:/', $post->post_content)) { $package['unsupported'][]='Niet vertaalde blok/HTML-ID in post '.$post->ID; }
        }
        $package['rules_version']=get_option('emsf_rules_version','');
        $slug=get_option('emsf_slug');if(defined('EMSF_LOCAL')&&EMSF_LOCAL&&$slug){$prospect=Factory::find($slug);$package['prospect']=array_intersect_key($prospect,array_flip(['uuid','slug','name','website','industry','region','research']));}
        foreach($posts as $p){
            $ref=self::$refs['post:'.$p->ID];$url=$p->post_type==='attachment'?wp_get_attachment_url($p->ID):get_permalink($p);
            if($url&&($p->post_type==='attachment'||$p->post_status==='publish')){self::$urls[$url]='{{emsf:url:'.$ref.'}}';}
            if($p->post_type==='attachment'){$meta=wp_get_attachment_metadata($p->ID);foreach($meta['sizes']??[] as $size){self::$urls[dirname((string)$url).'/'.$size['file']]='{{emsf:url:'.$ref.'}}';}}
        }
        self::$urls[wp_upload_dir()['baseurl'].'/skeletor-fonts']='{{emsf:fonts}}';
        self::$urls[home_url()]='{{emsf:home}}';uksort(self::$urls,static fn($a,$b)=>strlen($b)<=>strlen($a));
        foreach($terms as $t){$package['terms'][]=['ref'=>self::$refs['term:'.$t->term_id],'name'=>$t->name,'slug'=>$t->slug,'taxonomy'=>$t->taxonomy,'description'=>self::encode($t->description),'parent'=>$t->parent?self::ref('term',(int)$t->parent):0];}
        foreach($posts as $p){
            $row=['ref'=>self::$refs['post:'.$p->ID],'post'=>[],'meta'=>[],'terms'=>[]];
            foreach(['post_type','post_status','post_name','post_title','post_content','post_excerpt','post_date','menu_order','post_mime_type'] as $key){$row['post'][$key]=self::encode($p->$key);}
            $row['parent']=$p->post_parent?self::ref('post',(int)$p->post_parent):0;
            foreach(get_post_meta($p->ID) as $key=>$values){
                if(in_array($key,['_has_fluentform','_pingme','_encloseme','_edit_lock','_edit_last','_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_skeletor_search_text'],true)||str_starts_with($key,'_emsf')){continue;}
                $fieldkey=get_post_meta($p->ID,'_'.$key,true);$field=is_string($fieldkey)&&str_starts_with($fieldkey,'field_')?acf_get_field($fieldkey):null;
                if(!$field&&!self::layoutMeta($p->post_type,$key,$values)&&!str_starts_with($key,'teaser_')&&!in_array($key,['_thumbnail_id','_wp_page_template','_wp_attachment_image_alt','_wp_old_slug','_wp_old_date'],true)&&!(str_starts_with($key,'_')&&isset($values[0])&&str_starts_with((string)$values[0],'field_'))){$package['unsupported'][]='Onbekende postmetadata: '.$p->post_type.'/'.$key;continue;}
                $row['meta'][$key]=array_map(static fn($v)=>self::encode(maybe_unserialize($v),$field?:null,$key),$values);
            }
            foreach(get_object_taxonomies($p->post_type) as $tax){$ids=wp_get_object_terms($p->ID,$tax,['fields'=>'ids']);if(!is_wp_error($ids)&&$ids){$row['terms'][$tax]=array_map(static fn($id)=>self::ref('term',(int)$id),$ids);}}
            if($p->post_type==='attachment'){$file=get_attached_file($p->ID);if(!$file||!is_file($file)){throw new \RuntimeException('Mediabestand ontbreekt voor '.$p->ID);}$asset='media/'.hash_file('sha256',$file).'-'.self::mediaName(basename($file));self::asset($file,$asset,$dir,$package);$row['file']=$asset;}
            $package['posts'][]=$row;
        }
        foreach($menus as $m){$items=[];foreach(wp_get_nav_menu_items($m->term_id,['post_status'=>'publish'])?:[] as $item){$items[]=['source_id'=>$item->ID,'parent_source_id'=>$item->menu_item_parent,'title'=>$item->title,'type'=>$item->type,'object'=>$item->object,'object_ref'=>$item->type==='custom'?0:self::ref($item->type==='taxonomy'?'term':'post',(int)$item->object_id),'url'=>self::encode($item->url),'target'=>$item->target,'classes'=>$item->classes,'xfn'=>$item->xfn,'position'=>$item->menu_order,'link_stijl'=>in_array(get_post_meta($item->ID,'link_stijl',true),['link','btn-primary','btn-secondary'],true)?get_post_meta($item->ID,'link_stijl',true):'link'];}$package['menus'][]=['ref'=>self::$refs['menu:'.$m->term_id],'name'=>$m->name,'slug'=>$m->slug,'items'=>$items];}
        foreach(get_nav_menu_locations() as $location=>$id){if($id){$package['menu_locations'][$location]=self::ref('menu',(int)$id);}}
        $options=$wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'options\_%' OR option_name LIKE '\\_options\_%'",ARRAY_A);
        foreach($options as $option){$name=$option['option_name'];if(str_contains($name,'skeletor_theme_options_roles')){continue;}$fieldkey=get_option('_'.$name);$field=is_string($fieldkey)&&str_starts_with($fieldkey,'field_')?acf_get_field($fieldkey):null;
            if(!$field&&!str_starts_with($name,'_options_')){continue;}$package['options'][$name]=self::encode(maybe_unserialize($option['option_value']),$field?:null,substr($name,8));}
        foreach(['blogname','blogdescription','show_on_front','page_on_front','page_for_posts','permalink_structure','site_icon'] as $key){$v=get_option($key);$package['options'][$key]=in_array($key,['page_on_front','page_for_posts'],true)?self::ref('post',(int)$v):self::encode($v,null,$key);}
        $shared=json_decode(file_get_contents(dirname(__DIR__).'/shared-schema.json'),true);$custom=[];foreach($models as $model){if(!isset($shared[$model['key']])){unset($model['ID'],$model['local']);$custom[$model['key']]=$model;}}foreach(glob(get_stylesheet_directory().'/acf-json/*.json') as $path){$d=json_decode(file_get_contents($path),true);if(!isset($shared[$d['key']])){$custom[$d['key']]=$d;}}$package['models']=array_values($custom);
        if(defined('EMSF_LOCAL')&&EMSF_LOCAL){foreach(glob(Factory::model_dir().'/*.json') as $path){$package['models'][]=json_decode(file_get_contents($path),true);}}
        $fontdir=wp_upload_dir()['basedir'].'/skeletor-fonts';if(is_dir($fontdir)){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fontdir,\FilesystemIterator::SKIP_DOTS)) as $file){if($file->isFile()&&!str_ends_with($file->getFilename(),'.lock')){self::asset($file->getPathname(),'fonts/'.substr($file->getPathname(),strlen($fontdir)+1),$dir,$package);}}}
        $package['models']=self::project_models($package['models']);
        // Fluent Forms definitions only. Submissions, notifications and integrations are excluded.
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.'fluentform_forms'))){foreach($wpdb->get_results("SELECT * FROM {$wpdb->prefix}fluentform_forms",ARRAY_A) as $form){$id=$form['id'];unset($form['id'],$form['created_by']);$metas=$wpdb->get_results($wpdb->prepare("SELECT meta_key,value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id=%d AND meta_key IN ('formSettings','advancedValidation')",$id),ARRAY_A);foreach($metas as &$meta){if($meta['meta_key']==='formSettings'){$settings=json_decode($meta['value'],true);unset($settings['email_notifications'],$settings['integrations'],$settings['webhooks']);$meta['value']=wp_json_encode($settings);}}unset($meta);$package['forms'][]=['ref'=>'form:'.$id,'data'=>$form,'meta'=>$metas];}}
        $package['unsupported']=array_values(array_unique($package['unsupported']));
        self::json($dir.'/manifest.json',$package);return ['posts'=>count($package['posts']),'terms'=>count($package['terms']),'media'=>count($package['files']),'options'=>count($package['options']),'path'=>$dir];
    }
    private static function asset(string $source,string $relative,string $dir,array &$package): void {
        try{Online\Receiver::assetName($relative);}catch(\Throwable $e){throw new \RuntimeException('Pakketbestand heeft een niet-ondersteunde naam: '.$relative.'. Hernoem het bronbestand veilig voordat je opnieuw exporteert.');}
        wp_mkdir_p(dirname($dir.'/files/'.$relative));if(!copy($source,$dir.'/files/'.$relative)){throw new \RuntimeException('Kopiëren media mislukt.');}$package['files'][$relative]=['sha256'=>hash_file('sha256',$source),'size'=>filesize($source)];
    }
    public static function read(string $dir): array {
        $p=json_decode((string)file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if(($p['format']??'')!==self::FORMAT||($p['version']??0)!==1||($p['theme']??'')!=='skeletor'){throw new \RuntimeException('Onbekend pakketformaat.');}
        foreach($p['files'] as $name=>$meta){if(!is_string($name)||str_contains($name,'..')||str_contains($name,'\\')||str_starts_with($name,'/')||!preg_match('/\.(?:jpe?g|png|webp|gif|svg|avif|ico|pdf|woff2?|ttf|otf|css|txt|json)$/i',$name)){throw new \RuntimeException('Onveilig mediapad.');}$root=realpath($dir.'/files');$file=realpath($dir.'/files/'.$name);if(!$root||!$file||!str_starts_with($file,$root.'/')||filesize($file)!==($meta['size']??-1)||!hash_equals($meta['sha256'],hash_file('sha256',$file))){throw new \RuntimeException('Ongeldig pakketbestand: '.$name);}}
        foreach($p['posts'] as $post){if(isset($post['file'])&&!isset($p['files'][$post['file']])){throw new \RuntimeException('Mediabestand ontbreekt in controlesommen.');}}
        $refs=[];foreach(['posts','terms','menus','forms'] as $kind){foreach($p[$kind] as $row){if(isset($refs[$row['ref']])){throw new \RuntimeException('Dubbele pakketreferentie.');}$refs[$row['ref']]=true;}}
        $check=static function($v)use(&$check,$refs){if(is_array($v)){foreach(['$ref','$design'] as $key){if(isset($v[$key])&&!isset($refs[$v[$key]])){throw new \RuntimeException('Onbekende pakketreferentie: '.$v[$key]);}}foreach($v as $child){$check($child);}}};$check($p);
        foreach($p['options'] as $key=>$v){if(!preg_match('/^_?options_/',(string)$key)&&!in_array($key,['blogname','blogdescription','show_on_front','page_on_front','page_for_posts','permalink_structure','site_icon'],true)){throw new \RuntimeException('Optie niet toegestaan: '.$key);}if(str_contains($key,'skeletor_theme_options_roles')){throw new \RuntimeException('Rolrechten zijn niet overdraagbaar.');}}
        // Existing manifests must remain byte-for-byte equivalent when retried.
        return $p;
    }
    /** Remove redundant theme definitions; never install project overrides of shared models. */
    public static function project_models(array $models): array {
        $custom=[];
        foreach($models as $model){
            // Database identity and ACF version defaults do not change the project schema.
            unset($model['ID'],$model['local'],$model['modified']);
            if(str_starts_with($model['key']??'','post_type_')||str_starts_with($model['key']??'','taxonomy_')){
                $model+=['allow_ai_access'=>false,'ai_description'=>''];
            }
            $key=$model['key']??'';
            if(!preg_match('/^(post_type|taxonomy|group)_[a-zA-Z0-9_]+$/D',$key)){throw new \RuntimeException('Ongeldige projectdefinitie.');}
            $path=get_stylesheet_directory().'/acf-json/'.$key.'.json';
            if(is_file($path)){
                $theme=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
                $normalize=static function(array $value): array {
                    unset($value['ID'],$value['local'],$value['modified']);
                    // New ACF releases add these disabled/empty defaults to saved definitions.
                    if(str_starts_with($value['key']??'','post_type_')||str_starts_with($value['key']??'','taxonomy_')){
                        $value+=['allow_ai_access'=>false,'ai_description'=>''];
                    }
                    return $value;
                };
                if($normalize($model)!=$normalize($theme)){throw new \RuntimeException('Projectmodel wijkt inhoudelijk af van gedeeld thema: '.$key.'. Werk de projectdefinitie bij voordat je publiceert.');}
                continue;
            }
            if(isset($custom[$key])&&$custom[$key]!=$model){throw new \RuntimeException('Tegenstrijdige projectdefinities: '.$key);}
            $custom[$key]=$model;
        }
        return array_values($custom);
    }
    public static function models(string $dir): array {
        self::authorize_import();if(is_main_site()){throw new \RuntimeException('Kies een prospectsite.');}$p=self::read($dir);$models=self::project_models($p['models']);$dest=Factory::model_dir();$n=0;
        if (Online\Receiver::$importing) { foreach (glob($dest.'/*.json')?:[] as $old) { if (!unlink($old)) { throw new \RuntimeException('Oud model verwijderen mislukt.'); } } }
        foreach($models as $model){$key=$model['key']??'';if(!preg_match('/^(post_type|taxonomy|group)_[a-zA-Z0-9_]+$/',$key)){throw new \RuntimeException('Ongeldige projectdefinitie.');}if(is_file(get_stylesheet_directory().'/acf-json/'.$key.'.json')){throw new \RuntimeException('Projectmodel overschrijft gedeeld thema.');}self::json($dest.'/'.$key.'.json',$model);$n++;}return ['models'=>$n,'next'=>'Start een nieuw WP-CLI-proces voor de inhoudsimport.'];
    }
    /** Read-only availability check before uploads or site creation. */
    public static function check_models(array $package): void {
        $models=self::project_models($package['models']??[]);
        foreach($models as$model){if(!preg_match('/^(post_type|taxonomy|group)_[a-zA-Z0-9_]+$/',$model['key']??'')){throw new \RuntimeException('Ongeldige projectdefinitie.');}}
        $shared=json_decode((string)file_get_contents(dirname(__DIR__).'/shared-schema.json'),true);
        foreach(glob(wp_get_theme(ThemeRelease::slug())->get_stylesheet_directory().'/acf-json/*.json')?:[] as$path){$model=json_decode((string)file_get_contents($path),true);if(isset($model['key'])){$shared[$model['key']]=$model;}}
        $types=array_fill_keys(get_post_types(),true);$taxonomies=array_fill_keys(get_taxonomies(),true);
        foreach(array_merge(array_values($shared),$models)as$model){if(!empty($model['post_type'])){$types[$model['post_type']]=true;}if(!empty($model['taxonomy'])){$taxonomies[$model['taxonomy']]=true;}}
        if(is_file(wp_get_theme(ThemeRelease::slug())->get_stylesheet_directory().'/src/ThemeTeasers.php')){$types['skeletor_teaser']=true;}
        if(is_file(wp_get_theme(ThemeRelease::slug())->get_stylesheet_directory().'/src/ThemeSectionLibrary.php')){$types['skeletor_sections']=true;}
        foreach($package['posts']??[]as$row){$type=$row['post']['post_type']??'';if(!isset($types[$type])){throw new \RuntimeException('Ontbrekend inhoudstype: '.$type.'. Voeg het projectmodel toe vóór publicatie.');}}
        foreach($package['terms']??[]as$row){$taxonomy=$row['taxonomy']??'';if(!isset($taxonomies[$taxonomy])){throw new \RuntimeException('Ontbrekende taxonomie: '.$taxonomy);}}
    }
    private static function register_models(array $package): void {
        // switch_to_blog/switch_theme do not rerun the destination theme's init hooks.
        if(in_array('skeletor_teaser',array_column(array_column($package['posts']??[],'post'),'post_type'),true)&&!post_type_exists('skeletor_teaser')){
            $root=wp_get_theme(ThemeRelease::slug())->get_stylesheet_directory().'/src/';
            foreach(['ThemeOptionsAccess','ThemePalette','ThemeCards','ThemeImageRatios','ThemeContentLayouts','ThemeTeasers'] as $class){if(!class_exists($class,false)){require_once $root.$class.'.php';}}
            // Use the theme's exact registration without attaching unrelated constructor hooks.
            (new \ReflectionClass(\ThemeTeasers::class))->newInstanceWithoutConstructor()->register();
        }
        if(in_array('skeletor_sections',array_column(array_column($package['posts']??[],'post'),'post_type'),true)&&!post_type_exists('skeletor_sections')){
            $root=wp_get_theme(ThemeRelease::slug())->get_stylesheet_directory().'/src/';
            foreach(['ThemeOptionsAccess','ThemePalette','ThemeCards','ThemeImageRatios','ThemeContentLayouts','ThemeTeasers','ThemeSectionLibrary'] as $class){if(!class_exists($class,false)){require_once $root.$class.'.php';}}
            (new \ReflectionClass(\ThemeSectionLibrary::class))->newInstanceWithoutConstructor()->register();
        }
        $shared=json_decode(file_get_contents(dirname(__DIR__).'/shared-schema.json'),true);
        foreach(glob(get_stylesheet_directory().'/acf-json/*.json')?:[] as $path){$model=json_decode((string)file_get_contents($path),true);if(isset($model['key'])){$shared[$model['key']]=$model;}}
        foreach(array_merge(array_values($shared),self::project_models($package['models'])) as $model) {
            if (!empty($model['post_type'])) {
                $adapter=acf_get_instance('ACF_Post_Type');
                $args=$adapter->get_post_type_args(array_replace($adapter->get_settings_array(),$model));
                if (Online\Receiver::$importing || !post_type_exists($model['post_type'])) { register_post_type($model['post_type'],$args); }
            }
            if (!empty($model['taxonomy'])) {
                $adapter=acf_get_instance('ACF_Taxonomy');
                $args=$adapter->get_taxonomy_args(array_replace($adapter->get_settings_array(),$model));
                if (Online\Receiver::$importing || !taxonomy_exists($model['taxonomy'])) { register_taxonomy($model['taxonomy'],$model['object_type']??[],$args); }
            }
        }
    }
    public static function import(string $dir,bool $apply=false,bool $replace=false): array {
        global $wpdb;
        self::authorize_import();if(is_main_site()||!get_option('emsf_uuid')){throw new \RuntimeException('Kies een geregistreerde prospectsite.');}$p=self::read($dir);
        self::register_models($p);
        foreach($p['posts'] as $row){if(!post_type_exists($row['post']['post_type'])){throw new \RuntimeException('Ontbrekend inhoudstype: '.$row['post']['post_type']);}}
        foreach($p['terms'] as $row){if(!taxonomy_exists($row['taxonomy'])){throw new \RuntimeException('Ontbrekende taxonomie: '.$row['taxonomy']);}}
        $result=['site'=>get_current_blog_id(),'posts'=>count($p['posts']),'options'=>count($p['options']),'apply'=>$apply];if(!$apply){return $result;}
        $lock=fopen(Factory::state().'/site-'.get_current_blog_id().'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){throw new \RuntimeException('Er draait al een build voor deze site.');}
        $map=$replace?[]:(array)get_option('emsf_import_map',[]);$urls=['{{emsf:home}}'=>home_url(), '{{emsf:fonts}}'=>wp_upload_dir()['baseurl'].'/skeletor-fonts'];$created=[];$build=wp_generate_uuid4();
        $tables=$wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name LIKE %s',$wpdb->esc_like($wpdb->prefix).'%'),ARRAY_A);
        foreach($tables as $table){if(strcasecmp($table['Engine']??'','InnoDB')){flock($lock,LOCK_UN);fclose($lock);throw new \RuntimeException('Import vereist transactionele tabellen.');}}
        $committed=false;$wpdb->query('START TRANSACTION');
        try {
            if ($replace) { Online\Snapshot::clearContent(); }
            require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/file.php';
            foreach($p['terms'] as $row){$id=$map[$row['ref']]??0;$args=['slug'=>$row['slug'],'description'=>''];if($id&&term_exists($id,$row['taxonomy'])){$t=wp_update_term($id,$row['taxonomy'],['name'=>$row['name']]+$args);}else{$t=wp_insert_term($row['name'],$row['taxonomy'],$args);}if(is_wp_error($t)){if($t->get_error_code()==='term_exists'){$map[$row['ref']]=(int)$t->get_error_data();}else{throw new \RuntimeException($t->get_error_message());}}else{$map[$row['ref']]=(int)$t['term_id'];}}
            foreach($p['menus'] as $row){$id=$map[$row['ref']]??0;if(!$id||!wp_get_nav_menu_object($id)){$id=wp_create_nav_menu($row['name']);}if(is_wp_error($id)){throw new \RuntimeException($id->get_error_message());}$map[$row['ref']]=(int)$id;}
            foreach($p['posts'] as $row){$ref=$row['ref'];$id=$map[$ref]??0;if($id&&!get_post($id)){throw new \RuntimeException('Eerder geïmporteerde inhoud ontbreekt: '.$ref);}$post=$row['post'];$post['ID']=$id;$post['post_content']='';$post['post_excerpt']='';$post['post_title']='Import: '.$ref;$id=wp_insert_post(wp_slash($post),true);if(is_wp_error($id)){throw new \RuntimeException($id->get_error_message());}$map[$ref]=(int)$id;
                if(isset($row['file'])){$upload=wp_upload_dir();$target=$upload['basedir'].'/site-factory/'.$row['file'];if(!is_file($target)){wp_mkdir_p(dirname($target));if(!copy($dir.'/files/'.$row['file'],$target)){throw new \RuntimeException('Media opslaan mislukt.');}$created[]=$target;}update_attached_file($id,$target);$urls['{{emsf:url:'.$ref.'}}']=$upload['baseurl'].'/site-factory/'.$row['file'];}
                else{$urls['{{emsf:url:'.$ref.'}}']=get_permalink($id);}
            }
            // Resolve hierarchy before generating page URLs.
            foreach($p['posts'] as $row){$id=$map[$row['ref']];$r=wp_update_post(['ID'=>$id,'post_parent'=>self::decode($row['parent'],$map,$urls)],true);if(is_wp_error($r)){throw new \RuntimeException($r->get_error_message());}}
            if(isset($p['options']['permalink_structure'])){update_option('permalink_structure',$p['options']['permalink_structure']);}
            // The front page must resolve to the site root before decoding links.
            foreach(['show_on_front','page_on_front','page_for_posts'] as $key){if(array_key_exists($key,$p['options'])){update_option($key,self::decode($p['options'][$key],$map,$urls));}}
            global $wp_rewrite; $wp_rewrite->init(); self::register_models($p);
            foreach($p['posts'] as $row){if(!isset($row['file'])){$urls['{{emsf:url:'.$row['ref'].'}}']=get_permalink($map[$row['ref']]);}}
            foreach($p['forms'] as $form){$id=$map[$form['ref']]??0;$data=$form['data'];$data['created_by']=get_current_user_id();if($id){$ok=$wpdb->update($wpdb->prefix.'fluentform_forms',$data,['id'=>$id]);}else{$ok=$wpdb->insert($wpdb->prefix.'fluentform_forms',$data);$id=(int)$wpdb->insert_id;}if($ok===false){throw new \RuntimeException('Formulierimport mislukt.');}$map[$form['ref']]=$id;$urls['{{emsf:'.$form['ref'].'}}']=(string)$id;$wpdb->delete($wpdb->prefix.'fluentform_form_meta',['form_id'=>$id]);foreach($form['meta'] as $meta){if($wpdb->insert($wpdb->prefix.'fluentform_form_meta',['form_id'=>$id]+$meta)===false){throw new \RuntimeException('Formulierinstellingen mislukt.');}}}
            foreach($p['posts'] as $row){$id=$map[$row['ref']];$post=self::decode($row['post'],$map,$urls);$post['ID']=$id;$post['post_parent']=self::decode($row['parent'],$map,$urls);$r=wp_update_post(wp_slash($post),true);if(is_wp_error($r)){throw new \RuntimeException($r->get_error_message());}
                foreach($row['meta'] as $key=>$values){delete_post_meta($id,$key);foreach($values as $value){add_post_meta($id,$key,wp_slash(self::decode($value,$map,$urls)));}}
                foreach($row['terms'] as $tax=>$refs){$r=wp_set_object_terms($id,array_map('intval',self::decode($refs,$map,$urls)),$tax);if(is_wp_error($r)){throw new \RuntimeException($r->get_error_message());}}
                if(isset($row['file'])&&!wp_get_attachment_metadata($id)){wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,get_attached_file($id)));}
            }
            foreach($p['terms'] as $row){$r=wp_update_term($map[$row['ref']],$row['taxonomy'],['parent'=>self::decode($row['parent'],$map,$urls),'description'=>self::decode($row['description'],$map,$urls)]);if(is_wp_error($r)){throw new \RuntimeException($r->get_error_message());}}
            foreach($p['menus'] as $menu){$parents=[];foreach($menu['items'] as $item){$key=$menu['ref'].':item:'.$item['source_id'];$args=['menu-item-title'=>$item['title'],'menu-item-status'=>'publish','menu-item-type'=>$item['type'],'menu-item-object'=>$item['object'],'menu-item-object-id'=>self::decode($item['object_ref'],$map,$urls),'menu-item-url'=>self::decode($item['url'],$map,$urls),'menu-item-target'=>$item['target'],'menu-item-classes'=>implode(' ',$item['classes']),'menu-item-xfn'=>$item['xfn'],'menu-item-position'=>$item['position']];$id=wp_update_nav_menu_item($map[$menu['ref']],$map[$key]??0,$args);if(is_wp_error($id)){throw new \RuntimeException($id->get_error_message());}$map[$key]=$id;update_post_meta($id,'link_stijl',in_array($item['link_stijl']??'link',['link','btn-primary','btn-secondary'],true)?($item['link_stijl']??'link'):'link');$parents[$item['source_id']]=[$id,$item['parent_source_id']];}foreach($parents as [$id,$parent]){update_post_meta($id,'_menu_item_menu_item_parent',$parent?($parents[$parent][0]??0):0);}}
            foreach($p['options'] as $key=>$value){update_option($key,self::decode($value,$map,$urls));}
            set_theme_mod('nav_menu_locations',self::decode($p['menu_locations'],$map,$urls));
            foreach($p['files'] as $name=>$meta){if(str_starts_with($name,'fonts/')){$target=wp_upload_dir()['basedir'].'/skeletor-fonts/'.substr($name,6);if(!is_file($target)){wp_mkdir_p(dirname($target));if(!copy($dir.'/files/'.$name,$target)){throw new \RuntimeException('Font opslaan mislukt.');}$created[]=$target;}}}
            update_option('emsf_import_map',$map,false);update_option('emsf_last_build',$build,false);update_option('blog_public',0);if (defined('EMSF_LOCAL') && EMSF_LOCAL) { Factory::invalidate_approval(); }
            if($wpdb->last_error){throw new \RuntimeException('Databasefout tijdens import.');}if($wpdb->query('COMMIT')===false){throw new \RuntimeException('Commit mislukt.');}$committed=true;flush_rewrite_rules(false);wp_cache_flush();
            self::json(Factory::state().'/build-'.$build.'.json',$result+['success'=>true,'package_sha256'=>hash_file('sha256',$dir.'/manifest.json'),'created_at'=>gmdate('c')]);return $result+['build'=>$build];
        }catch(\Throwable $e){if(!$committed){$wpdb->query('ROLLBACK');foreach($created as $path){self::remove_generated($path);}}wp_cache_flush();self::json(Factory::state().'/build-'.$build.'.json',['success'=>false,'error'=>$e->getMessage(),'site'=>get_current_blog_id()]);throw $e;}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
