<?php

namespace App\Services\Signing;

use App\Enums\AcceptanceAction;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Recipient;
use App\Models\SigningField;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * O que é apresentado ao signatário e o **snapshot** desse apresentado.
 *
 * O snapshot é o coração da evidência: um aceite só vale para a tela que a pessoa viu. Ele
 * resume, em uma estrutura canônica e ordenada, os bytes do documento (sha256), a versão do
 * texto de aceite, o próprio texto (por hash) e cada campo apresentado com tipo, página,
 * geometria e obrigatoriedade.
 *
 * O hash desse snapshot é gravado em `signing_sessions.snapshot_hash` no momento em que a
 * tela é montada, e reconferido no POST do aceite. Se o remetente trocou o documento, moveu
 * um campo ou mudou a redação do aceite nesse intervalo, o hash não bate e o aceite é
 * recusado — a pessoa vê a tela nova antes de decidir de novo. É o que impede o clássico
 * "assinei uma coisa e ficou registrada outra".
 */
final class SignerPresentation
{
    /**
     * Campos DESTE destinatário na versão apresentada, na ordem de leitura.
     *
     * @return Collection<int, SigningField>
     */
    public function myFields(SignerContext $context, DocumentVersion $version): Collection
    {
        return SigningField::withoutOrganizationScope()
            ->where('envelope_id', $context->envelope->getKey())
            ->where('document_version_id', $version->getKey())
            ->where('recipient_id', $context->recipient->getKey())
            ->orderBy('page')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Campos dos OUTROS destinatários — só posição, tipo e se já foram preenchidos.
     * Nome do participante entra (a pessoa precisa saber quem assina o quê); e-mail, nunca.
     *
     * @return list<array<string, mixed>>
     */
    public function otherFields(SignerContext $context, DocumentVersion $version): array
    {
        /** @var Collection<int, SigningField> $fields */
        $fields = SigningField::withoutOrganizationScope()
            ->where('envelope_id', $context->envelope->getKey())
            ->where('document_version_id', $version->getKey())
            ->where('recipient_id', '!=', $context->recipient->getKey())
            ->orderBy('page')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($fields->isEmpty()) {
            return [];
        }

        /** @var Collection<int, Recipient> $recipients */
        $recipients = Recipient::withoutOrganizationScope()
            ->whereIn('id', $fields->pluck('recipient_id')->unique()->all())
            ->get()
            ->keyBy('id');

        /** @var list<array<string, mixed>> */
        return $fields
            ->map(function (SigningField $field) use ($recipients): array {
                $owner = $recipients->get($field->recipient_id);

                return [
                    'recipient_name' => $owner === null ? 'Outro participante' : $owner->name,
                    'role' => self::roleLabel($owner),
                    'type' => $field->type->value,
                    'page' => (int) $field->page,
                    'x' => (float) $field->x,
                    'y' => (float) $field->y,
                    'w' => (float) $field->width,
                    'h' => (float) $field->height,
                    'signed' => $owner?->status === RecipientStatus::Signed,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Campos DESTE destinatário em TODOS os documentos (Fase 2 §2.3), na ordem de leitura:
     * documento (posição), página, ordem. Com um documento, igual a {@see self::myFields()}.
     *
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @return Collection<int, SigningField>
     */
    public function myFieldsForDocuments(SignerContext $context, array $sent): Collection
    {
        return $this->fieldsForDocuments($context, $sent, mine: true);
    }

    /**
     * Campos dos OUTROS destinatários em todos os documentos (mesmo formato de
     * {@see self::otherFields()}, com `document_id`).
     *
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @return list<array<string, mixed>>
     */
    public function otherFieldsForDocuments(SignerContext $context, array $sent): array
    {
        $fields = $this->fieldsForDocuments($context, $sent, mine: false);

        if ($fields->isEmpty()) {
            return [];
        }

        $ulids = self::documentUlidsByVersion($sent);

        /** @var Collection<int, Recipient> $recipients */
        $recipients = Recipient::withoutOrganizationScope()
            ->whereIn('id', $fields->pluck('recipient_id')->unique()->all())
            ->get()
            ->keyBy('id');

        /** @var list<array<string, mixed>> */
        return $fields
            ->map(function (SigningField $field) use ($recipients, $ulids): array {
                $owner = $recipients->get($field->recipient_id);

                return [
                    'recipient_name' => $owner === null ? 'Outro participante' : $owner->name,
                    'role' => self::roleLabel($owner),
                    'type' => $field->type->value,
                    'page' => (int) $field->page,
                    'x' => (float) $field->x,
                    'y' => (float) $field->y,
                    'w' => (float) $field->width,
                    'h' => (float) $field->height,
                    'signed' => $owner?->status === RecipientStatus::Signed,
                    'document_id' => $ulids[(int) $field->document_version_id] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @return Collection<int, SigningField>
     */
    private function fieldsForDocuments(SignerContext $context, array $sent, bool $mine): Collection
    {
        $order = [];

        foreach ($sent as $index => $row) {
            $order[(int) $row['version']->getKey()] = $index;
        }

        if ($order === []) {
            return new Collection;
        }

        return SigningField::withoutOrganizationScope()
            ->where('envelope_id', $context->envelope->getKey())
            ->whereIn('document_version_id', array_keys($order))
            ->where('recipient_id', $mine ? '=' : '!=', $context->recipient->getKey())
            ->orderBy('page')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->sort(fn (SigningField $a, SigningField $b): int => [
                $order[(int) $a->document_version_id] ?? 0, (int) $a->page, (int) $a->sort_order, (int) $a->id,
            ] <=> [
                $order[(int) $b->document_version_id] ?? 0, (int) $b->page, (int) $b->sort_order, (int) $b->id,
            ])
            ->values();
    }

    /**
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @return array<int, string> id da versão => ULID do documento
     */
    public static function documentUlidsByVersion(array $sent): array
    {
        $map = [];

        foreach ($sent as $row) {
            $map[(int) $row['version']->getKey()] = $row['document']->ulid;
        }

        return $map;
    }

    /**
     * `my_fields` no formato das props (ROUTES §2.18), com os pré-preenchimentos.
     *
     * `date` vem pré-preenchido apenas para exibição: no aceite o valor gravado é sempre o
     * carimbo do servidor (RECONCILIACAO Q9), não o que voltar do cliente.
     *
     * @param  Collection<int, SigningField>  $fields
     * @param  array<int, string>  $documentUlids  id da versão => ULID do documento (Fase 2)
     * @return list<array<string, mixed>>
     */
    public function myFieldProps(Collection $fields, SignerContext $context, array $documentUlids = []): array
    {
        $witness = $context->recipient->role === RecipientRole::Witness;

        /** @var list<array<string, mixed>> */
        return $fields
            ->map(fn (SigningField $field): array => [
                'id' => $field->ulid,
                'type' => $field->type->value,
                'page' => (int) $field->page,
                'x' => (float) $field->x,
                'y' => (float) $field->y,
                'w' => (float) $field->width,
                'h' => (float) $field->height,
                'required' => (bool) $field->required,
                // Testemunha: o campo de assinatura diz "Testemunha" (Fase 2 §2.4).
                'label' => $witness && $field->type === FieldType::Signature && ($field->label === null || $field->label === '')
                    ? 'Testemunha'
                    : $field->label,
                'placeholder' => $witness && $field->type === FieldType::Signature
                    ? 'Clique para assinar como testemunha'
                    : self::placeholder($field),
                'prefill' => $this->prefill($field, $context),
                // Extras ao contrato de ROUTES §2.18, consumidos pelo editor de assinatura:
                'auto' => (bool) ($field->options['auto'] ?? false),
                'server_filled' => $field->type->isServerFilled(),
                'options' => $field->options,
                'document_id' => $documentUlids[(int) $field->document_version_id] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Valor exibido em campos pré-preenchidos.
     */
    public function prefill(SigningField $field, SignerContext $context): ?string
    {
        return match ($field->type) {
            FieldType::Name => $context->recipient->name,
            FieldType::Date => $this->serverDate($field, $context),
            default => null,
        };
    }

    /**
     * Data carimbada pelo servidor, no fuso da organização (RECONCILIACAO Q9).
     * O cliente pode mandar o que quiser: este é o valor que vai para o banco.
     */
    public function serverDate(SigningField $field, SignerContext $context, ?Carbon $now = null): string
    {
        $format = $field->options['date_format'] ?? 'd/m/Y';
        $format = is_string($format) && $format !== '' ? $format : 'd/m/Y';

        $timezone = $context->organization->timezone ?: 'America/Sao_Paulo';

        return ($now ?? Carbon::now())->copy()->setTimezone($timezone)->format($format);
    }

    /**
     * Snapshot canônico do que está sendo apresentado.
     *
     * @param  Collection<int, SigningField>  $myFields
     * @return array<string, mixed>
     */
    public function snapshot(
        SignerContext $context,
        DocumentVersion $version,
        Collection $myFields,
        string $consentText,
    ): array {
        return [
            'schema' => 1,
            'envelope_ulid' => $context->envelope->ulid,
            'recipient_ulid' => $context->recipient->ulid,
            'document' => [
                'version_ulid' => $version->ulid,
                'sha256' => $version->sha256,
                'page_count' => (int) ($version->page_count ?? 0),
            ],
            'terms_version' => ConsentText::versionFor($context->envelope),
            'consent_sha256' => hash('sha256', $consentText),
            'fields' => $myFields
                ->map(fn (SigningField $field): array => [
                    'ulid' => $field->ulid,
                    'type' => $field->type->value,
                    'page' => (int) $field->page,
                    'x' => round((float) $field->x, 6),
                    'y' => round((float) $field->y, 6),
                    'w' => round((float) $field->width, 6),
                    'h' => round((float) $field->height, 6),
                    'required' => (bool) $field->required,
                    'label' => $field->label,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Snapshot do que está sendo apresentado — escolhe o formato.
     *
     * - um documento e papel `signer`: exatamente o snapshot da Fase 1 (`schema` 1);
     * - vários documentos ou papel `witness`/`approver`: `schema` 2, com a lista ordenada
     *   dos documentos (ULID, posição, versão, SHA-256, páginas), a AÇÃO do aceite e cada
     *   campo com o documento a que pertence.
     *
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @param  Collection<int, SigningField>  $myFields
     * @return array<string, mixed>
     */
    public function snapshotFor(SignerContext $context, array $sent, Collection $myFields, string $consentText): array
    {
        $action = $context->action() ?? AcceptanceAction::Sign;

        if (count($sent) === 1 && $action === AcceptanceAction::Sign) {
            return $this->snapshot($context, $sent[0]['version'], $myFields, $consentText);
        }

        $ulids = self::documentUlidsByVersion($sent);

        return [
            'schema' => 2,
            'envelope_ulid' => $context->envelope->ulid,
            'recipient_ulid' => $context->recipient->ulid,
            'action' => $action->value,
            'documents' => array_map(static fn (array $row): array => [
                'document_ulid' => $row['document']->ulid,
                'position' => (int) $row['document']->position,
                'version_ulid' => $row['version']->ulid,
                'sha256' => $row['version']->sha256,
                'page_count' => (int) ($row['version']->page_count ?? 0),
            ], $sent),
            'terms_version' => ConsentText::versionFor($context->envelope, $context->recipient, count($sent)),
            'consent_sha256' => hash('sha256', $consentText),
            'fields' => $myFields
                ->map(fn (SigningField $field): array => self::fieldSnapshot($field) + [
                    'document_ulid' => $ulids[(int) $field->document_version_id] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Um campo como entra no snapshot (mesma forma do `schema` 1).
     *
     * @return array<string, mixed>
     */
    public static function fieldSnapshot(SigningField $field): array
    {
        return [
            'ulid' => $field->ulid,
            'type' => $field->type->value,
            'page' => (int) $field->page,
            'x' => round((float) $field->x, 6),
            'y' => round((float) $field->y, 6),
            'w' => round((float) $field->width, 6),
            'h' => round((float) $field->height, 6),
            'required' => (bool) $field->required,
            'label' => $field->label,
        ];
    }

    /**
     * Hash do snapshot. `JSON_THROW_ON_ERROR` porque um snapshot que não serializa não pode
     * virar hash silenciosamente diferente entre a renderização e o aceite.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function hash(array $snapshot): string
    {
        return hash('sha256', (string) json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    public static function placeholder(SigningField $field): ?string
    {
        return match ($field->type) {
            FieldType::Signature => 'Clique para assinar aqui',
            FieldType::Initials => 'Rubrique aqui',
            FieldType::Name => 'Seu nome completo',
            FieldType::Text => 'Preencha este campo',
            default => null,
        };
    }

    /**
     * Papel livre do participante ("Locatária", "Fiador"), quando a coluna existir.
     */
    public static function roleLabel(?Recipient $recipient): ?string
    {
        if ($recipient === null) {
            return null;
        }

        $label = $recipient->getAttribute('role_label');

        return is_string($label) && $label !== '' ? $label : null;
    }
}
