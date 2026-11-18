<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Illuminate\Http\Request;

final class PaymentUiContext
{
    public function forRequest(Request $request): array
    {
        $actor = $request->user();
        $audience = hash_hmac('sha256', 'silansia.payment.ui.audience.v1|'.app()->environment().'|'.config('database.default').'|'.(getenv('SILANSIA_TEST_IDENTITY') ?: 'operational-disabled'), config('app.key'));
        $resource = $request->route('invoice', 'queue').'|'.$request->route('payment', 'invoice');

        return ['version' => 1, 'actor_id' => $actor->id, 'role' => $actor->role,
            'session_context' => hash_hmac('sha256', 'silansia.payment.ui.session.v1|'.$request->session()->getId(), config('app.key')),
            'audience_context' => $audience,
            // Stable across session rotation: a boolean hold must not disappear on re-login.
            // This cannot identify/replay any command and contains no financial command facts.
            'hold_key' => hash_hmac('sha256', 'silansia.payment.ui.hold.v1|'.$audience.'|'.$actor->id.'|'.$actor->role.'|'.$resource, config('app.key'))];
    }
}
