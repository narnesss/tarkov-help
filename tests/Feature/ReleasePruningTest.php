<?php
declare(strict_types=1);
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

/** Очистка незавершённого кода не должна затрагивать работающий релиз и приватные ссылки. */
final class ReleasePruningTest extends TestCase
{
    public function test_storage_maintenance_uses_only_fixed_commands_and_never_uploads_or_activates(): void
    {
        $root=sys_get_temp_dir().'/wiki-storage-'.bin2hex(random_bytes(8));mkdir($root.'/bin',0700,true);
        $ssh=<<<'SH'
#!/usr/bin/env bash
set -eu
command=${!#}
case "$command" in
  "bash -s -- 'storage-check'"|"bash -s -- 'storage-clean'") ;;
  *) exit 66 ;;
esac
printf '%s\n' "$command" >> "$STORAGE_TEST_ROOT/commands"
cat > "$STORAGE_TEST_ROOT/script"
SH;
        file_put_contents($root.'/bin/ssh',$ssh);chmod($root.'/bin/ssh',0700);
        try{
            foreach(['storage-check','storage-clean','storage-unknown'] as $mode){
                $process=new \Symfony\Component\Process\Process(['bash',base_path('deployment/alwaysdata/github-deploy.sh'),$mode],base_path(),[
                    'PATH'=>$root.'/bin:'.getenv('PATH'),'STORAGE_TEST_ROOT'=>$root,'RUNNER_TEMP'=>$root,
                    'ALWAYSDATA_SSH_KEY'=>'isolated-key','ALWAYSDATA_KNOWN_HOSTS'=>'isolated-host',
                    'GITHUB_SHA'=>str_repeat('a',40),'GITHUB_RUN_ID'=>'1','GITHUB_RUN_ATTEMPT'=>'1','ALWAYSDATA_API_KEY'=>false,
                ]);$process->setTimeout(15);$process->run();$this->assertSame($mode==='storage-unknown'?1:0,$process->getExitCode(),$process->getErrorOutput());
            }
            $this->assertSame(["bash -s -- 'storage-check'","bash -s -- 'storage-clean'"],file($root.'/commands',FILE_IGNORE_NEW_LINES));
            $script=file_get_contents($root.'/script');$this->assertStringContainsString('composer clear-cache --no-interaction',$script);
            $this->assertStringContainsString('prune-releases.php',$script);$this->assertStringContainsString('flock -w 60',$script);
            $this->assertDoesNotMatchRegularExpression('/\b(?:rm|migrate|restore|scp)\b/',$script);
        }finally{(new Filesystem())->deleteDirectory($root);}
    }

    public function test_deploy_frees_quota_before_creating_remote_key_or_uploading_archive(): void
    {
        $root=sys_get_temp_dir().'/wiki-quota-'.bin2hex(random_bytes(8));mkdir($root.'/bin',0700,true);
        $ssh=<<<'SH'
#!/usr/bin/env bash
set -eu
command=${!#}
if [[ $command = *'php /dev/stdin'* ]]; then
 cat >/dev/null; touch "$QUOTA_TEST_ROOT/freed"; echo cleanup >> "$QUOTA_TEST_ROOT/events"
elif [[ $command = *'.alwaysdata-api-key'* ]]; then
 test -f "$QUOTA_TEST_ROOT/freed" || { echo 'Disk quota exceeded' >&2; exit 65; }
 cat >/dev/null; echo key >> "$QUOTA_TEST_ROOT/events"
elif [[ $command = *'mkdir -p'* ]]; then
 echo directory >> "$QUOTA_TEST_ROOT/events"
elif [[ $command = *'bash -s --'* ]]; then
 cat >/dev/null; echo activate >> "$QUOTA_TEST_ROOT/events"
else exit 66; fi
SH;
        $scp=<<<'SH'
#!/usr/bin/env bash
set -eu
test -f "$QUOTA_TEST_ROOT/freed" || exit 67
echo upload >> "$QUOTA_TEST_ROOT/events"
SH;
        foreach(['ssh'=>$ssh,'scp'=>$scp] as $name=>$source){file_put_contents($root.'/bin/'.$name,str_replace("\r\n","\n",$source));chmod($root.'/bin/'.$name,0700);}
        try{
            $process=new \Symfony\Component\Process\Process(['bash',base_path('deployment/alwaysdata/github-deploy.sh')],base_path(),[
                'PATH'=>$root.'/bin:'.getenv('PATH'),'QUOTA_TEST_ROOT'=>$root,'RUNNER_TEMP'=>$root,
                'ALWAYSDATA_SSH_KEY'=>'isolated-ssh-key','ALWAYSDATA_KNOWN_HOSTS'=>'isolated-host',
                'ALWAYSDATA_API_KEY'=>'isolated-api-key-only','GITHUB_SHA'=>str_repeat('a',40),'GITHUB_RUN_ID'=>'1','GITHUB_RUN_ATTEMPT'=>'1',
            ]);$process->setTimeout(15);$process->run();
            $this->assertSame(0,$process->getExitCode(),$process->getErrorOutput());
            $this->assertSame(['cleanup','key','directory','upload','activate'],file($root.'/events',FILE_IGNORE_NEW_LINES));
            $this->assertStringNotContainsString('isolated-api-key-only',$process->getOutput().$process->getErrorOutput());
        }finally{(new Filesystem())->deleteDirectory($root);}
    }
    public function test_pruning_keeps_active_preparing_and_three_completed_releases_without_following_links(): void
    {
        $root=sys_get_temp_dir().'/wiki-prune-'.bin2hex(random_bytes(8));$base=$root.'/tarkov-releases';mkdir($base,0700,true);mkdir($root.'/shared');file_put_contents($root.'/shared/secret','сохранить');
        mkdir($root.'/tarkov-incoming');
        $paths=[];for($i=1;$i<=9;$i++){
            if($i===8)continue;
            $id=sprintf('%040x-%d-1',$i,$i);$path=$base.'/'.$id;mkdir($path);file_put_contents($path.'/code.php','код');
            file_put_contents($path.'/REVISION',$id);
            if($i<=3||$i===6){mkdir($path.'/bootstrap/cache',0700,true);file_put_contents($path.'/bootstrap/cache/config.php','<?php return [];');}
            if(in_array($i,[4,5],true))file_put_contents($path.'/PUBLISHED',$id);
            if(in_array($i,[6,7],true))file_put_contents($path.'/.publication-pending',$id);
            if($i!==6)file_put_contents($root.'/tarkov-incoming/'.$id.'.tar.gz','архив кода');
            touch($path,$i);$paths[$i]=$path;
        }
        symlink($root.'/shared/secret',$root.'/tarkov-incoming/'.basename($paths[6]).'.tar.gz');
        file_put_contents($root.'/tarkov-incoming/manual.tar.gz','сохранить');
        symlink($root.'/shared',$paths[6].'/storage');symlink($root.'/shared/secret',$paths[6].'/.env');
        $link=$base.'/'.sprintf('%040x-8-1',8);symlink($root.'/shared',$link);mkdir($base.'/manual-not-a-release');
        try{
            $process=proc_open([PHP_BINARY,base_path('deployment/alwaysdata/prune-releases.php'),$base,$paths[1],$paths[7]],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($process),$error);
            foreach([1,3,4,5,7] as $i){$this->assertDirectoryExists($paths[$i]);}
            foreach([2,6,9] as $i){$this->assertDirectoryDoesNotExist($paths[$i]);}
            foreach([2,9] as $i)$this->assertFileDoesNotExist($root.'/tarkov-incoming/'.basename($paths[$i]).'.tar.gz');
            foreach([1,3,4,5,7] as $i)$this->assertFileExists($root.'/tarkov-incoming/'.basename($paths[$i]).'.tar.gz');
            $this->assertTrue(is_link($root.'/tarkov-incoming/'.basename($paths[6]).'.tar.gz'));
            $this->assertSame('сохранить',file_get_contents($root.'/tarkov-incoming/manual.tar.gz'));
            $this->assertSame('сохранить',file_get_contents($root.'/shared/secret'));$this->assertTrue(is_link($link));$this->assertDirectoryExists($base.'/manual-not-a-release');$this->assertStringContainsString(': 3',$output);
        }finally{(new Filesystem())->deleteDirectory($root);}
    }
}
