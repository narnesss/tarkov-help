<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    if(Illuminate\Support\Facades\DB::getDriverName()!=='mysql'){throw new RuntimeException('CLI ещё не использует MySQL.');}
    $pdo=Illuminate\Support\Facades\DB::connection()->getPdo();
    App\Services\SqlDatabaseAudit::foreignKeys($pdo);
    $files=App\Services\SqlDatabaseAudit::content($pdo,App\Services\ContentFiles::forDatabase(config('database.connections.mysql.database')));
    $curl=curl_init('https://narnes.alwaysdata.net/up?mysql-verification='.time());$driver='';
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['X-Wiki-Proxy-Token: '.config('api.proxy_token')],CURLOPT_HEADERFUNCTION=>function($handle,$line)use(&$driver){if(str_starts_with(strtolower($line),'x-wiki-database:')){$driver=trim(substr($line,16));}return strlen($line);}]);
    curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if($status!==200||$driver!=='mysql'){throw new RuntimeException('PHP сайта ещё не подтвердил MySQL: HTTP '.$status.', driver='.$driver.'. Перезапустите PHP в панели.');}
    $counts=[];foreach(App\Services\SqlDatabaseAudit::tables($pdo) as $table){$counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.App\Services\SqlDatabaseAudit::identifier($table))->fetchColumn();}
    echo json_encode(['driver'=>$driver,'database'=>$pdo->query('SELECT DATABASE()')->fetchColumn(),'content_files'=>$files,'tables'=>$counts],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,'Проверка MySQL: '.$error->getMessage().PHP_EOL);exit(1);}
