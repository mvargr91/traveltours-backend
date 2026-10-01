@php
    // Colores de la marca (frontend: src/shared/constants/Marca.jsx).
    $azul = '#00A1CC';
    $azulOscuro = '#1C4A59';
    $texto = '#1F2933';
    $estados = [
        'pendiente' => ['Pendiente', '#FE8500'],
        'pendiente_pago' => ['Pendiente de pago', '#7B1FA2'],
        'aceptada' => ['Aceptada', '#2E7D32'],
        'rechazada' => ['Rechazada', '#D32F2F'],
        'cancelada' => ['Cancelada', '#B80001'],
        'finalizada' => ['Finalizada', '#2E75B6'],
        'no_asistio' => ['No asistió', '#3B3838'],
    ];
    [$estadoNombre, $estadoColor] = $estados[$reserva->estado] ?? [$reserva->estado, '#3B3838'];
    $front = rtrim(env('APP_FRONT_URL', ''), '/');
    $filas = [
        'Experiencia' => $reserva->experiencia_nombre,
        'Fecha' => $reserva->fecha . ($reserva->hora_inicio ? ' · ' . substr($reserva->hora_inicio, 0, 5) : ''),
        'Personas' => $reserva->cantidad_personas,
        'Punto de encuentro' => $reserva->punto_encuentro,
        'Proveedor' => $verContactoCliente ? null : $reserva->proveedor_nombre,
        'Contacto del proveedor' => $verContactoCliente ? null : $reserva->proveedor_telefono,
        'Viajero' => $verContactoCliente ? $reserva->nombre : null,
        'Correo' => $verContactoCliente ? $reserva->correo : null,
        'Teléfono' => $verContactoCliente ? $reserva->telefono : null,
        'Comentarios' => $verContactoCliente ? $reserva->observaciones : null,
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $asunto }}</title>
</head>
<body style="margin:0; padding:0; background:#F2F5F7; font-family:Arial, Helvetica, sans-serif; color:{{ $texto }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F2F5F7; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#FFFFFF; border-radius:12px; overflow:hidden;">
                {{-- Franja arcoíris del logo --}}
                <tr>
                    <td style="height:6px; font-size:0; line-height:0; background:#E40303; background-image:linear-gradient(90deg,#E40303,#FF8C00,#FFED00,#008026,#24408E,#732982);">&nbsp;</td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 24px 8px;">
                        <img src="{{ $front }}/brand/logo.png" alt="Travel City LGTBIQ+" height="48" style="height:48px; border:0;">
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 0;">
                        <h1 style="margin:0 0 12px; font-size:22px; color:{{ $azulOscuro }};">{{ $titulo }}</h1>
                        <div style="margin:0 0 20px; font-size:15px; line-height:1.5;">{!! $mensaje !!}</div>
                    </td>
                </tr>
                {{-- Código y estado --}}
                <tr>
                    <td style="padding:0 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px dashed {{ $azul }}; border-radius:10px; background:#EEF8FB;">
                            <tr>
                                <td style="padding:14px 16px;">
                                    <div style="font-size:12px; color:#5F6B76;">Código de reserva</div>
                                    <div style="font-size:20px; font-weight:bold; font-family:'Courier New', monospace; letter-spacing:1px;">{{ $reserva->codigo_reserva }}</div>
                                </td>
                                <td align="right" style="padding:14px 16px;">
                                    <span style="display:inline-block; padding:6px 12px; border-radius:16px; background:{{ $estadoColor }}; color:#FFFFFF; font-size:13px; font-weight:bold;">{{ $estadoNombre }}</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                {{-- Detalle --}}
                <tr>
                    <td style="padding:20px 32px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                            @foreach ($filas as $etiqueta => $valor)
                                @if ($valor !== null && $valor !== '')
                                    <tr>
                                        <td style="padding:7px 0; color:#5F6B76; border-bottom:1px solid #EDF0F2; vertical-align:top;">{{ $etiqueta }}</td>
                                        <td align="right" style="padding:7px 0; border-bottom:1px solid #EDF0F2;">{{ $valor }}</td>
                                    </tr>
                                @endif
                            @endforeach
                            <tr>
                                <td style="padding:12px 0 0; font-weight:bold; font-size:16px;">Total</td>
                                <td align="right" style="padding:12px 0 0; font-weight:bold; font-size:16px;">$ {{ number_format((float) $reserva->valor_total, 0, ',', '.') }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                @if ($botonUrl)
                    <tr>
                        <td align="center" style="padding:28px 32px 8px;">
                            <a href="{{ $botonUrl }}" style="display:inline-block; padding:13px 28px; background:{{ $azul }}; color:#FFFFFF; text-decoration:none; border-radius:8px; font-weight:bold; font-size:15px;">{{ $botonTexto }}</a>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:24px 32px 28px; font-size:12px; color:#8A949C; line-height:1.5;">
                        @if ($verContactoCliente)
                            Puedes responder este correo para escribirle directamente al viajero.
                        @else
                            Si tienes preguntas, responde este correo o comunícate con el proveedor.
                        @endif
                    </td>
                </tr>
            </table>
            <p style="margin:16px 0 0; font-size:12px; color:#8A949C;">© {{ date('Y') }} Travel City LGTBIQ+ · Explora Colombia con libertad</p>
        </td>
    </tr>
</table>
</body>
</html>
