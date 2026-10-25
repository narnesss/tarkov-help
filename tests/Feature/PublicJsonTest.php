<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Services\PublicJson;
use PHPUnit\Framework\TestCase;

/** Выборочный декодер сохраняет строки и вложенные значения без полного разворота справочника. */
final class PublicJsonTest extends TestCase
{
    public function test_selected_rows_keep_quotes_brackets_unicode_nested_arrays_and_false_values(): void
    {
        $source=['meta'=>['name'=>'Маяк','quote'=>'" } ] \\'],'unused'=>array_fill(0,200,['large'=>str_repeat('x',100)]),'items'=>[
            ['id'=>'other','value'=>[null,true,false,12.3]],['id'=>'selected','text'=>'[ ] { } " \\','nested'=>[['a'=>['b'=>'Юникод']]],'flag'=>false],
        ]];
        foreach([0,JSON_PRETTY_PRINT] as $flags){$result=PublicJson::select(json_encode($source,$flags|JSON_THROW_ON_ERROR),['meta'],['items'=>fn($row)=>$row['id']==='selected'?$row:null]);$this->assertSame(['meta'=>$source['meta'],'items'=>[$source['items'][1]]],$result);}
        $this->assertSame(['items'=>[]],PublicJson::select('{"items":[],"ignore":null}',[],['items'=>fn($row)=>$row]));
    }
    public function test_long_strings_and_escape_boundaries_match_native_json_without_duplicate_collection_scan(): void
    {
        $texts=['', str_repeat('Текст [ ] { } ',10000), '\\', '\\"', '\\\\"', "Перенос\nСтроки\tИ табуляция", '\\u0022', '😀 \" конец'];
        $source=['skip'=>['large'=>str_repeat('x',100000)],'items'=>array_map(fn($text)=>['text'=>$text,'values'=>[false,null,0,1e-5,[],['nested'=>['value'=>$text]]]],$texts),'tail'=>false];
        foreach([0,JSON_PRETTY_PRINT,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES] as $flags){
            $raw=json_encode($source,$flags|JSON_THROW_ON_ERROR);
            $this->assertSame(['items'=>$source['items'],'tail'=>false],PublicJson::select($raw,['tail'],['items'=>fn($row)=>$row]));
        }
        foreach(['{"items":[{"text":"unterminated}', '{"items":[{"text":"bad\\', '{"items":[1,2,', '{"items":[{"value":1}'] as $raw){
            try{PublicJson::select($raw,[],['items'=>fn($row)=>$row]);$this->fail('Повреждённый JSON принят');}
            catch(\JsonException){$this->addToAssertionCount(1);}
        }
    }

    public function test_unfinished_file_is_rejected(): void
    { $this->expectException(\JsonException::class);PublicJson::select('{"items":[{"id":"broken"}',[],['items'=>fn($row)=>$row]); }

    public function test_keyed_collections_are_selected_row_by_row_like_lists(): void
    {
        $items=['ignored'=>['id'=>'ignored','name'=>'Не нужен'],'selected'=>['id'=>'selected','name'=>'Кавычки " [ ] { } \\ Юникод','values'=>[0,false,null]]];
        foreach([0,JSON_PRETTY_PRINT] as $flags){
            $visited=[];
            $raw=json_encode(['items'=>$items,'tail'=>false],$flags|JSON_THROW_ON_ERROR);
            $actual=PublicJson::select($raw,['tail'],['items'=>function($row) use(&$visited){$visited[]=$row['id'];return $row['id']==='selected'?$row:null;}]);
            $this->assertSame(['ignored','selected'],$visited);
            $this->assertSame(['items'=>[$items['selected']],'tail'=>false],$actual);
        }
        $this->assertSame(['items'=>[]],PublicJson::select('{"items":{}}',[],['items'=>fn($row)=>$row]));
        foreach(['{"items":{"id":}}','{"items":{"id" {"id":"bad"}}}','{"items":{"id":{"id":"unfinished"}'] as $raw){
            try{PublicJson::select($raw,[],['items'=>fn($row)=>$row]);$this->fail('Повреждённый словарь принят');}
            catch(\JsonException){$this->addToAssertionCount(1);}
        }
    }
}
