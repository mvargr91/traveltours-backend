@php
    // Colores de la marca: Parametrización > Apariencia (primario y primario oscuro del portal).
    ['primario' => $azul, 'oscuro' => $azulOscuro, 'claro' => $azulClaro] = \App\Models\Parametrizacion\Apariencia::coloresCorreo();
    $filas = array_filter([
        'Proveedor' => $proveedorNombre,
        'Cambio' => ucfirst($datos['accion']),
        'Detalle' => implode(' · ', $datos['detalle']),
        'Hecho por' => $datos['responsable'],
        'Fecha' => $datos['fecha'],
        'IP' => $datos['ip'],
    ], fn ($valor) => $valor !== null && $valor !== '');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $asunto }}</title>
</head>
<body style="margin:0; padding:0; background:#F2F5F7; font-family:Arial, Helvetica, sans-serif; color:#1F2933;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F2F5F7; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#FFFFFF; border-radius:12px; overflow:hidden;">
                {{-- Línea superior con el color primario de la marca --}}
                <tr>
                    <td style="height:6px; font-size:0; line-height:0; background:{{ $azul }};">&nbsp;</td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 24px 8px;">
                        @include('emails.partials.logo')
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 0;">
                        <h1 style="margin:0 0 12px; font-size:22px; color:{{ $azulOscuro }};">Cambio en la pasarela de pago</h1>
                        <div style="margin:0 0 20px; font-size:15px; line-height:1.5;">
                            Se registró un cambio en la cuenta donde <strong>{{ $proveedorNombre }}</strong> recibe los pagos en línea.
                            Si lo hiciste tú, no tienes que hacer nada.
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                            @foreach ($filas as $etiqueta => $valor)
                                <tr>
                                    <td style="padding:7px 0; color:#5F6B76; border-bottom:1px solid #EDF0F2; vertical-align:top;">{{ $etiqueta }}</td>
                                    <td align="right" style="padding:7px 0; border-bottom:1px solid #EDF0F2;">{{ $valor }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 32px 0;">
                        <div style="padding:12px 14px; border-radius:8px; background:#FDECEA; color:#8A1C1C; font-size:14px; line-height:1.5;">
                            <strong>¿No reconoces este cambio?</strong> Cambia tu contraseña, revisa la pasarela en el panel y escríbenos
                            respondiendo este correo: los pagos podrían estar llegando a otra cuenta.
                        </div>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:28px 32px 8px;">
                        <a href="{{ $botonUrl }}" style="display:inline-block; padding:13px 28px; background:{{ $azul }}; color:#FFFFFF; text-decoration:none; border-radius:8px; font-weight:bold; font-size:15px;">Revisar pasarela</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 32px 28px; font-size:12px; color:#8A949C; line-height:1.5;">
                        Este aviso nunca incluye tus llaves. Nadie de {{ \App\Models\Parametrizacion\Apariencia::nombreMarca() }} te las pedirá por correo.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
