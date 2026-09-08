<?php

namespace App\Http\Controllers\Sign;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página pública do signatário (ROUTES §2.18 / arquitetura §4). Esqueleto com o contrato de
 * props — // TODO(Wave B): resolver recipient_access_links pelo digest, estados e sessão.
 */
class SignerPageController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        return Inertia::render('sign/show', [
            'token' => $token,
            'screen' => 'invalid',
            'sender' => ['organization_name' => 'AssinaVelox', 'organization_initials' => 'AV', 'logo_url' => null, 'user_name' => ''],
            'envelope' => null,
            'recipient' => null,
            'others' => [],
            'signing_order' => 'sequential',
            'otp' => null,
            'document' => null,
            'my_fields' => [],
            'other_fields' => [],
            'signature_options' => ['draw' => true, 'type' => true, 'upload' => true, 'fonts' => ['Caveat']],
            'consent_text' => '',
            'legal' => ['terms_url' => route('legal.terms'), 'privacy_url' => route('legal.privacy')],
            'receipt' => null,
            'refusal' => null,
            'auth_methods' => ['email_otp'],
        ]);
    }
}
