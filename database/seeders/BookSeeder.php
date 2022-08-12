<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $level= ['بسيط', 'متوسط', 'عميق'];
       $i=0;
        while ($i<=200){
            
            DB::table('books')->insert([
                'post_id' => rand(0,200),
                'name' => Str::random(10),
                'writer' => Str::random(10),
                'publisher' => Str::random(10),
                'brief' => Str::random(150),
                'start_page' => 1,
                'end_page' => rand(150,600),
                'link' => 'https://www.google.com/',
                'section_id' =>rand(1,8),
                'type_id' => rand(1,4),
                'level' => $level[rand(0,2)],
            ]);
            $i++;    
        }
    }
}
