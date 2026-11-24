<?php

namespace App\VideoProcessing\Actions;

use App\Models\User;
use App\Models\VideoWorker;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateVideoWorkerAction
{
    /**
     * @return array{worker: VideoWorker, token: string}
     */
    public function execute(User $user, string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('El trabajador necesita un nombre.');
        }

        $token = Str::random(64);

        $worker = VideoWorker::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => VideoWorker::hashToken($token),
            'is_enabled' => true,
        ]);

        return [
            'worker' => $worker,
            'token' => $token,
        ];
    }
}
