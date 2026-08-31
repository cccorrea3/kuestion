<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DbBackup extends Command
{
    protected $signature = 'db:backup {--compress : Gzip the backup}';

    protected $description = 'Backup the MySQL database';

    public function handle(): int
    {
        $db = config('database.connections.mysql.database');
        $user = config('database.connections.mysql.username');
        $pass = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $date = now()->format('Y-m-d-His');
        $file = "db-backup-{$db}-{$date}.sql";
        $path = storage_path("backups/{$file}");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        // ponytail: defaults-extra-file avoids password in process list
        $tmp = tempnam(sys_get_temp_dir(), 'my');
        file_put_contents($tmp, "[mysqldump]\nhost={$host}\nuser={$user}\npassword={$pass}\n");
        chmod($tmp, 0600);

        $cmd = sprintf(
            'mysqldump --defaults-extra-file=%s --single-transaction --routines %s > %s',
            escapeshellarg($tmp),
            escapeshellarg($db),
            escapeshellarg($path)
        );

        if ($this->option('compress')) {
            $cmd .= ' && gzip '.escapeshellarg($path);
            $path .= '.gz';
        }

        $exitCode = 0;
        $output = null;
        exec($cmd, $output, $exitCode);
        unlink($tmp);

        if ($exitCode !== 0) {
            $this->error('Backup failed');
            $this->line(implode("\n", $output));

            return 1;
        }

        // ponytail: keep last 7 backups
        $files = glob(storage_path('backups/db-backup-*.sql*'));
        if (count($files) > 7) {
            usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));
            foreach (array_slice($files, 0, count($files) - 7) as $old) {
                unlink($old);
            }
        }

        $this->info("Backup saved: {$path}");

        return 0;
    }
}
