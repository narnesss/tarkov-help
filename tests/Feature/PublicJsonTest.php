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
    public function test_unfinished_file_is_rejected(): void
    { $this->expectException(\JsonException::class);PublicJson::select('{"items":[{"id":"broken"}',[],['items'=>fn($row)=>$row]); }
}
