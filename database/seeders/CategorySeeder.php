<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Initial catalogue (REAL data, it goes to production). Only creates what is missing: a category that already exists is never
 * touched, so a name or an "inactive" state the admin set is never overwritten. Safe to run any number of times.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['cafe' => 'Café'] as $slug => $name) {
            Category::firstOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
        }
    }
}
