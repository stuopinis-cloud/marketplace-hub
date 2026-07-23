<?php

namespace Tests\Unit\Services\Marketplace\Pigu;

use App\Models\PiguCategoryField;
use App\Services\Marketplace\Pigu\PiguCategoryFieldImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiguCategoryFieldImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_csv_category_fields(): void
    {
        $path = storage_path('app/pigu-fields-test.csv');
        file_put_contents($path, implode("\n", [
            'lowest_category_id,lowest_category_title,lowest_category_title_en,top_category_title,top_category_title_en,field_id,field_title,field_title_en,mandatory,field_value_type,lt values,lv values,ee values,fi values,en values',
            '1268,Shirts,Shirts,Apparel,Apparel,100,Prekės ženklas,Brand,y,list,Nike;Adidas,,,,,Nike;Adidas',
            '1268,Shirts,Shirts,Apparel,Apparel,101,Spalva,Color,y,list,Juoda;Žalia,,,,,Black;Green',
        ]));

        $result = app(PiguCategoryFieldImporter::class)->import($path);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, PiguCategoryField::query()->count());
        $this->assertTrue(PiguCategoryField::query()->where('field_id', 100)->where('mandatory', true)->exists());

        @unlink($path);
    }
}
