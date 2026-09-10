<?php

namespace App\Http\Controllers;

use App\Models\User;

/**
 * Ola 2, Punto 5 — Fase A.4: baja de correos por link firmado (sin login).
 *
 * Los pies de correo enlazan "Dejar de recibir estos correos" aquí; la URL
 * firmada evita que terceros den de baja a otros usuarios (el id es el PK
 * interno, nunca navegable sin firma).
 */
class UnsubscribeController extends Controller
{
    public function __invoke(User $user)
    {
        $user->update(['email_notifications' => User::EMAIL_PREF_NONE]);

        return view('emails.unsubscribed', ['user' => $user]);
    }
}
