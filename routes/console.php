<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    /** @var ClosureCommand $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('disponibilidad:generar', function () {
    /** @var ClosureCommand $this */
    $this->info('Fechas creadas: ' . \App\Services\GeneradorDisponibilidad::generarTodas());
})->purpose('Crea las fechas de disponibilidad de los tours con horario semanal');
// No se programa: la ventana de fechas avanza sola con la primera visita del día al portal (generarSiHaceFalta).

// Procesa la cola (correos de los envíos masivos) sin un worker permanente: basta con el cron
// de `php artisan schedule:run` cada minuto en el servidor. En desarrollo: `php artisan queue:work`.
Schedule::command('queue:work --stop-when-empty --tries=1')->everyMinute()->withoutOverlapping();
