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

// Cobro a proveedores: borradores del mes anterior (y emisión si COBRO_EMITIR_AUTOMATICO = SI).
Artisan::command('liquidaciones:generar {periodo? : Mes AAAA-MM; por defecto el anterior}', function ($periodo = null) {
    /** @var ClosureCommand $this */
    $periodo = $periodo ?: \App\Services\CobroProveedores::periodoAnterior();
    $total = \Illuminate\Support\Facades\DB::transaction(fn () => \App\Services\CobroProveedores::generar($periodo));
    $this->info("Liquidaciones en borrador de {$periodo}: {$total}");

    if (strtoupper(trim(\App\Services\CobroProveedores::parametro('COBRO_EMITIR_AUTOMATICO'))) === 'SI') {
        $sistema = (object) ['id' => 0, 'nombre' => 'Sistema'];
        $borradores = \Illuminate\Support\Facades\DB::table('liquidaciones')->where('periodo', $periodo)->where('estado', 'borrador')->pluck('id');
        foreach ($borradores as $id) {
            \Illuminate\Support\Facades\DB::transaction(fn () => \App\Services\CobroProveedores::emitir($id, $sistema));
        }
        $this->info('Emitidas: ' . $borradores->count());
    }
})->purpose('Genera las liquidaciones del mes a los proveedores');
Schedule::command('liquidaciones:generar')->monthlyOn(1, '02:00')->withoutOverlapping();

// Avisa las liquidaciones vencidas y oculta del portal a los proveedores en mora.
Artisan::command('liquidaciones:revisar-mora', function () {
    /** @var ClosureCommand $this */
    [$avisos, $suspendidos] = \App\Services\CobroProveedores::revisarMora();
    $this->info("Avisos de vencimiento: {$avisos} · proveedores suspendidos: {$suspendidos}");
})->purpose('Avisa las liquidaciones vencidas y suspende a los proveedores en mora');
Schedule::command('liquidaciones:revisar-mora')->dailyAt('07:00')->withoutOverlapping();
