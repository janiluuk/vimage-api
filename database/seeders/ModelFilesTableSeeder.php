<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ModelFilesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('model_files')->insert(
	[
            'filename' => 'inkpunkDiffusion_v2.ckpt',
            'name' => 'InkPunk Diffusion',
            'description' => 'Inkpunk description',
            'version' => '2.0',
            'previewUrl' => 'http://example.com/preview',
            'enabled' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    	DB::table('model_files')->insert(

        [
            'filename' => 'lofi_V2.safetensors',
            'name' => 'Lo-Fi',
            'description' => 'Lofi description',
            'version' => '2.0',
            'previewUrl' => 'http://example.com/preview',
            'enabled' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]
	);
     	DB::table('model_files')->insert(
        [
            'filename' => 'chilloutmix_NiPrunedFp32Fix.safetensors',
            'name' => 'Chillout Mix',
            'description' => 'Chillout description',
            'version' => '1.6',
            'previewUrl' => 'http://example.com/preview',
            'enabled' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ],);
    }
}

