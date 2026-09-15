<?php

namespace App\Services\Anchors;

enum SuggestionStatus: string
{
    /** À espera da revisão do remetente: o envelope não fica pronto. */
    case Pending = 'pending';
    /** O remetente confirmou: virou um campo no editor (validado de novo pelo FieldSync). */
    case Accepted = 'accepted';
    case Discarded = 'discarded';
    /** Nova busca no mesmo documento ou arquivo substituído: a sugestão antiga saiu de cena. */
    case Superseded = 'superseded';
}
