{{-- Correo de un envío masivo: el cuerpo (HTML del editor) va dentro del diseño de la marca. --}}
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
                    <td style="height:6px; font-size:0; line-height:0; background:{{ \App\Models\Parametrizacion\Apariencia::coloresCorreo()['primario'] }};">&nbsp;</td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 24px 8px;">
                        @include('emails.partials.logo')
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 0; font-size:15px; line-height:1.6;">
                        {!! \App\Services\EnviosCorreo::incrustarImagenes($cuerpo, $message ?? null) !!}
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 32px 28px; font-size:12px; color:#8A949C; line-height:1.5;">
                        Recibes este correo porque reservaste en <strong>{{ \App\Models\Parametrizacion\Apariencia::nombreMarca() }}</strong>.
                        @if ($proveedorCorreo)
                            Si tienes preguntas, puedes responder este correo.
                        @endif
                        Si no deseas recibir más correos como este, responde con el asunto "Darme de baja".
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
