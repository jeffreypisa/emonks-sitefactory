<?php
declare(strict_types=1);
namespace Emonks\Factory;

/** The release number is authoritative; file digests remain diagnostic metadata. */
final class ThemeRelease {
    private static ?string $requestSlug = null;
    public static function validateSlug(string $slug): string {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $slug)) { throw new \RuntimeException('Gebruik voor de themamap alleen letters, cijfers, streepjes en underscores.'); }
        return $slug;
    }
    public static function slug(): string {
        return self::$requestSlug ?? self::validateSlug((string)(function_exists('get_site_option') ? get_site_option('emsf_theme_slug', 'skeletor') : 'skeletor'));
    }
    public static function remoteSlug(): string {
        return self::validateSlug((string)get_site_option('emsf_remote_theme_slug', 'skeletor'));
    }
    public static function useRequestSlug(string $slug): void { self::$requestSlug = self::validateSlug($slug); }
    public static function pluginVersion(): string {
        preg_match('/^ \* Version:\s*(\S+)/m', (string)file_get_contents(dirname(__DIR__).'/emonks-site-factory.php'), $match);
        return $match[1] ?? '';
    }
    public static function environment(): array {
        $theme = wp_get_theme(self::slug());
        return [
            'theme'=>['slug'=>self::slug(), 'name'=>$theme->get('Name'), 'version'=>self::version(), 'installed'=>$theme->exists(), 'enabled'=>$theme->exists() && $theme->is_allowed('network')],
            'plugin'=>['version'=>self::pluginVersion()],
            'acf'=>['active'=>function_exists('acf_get_field'), 'pro'=>defined('ACF_PRO') && ACF_PRO, 'version'=>defined('ACF_VERSION') ? ACF_VERSION : ''],
            'hosting'=>['verified'=>defined('EMSF_PRIVATE_HOSTING_VERIFIED') && EMSF_PRIVATE_HOSTING_VERIFIED],
        ];
    }
    public static function version(): string {
        return trim((string)wp_get_theme(self::slug())->get('Version'));
    }
    public static function valid(string $version): bool {
        return (bool)preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/D', $version);
    }
    public static function compare(string $installed, string $expected): string {
        $installed=trim($installed);$expected=trim($expected);
        if(!self::valid($installed)||!self::valid($expected)){return 'unknown';}
        $order=version_compare($installed,$expected);
        return $installed===$expected?'current':($order<0?'behind':'ahead');
    }
    public static function requireVersion(array $dependencies): void {
        $expected=is_string($dependencies['theme_version']??null)?trim($dependencies['theme_version']):'';
        $installed=self::version();
        if(self::compare($installed,$expected)!=='current'){
            throw new \RuntimeException('Skeletor-versie wijkt af of ontbreekt. Verwacht: '.($expected?:'geen versienummer').'; geïnstalleerd: '.($installed?:'geen versienummer').'. Installeer dezelfde thema-uitgave. Is het pakket ouder? Annuleer de openstaande upload onder Beheer & herstel en maak een nieuw pakket.');
        }
    }
    /** Read data only, never execute or render Markdown from the theme. */
    public static function releases(): array {
        $file=wp_get_theme(self::slug())->get_stylesheet_directory().'/releases.json';
        if(!is_readable($file)||filesize($file)>256*1024){return [];}
        $data=json_decode((string)file_get_contents($file),true);
        if(!is_array($data)||($data['format']??'')!=='skeletor-releases-v1'||!is_array($data['releases']??null)){return [];}
        $releases=[];
        foreach(array_slice($data['releases'],0,100) as $release){
            if(!is_array($release)||!is_string($release['version']??null)||!self::valid($release['version'])||!is_string($release['date']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$release['date'])||!is_array($release['changes']??null)){continue;}
            $changes=array_values(array_filter(array_slice($release['changes'],0,50),static fn($note)=>is_string($note)&&trim($note)!==''&&strlen($note)<=2000));
            if($changes){$releases[]=['version'=>$release['version'],'date'=>$release['date'],'changes'=>$changes];}
        }
        usort($releases,static fn($a,$b)=>version_compare($b['version'],$a['version']));
        return $releases;
    }
}
