<?php

namespace App\Console\Commands;

use App\Models\Heartbeat;
use App\Support\EntityClassifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('heartbeats:classify')]
#[Description('Reapply the current entity-classification rules to stored heartbeats')]
class ClassifyHeartbeatsCommand extends Command
{
    public function handle(): int
    {
        $updated = 0;

        Heartbeat::query()->chunkById(500, function (Collection $heartbeats) use (&$updated): void {
            foreach ($heartbeats as $heartbeat) {
                $entityClass = EntityClassifier::classify($heartbeat->entity, $heartbeat->entity_type);

                if ($heartbeat->entity_class !== $entityClass) {
                    $heartbeat->update(['entity_class' => $entityClass]);
                    $updated++;
                }
            }
        });

        $this->components->info("Reclassified {$updated} heartbeat(s).");

        return self::SUCCESS;
    }
}
