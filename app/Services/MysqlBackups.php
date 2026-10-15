<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/** Приватные согласованные SQL gzip-снимки MariaDB с проверяемым восстановлением. */
final class MysqlBackups
{
    private PDO $pdo;
    private array $configuration;
    private string $directory;
    public function __construct(private string $connection='mysql')
    {
        $database=DB::connection($connection);
        if($database->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'){throw new RuntimeException('Нужен MySQL/MariaDB.');}
        $this->pdo=$database->getPdo();$this->configuration=$database->getConfig();
        $this->directory=(string)(config('backup.mysql_directory')?:storage_path('app/mysql-backups'));
        if(!str_starts_with($this->directory,'/')||is_link($this->directory)){throw new RuntimeException('Нужен приватный абсолютный каталог MySQL-копий.');}
        $mask=umask(0077);
        try{if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)){throw new RuntimeException('Нет каталога резервных копий.');}}finally{umask($mask);}
    }

    private function locked(callable $callback): mixed
    {
        $path=$this->directory.'/.backup.lock';if(is_link($path)){throw new RuntimeException('Блокировка не должна быть ссылкой.');}
        $mask=umask(0077);try{$lock=fopen($path,'c+');}finally{umask($mask);}
        if(!$lock){throw new RuntimeException('Нет блокировки копий.');}
        try{if(!flock($lock,LOCK_EX)){throw new RuntimeException('Не удалось заблокировать копии.');}return $callback();}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    public function create(string $kind='daily'): array
    {
        $access=(new DatabaseAccess(DatabaseAccess::configuredPath()))->acquire(wait:60);
        if($access===null){throw new RuntimeException('База закрыта для переноса или восстановления.');}
        try{return $this->locked(fn()=>$this->snapshot($kind));}finally{flock($access,LOCK_UN);fclose($access);}
    }

    private function snapshot(string $kind): array
    {
        if(!in_array($kind,['daily','deploy'],true)){throw new RuntimeException('Неизвестный вид копии.');}
        if($kind==='daily'){
            foreach($this->copies() as $file){if(str_starts_with(basename($file),'mysql-daily-'.gmdate('Ymd'))){
                try{$this->verify($file);return $this->prune($file,false);}catch(Throwable){}
            }}
        }
        SqlDatabaseAudit::foreignKeys($this->pdo);
        $files=SqlDatabaseAudit::content($this->pdo,ContentFiles::forDatabase($this->configuration['database']));
        $path=$this->directory.'/mysql-'.$kind.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql.gz';
        $partial=$path.'.partial';
        try{
            $this->client('dump',$partial);
            if(filesize($partial)>(int)config('backup.max_bytes')){throw new RuntimeException('Снимок MySQL превышает бюджет резервных копий.');}
            $references=$this->pdo->query('SELECT payload FROM quests UNION SELECT payload FROM content_datasets')->fetchAll(PDO::FETCH_COLUMN);
            $manifest=['sha256'=>hash_file('sha256',$partial),'schema'=>SqlDatabaseAudit::schema($this->pdo),'content_files'=>$files,'references'=>$references,'database'=>$this->configuration['database']];
            if(!rename($partial,$path)||file_put_contents($path.'.manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR),LOCK_EX)===false){throw new RuntimeException('Не удалось опубликовать копию.');}
            chmod($path,0600);chmod($path.'.manifest.json',0600);$this->verify($path);
            return $this->prune($path,true);
        }finally{if(is_file($partial)&&!is_link($partial)){unlink($partial);}}
    }

    /** Пароль получает только native-клиент через временный файл 0600, без аргументов и логов. */
    private function client(string $mode,string $archive): void
    {
        $binary=null;
        foreach($mode==='dump'?['mariadb-dump','mysqldump']:['mariadb','mysql'] as $candidate){
            foreach(explode(PATH_SEPARATOR,(string)getenv('PATH')) as $directory){if(is_file($directory.'/'.$candidate)&&is_executable($directory.'/'.$candidate)){$binary=$directory.'/'.$candidate;break 2;}}
        }
        if($binary===null){throw new RuntimeException('На сервере нужен MariaDB/MySQL CLI-клиент для копий.');}
        $options=$this->directory.'/.client-'.bin2hex(random_bytes(8)).'.cnf';$errors=$options.'.errors';
        $quote=fn($value)=>'"'.str_replace(['\\','"',"\n","\r"],['\\\\','\\"','\\n','\\r'],(string)$value).'"';
        $configuration="[client]\nuser=".$quote($this->configuration['username'])."\npassword=".$quote($this->configuration['password'])."\ndefault-character-set=utf8mb4\n";
        if(!empty($this->configuration['unix_socket'])){$configuration.='socket='.$quote($this->configuration['unix_socket'])."\n";}
        else{$configuration.='host='.$quote($this->configuration['host'])."\nport=".(int)$this->configuration['port']."\n";}
        if($ca=$this->configuration['options'][PDO::MYSQL_ATTR_SSL_CA]??null){$configuration.='ssl-ca='.$quote($ca)."\nssl-verify-server-cert\n";}
        $mask=umask(0077);$process=null;$gzip=null;$pipes=[];
        try{
            if(file_put_contents($options,$configuration,LOCK_EX)===false){throw new RuntimeException('Нет файла настроек клиента.');}
            $command=[$binary,'--defaults-extra-file='.$options];
            if($mode==='dump'){$command=[...$command,'--single-transaction','--quick','--skip-lock-tables','--hex-blob','--skip-comments','--no-tablespaces',$this->configuration['database']];}
            else{$command[]=$this->configuration['database'];}
            $descriptors=$mode==='dump'?[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$errors,'w']]:[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$errors,'w']];
            $process=proc_open($command,$descriptors,$pipes);
            if(!is_resource($process)){throw new RuntimeException('Не удалось запустить SQL-клиент.');}
            $gzip=gzopen($archive,$mode==='dump'?'wb6':'rb');if(!$gzip){throw new RuntimeException('Не удалось открыть gzip-снимок.');}
            if($mode==='dump'){
                while(!feof($pipes[1])){$chunk=fread($pipes[1],1048576);if($chunk===false||gzwrite($gzip,$chunk)!==strlen($chunk)){throw new RuntimeException('Ошибка записи снимка.');}}fclose($pipes[1]);
            }else{
                while(!gzeof($gzip)){$chunk=gzread($gzip,1048576);if($chunk===false){throw new RuntimeException('Ошибка чтения снимка.');}$offset=0;while($offset<strlen($chunk)){$written=@fwrite($pipes[0],substr($chunk,$offset));if(!$written){throw new RuntimeException('Клиент остановил восстановление.');}$offset+=$written;}}fclose($pipes[0]);
            }
            gzclose($gzip);$gzip=null;$status=proc_close($process);$process=null;
            if($status!==0){throw new RuntimeException('SQL-клиент завершился ошибкой '.$status.'. Содержимое БД не выводится в журнал.');}
        }finally{
            if(is_resource($gzip)){gzclose($gzip);}foreach($pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}
            if(is_resource($process)){proc_terminate($process);proc_close($process);}
            foreach([$options,$errors] as $temporary){if(is_file($temporary)&&!is_link($temporary)){unlink($temporary);}}umask($mask);
        }
    }

    private function copies(): array
    {
        $files=[];foreach(glob($this->directory.'/mysql-*.sql.gz')?:[] as $file){
            if(is_file($file)&&!is_link($file)&&preg_match('/^mysql-(daily|deploy)-\d{8}-\d{6}-[a-f0-9]{6}\.sql\.gz$/D',basename($file))){$files[]=$file;}
        }
        usort($files,fn($a,$b)=>strcmp(preg_replace('/^mysql-(daily|deploy)-/','',basename($b)),preg_replace('/^mysql-(daily|deploy)-/','',basename($a))));
        return $files;
    }

    private function verify(string $path): array
    {
        $manifest=$path.'.manifest.json';
        if(!is_file($path)||is_link($path)||!is_file($manifest)||is_link($manifest)){throw new RuntimeException('Нет обычного снимка и его манифеста.');}
        $data=json_decode(file_get_contents($manifest),true,flags:JSON_THROW_ON_ERROR);
        if(!hash_equals((string)$data['sha256'],hash_file('sha256',$path))){throw new RuntimeException('Нарушена контрольная сумма MySQL-копии.');}
        $files=ContentFiles::forDatabase($this->configuration['database']);foreach($data['references'] as $reference){$files->json($reference);}
        $gzip=gzopen($path,'rb');$bytes=0;
        try{while(!gzeof($gzip)){$chunk=gzread($gzip,1048576);if($chunk===false){throw new RuntimeException('Повреждён gzip-снимок.');}$bytes+=strlen($chunk);}}
        finally{gzclose($gzip);}
        if($bytes===0){throw new RuntimeException('Пустая копия.');}return $data;
    }

    private function prune(string $latest,bool $created): array
    {
        $bytes=0;$count=0;$deleted=0;$kinds=['daily'=>0,'deploy'=>0];
        $files=$this->copies();$files=array_values(array_unique([$latest,...$files]));
        foreach($files as $file){$kind=str_starts_with(basename($file),'mysql-daily-')?'daily':'deploy';$size=filesize($file);
            $keep=$file===$latest||($kinds[$kind]<(int)config('backup.'.$kind.'_keep')&&$bytes+$size<=(int)config('backup.max_bytes'));
            if($keep){$bytes+=$size;$count++;$kinds[$kind]++;continue;}
            unlink($file);$manifest=$file.'.manifest.json';if(is_file($manifest)&&!is_link($manifest)){unlink($manifest);}$deleted++;
        }
        return ['path'=>$latest,'created'=>$created,'deleted'=>$deleted,'count'=>$count,'bytes'=>$bytes];
    }

    public function catalog(): array
    {
        return $this->locked(fn()=>array_map(function($file){try{$this->verify($file);$valid=true;}catch(Throwable){$valid=false;}return ['name'=>basename($file),'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file),'valid'=>$valid];},$this->copies()));
    }
    private function selected(string $name,string $sha256): string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha256)){throw new RuntimeException('Нужен SHA-256 копии.');}
        foreach($this->copies() as $file){if(basename($file)===$name){$manifest=$this->verify($file);
            if(!hash_equals($sha256,hash_file('sha256',$file))||$manifest['schema']!==SqlDatabaseAudit::schema($this->pdo)){throw new RuntimeException('Снимок изменён или схема несовместима.');}return $file;}}
        throw new RuntimeException('Копия не найдена.');
    }
    public function check(string $name,string $sha256): void {$this->locked(fn()=>$this->selected($name,$sha256));}

    public function restore(string $name,string $sha256,callable $validate): array
    {
        $gate=new DatabaseAccess(DatabaseAccess::configuredPath());$access=$gate->acquire(exclusive:true,wait:60);
        if($access===null){throw new RuntimeException('Не завершились запросы к базе.');}
        try{return $this->locked(function()use($name,$sha256,$validate,$gate){
            $selected=$this->selected($name,$sha256);$temporary=$this->directory.'/.selected-'.bin2hex(random_bytes(8)).'.sql.gz';$clear=true;
            if(!copy($selected,$temporary)){throw new RuntimeException('Не удалось сохранить выбранную копию.');}chmod($temporary,0600);
            try{
                $safety=$this->snapshot('deploy');file_put_contents($gate->marker(),'mysql restore',LOCK_EX);chmod($gate->marker(),0600);
                try{
                    $this->client('restore',$temporary);SqlDatabaseAudit::foreignKeys($this->pdo);SqlDatabaseAudit::content($this->pdo,ContentFiles::forDatabase($this->configuration['database']));$validate();
                    $this->pdo->exec('DELETE FROM sessions');$this->pdo->exec('DELETE FROM admin_sessions');
                    $this->pdo->exec('UPDATE admin_users SET remember_token=NULL');
                }catch(Throwable $error){
                    try{$this->client('restore',$safety['path']);SqlDatabaseAudit::foreignKeys($this->pdo);}catch(Throwable){$clear=false;throw new RuntimeException('Аварийный возврат не завершён; доступ остаётся закрыт. Копия: '.basename($safety['path']));}
                    throw $error;
                }
                return ['source'=>$name,'safety'=>basename($safety['path'])];
            }finally{unlink($temporary);if($clear&&is_file($gate->marker())){unlink($gate->marker());}}
        });}finally{flock($access,LOCK_UN);fclose($access);}
    }
}
