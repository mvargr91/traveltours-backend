@php
    // Colores de la marca (frontend: src/shared/constants/Marca.jsx).
    $azul = '#00A1CC';
    $azulOscuro = '#1C4A59';
    $texto = '#1F2933';
    $colores = ['Borrador' => '#3B3838', 'Emitida' => '#FE8500', 'Vencida' => '#D32F2F', 'Pagada' => '#2E7D32', 'Anulada' => '#3B3838'];
    $l = $liquidacion;
    $pesos = fn ($valor) => '$ ' . number_format((float) $valor, 0, ',', '.');
    $filas = [
        'Periodo' => ucfirst($l['periodo_nombre']),
        'Modelo de cobro' => $l['tipo_modelo_nombre'],
        'Suscripción' => $l['valor_suscripcion'] > 0 ? $pesos($l['valor_suscripcion']) : null,
        'Ventas liquidadas' => $l['total_comisiones'] != 0 ? $pesos($l['total_ventas']) : null,
        'Comisión (' . rtrim(rtrim(number_format($l['porcentaje_comision'], 2, ',', ''), '0'), ',') . ' %)' => $l['total_comisiones'] != 0 ? $pesos($l['total_comisiones']) : null,
        'IVA (' . rtrim(rtrim(number_format($l['porcentaje_iva'], 2, ',', ''), '0'), ',') . ' %)' => $l['valor_iva'] > 0 ? $pesos($l['valor_iva']) : null,
        'Vence' => $l['fecha_vencimiento'],
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
                        @include('emails.partials.logo')
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
                                    <div style="font-size:12px; color:#5F6B76;">Liquidación</div>
                                    <div style="font-size:20px; font-weight:bold; font-family:'Courier New', monospace; letter-spacing:1px;">{{ $l['codigo'] }}</div>
                                </td>
                                <td align="right" style="padding:14px 16px;">
                                    <span style="display:inline-block; padding:6px 12px; border-radius:16px; background:{{ $colores[$l['estado_nombre']] ?? '#3B3838' }}; color:#FFFFFF; font-size:13px; font-weight:bold;">{{ $l['estado_nombre'] }}</span>
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
                                <td align="right" style="padding:12px 0 0; font-weight:bold; font-size:16px;">{{ $pesos($l['total']) }}</td>
                            </tr>
                            @if ($l['valor_pagado'] > 0)
                                <tr>
                                    <td style="padding:6px 0 0; color:#5F6B76;">Pagado</td>
                                    <td align="right" style="padding:6px 0 0;">{{ $pesos($l['valor_pagado']) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:6px 0 0; color:#5F6B76;">Saldo</td>
                                    <td align="right" style="padding:6px 0 0;">{{ $pesos($l['saldo']) }}</td>
                                </tr>
                            @endif
                        </table>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:28px 32px 8px;">
                        <a href="{{ $botonUrl }}" style="display:inline-block; padding:13px 28px; background:{{ $azul }}; color:#FFFFFF; text-decoration:none; border-radius:8px; font-weight:bold; font-size:15px;">Ver liquidación</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 32px 28px; font-size:12px; color:#8A949C; line-height:1.5;">
                        Si tienes preguntas sobre esta liquidación, responde este correo.
                    </td>
                </tr>
            </table>
            <p style="margin:16px 0 0; font-size:12px; color:#8A949C;">© {{ date('Y') }} Travel City LGTBIQ+ · Explora Colombia con libertad</p>
        </td>
    </tr>
</table>
</body>
</html>
