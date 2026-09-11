<?php

namespace App\Services\Envelopes;

use App\Enums\AuditEventType;
use App\Enums\FieldType;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Documents\EnvelopeDocuments;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Substitui a lista completa de campos do envelope (PUT envelopes.fields.sync,
 * ROUTES §2.6 passo 3).
 *
 * Regras que o servidor NÃO delega ao navegador:
 *  - a página tem de existir no `page_count` do documento;
 *  - o destinatário tem de pertencer ao envelope;
 *  - a geometria é validada contra o `pages_meta` da versão exibida (FieldGeometry);
 *  - `page_width_pt`, `page_height_pt`, `page_rotation` e `box_type` vêm SEMPRE do
 *    `pages_meta`; qualquer valor de página enviado pelo cliente é ignorado.
 *
 * Fase 2 (docs/fase-2/multi-documento-e-papeis.md):
 *  - §2.3 — cada campo pertence a UM documento (`fields.*.document_id`, ULID; ausente = o
 *    primeiro) e referencia a versão exibível exata daquele documento;
 *  - §2.4 — visualizador não recebe campo nenhum; aprovador não recebe assinatura nem
 *    rubrica. A rubrica automática só é gerada para quem assina (signatário/testemunha).
 *
 * Rubrica automática (RECONCILIACAO §4 Q10): com `initials_on_all_pages`, o serviço grava
 * campos `initials` REAIS, um por página, por documento e por destinatário que assina, na
 * posição padrão, pulando as páginas em que já existe uma rubrica posicionada à mão para
 * aquele destinatário. Os campos gerados carregam `options.auto = true` e são
 * descartados/regerados a cada sync.
 */
final class FieldSync
{
    public const MAX_FIELDS = 200;

    /** Formatos aceitos em `options.date_format` (campo `date`, carimbado pelo servidor). */
    public const DATE_FORMATS = ['d/m/Y', 'd/m/Y H:i', 'd \d\e F \d\e Y'];

    public const MIN_FONT_SIZE = 6;

    public const MAX_FONT_SIZE = 24;

