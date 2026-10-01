<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CleanSeededNamesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Seeded name cleanup is only available locally.');
        }

        DB::transaction(function () {
            $names = [
                'Test User' => 'Hasan Rahman',
                'Authentication Test' => 'Nusrat Jahan',
                'Teacher Test' => 'Farhan Ahmed',
                'API Verification' => 'Sadia Islam',
            ];
            foreach (['Amina', 'Rafi', 'Nadia', 'Sami', 'Maya', 'Arif', 'Lina', 'Fahim', 'Sara', 'Rohan', 'Tania', 'Imran'] as $name) {
                $names[$name.' Demo'] = $name;
            }
            foreach ($names as $old => $new) {
                User::where('name', $old)->update(['name' => $new]);
            }
            User::where('bio', 'Demo traveler who enjoys discovering new places and meeting travel companions.')
                ->update(['bio' => 'Enjoys discovering new places and meeting travel companions.']);
            foreach (DB::table('trips')->where('title', 'like', 'Demo journey to %')->get(['id', 'title', 'destination']) as $trip) {
                if ($trip->title === 'Demo journey to '.$trip->destination) {
                    DB::table('trips')->where('id', $trip->id)->update(['title' => $trip->destination]);
                }
            }
        });
    }
}
