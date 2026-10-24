{{--
    Logo de la marca incrustado en el correo (CID): el logo principal subido en Parametrización > Apariencia o,
    si no hay, el fijo de resources/images/correo (Apariencia::logoCorreo). No se enlaza a una URL: los clientes
    de correo (Gmail descarga las imágenes desde sus servidores) no pueden abrir localhost ni un frontend sin publicar.
    $message solo existe al enviar; en una vista previa se usa la imagen del frontend.
--}}
@php
    [$rutaLogo, $nombreMarca] = \App\Models\Parametrizacion\Apariencia::logoCorreo();
    $srcLogo = isset($message) && is_file($rutaLogo)
        ? $message->embed($rutaLogo)
        : config('app.front_url') . '/brand/logo.png';
    // 48 px de alto con el ancho proporcional del logo (máximo 220 px para logos muy horizontales).
    $medidas = is_file($rutaLogo) ? @getimagesize($rutaLogo) : false;
    $altoLogo = 48;
    $anchoLogo = $medidas && $medidas[1] > 0 ? (int) round($medidas[0] * $altoLogo / $medidas[1]) : 109;
    if ($anchoLogo > 220) {
        $altoLogo = (int) round($altoLogo * 220 / $anchoLogo);
        $anchoLogo = 220;
    }
@endphp
<img src="{{ $srcLogo }}" alt="{{ $nombreMarca }}" width="{{ $anchoLogo }}" height="{{ $altoLogo }}" style="height:{{ $altoLogo }}px; width:{{ $anchoLogo }}px; border:0; display:inline-block;">
