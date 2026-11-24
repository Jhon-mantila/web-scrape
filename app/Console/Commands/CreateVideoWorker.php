<?php

namespace App\Console\Commands;

use App\Models\User;
use App\VideoProcessing\Actions\CreateVideoWorkerAction;
use Illuminate\Console\Command;

class CreateVideoWorker extends Command
{
    protected $signature = 'video-worker:create
        {name : Nombre del dispositivo (por ejemplo PC-Jhon)}
        {--user= : Email o ID del usuario dueño}';

    protected $description = 'Registra un Esquina Video Worker y muestra su token una sola vez';

    public function handle(CreateVideoWorkerAction $action): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return Command::FAILURE;
        }

        $created = $action->execute($user, (string) $this->argument('name'));

        $this->info("Trabajador #{$created['worker']->id} «{$created['worker']->name}» creado para {$user->email}.");
        $this->newLine();
        $this->warn('Guarda este token ahora. No se volverá a mostrar.');
        $this->line('ESQUINA_WORKER_TOKEN='.$created['token']);
        $this->newLine();
        $this->line('En workers/esquina-video-worker/.env:');
        $this->line('ESQUINA_API_URL='.rtrim((string) config('app.url'), '/'));
        $this->line('ESQUINA_WORKER_TOKEN='.$created['token']);

        return Command::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $option = $this->option('user');

        if (is_string($option) && $option !== '') {
            $user = is_numeric($option)
                ? User::query()->find((int) $option)
                : User::query()->where('email', $option)->first();

            if ($user === null) {
                if (User::query()->count() === 0) {
                    $this->error('No hay usuarios en la base de datos. Entra a /register, crea la cuenta y vuelve a emitir el token.');
                } else {
                    $this->error('No hay un usuario con ese email o ID.');
                    $this->line('Usuarios actuales: '.User::query()->orderBy('id')->pluck('email')->implode(', '));
                }

                return null;
            }

            return $user;
        }

        $count = User::query()->count();

        if ($count === 0) {
            $this->error('No hay usuarios. Crea uno en /register antes de emitir un token.');

            return null;
        }

        if ($count > 1) {
            $this->error('Hay varios usuarios. Pasa --user=email o --user=id.');

            return null;
        }

        return User::query()->first();
    }
}
