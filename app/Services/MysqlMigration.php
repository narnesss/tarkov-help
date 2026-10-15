<?php
declare(strict_types=1);
namespace App\Services;

use Illuminate\Support\Facades\{Artisan,DB,Schema};
use PDO;
use RuntimeException;
use Throwable;

/** Двухэтапный перенос с побайтовой проверкой всех строк и закрытием старого источника. */
final class MysqlMigration
{
    private PDO $target;
    private array $access;
    private string $report;
    private string $source;
    public function __construct(string $accessFile)
    {
        if(DB::getDriverName()!=='sqlite'){throw new RuntimeException('Перенос разрешён только из действующей SQLite.');}
        if(!is_file($accessFile)||is_link($accessFile)||(fileperms($accessFile)&0077)!==0){throw new RuntimeException('Нужен приватный файл подключения 0600.');}
        $this->source=DB::connection()->getDatabaseName();
        $this->access=json_decode(file_get_contents($accessFile),true,flags:JSON_THROW_ON_ERROR);
        foreach(['host','database','username','password'] as $key){if(empty($this->access[$key])||!is_string($this->access[$key])){throw new RuntimeException('Неполные настройки подключения.');}}
        $configuration=[...config('database.connections.mysql'),...$this->access,'driver'=>'mysql','url'=>null,'collation'=>'utf8mb4_nopad_bin'];
        if(!empty($this->access['ssl_ca'])){$configuration['options'][PDO::MYSQL_ATTR_SSL_CA]=$this->access['ssl_ca'];}
        config(['database.connections.mysql_transfer'=>$configuration]);DB::purge('mysql_transfer');
        $this->target=DB::connection('mysql_transfer')->getPdo();
        $version=$this->target->query('SELECT VERSION()')->fetchColumn();
        if(!str_contains($version,'MariaDB')||version_compare($version,'10.11','<')){throw new RuntimeException('Поддерживается MariaDB >= 10.11.');}
        $this->report=$this->source.'.mysql-transfer.json';
    }

    private function fingerprint(): string
    {return hash('sha256',json_encode(array_intersect_key($this->access,array_flip(['host','port','database','username'])),JSON_THROW_ON_ERROR));}

