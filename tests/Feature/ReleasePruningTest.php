<?php
declare(strict_types=1);
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

/** Очистка незавершённого кода не должна затрагивать работающий релиз и приватные ссылки. */
final class ReleasePruningTest extends TestCase
{
    public function test_pruning_keeps_active_preparing_and_three_completed_releases_without_following_links(): void
    {
        $root=sys_get_temp_dir().'/wiki-prune-'.bin2hex(random_bytes(8));$base=$root.'/tarkov-releases';mkdir($base,0700,true);mkdir($root.'/shared');file_put_contents($root.'/shared/secret','сохранить');
        $paths=[];for($i=1;$i<=7;$i++){$id=sprintf('%040x-%d-1',$i,$i);$path=$base.'/'.$id;mkdir($path);file_put_contents($path.'/code.php','код');if($i<=5){file_put_contents($path.'/REVISION',$id);}touch($path,$i);$paths[$i]=$path;}
        symlink($root.'/shared',$paths[6].'/storage');symlink($root.'/shared/secret',$paths[6].'/.env');
        $link=$base.'/'.sprintf('%040x-8-1',8);symlink($root.'/shared',$link);mkdir($base.'/manual-not-a-release');
        try{
            $process=proc_open([PHP_BINARY,base_path('deployment/alwaysdata/prune-releases.php'),$base,$paths[1],$paths[7]],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($process),$error);
            foreach([1,3,4,5,7] as $i){$this->assertDirectoryExists($paths[$i]);}
            foreach([2,6] as $i){$this->assertDirectoryDoesNotExist($paths[$i]);}
            $this->assertSame('сохранить',file_get_contents($root.'/shared/secret'));$this->assertTrue(is_link($link));$this->assertDirectoryExists($base.'/manual-not-a-release');$this->assertStringContainsString(': 2',$output);
        }finally{(new Filesystem())->deleteDirectory($root);}
    }
}
