<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use RuntimeException;

/** Проверяет все строки и внешние ключи без вывода содержимого аккаунтов и прогресса. */
final class SqlDatabaseAudit
{
    public static function identifier(string $name): string
    {
        if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$name)){throw new RuntimeException('Недопустимое имя SQL-объекта.');}
        return '`'.$name.'`';
    }

    public static function tables(PDO $pdo): array
    {
        $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ?"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            :"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME";
        $tables=$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);sort($tables,SORT_STRING);return $tables;
    }

    /** Сохраняет также внутренние ID составных wiki-связей, использованные в URL Orchid. */
    public static function sqliteSpecification(PDO $source,array $targetColumns): array
    {
        $tables=[];
        foreach(self::tables($source) as $table){
            $columns=$source->query('PRAGMA table_info('.self::identifier($table).')')->fetchAll(PDO::FETCH_ASSOC);
            $primary=array_values(array_filter($columns,fn($column)=>$column['pk']>0));
            usort($primary,fn($a,$b)=>$a['pk']<=>$b['pk']);
            $order=array_column($primary,'name');
            if(in_array('rowid',$targetColumns[$table]??[],true)&&!in_array('rowid',array_column($columns,'name'),true)){
                array_unshift($columns,['name'=>'rowid','type'=>'INTEGER']);$order=['rowid'];
            }
            if($order===[]){throw new RuntimeException('У таблицы нет стабильного порядка: '.$table);}
            $tables[$table]=['columns'=>array_map(fn($column)=>['name'=>$column['name'],'type'=>$column['type']],$columns),'order'=>$order];
        }
        return $tables;
    }

    public static function select(string $table,array $specification): string
    {
        return 'SELECT '.implode(',',array_map(fn($column)=>self::identifier($column['name']).' AS '.self::identifier($column['name']),$specification['columns']))
            .' FROM '.self::identifier($table).' ORDER BY '.implode(',',array_map(self::identifier(...),$specification['order']));
    }

    public static function digest(PDO $pdo,string $table,array $specification): array
    {
        $hash=hash_init('sha256');$count=0;$query=$pdo->query(self::select($table,$specification));
        while($row=$query->fetch(PDO::FETCH_ASSOC)){
            foreach($specification['columns'] as $column){
                $value=$row[$column['name']];
                if($value===null){hash_update($hash,'N;');continue;}
                if(preg_match('/INT|BOOL/i',$column['type'])){$value=(string)(int)$value;}
                elseif(preg_match('/REAL|DOUBLE|FLOAT/i',$column['type'])){$value=sprintf('%.17g',(float)$value);if((float)$value==0){$value='0';}}
                else{$value=(string)$value;}
                hash_update($hash,strlen($value).':'.$value.';');
            }
            hash_update($hash,"\n");$count++;
        }
        $query->closeCursor();return ['rows'=>$count,'sha256'=>hash_final($hash)];
    }

    public static function foreignKeys(PDO $pdo): void
    {
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            if($pdo->query('PRAGMA quick_check')->fetchAll(PDO::FETCH_COLUMN)!==['ok']||$pdo->query('PRAGMA foreign_key_check')->fetchAll()!==[]){throw new RuntimeException('SQLite не прошла проверку целостности.');}
            return;
        }
        $foreigns=[];
        foreach($pdo->query('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC) as $column){
            $foreigns[$column['TABLE_NAME'].'.'.$column['CONSTRAINT_NAME']][]=$column;
        }
        foreach($foreigns as $name=>$columns){
            $joins=[];$required=[];
            foreach($columns as $column){$child='c.'.self::identifier($column['COLUMN_NAME']);$parent='p.'.self::identifier($column['REFERENCED_COLUMN_NAME']);$joins[]=$child.'='.$parent;$required[]=$child.' IS NOT NULL';}
            $first=$columns[0];$sql='SELECT COUNT(*) FROM '.self::identifier($first['TABLE_NAME']).' c LEFT JOIN '.self::identifier($first['REFERENCED_TABLE_NAME']).' p ON '.implode(' AND ',$joins)
                .' WHERE '.implode(' AND ',$required).' AND p.'.self::identifier($first['REFERENCED_COLUMN_NAME']).' IS NULL';
            if((int)$pdo->query($sql)->fetchColumn()!==0){throw new RuntimeException('Нарушен внешний ключ: '.$name);}
        }
    }

    public static function content(PDO $pdo,ContentFiles $files): int
    {
        $checked=[];
        foreach(['quests','content_datasets'] as $table){
            if(!in_array($table,self::tables($pdo),true)){continue;}
            $query=$pdo->query('SELECT payload FROM '.self::identifier($table));
            while(($reference=$query->fetchColumn())!==false){
                if(!preg_match('/^@file:[a-f0-9]{64}$/D',$reference)){throw new RuntimeException('Контент SQL должен содержать только файловые ссылки: '.$table);}
                if(!isset($checked[$reference])){$files->json($reference);$checked[$reference]=true;}
            }
            $query->closeCursor();
        }
        return count($checked);
    }

    public static function schema(PDO $pdo): string
    {
        $schema=[];
        foreach(self::tables($pdo) as $table){$definition=$pdo->query('SHOW CREATE TABLE '.self::identifier($table))->fetch(PDO::FETCH_NUM)[1];$schema[$table]=preg_replace('/ AUTO_INCREMENT=\d+/','',$definition);}
        foreach($pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger){$schema['trigger:'.$trigger['Trigger']]=[$trigger['Event'],$trigger['Table'],$trigger['Timing'],$trigger['Statement']];}
        if(in_array('migrations',self::tables($pdo),true)){$schema['migration_log']=$pdo->query('SELECT migration,batch FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_ASSOC);}
        ksort($schema);return hash('sha256',json_encode($schema,JSON_THROW_ON_ERROR));
    }
}
