<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\PlayerOAuthSettings;
use Tests\TestCase;

/** Проверяет ограниченный импорт секретов без изменения остальных настроек. */
final class PlayerOAuthSettingsTest extends TestCase
{
    public function test_only_complete_allowed_pairs_change_and_repeated_import_preserves_other_settings(): void
    {
        $path=tempnam(sys_get_temp_dir(),'oauth-test-');
        try{
            file_put_contents($path,"APP_KEY=base64:unchanged\nDB_DATABASE=keep\nAPI_PROXY_TOKEN=keep-token\nGOOGLE_CLIENT_ID=old\n");
            $settings=['GOOGLE_CLIENT_ID'=>'client.apps.googleusercontent.com','GOOGLE_CLIENT_SECRET'=>'GOCSPX-private'];
            PlayerOAuthSettings::write($path,$settings);$first=file_get_contents($path);
            PlayerOAuthSettings::write($path,$settings);$this->assertSame($first,file_get_contents($path));
            $values=\Dotenv\Dotenv::parse($first);$this->assertSame('base64:unchanged',$values['APP_KEY']);$this->assertSame('keep',$values['DB_DATABASE']);$this->assertSame('keep-token',$values['API_PROXY_TOKEN']);
            foreach([['APP_KEY'=>'changed'],['GOOGLE_CLIENT_ID'=>'missing-secret'],['DISCORD_CLIENT_ID'=>'client','DISCORD_CLIENT_SECRET'=>"injection\nAPP_KEY=bad"]] as $invalid){
                try{PlayerOAuthSettings::write($path,$invalid);$this->fail('Некорректный импорт принят.');}catch(\InvalidArgumentException){}
                $this->assertSame($first,file_get_contents($path));
            }
        }finally{unlink($path);}
    }
}
