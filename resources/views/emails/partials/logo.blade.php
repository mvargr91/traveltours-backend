{{--
    Logo de la marca incrustado en el correo (CID). No se enlaza a una URL: los clientes de correo
    (Gmail descarga las imágenes desde sus servidores) no pueden abrir localhost ni un frontend sin publicar.
    $message solo existe al enviar; en una vista previa se usa la imagen del frontend.
--}}
@php
    $rutaLogo = resource_path('images/correo/logo.png');
    $srcLogo = isset($message) && is_file($rutaLogo)
        ? $message->embed($rutaLogo)
        : config('app.front_url') . '/brand/logo.png';
@endphp
<img src="{{ $srcLogo }}" alt="Travel City LGTBIQ+" width="109" height="48" style="height:48px; width:auto; border:0; display:inline-block;">
