<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kuestion: estado de tu aporte</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f4;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:16px;border:1px solid #e7e5e4;overflow:hidden;">
                    <tr>
                        <td style="padding:32px 32px 8px;">
                            <h1 style="margin:0;font-size:20px;line-height:1.3;color:#1c1917;">
                                @if ($aprobado)
                                    Tu aporte fue aprobado ✅
                                @else
                                    Tu aporte fue rechazado
                                @endif
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 0;">
                            @if ($aprobado)
                                <p style="margin:0;font-size:15px;line-height:1.6;color:#57534e;">
                                    Tu aporte ya forma parte de tu base de conocimiento.@if ($revisadoPorNombre) Lo revisó {{ $revisadoPorNombre }}.@endif
                                </p>
                            @else
                                <p style="margin:0;font-size:15px;line-height:1.6;color:#57534e;">
                                    Tu aporte no fue incorporado a la base de conocimiento.@if ($revisadoPorNombre) Lo revisó {{ $revisadoPorNombre }}.@endif Podés volver a intentarlo cuando quieras con más contexto o fuentes.
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 0;">
                            <p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#78716c;">Tu aporte:</p>
                            <p style="margin:0;font-size:15px;line-height:1.6;color:#1c1917;">{{ \Illuminate\Support\Str::limit($textoAporte, 400) }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 0;">
                            <a href="{{ $url }}" style="display:inline-block;background-color:#f97316;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 24px;border-radius:10px;">
                                Abrir la bandeja de revisión
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 32px;">
                            <p style="margin:0 0 8px;font-size:12px;line-height:1.5;color:#a8a29e;">
                                Kuestion · Aportes de conocimiento con QuBeKa
                            </p>
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#a8a29e;">
                                <a href="{{ $settingsUrl }}" style="color:#78716c;">Configurar mis notificaciones</a>
                                @if (! empty($unsubscribeUrl))
                                    ·
                                    <a href="{{ $unsubscribeUrl }}" style="color:#78716c;">Dejar de recibir estos correos</a>
                                @endif
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
