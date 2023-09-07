<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateModelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:models';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the models in the database from the JSON file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Read the JSON file
        $json = file_get_contents('/tmp/model_data.json');

        // Convert it to an associative array
        $data = json_decode($json, true);

        // Go through each item
        foreach ($data as $item) {
            // Check if the item already exists in the database
            $exists = DB::table('model_files')->where('name', $item['name'])->exists();

            // If it doesn't exist yet
            if (!$exists) {
                // Insert it into the database
                DB::table('model_files')->insert([
                    'name' => $item['name'],
                    'filename' => $item['filename'],
		    'version' => $item['version'],
                    'enabled' => $item['enabled'],
                ]);
            }
        }

        $this->info('Models updated successfully.');

        return 0;
    }
}
