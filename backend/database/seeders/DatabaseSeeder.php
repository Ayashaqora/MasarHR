<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * MasarHR has no public registration and no synthetic default account (§7/§19 of the S03
     * authorization): the first security principal is created only through
     * `php artisan masar:security:bootstrap-admin`. This seeder intentionally does nothing.
     */
    public function run(): void {}
}
