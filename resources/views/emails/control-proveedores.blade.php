@php
    // Colores de la marca: Parametrización > Apariencia (primario y primario oscuro del portal).
    ['primario' => $azul, 'oscuro' => $azulOscuro, 'claro' => $azulClaro] = \App\Models\Parametrizacion\Apariencia::coloresCorreo();
    $r = $datos['resumen'];
    $pesos = fn ($valor) => '$ ' . number_format((float) $valor, 0, ',', '.');
    $cifras = [
        ['Proveedores para revisar', $r['con_alertas'] . ' de ' . $r['proveedores']],
        ['Reservas sin finalizar (tour ya pasado)', $r['reservas_sin_cerrar']],
        ['Canceladas el día del tour o después', $r['canceladas_tarde']],
        ['Solicitudes sin responder', $r['reservas_sin_responder']],
        ['Liquidaciones vencidas', $r['liquidaciones_vencidas']],
        ['Saldo por cobrar a proveedores', $pesos($r['saldo_por_pagar'])],
        ['Proveedores en mora', $r['en_mora']],
    ];
    $revisar = array_slice(array_values(array_filter($datos['proveedores'], fn ($p) => $p['nivel'] !== 'ok')), 0, 15);
    $colorNivel = ['alto' => '#D32F2F', 'medio' => '#FE8500'];
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
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#FFFFFF; border-radius:12px; overflow:hidden;">
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
                        <h1 style="margin:0 0 12px; font-size:22px; color:{{ $azulOscuro }};">Control de proveedores</h1>
                        <div style="margin:0 0 20px; font-size:15px; line-height:1.5;">
                            Resumen de la semana: lo que conviene revisar para que las comisiones y los cobros estén al día.
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
                            @foreach ($cifras as [$etiqueta, $valor])
                                <tr>
                                    <td style="padding:7px 0; color:#5F6B76; border-bottom:1px solid #EDF0F2;">{{ $etiqueta }}</td>
                                    <td align="right" style="padding:7px 0; border-bottom:1px solid #EDF0F2; font-weight:bold;">{{ $valor }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
                @if ($revisar)
                    <tr>
                        <td style="padding:24px 32px 0;">
                            <div style="font-size:16px; font-weight:bold; color:{{ $azulOscuro }}; margin-bottom:8px;">Proveedores para revisar</div>
                            @foreach ($revisar as $p)
                                <div style="padding:10px 12px; margin-bottom:8px; border-left:4px solid {{ $colorNivel[$p['nivel']] }}; background:{{ $azulClaro }}; border-radius:6px;">
                                    <div style="font-weight:bold; font-size:14px;">{{ $p['proveedor_nombre'] }}</div>
                                    @foreach ($p['alertas'] as $alerta)
                                        <div style="font-size:13px; color:#3B3838; margin-top:3px;">• {{ $alerta['texto'] }}</div>
                                    @endforeach
                                </div>
                            @endforeach
                        </td>
                    </tr>
                @else
                    <tr>
                        <td style="padding:20px 32px 0; font-size:15px;">No hay proveedores con alertas esta semana.</td>
                    </tr>
                @endif
                <tr>
                    <td align="center" style="padding:28px 32px 8px;">
                        <a href="{{ $botonUrl }}" style="display:inline-block; padding:13px 28px; background:{{ $azul }}; color:#FFFFFF; text-decoration:none; border-radius:8px; font-weight:bold; font-size:15px;">Ver control de proveedores</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 32px 28px; font-size:12px; color:#8A949C; line-height:1.5;">
                        Cancelaciones y tasa calculadas sobre los últimos {{ $datos['parametros']['dias_ventana'] }} días.
                        Puedes apagar este correo con el parámetro CONTROL_RESUMEN_SEMANAL.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
