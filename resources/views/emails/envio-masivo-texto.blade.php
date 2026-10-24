{{-- Parte de texto plano del envío masivo (la acompaña la versión HTML). --}}
{!! $textoPlano !!}

--
Recibes este correo porque reservaste en {{ \App\Models\Parametrizacion\Apariencia::nombreMarca() }}.
@if ($proveedorCorreo)
Si tienes preguntas, puedes responder este correo.
@endif
Si no deseas recibir más correos como este, responde con el asunto "Darme de baja".
