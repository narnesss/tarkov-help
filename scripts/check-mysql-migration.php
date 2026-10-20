<?php
declare(strict_types=1);

// Только изолированная CI-БД. Production-реквизиты этот сценарий не читает.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{Artisan,DB};
use App\Services\{ContentFiles,MysqlBackups,MysqlMigration,SqlDatabaseAudit};

try {
    $host=getenv('MYSQL_TEST_HOST')?:'127.0.0.1';$socket=getenv('MYSQL_TEST_SOCKET')?:'';
    $password=getenv('MYSQL_TEST_PASSWORD')?:'';
    $admin=new PDO($socket?'mysql:unix_socket='.$socket:'mysql:host='.$host,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    // Имена фиксированы: запрещено подставлять произвольную или production-БД.
    $admin->exec('DROP DATABASE IF EXISTS tarkov_transfer_test');
    $admin->exec('CREATE DATABASE tarkov_transfer_test CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin');
    $admin->exec("CREATE USER IF NOT EXISTS 'tarkov_transfer_test'@'%' IDENTIFIED BY 'local-transfer-test-only'");
    $admin->exec("GRANT ALL PRIVILEGES ON tarkov_transfer_test.* TO 'tarkov_transfer_test'@'%'");
    $directory=sys_get_temp_dir().'/tarkov-transfer-'.bin2hex(random_bytes(8));mkdir($directory,0700,true);
    $source=$directory.'/source.sqlite';touch($source);chmod($source,0600);
    config(['database.default'=>'sqlite','database.connections.sqlite.database'=>$source,'content.directory'=>$directory.'/content','backup.mysql_directory'=>$directory.'/backups']);DB::purge();
    if(Artisan::call('migrate',['--force'=>true])!==0){throw new RuntimeException('Не создан источник.');}
    $pdo=DB::connection()->getPdo();
    $pdo->prepare('INSERT INTO users(id,username,password_hash,created_at) VALUES (?,?,?,?)')->execute(['stable-player','Игрок',password_hash('keep-player-password',PASSWORD_DEFAULT),time()]);
    $pdo->prepare('INSERT INTO user_state(user_id,state_key,value,version,updated_at) VALUES (?,?,?,?,?)')->execute(['stable-player','wiki-ui-v1',json_encode(['query'=>str_repeat('Сохранить ',15000)],JSON_UNESCAPED_UNICODE),42,time()]);
    $pdo->prepare('INSERT INTO sessions(token_hash,user_id,expires_at) VALUES (?,?,?)')->execute([str_repeat('a',64),'stable-player',time()+86400]);
    $editor=App\Models\User::create(['name'=>'Редактор','email'=>'transfer@example.test','password'=>bcrypt('keep-admin-password'),'permissions'=>['wiki.content'=>true]]);
    DB::table('admin_sessions')->insert(['id'=>'keep-editor-session','user_id'=>$editor->id,'payload'=>'opaque-session-payload','last_activity'=>time()]);
    $ref=(new ContentFiles($directory.'/content'))->put(['id'=>'stable-quest','name'=>'Квест для переноса','objectives'=>[['id'=>'stable-goal','description'=>'Сохранённая цель']]]);
    DB::table('quests')->insert(['id'=>91,'quest_id'=>'stable-quest','mode'=>'pve','name'=>'Квест для переноса','published'=>0,'payload'=>$ref,'created_at'=>'2026-10-15 12:34:56','updated_at'=>'2026-10-15 12:34:56']);
    DB::table('quests')->insert(['id'=>120,'quest_id'=>'deleted-test','mode'=>'pve','name'=>'Удалённый ID','published'=>0,'payload'=>$ref]);DB::table('quests')->where('id',120)->delete();
    // Реальные составные PK, скрытые SQLite rowid и точные DOUBLE.
    DB::table('wiki_locations')->insert(['id'=>7,'name'=>'Маяк','slug'=>'lighthouse','external_id'=>'game-map']);
    DB::table('wiki_items')->insert(['id'=>37,'external_id'=>'key','name'=>'Ключ','search_text'=>'ключ','category'=>'key']);
    DB::table('wiki_keys')->insert(['item_id'=>37]);
    DB::table('wiki_versions')->insert(['id'=>6,'code'=>'transfer','patch'=>'0.0','valid_from'=>'2026-10-15']);
    DB::table('wiki_maps')->insert(['id'=>8,'location_id'=>7,'version_id'=>6,'slug'=>'map','image_url'=>'/map.png','width'=>1000,'height'=>1000]);
    DB::table('wiki_map_points')->insert(['id'=>9,'location_id'=>7,'title'=>'Архивная точка','external_id'=>'point','search_text'=>'архивная точка','archived'=>1]);
    DB::table('wiki_key_usages')->insert(['point_id'=>9,'key_item_id'=>37,'usage_type'=>'unlock']);
    DB::table('wiki_point_positions')->insert(['point_id'=>9,'location_id'=>7,'map_id'=>8,'x_percent'=>12.123456789012345,'y_percent'=>99.98765432109876,'archived'=>1]);
    DB::table('wiki_map_layers')->insert(['id'=>10,'map_id'=>8,'external_id'=>'floor--1','name'=>'Подземный этаж']);
    DB::table('wiki_document_exit_positions')->insert(['id'=>17,'point_id'=>9,'map_id'=>8,'layer_id'=>10,'x_percent'=>23.1234567890123,'y_percent'=>78.9876543210987,'revision'=>4]);
    DB::table('wiki_document_map_audit')->insert(['event_id'=>'00000000-0000-4000-8000-000000000001','actor_ref'=>(string)$editor->id,'point_id'=>9,'entity_type'=>'move_exit','result'=>'success',
        'revision_before'=>str_repeat('a',64),'revision_after'=>str_repeat('b',64),'changed_fields'=>'floor,x_percent,y_percent','recorded_at'=>1792454400,'previous_hash'=>str_repeat('0',64),'signature'=>str_repeat('c',64)]);
    $access=$directory.'/access.json';file_put_contents($access,json_encode(['host'=>$host,'port'=>3306,'database'=>'tarkov_transfer_test','username'=>'tarkov_transfer_test','password'=>'local-transfer-test-only','unix_socket'=>$socket],JSON_THROW_ON_ERROR));chmod($access,0600);
    $migration=new MysqlMigration($access);$result=$migration->run();
    if($result['activated']||$result['rows']<10){throw new RuntimeException('Пробный перенос не подтверждён.');}
    $mysql=DB::connection('mysql_transfer')->getPdo();
    if($mysql->query("SELECT value FROM user_state WHERE user_id='stable-player'")->fetchColumn()!==$pdo->query("SELECT value FROM user_state WHERE user_id='stable-player'")->fetchColumn()){throw new RuntimeException('Изменился большой прогресс.');}
    $migration->run();
    $mysql->exec("UPDATE wiki_locations SET name='Изменение после проверки' WHERE id=7");
    try{$migration->run();throw new LogicException('Изменённая пробная база была перезаписана.');}catch(RuntimeException $expected){if(!str_contains($expected->getMessage(),'изменена извне')){throw $expected;}}
    $mysql->exec("UPDATE wiki_locations SET name='Маяк' WHERE id=7");
    $mysql->exec("INSERT INTO quests(quest_id,mode,name,published,payload) VALUES ('new-id','pve','После переноса',0,".$mysql->quote($ref).")");
    if((int)$mysql->lastInsertId()<=120){throw new RuntimeException('Повторно использован удалённый ID.');}
    config(['database.default'=>'mysql_transfer','database.access_path'=>$directory.'/mysql']);
    $backups=new MysqlBackups('mysql_transfer');$copy=$backups->create('deploy');$sha=hash_file('sha256',$copy['path']);
    $mysql->exec("UPDATE users SET username='После снимка' WHERE id='stable-player'");
    $backups->check(basename($copy['path']),$sha);
    try{$backups->restore(basename($copy['path']),$sha,fn()=>throw new RuntimeException('test-validation-failure'));throw new LogicException('Не сработала ошибка проверки.');}
    catch(RuntimeException $expected){if($expected->getMessage()!=='test-validation-failure'){throw $expected;}}
    if($mysql->query("SELECT username FROM users WHERE id='stable-player'")->fetchColumn()!=='После снимка'){throw new RuntimeException('Аварийный возврат потерял текущие данные.');}
    $backups->restore(basename($copy['path']),$sha,fn()=>SqlDatabaseAudit::foreignKeys($mysql));
    if($mysql->query("SELECT username FROM users WHERE id='stable-player'")->fetchColumn()!=='Игрок'||(int)$mysql->query('SELECT COUNT(*) FROM admin_sessions')->fetchColumn()!==0){throw new RuntimeException('SQL restore или отзыв сессий не подтверждён.');}
    if(!password_verify('keep-player-password',$mysql->query("SELECT password_hash FROM users WHERE id='stable-player'")->fetchColumn())){throw new RuntimeException('Изменился пароль игрока.');}
    $backups->create('daily');$backups->create('daily');
    echo json_encode(['PASS'=>'перенос всех таблиц, контрольные суммы, rowid, архивы, DOUBLE, большой прогресс, пароли, сессии, AUTO_INCREMENT, повторная проверка, native backup/restore','tables'=>$result['tables'],'rows'=>$result['rows']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL);exit(1);}
