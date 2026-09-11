<?php

namespace App\Http\Controllers\Batch;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\User;
use App\Policies\BatchSigningPolicy;
use App\Services\Batch\BatchLinks;
use App\Services\InPerson\PresenceFeatures;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Enviar link de lote" para um participante (`envelopes.recipients.batch`,
 * docs/fase-2/presencial-e-lote.md §3.2). Flag `batch_signing` desligada: 404.
 */
class BatchLinkController extends Controller
{
    public function __construct(
        private readonly BatchLinks $links,
        private readonly BatchSigningPolicy $policy,
    ) {}

    public function store(Request $request, Envelope $envelope, Recipient $recipient): RedirectResponse
    {
        abort_unless(PresenceFeatures::batchSigning(CurrentOrganization::instance()->get()), 404);
        abort_unless($recipient->envelope_id === $envelope->getKey(), 404);

        /** @var User $user */
        $user = $request->user();

        abort_unless($this->policy->issue($user, $envelope), 403);

        try {
            $result = $this->links->issue($envelope, $recipient, $user, $request);
        } catch (SigningRejectedException $exception) {
            abort_if($exception->status === 404, 404);

            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Link de lote enviado para %s, com %d documentos pendentes desta conta.',
            $recipient->masked_email,
            $result['items'],
        ));
    }
}
