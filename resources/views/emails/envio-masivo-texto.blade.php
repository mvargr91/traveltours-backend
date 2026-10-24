{{-- Parte de texto plano del envío masivo (la acompaña la versión HTML). --}}
{!! $textoPlano !!}

--
Recibes este correo de {{ $proveedorNombre }} porque reservaste con ellos en {{ \App\Models\Parametrizacion\Apariencia::nombreMarca() }}.
@if ($proveedorCorreo)
Puedes responder este correo para escribirles directamente.
@endif
Si no deseas recibir más correos como este, responde con el asunto "Darme de baja".
