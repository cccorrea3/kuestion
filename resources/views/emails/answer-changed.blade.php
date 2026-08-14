<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kuestion: respuesta actualizada</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f4;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:16px;border:1px solid #e7e5e4;overflow:hidden;">
                    <tr>
                        <td style="padding:32px 32px 8px;">
                            <h1 style="margin:0;font-size:20px;line-height:1.3;color:#1c1917;">Una respuesta vigilada cambió</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 0;">
                            <p style="margin:0;font-size:15px;line-height:1.5;color:#57534e;">Tu pregunta:</p>
                            <p style="margin:8px 0 0;font-size:16px;line-height:1.5;color:#1c1917;font-weight:600;">{{ $questionText }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fafaf9;border:1px solid #e7e5e4;border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:4px 0;font-size:13px;color:#78716c;">Tipo de cambio</td>
                                                <td align="right" style="padding:4px 0;font-size:14px;color:#1c1917;font-weight:600;">
                                                    {{ $changeType === 'minor' ? 'Cambio menor' : 'Nueva versión' }}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:4px 0;font-size:13px;color:#78716c;">Similitud</td>
                                                <td align="right" style="padding:4px 0;font-size:14px;color:#1c1917;font-weight:600;">{{ round($similarity * 100) }}%</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:4px 0;font-size:13px;color:#78716c;">Versión</td>
                                                <td align="right" style="padding:4px 0;font-size:14px;color:#1c1917;font-weight:600;">v{{ $versionNumber }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 32px;">
                            <a href="{{ $url }}" style="display:inline-block;background-color:#f97316;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 24px;border-radius:10px;">
                                Revisar el cambio
                            </a>
                            <p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#a8a29e;">
                                Kuestion · Vigilancia de respuestas de tu base de conocimiento en Kuaforia
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
