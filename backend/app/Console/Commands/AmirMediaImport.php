<?php

namespace App\Console\Commands;

use App\Services\MediaStore;
use Illuminate\Console\Command;

class AmirMediaImport extends Command
{
    protected $signature = 'amir:media-import';

    protected $description = 'Porta nel database stemmi e foto salvati su disco (storage/app/private/media) dalle versioni precedenti';

    public function handle(MediaStore $media): int
    {
        $copied = $media->importFromDisk();

        $this->info("Immagini copiate nel database: {$copied}");

        return self::SUCCESS;
    }
}
