<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SiteSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/sql/sites.sql');

        if (!file_exists($path)) {
            $this->command->error("File not found: {$path}");
            return;
        }

        $sql = file_get_contents($path);

        // فقط از اولین INSERT تا آخرین «;» بعد از آن را نگه دار
        $start = stripos($sql, 'INSERT INTO `sites`');
        if ($start === false) {
            $this->command->error('No INSERT found.');
            return;
        }

        // آخرین INSERT: پایان آن جایی است که «NULL);» یا «);» قبل از «-- Indexes» می‌آید
        $end = stripos($sql, "\n--\n-- Indexes for dumped tables");
        $insertSql = $end !== false
            ? substr($sql, $start, $end - $start)
            : substr($sql, $start);

        $insertSql = trim($insertSql);

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::unprepared($insertSql);
            $this->command->info('sites imported successfully.');
        } catch (\Throwable $e) {
            $this->command->error('Import failed: ' . $e->getMessage());
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