    /**
     * @param  array{initials_on_all_pages?: bool, fields: list<array<string, mixed>>}  $data
     * @return array{fields: int, auto_initials: int}
     */
    public function handle(Envelope $envelope, array $data): array
    {
        $targets = $this->targets($envelope);
        $firstUlid = (string) array_key_first($targets);
        $recipients = $envelope->recipients()->get();

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'fields' => 'Adicione pelo menos um signatário antes de posicionar os campos.',
            ]);
        }

        $recipientsByUlid = $recipients->keyBy('ulid');
        $initialsOnAllPages = (bool) ($data['initials_on_all_pages'] ?? false);

        // Campos gerados automaticamente no sync anterior não voltam como "manuais".
        $autoIds = [];

        foreach ($envelope->fields()->get() as $existing) {
            if ((bool) ($existing->options['auto'] ?? false)) {
                $autoIds[] = $existing->ulid;
            }
        }

        $rows = [];
        $errors = [];

        foreach ($data['fields'] as $index => $raw) {
            if ($this->isAutoField($raw, $autoIds)) {
                continue;
            }

            $target = $this->resolveTarget($raw, $targets, $firstUlid, $index, $errors);

            if ($target === null) {
                continue;
            }

            $recipient = $this->resolveRecipient($raw, $recipientsByUlid);

            if ($recipient === null) {
                $errors["fields.{$index}.recipient_id"] = 'Este signatário não pertence ao documento.';

                continue;
            }

            $type = FieldType::tryFrom((string) ($raw['type'] ?? ''));

            if ($type === null) {
                $errors["fields.{$index}.type"] = 'Tipo de campo inválido.';

                continue;
            }

            // Fase 2, onda B: `cpf` (flag `cpf_field`) e `stamp` (flag `branding`) só nascem
            // com a flag; um campo já gravado desse tipo continua aceito.
            if (! FieldTypeAvailability::allows($type, $envelope->organization)
                && ! $this->alreadyExists($envelope, $raw, $type)) {
                $errors["fields.{$index}.type"] = FieldTypeAvailability::unavailableMessage($type);

                continue;
            }

            // Papel (Fase 2 §2.4) — mesma regra da prontidão, recusada já aqui para que o
            // remetente veja o erro no campo e não só como pendência no passo 4.
            if (! $recipient->role->allowsFields()) {
                $errors["fields.{$index}.recipient_id"] = 'Visualizadores só recebem cópia do documento e não podem ter campos.';

                continue;
            }

            if ($type->isImageBased() && ! $recipient->role->allowsVisualSignature()) {
                $errors["fields.{$index}.type"] = 'Aprovadores aprovam o conteúdo sem assinar: não podem ter campo de assinatura ou rubrica.';

                continue;
            }

            /** @var DocumentVersion $version */
            $version = $target['version'];
            $pages = $this->resolvePages($raw, $type, $target['pages'], $index, $errors);

            if ($pages === []) {
                continue;
            }

            $x = (float) ($raw['x'] ?? 0);
            $y = (float) ($raw['y'] ?? 0);
            $width = (float) ($raw['w'] ?? $raw['width'] ?? 0);
            $height = (float) ($raw['h'] ?? $raw['height'] ?? 0);

            $geometryOk = true;

            foreach ($pages as $page) {
                $box = $this->pageBox($version, $page);

                foreach (FieldGeometry::validate($type, $x, $y, $width, $height, $box) as $attribute => $message) {
                    $errors["fields.{$index}.{$attribute}"] = $message;
                    $geometryOk = false;
                }
            }

            if (! $geometryOk) {
                continue;
            }

            $geometry = FieldGeometry::normalize($x, $y, $width, $height);
            $options = $this->options($raw, $type, $index, $errors);

            foreach ($pages as $offset => $page) {
                $rows[] = [
                    // Um campo `page: "all"` vira N linhas; só a primeira reaproveita o id
                    // existente, as demais são novas.
                    'ulid' => $offset === 0 ? $this->incomingUlid($raw) : null,
                    'recipient' => $recipient,
                    'version' => $version,
                    'type' => $type,
                    'page' => $page,
                    'geometry' => $geometry,
                    // Assinatura e rubrica são SEMPRE obrigatórias: o aceite existe para
                    // colher a manifestação de vontade, e "assinar se quiser" não é um
                    // estado do domínio. Aceitar `required: false` aqui produzia um
                    // envelope `ready` cuja própria lista de pendências acusava a falta
                    // do campo de assinatura (as duas leituras da mesma invariante
                    // discordavam) — e ele era enviado assim mesmo.
                    'required' => match ($type) {
                        FieldType::Signature, FieldType::Initials => true,
                        FieldType::Checkbox => (bool) ($raw['required'] ?? false),
                        default => (bool) ($raw['required'] ?? true),
                    },
                    'label' => $this->label($raw),
                    'options' => $options,
                ];
            }

            // Limite verificado durante a construção: um campo `page: "all"` em um documento
            // muito longo não pode inflar a lista antes da checagem final.
            if (count($rows) > self::MAX_FIELDS) {
                throw ValidationException::withMessages([
                    'fields' => 'O documento aceita no máximo '.self::MAX_FIELDS.' campos.',
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if (count($rows) > self::MAX_FIELDS) {
            throw ValidationException::withMessages([
                'fields' => 'O documento aceita no máximo '.self::MAX_FIELDS.' campos.',
            ]);
        }

        // A rubrica automática vale para quem ASSINA: aprovador e visualizador não rubricam.
        $initialing = $recipients
            ->filter(fn (Recipient $recipient): bool => $recipient->role->allowsVisualSignature())
            ->values();

        $totalPages = array_sum(array_map(
            static fn (array $target): int => $target['version'] === null ? 0 : $target['pages'],
            $targets,
        ));

        if ($initialsOnAllPages && count($rows) + ($initialing->count() * $totalPages) > self::MAX_FIELDS) {
            throw ValidationException::withMessages([
                'initials_on_all_pages' => 'A rubrica em todas as páginas ultrapassa o limite de '.self::MAX_FIELDS.' campos. Reduza a quantidade de campos ou de signatários.',
            ]);
        }

        $autoRows = $initialsOnAllPages
            ? $this->autoInitialsRows($rows, $initialing, $targets)
            : [];

        $this->persist($envelope, array_merge($rows, $autoRows));

        $settings = $envelope->settings ?? [];
        $settings['initials_on_all_pages'] = $initialsOnAllPages;
        $envelope->forceFill(['settings' => $settings])->save();

        $envelope->unsetRelation('fields');

        /** @var DocumentVersion $firstVersion */
        $firstVersion = $targets[$firstUlid]['version'];

        $payload = [
            'document_version' => $firstVersion->ulid,
            'count' => count($rows) + count($autoRows),
            'auto_initials' => count($autoRows),
            'initials_on_all_pages' => $initialsOnAllPages,
        ];

        if (count($targets) > 1) {
            $payload['documents'] = count($targets);
        }

        EnvelopeAudit::record($envelope, AuditEventType::FieldsUpdated, $payload);

        EnvelopeReadiness::refresh($envelope);

        return ['fields' => count($rows), 'auto_initials' => count($autoRows)];
    }

    // -- Persistência ------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persist(Envelope $envelope, array $rows): void
    {
        DB::transaction(function () use ($envelope, $rows): void {
            // Sob lock, dentro da transação: entre o carregamento do envelope e este
            // ponto o envio pode ter acontecido, e apagar campos de um envelope enviado
            // apagaria em cascata os `signing_field_values` de aceites já gravados.
            PreparationGuard::lockForPreparation($envelope, 'fields');

            $existing = $envelope->fields()->get()->keyBy('ulid');
            $keptIds = [];
            $sortOrder = [];
            $usedUlids = [];

            foreach ($rows as $row) {
                /** @var Recipient $recipient */
                $recipient = $row['recipient'];
                /** @var DocumentVersion $version */
                $version = $row['version'];
                $page = (int) $row['page'];
                $box = $this->pageBox($version, $page);

                $ulid = $row['ulid'];
                $field = $ulid !== null && ! isset($usedUlids[$ulid]) ? $existing->get($ulid) : null;

                if ($field !== null) {
                    $usedUlids[$ulid] = true;
                }

                if ($field === null) {
                    $field = new SigningField;
                    $field->envelope_id = $envelope->getKey();
                    $field->organization_id = $envelope->organization_id;
                }

                $slot = $version->getKey().':'.$page;
                $sortOrder[$slot] = ($sortOrder[$slot] ?? 0) + 1;

                $field->forceFill([
                    'document_version_id' => $version->getKey(),
                    'recipient_id' => $recipient->getKey(),
                    'type' => $row['type'],
                    'page' => $page,
                    'x' => $row['geometry']['x'],
                    'y' => $row['geometry']['y'],
                    'width' => $row['geometry']['width'],
                    'height' => $row['geometry']['height'],
                    // Nunca vem do navegador: sempre do pages_meta da versão exibida.
                    'box_type' => $box->boxType,
                    'page_width_pt' => $box->displayedWidth(),
                    'page_height_pt' => $box->displayedHeight(),
                    'page_rotation' => $box->rotation,
                    'required' => $row['required'],
                    'label' => $row['label'],
                    'options' => $row['options'],
                    'sort_order' => $sortOrder[$slot],
                ])->save();

                $keptIds[] = $field->getKey();
            }

            $envelope->fields()
                ->whereNotIn('id', $keptIds === [] ? [0] : $keptIds)
                ->delete();
        });
    }

    // -- Rubrica automática ------------------------------------------------------------

    /**
     * Uma rubrica por página, por documento e por destinatário que assina, pulando as
     * páginas em que já existe uma rubrica manual daquele destinatário.
     *
     * @param  list<array<string, mixed>>  $manualRows
     * @param  Collection<int, Recipient>  $recipients
     * @param  array<string, array{document: Document, version: DocumentVersion|null, pages: int}>  $targets
     * @return list<array<string, mixed>>
     */
    private function autoInitialsRows(array $manualRows, Collection $recipients, array $targets): array
    {
        $manual = [];

        foreach ($manualRows as $row) {
            if ($row['type'] === FieldType::Initials) {
                /** @var Recipient $recipient */
                $recipient = $row['recipient'];
                /** @var DocumentVersion $version */
                $version = $row['version'];
                $manual[$recipient->getKey().':'.$version->getKey().':'.$row['page']] = true;
            }
        }

        $rows = [];
        $slot = 0;

        foreach ($recipients as $recipient) {
            // Uma posição por DESTINATÁRIO (a mesma em todas as páginas, para que a
            // rubrica de cada pessoa caia sempre no mesmo canto), deslocada da do
            // vizinho — ver {@see FieldGeometry::autoInitialsSlot()}.
            $geometry = FieldGeometry::autoInitialsSlot($slot++);

            foreach ($targets as $target) {
                $version = $target['version'];

                if ($version === null) {
                    continue;
                }

                for ($page = 1; $page <= $target['pages']; $page++) {
                    if (isset($manual[$recipient->getKey().':'.$version->getKey().':'.$page])) {
                        continue;
                    }

                    $rows[] = [
                        'ulid' => null,
                        'recipient' => $recipient,
                        'version' => $version,
                        'type' => FieldType::Initials,
                        'page' => $page,
                        'geometry' => $geometry,
                        'required' => true,
                        'label' => null,
                        'options' => ['auto' => true],
                    ];
                }
            }
        }

        return $rows;
    }

    // -- Leitura dos documentos --------------------------------------------------------

    /**
     * Documentos do envelope (ordem de apresentação) com a versão exibível e o total de
     * páginas. O PRIMEIRO precisa estar processado — é a regra da Fase 1; os demais são
     * conferidos por campo, em {@see self::resolveTarget()}.
     *
     * @return array<string, array{document: Document, version: DocumentVersion|null, pages: int}>
     */
    private function targets(Envelope $envelope): array
    {
        $documents = EnvelopeDocuments::ordered($envelope);
        $targets = [];

        foreach ($documents as $document) {
            $version = $document->current_version_id !== null
                ? DocumentVersion::query()->whereKey($document->current_version_id)->first()
                : null;

            $targets[$document->ulid] = [
                'document' => $document,
                'version' => $version,
                'pages' => $version === null ? 0 : (int) ($version->page_count ?? $document->page_count),
            ];
        }

        $first = $targets === [] ? null : reset($targets);

        if ($first === null || $first['version'] === null) {
            throw ValidationException::withMessages([
                'fields' => 'Envie e processe o documento antes de posicionar os campos.',
            ]);
        }

        if ($first['pages'] < 1) {
            throw ValidationException::withMessages([
                'fields' => 'O documento ainda não tem páginas conhecidas. Aguarde o processamento terminar.',
            ]);
        }

        return $targets;
    }

    /**
     * Documento de destino do campo. Sem `document_id`, o primeiro (contrato da Fase 1).
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, array{document: Document, version: DocumentVersion|null, pages: int}>  $targets
     * @param  array<string, string>  $errors
     * @return array{document: Document, version: DocumentVersion, pages: int}|null
     */
    private function resolveTarget(array $raw, array $targets, string $firstUlid, int $index, array &$errors): ?array
    {
        $ulid = $raw['document_id'] ?? null;
        $ulid = is_string($ulid) && $ulid !== '' ? $ulid : $firstUlid;

        $target = $targets[$ulid] ?? null;

        if ($target === null) {
            $errors["fields.{$index}.document_id"] = 'Este arquivo não pertence ao documento.';

            return null;
        }

        if ($target['version'] === null || $target['pages'] < 1) {
            $errors["fields.{$index}.document_id"] = sprintf(
                'O arquivo "%s" ainda não foi processado. Aguarde antes de posicionar campos nele.',
                $target['document']->original_filename,
            );

            return null;
        }

        /** @var array{document: Document, version: DocumentVersion, pages: int} $target */
        return $target;
    }

    /**
     * Caixa da página vinda de `pages_meta`. Sem meta para a página (documento antigo ou
     * conversão parcial), cai para A4 retrato sem rotação — nunca para valores do cliente.
     */
    private function pageBox(DocumentVersion $version, int $page): PageBox
    {
        $meta = $version->pageMeta($page);

        if ($meta === null) {
            return PageBox::fromPageMeta(['width_pt' => 595.276, 'height_pt' => 841.89, 'rotation' => 0]);
        }

        $box = PageBox::fromPageMeta($meta);

        return $box->isDegenerate()
            ? PageBox::fromPageMeta(['width_pt' => 595.276, 'height_pt' => 841.89, 'rotation' => 0])
            : $box;
    }

    // -- Normalização da entrada -------------------------------------------------------

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $autoIds
     */
    private function isAutoField(array $raw, array $autoIds): bool
    {
        if (filter_var($raw['auto'] ?? false, FILTER_VALIDATE_BOOL)) {
            return true;
        }

        $ulid = $this->incomingUlid($raw);

        return $ulid !== null && in_array($ulid, $autoIds, true);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function incomingUlid(array $raw): ?string
    {
        $ulid = $raw['id'] ?? null;

        return is_string($ulid) && strlen($ulid) === 26 ? $ulid : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  Collection<string, Recipient>  $recipientsByUlid
     */
    private function resolveRecipient(array $raw, $recipientsByUlid): ?Recipient
    {
        foreach (['recipient_id', 'recipient_client_id'] as $key) {
            $value = $raw[$key] ?? null;

            if (is_string($value) && $recipientsByUlid->has($value)) {
                return $recipientsByUlid->get($value);
            }
        }

        return null;
    }

    /**
     * `page` é 1-based; `'all'` só vale para `initials` (contrato ROUTES §2.6).
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $errors
     * @return list<int>
     */
    private function resolvePages(array $raw, FieldType $type, int $pageCount, int $index, array &$errors): array
    {
        $page = $raw['page'] ?? null;

        if (is_string($page) && strtolower($page) === 'all') {
            if ($type !== FieldType::Initials) {
                $errors["fields.{$index}.page"] = 'Só a rubrica pode ser aplicada a todas as páginas.';

                return [];
            }

            return range(1, $pageCount);
        }

        if (! is_numeric($page)) {
            $errors["fields.{$index}.page"] = 'Informe a página do campo.';

            return [];
        }

        $page = (int) $page;

        if ($page < 1 || $page > $pageCount) {
            $errors["fields.{$index}.page"] = "O documento tem {$pageCount} página(s); a página {$page} não existe.";

            return [];
        }

        return [$page];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function label(array $raw): ?string
    {
        $label = $raw['label'] ?? null;

        if (! is_string($label)) {
            return null;
        }

        $label = trim($label);

        return $label === '' ? null : mb_substr($label, 0, 120);
    }

    /**
     * `options` por tipo (arquitetura §3.1: font_size, date_format, default).
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>  $errors
     * @return array<string, mixed>|null
     */
    private function options(array $raw, FieldType $type, int $index, array &$errors): ?array
    {
        $incoming = is_array($raw['options'] ?? null) ? $raw['options'] : [];
        $options = [];

        $placeholder = $raw['placeholder'] ?? $incoming['placeholder'] ?? null;

        if (is_string($placeholder) && trim($placeholder) !== '') {
            $options['placeholder'] = mb_substr(trim($placeholder), 0, 60);
        }

        if (in_array($type, [FieldType::Name, FieldType::Date, FieldType::Text, FieldType::Cpf], true)) {
            $fontSize = $incoming['font_size'] ?? null;

            if ($fontSize !== null) {
                if (! is_numeric($fontSize) || (float) $fontSize < self::MIN_FONT_SIZE || (float) $fontSize > self::MAX_FONT_SIZE) {
                    $errors["fields.{$index}.options.font_size"] = 'O tamanho da fonte deve estar entre '.self::MIN_FONT_SIZE.' e '.self::MAX_FONT_SIZE.' pontos.';
                } else {
                    $options['font_size'] = (float) $fontSize;
                }
            }
        }

        if ($type === FieldType::Date) {
            $format = $incoming['date_format'] ?? null;

            if ($format !== null) {
                if (! is_string($format) || ! in_array($format, self::DATE_FORMATS, true)) {
                    $errors["fields.{$index}.options.date_format"] = 'Formato de data não suportado.';
                } else {
                    $options['date_format'] = $format;
                }
            }

            $options['date_format'] ??= self::DATE_FORMATS[0];
        }

        if ($type === FieldType::Checkbox) {
            $options['default'] = filter_var($incoming['default'] ?? false, FILTER_VALIDATE_BOOL);
        }

        return $options === [] ? null : $options;
    }

    /**
     * O campo enviado é um campo JÁ GRAVADO deste envelope, com o mesmo tipo?
     *
     * @param  array<string, mixed>  $raw
     */
    private function alreadyExists(Envelope $envelope, array $raw, FieldType $type): bool
    {
        $ulid = $this->incomingUlid($raw);

        return $ulid !== null && $envelope->fields()
            ->where('ulid', $ulid)
            ->where('type', $type->value)
            ->exists();
    }
}
