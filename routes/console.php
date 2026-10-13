<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    /** @var ClosureCommand $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('disponibilidad:generar', function () {
    /** @var ClosureCommand $this */
    $this->info('Fechas creadas: ' . \App\Services\GeneradorDisponibilidad::generarTodas());
})->purpose('Crea las fechas de disponibilidad de los tours con horario semanal');
// No se programa: la ventana de fechas avanza sola con la primera visita del día al portal (generarSiHaceFalta).
