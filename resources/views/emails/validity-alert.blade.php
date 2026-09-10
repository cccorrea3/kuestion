{{--
    Ola 2, Punto 5 — Fase E.3: plantilla de alerta de vigencia crítica (copy listo).
    CONTRATO DE DATOS (por definir con Punto 3/Kuaforia — no conectar aún):
      - Disparador: un chequeo de QuestionChecker detecta señales Kuaforia
        `stale_case` o `low_confidence` en la respuesta consultada de una pregunta vigilada.
      - Datos que consumirá el mailable (mismos nombres que ya viajan en
        AnswerChangedNotification::signals[] cuando existan por respuesta):
          question_id      → CTA (route questions.show)
          question_text    → cuerpo
          signal           → 'stale_case' | 'low_confidence' (elige el copy del bloque)
          detected_at      → fecha de la detección
      - Destinatario: autor de la pregunta. Evento CRÍTICO (§2.2) → llega con
        all y critical_only. Dedupe: (evento 'validity_alert', question_id).
      - Pendiente explícito (B5): Kuaforia no expone señales por respuesta hoy;
        este archivo no debe referenciarse desde ningún mailable hasta entonces.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kuestion: alerta de vigencia</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f4;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:16px;border:1px solid #e7e5e4;overflow:hidden;">
                    <tr>
                        <td style="padding:32px 32px 8px;">
                            <h1 style="margin:0;font-size:20px;line-height:1.3;color:#1c1917;">Una respuesta necesita tu revisión</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 0;">
                            <p style="margin:0;font-size:15px;line-height:1.6;color:#57534e;">
                                La información detrás de tu pregunta muestra señales de que podría estar desactualizada o ser poco confiable. Revisala para decidir si sigue siendo válida.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 0;">
                            <p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#78716c;">Tu pregunta:</p>
                            <p style="margin:0;font-size:16px;line-height:1.5;color:#1c1917;font-weight:600;">{{ $questionText ?? 'Tu pregunta vigilada' }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 0;">
                            <a href="{{ $url ?? '#' }}" style="display:inline-block;background-color:#f97316;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 24px;border-radius:10px;">
                                Revisar ahora
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 32px;">
                            <p style="margin:0 0 8px;font-size:12px;line-height:1.5;color:#a8a29e;">
                                Kuestion · Vigilancia de respuestas de tu base de conocimiento
                            </p>
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#a8a29e;">
                                <a href="{{ $settingsUrl ?? '#' }}" style="color:#78716c;">Configurar mis notificaciones</a>
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