    private function write(array $data): void
    {
        $mask=umask(0077);try{
            $temporary=$this->report.'.partial';
            if(file_put_contents($temporary,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false||!rename($temporary,$this->report)){throw new RuntimeException('Не удалось сохранить отчёт переноса.');}
        }finally{umask($mask);}
    }

    private function previous(): ?array
    {
        if(!is_file($this->report)){return null;}
        if(is_link($this->report)){throw new RuntimeException('Отчёт не должен быть ссылкой.');}
        $previous=json_decode(file_get_contents($this->report),true,flags:JSON_THROW_ON_ERROR);
        if(!hash_equals($previous['target'],$this->fingerprint())){throw new RuntimeException('Отчёт относится к другой базе.');}
        return $previous;
    }

    private function verifyPrevious(array $previous): void
    {
        if(($previous['status']??'')!=='verified'||SqlDatabaseAudit::schema($this->target)!==$previous['schema']){throw new RuntimeException('Предыдущий пробный перенос не подтверждён.');}
        foreach($previous['tables'] as $table=>$specification){
            if(SqlDatabaseAudit::digest($this->target,$table,$specification)!==$previous['digests'][$table]){throw new RuntimeException('Пробная база изменена извне: '.$table);}
        }
    }

    public function run(bool $activate=false): array
    {
        $previous=$this->previous();$tables=SqlDatabaseAudit::tables($this->target);
        if($tables!==[]){if($previous===null){throw new RuntimeException('Целевая база не пуста и не принадлежит этому переносу.');}$this->verifyPrevious($previous);}
        elseif($activate){throw new RuntimeException('Сначала нужен успешный пробный перенос.');}
        if($tables===[]){
            $status=Artisan::call('migrate',['--database'=>'mysql_transfer','--force'=>true]);
            if($status!==0){throw new RuntimeException('Не удалось подготовить схему MariaDB.');}
        }
        $gate=new DatabaseAccess($this->source);$lock=null;$switched=false;$ownsMarker=false;
        $mask=umask(0077);
        try{
            if($activate){
                if(file_exists($gate->marker())){throw new RuntimeException('Источник уже закрыт для обслуживания.');}
                if(file_put_contents($gate->marker(),'mysql migration',LOCK_EX)===false){throw new RuntimeException('Нет маркера обслуживания.');}
                $ownsMarker=true;
                $lock=$gate->acquire(exclusive:true,wait:60);
                if($lock===null){throw new RuntimeException('Не завершились старые запросы.');}
            }
            $backup=$this->source.'.before-mysql-'.($activate?'final-':'trial-').gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
            DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($backup));chmod($backup,0600);
            $source=new PDO('sqlite:'.$backup,options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            SqlDatabaseAudit::foreignKeys($source);
            $contentDirectory=(string)(config('content.directory')?:dirname($this->source).'/content');
            $files=SqlDatabaseAudit::content($source,new ContentFiles($contentDirectory));
            $sourceTables=SqlDatabaseAudit::tables($source);$targetTables=SqlDatabaseAudit::tables($this->target);
            if($sourceTables!==$targetTables){throw new RuntimeException('Наборы таблиц источника и назначения различаются.');}
            $columns=[];
            foreach($targetTables as $table){$columns[$table]=array_column(Schema::connection('mysql_transfer')->getColumns($table),'name');}
            $specifications=SqlDatabaseAudit::sqliteSpecification($source,$columns);
            foreach($specifications as $table=>$specification){
                $expected=array_column($specification['columns'],'name');$actual=$columns[$table];sort($expected);sort($actual);
                if($expected!==$actual){throw new RuntimeException('Набор колонок различается: '.$table);}
            }
            $this->target->exec('SET FOREIGN_KEY_CHECKS=0');$this->target->beginTransaction();
            $digests=[];
            try{
                foreach($targetTables as $table){$this->target->exec('DELETE FROM '.SqlDatabaseAudit::identifier($table));}
                foreach($specifications as $table=>$specification){
                    $names=array_column($specification['columns'],'name');
                    $prefix='INSERT INTO '.SqlDatabaseAudit::identifier($table).' ('.implode(',',array_map(SqlDatabaseAudit::identifier(...),$names)).') VALUES ';
                    $placeholder='('.implode(',',array_fill(0,count($names),'?')).')';$batch=[];$bytes=0;
                    $flush=function()use(&$batch,&$bytes,$prefix,$placeholder): void {
                        if($batch===[]){return;}
                        $insert=$this->target->prepare($prefix.implode(',',array_fill(0,count($batch),$placeholder)));
                        $insert->execute(array_merge(...$batch));$batch=[];$bytes=0;
                    };
                    $rows=$source->query(SqlDatabaseAudit::select($table,$specification));
                    while($row=$rows->fetch(PDO::FETCH_NUM)){
                        foreach($specification['columns'] as $index=>$column){if($row[$index]!==null&&preg_match('/REAL|DOUBLE|FLOAT/i',$column['type'])){$row[$index]=sprintf('%.17g',(float)$row[$index]);}}
                        $size=array_sum(array_map(fn($value)=>strlen((string)$value),$row));
                        if($bytes+$size>524288){$flush();}$batch[]=$row;$bytes+=$size;
                        if(count($batch)>=200){$flush();}
                    }
                    $flush();
                    $digests[$table]=SqlDatabaseAudit::digest($source,$table,$specification);
                    if($digests[$table]!==SqlDatabaseAudit::digest($this->target,$table,$specification)){throw new RuntimeException('Контрольная сумма строк не совпала: '.$table);}
                }
                SqlDatabaseAudit::foreignKeys($this->target);
                if(SqlDatabaseAudit::content($this->target,new ContentFiles($contentDirectory))!==$files){throw new RuntimeException('Различается набор файлов контента.');}
                $this->target->commit();
            }catch(Throwable $error){if($this->target->inTransaction()){$this->target->rollBack();}throw $error;}
            finally{$this->target->exec('SET FOREIGN_KEY_CHECKS=1');}
            // SQLite AUTOINCREMENT не переиспользует даже удалённые ID: сохраняем верхнюю границу.
            foreach($source->query('SELECT name,seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_ASSOC) as $sequence){
                if(in_array($sequence['name'],$targetTables,true)){$this->target->exec('ALTER TABLE '.SqlDatabaseAudit::identifier($sequence['name']).' AUTO_INCREMENT='.(1+(int)$sequence['seq']));}
            }
            $result=['status'=>'verified','target'=>$this->fingerprint(),'source_backup'=>$backup,'tables'=>$specifications,'digests'=>$digests,'schema'=>SqlDatabaseAudit::schema($this->target),'content_files'=>$files,'activated'=>false];
            $this->write($result);
            if($activate){
                $oldGate=config('database.access_path');config(['database.access_path'=>dirname($this->source).'/mysql','content.directory'=>$contentDirectory,'backup.mysql_directory'=>dirname($this->source).'/mysql-backups']);
                try{$snapshot=(new MysqlBackups('mysql_transfer'))->create('deploy');}finally{config(['database.access_path'=>$oldGate]);}
                $result['mysql_backup']=$snapshot['path'];
                $this->activate($contentDirectory,$switched);$result['activated']=true;$this->write($result);
            }
            return ['tables'=>count($digests),'rows'=>array_sum(array_column($digests,'rows')),'content_files'=>$files,'source_backup'=>$backup,'activated'=>$activate];
        }finally{
            if($ownsMarker&&!$switched&&file_exists($gate->marker())){unlink($gate->marker());}
            if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}umask($mask);
        }
    }

    private function activate(string $contentDirectory,bool &$switched): void
    {
        $environment=realpath(base_path('.env'));
        if($environment===false){throw new RuntimeException('Нет production .env.');}
        $before=file_get_contents($environment);$backup=$environment.'.before-mysql-'.gmdate('Ymd-His');
        if(file_put_contents($backup,$before,LOCK_EX)===false){throw new RuntimeException('Нет копии прежних настроек.');}chmod($backup,0600);
        $values=['DB_CONNECTION'=>'mysql','DB_URL'=>'','DB_SOCKET'=>'','DB_HOST'=>$this->access['host'],'DB_PORT'=>(string)($this->access['port']??3306),'DB_DATABASE'=>$this->access['database'],'DB_USERNAME'=>$this->access['username'],'DB_PASSWORD'=>$this->access['password'],
            'DB_COLLATION'=>'utf8mb4_nopad_bin','CONTENT_DIRECTORY'=>$contentDirectory,'DB_ACCESS_PATH'=>dirname($this->source).'/mysql','MYSQL_BACKUP_DIRECTORY'=>dirname($this->source).'/mysql-backups'];
        if(!empty($this->access['ssl_ca'])){$values['MYSQL_ATTR_SSL_CA']=$this->access['ssl_ca'];}
        $after=$before;
        foreach($values as $key=>$value){
            if(preg_match('/[\r\n]/',$value)){throw new RuntimeException('Настройки не должны содержать переносы строк.');}
            $line=$key.'="'.str_replace(['\\','"','$'],['\\\\','\\"','\\$'],$value).'"';
            $after=preg_match('/^'.preg_quote($key,'/').'=/m',$after)?preg_replace_callback('/^'.preg_quote($key,'/').'=.*$/m',fn()=>$line,$after):rtrim($after)."\n".$line."\n";
        }
        if(file_put_contents($environment.'.mysql',$after,LOCK_EX)===false||!rename($environment.'.mysql',$environment)){throw new RuntimeException('Не удалось переключить .env.');}chmod($environment,0600);$switched=true;
        $process=proc_open([PHP_BINARY,base_path('artisan'),'config:cache'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,base_path());
        if(!is_resource($process)||proc_close($process)!==0){throw new RuntimeException('Не обновился config cache; старый источник закрыт, нужен оператор.');}
        // Старые PHP-процессы видят SQLite и остаются закрыты до перезапуска PHP.
    }
}
