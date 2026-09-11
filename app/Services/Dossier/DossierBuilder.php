<?php

namespace App\Services\Dossier;

use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\VerificationRecordDocument;
use App\Services\Documents\DocumentStorage;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\TemporaryDirectory;
use App\Services\Signing\Certificates\CertificateInspection;
use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\Models\TimestampToken;
use App\Services\Timestamp\OperatorTsa;
use App\Services\Timestamp\OperatorTsaConfig;
use App\Services\Timestamp\TimestampFeatures;
use App\Services\Timestamp\TimestampTokens;
use App\Services\Timestamp\TimestampVerifier;
use App\Services\Timestamp\TsaKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Monta o dossiê ZIP de UM envelope concluído (roadmap §2.13).
 *
 * Conteúdo (e só isto):
 *
 * - `documentos/NN-nome/vK-tipo.ext` — cada versão guardada de cada documento (original,
 *   convertida, consolidada, página de evidências, final e qualquer revisão futura);
 * - `trilha/auditoria.json` e `trilha/auditoria.csv` — a trilha do envelope;
 * - `validacao.json` — resultado criptográfico gravado na conclusão + revalidação no momento
 *   da montagem + carimbos de assinatura existentes;
 * - `carimbos/*.tsr` — carimbos de assinatura já guardados (se houver);
 * - `LEIA-ME.txt` — como conferir cada resumo e o carimbo;
 * - `manifest.json` — o SHA-256 de cada arquivo acima e o que ele identifica;
 * - `carimbo/manifesto.tsr` (+ `carimbo/carimbo.json`, `carimbo/cadeia-tsa.pem`) — com a
 *   flag `operator_tsa`: token RFC 3161 da TSA da operadora sobre o SHA-256 do `manifest.json`.
 *   Fica FORA do manifesto por construção (é emitido depois dele).
 *
 * Nunca: tokens de acesso, digests de código, senhas, PFX, chaves, caminhos do disco, imagens
 * de assinatura ou de captura. IP e e-mail seguem `evidence_show_ip` ({@see DossierRedaction}).
 *
 * Reprodutível: o mesmo envelope, com a mesma trilha, gera o mesmo `manifest.json` (nenhuma
 * data de montagem dentro dele; mtime fixo das entradas = conclusão do envelope).
 * Nenhuma transação de banco fica aberta durante as chamadas ao pdftool.
 */
final class DossierBuilder
{
    public const FORMAT = 'assinavelox-dossier/1';

    public const MANIFEST = 'manifest.json';

    public const README = 'LEIA-ME.txt';

    /** O que o dossiê nunca contém (repetido no manifesto e no LEIA-ME). */
    public const NEVER_INCLUDED = [
        'tokens de convite, de sessão ou de API',
        'códigos de uso único (OTP) ou seus resumos',
        'PIN do remetente ou seu resumo',
        'senhas, arquivos PFX, chaves privadas ou segredos de webhook',
        'caminhos internos de armazenamento',
        'imagens da assinatura desenhada e fotos da captura de identidade',
    ];

    private const KIND_SLUGS = [
        'original' => 'original',
        'converted' => 'convertido',
        'consolidated' => 'consolidado',
        'evidence' => 'evidencias',
        'final' => 'final',
        // Onda C (K-A1): base congelada para as assinaturas e cada revisão incremental assinada.
        'pre_signature' => 'base-para-assinatura',
        'signed_incremental' => 'revisao-assinada',
    ];

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditTrailExport $trail,
        private readonly PdfToolClient $pdftool,
        private readonly OperatorTsa $tsa,
        private readonly OperatorTsaConfig $tsaConfig,
        private readonly TimestampTokens $tokens,
        private readonly TimestampVerifier $verifier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  bool  $stamp  carimbar o manifesto (só vale com `operator_tsa` ligada)
     */
    public function build(Envelope $envelope, string $zipPath, TemporaryDirectory $work, ?int $dossierExportId = null, bool $stamp = true, ?string $correlationId = null): BuiltDossier
    {
        if ($envelope->status !== EnvelopeStatus::Completed) {
            throw new DossierException('O dossiê só está disponível para documentos concluídos.', 'envelope_not_completed');
        }

        $correlationId ??= (string) Str::ulid();
        $envelope->loadMissing(['organization', 'recipients.acceptance', 'verificationRecord.documents']);
        $organization = $envelope->organization;
        $redaction = DossierRedaction::for($organization);
        $mtime = ($envelope->completed_at ?? $envelope->updated_at ?? Carbon::now())->getTimestamp();
        $staging = $work->subdirectory('dossie-'.$envelope->ulid);

        /** @var array<string, array{source: string, sha256: string, size: int, meta: array<string, mixed>}> $files */
        $files = [];
        $documentsMeta = [];

        foreach (EnvelopeDocuments::ordered($envelope) as $document) {
            $documentsMeta[] = $this->collectDocument($envelope, $document, $work, $files);
        }

        $rows = $this->trail->rows($envelope, $redaction);
        $this->addString($files, $staging, 'trilha/auditoria.json', $this->trail->json($envelope, $rows, $redaction->ipMode()), [
            'kind' => 'audit_trail_json',
            'description' => 'Trilha de auditoria do envelope (JSON), até a montagem do dossiê. IP e e-mail conforme a política de exibição.',
        ]);
        $this->addString($files, $staging, 'trilha/auditoria.csv', $this->trail->csv($rows), [
            'kind' => 'audit_trail_csv',
            'description' => 'A mesma trilha em CSV (;), com proteção contra fórmulas de planilha.',
        ]);

        $this->addString($files, $staging, 'validacao.json', $this->validationJson($envelope, $files, $staging, $correlationId), [
            'kind' => 'validation',
            'description' => 'Resultado criptográfico gravado na conclusão, revalidação no momento da montagem e carimbos de assinatura existentes.',
        ]);

        $this->addString($files, $staging, self::README, $this->readme($envelope, $stamp && TimestampFeatures::operatorTsa()), [
            'kind' => 'readme',
            'description' => 'Instruções para conferir cada resumo e o carimbo do tempo.',
        ]);

        ksort($files, SORT_STRING);
        $manifest = $this->manifest($envelope, $redaction, $files, $documentsMeta);
        $manifestSha256 = hash('sha256', $manifest);

        $entries = [self::MANIFEST => ['string' => $manifest]];

        foreach ($files as $name => $file) {
            $entries[$name] = ['file' => $file['source']];
        }

        [$timestampStatus, $timestampTokenId, $stampEntries] = $this->stampManifest($envelope, $manifestSha256, $stamp, $dossierExportId, $correlationId);
        $entries = [...$entries, ...$stampEntries];

        $this->writeZip($zipPath, $entries, $mtime);

        $size = (int) filesize($zipPath);
        $max = max(1, (int) config('assinavelox.dossier.max_mb', 500)) * 1024 * 1024;

        if ($size > $max) {
            @unlink($zipPath);

            throw new DossierException('O dossiê ultrapassa o tamanho máximo permitido.', 'dossier_too_large');
        }

        return new BuiltDossier(
            path: $zipPath,
            sha256: (string) hash_file('sha256', $zipPath),
            sizeBytes: $size,
            manifestSha256: $manifestSha256,
            timestampStatus: $timestampStatus,
            timestampTokenId: $timestampTokenId,
            envelopeCount: 1,
            entries: array_keys($entries),
        );
    }

    /**
     * @param  array<string, array{source: string, sha256: string, size: int, meta: array<string, mixed>}>  $files
     * @return array<string, mixed>
     */
    private function collectDocument(Envelope $envelope, Document $document, TemporaryDirectory $work, array &$files): array
    {
        $folder = sprintf('documentos/%02d-%s', (int) $document->position, $this->slug($document->name ?: 'documento'));
        $versions = DocumentVersion::withoutOrganizationScope()
            ->where('organization_id', $envelope->organization_id)
            ->where('document_id', $document->getKey())
            ->orderBy('version_number')
            ->orderBy('id')
            ->get();

        $items = [];

        foreach ($versions as $version) {
            $kind = $version->kind->value;
            $extension = strtolower((string) pathinfo($version->storage_path, PATHINFO_EXTENSION)) ?: 'bin';
            $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
            $name = sprintf('%s/v%d-%s.%s', $folder, (int) $version->version_number, self::KIND_SLUGS[$kind], $extension);

            $item = [
                'version_number' => (int) $version->version_number,
                'kind' => $kind,
                'recorded_sha256' => $version->sha256,
                'is_sent_version' => $document->sent_version_id === $version->getKey(),
                'is_final_version' => $document->final_version_id === $version->getKey() || $envelope->final_document_version_id === $version->getKey(),
            ];

            if (! $this->storage->exists($version)) {
                $items[] = [...$item, 'path' => null, 'status' => 'missing', 'note' => 'Arquivo não encontrado no armazenamento no momento da montagem.'];

                continue;
            }

            $local = $this->storage->copyToTemporary($version, $work, sprintf('%s-v%d.%s', $envelope->ulid, (int) $version->getKey(), $extension));
            $sha256 = (string) hash_file('sha256', $local);

            $files[$name] = [
                'source' => $local,
                'sha256' => $sha256,
                'size' => (int) filesize($local),
                'meta' => [
                    'kind' => 'document_version',
                    'version_kind' => $kind,
                    'document_position' => (int) $document->position,
                    'document_name' => $document->name,
                    'version_number' => (int) $version->version_number,
                    'mime_type' => $version->mime_type,
                    'has_signatures' => (bool) $version->has_signatures,
                    'recorded_sha256' => $version->sha256,
                    'matches_record' => hash_equals((string) $version->sha256, $sha256),
                    'description' => $this->versionDescription($version->kind, $item['is_sent_version'], $item['is_final_version']),
                ],
            ];

            $items[] = [...$item, 'path' => $name, 'status' => 'included', 'matches_record' => hash_equals((string) $version->sha256, $sha256)];
        }

        return [
            'position' => (int) $document->position,
            'name' => $document->name,
            'original_filename' => $document->original_filename,
            'versions' => $items,
        ];
    }

    private function versionDescription(DocumentVersionKind $kind, bool $sent, bool $final): string
    {
        $base = match ($kind) {
            DocumentVersionKind::Original => 'O arquivo como foi enviado pela organização, antes de qualquer conversão.',
            DocumentVersionKind::Converted => 'A conversão para PDF do arquivo original.',
            DocumentVersionKind::Consolidated => 'O PDF com os campos autorizados achatados e o rodapé, antes da página de evidências.',
            DocumentVersionKind::Evidence => 'A página de evidências gerada na conclusão.',
            DocumentVersionKind::Final => 'O arquivo final entregue (consolidado + evidências + assinatura, quando houver).',
            DocumentVersionKind::PreSignature => 'A base congelada (consolidado + evidências) sobre a qual os participantes assinaram com o próprio certificado.',
            DocumentVersionKind::SignedIncremental => 'Revisão incremental com a assinatura do certificado de um participante: os bytes da revisão anterior são o início exato deste arquivo.',
        };

        if ($sent) {
            $base .= ' É a versão congelada no envio e apresentada aos participantes (o resumo citado em cada aceite).';
        }

        if ($final) {
            $base .= ' Seu resumo é o publicado na verificação pública.';
        }

        return $base;
    }

    /**
     * @param  array<string, array{source: string, sha256: string, size: int, meta: array<string, mixed>}>  $files
     */
    private function validationJson(Envelope $envelope, array $files, string $staging, string $correlationId): string
    {
        $record = $envelope->verificationRecord;
        $revalidation = [];

        if ((bool) config('assinavelox.dossier.revalidate_signatures', true)) {
            foreach ($files as $name => $file) {
                if (($file['meta']['version_kind'] ?? null) !== DocumentVersionKind::Final->value || ! str_ends_with($name, '.pdf')) {
                    continue;
                }

                if (($file['meta']['has_signatures'] ?? false) !== true) {
                    $revalidation[] = ['file' => $name, 'status' => 'not_signed', 'note' => 'Arquivo sem assinatura criptográfica: não há o que validar.'];

                    continue;
                }

                try {
                    $result = $this->pdftool->validate($file['source'], array_values((array) config('pdftool.trust_roots', [])), $correlationId);
                    // Mesmo mascaramento do validation_result publicado: o CN de um e-CPF
                    // (`NOME:CPF`) nunca sai com o CPF completo.
                    $revalidation[] = ['file' => $name, 'status' => 'checked', 'result' => CertificateInspection::maskSignatureSummary($result->summary())];
                } catch (Throwable $exception) {
                    $revalidation[] = ['file' => $name, 'status' => 'unavailable', 'note' => 'Não foi possível revalidar no momento da montagem ('.class_basename($exception).').'];
                }
            }
        }

        $signatureStamps = TimestampToken::withoutOrganizationScope()
            ->where('organization_id', $envelope->organization_id)
            ->where('envelope_id', $envelope->getKey())
            ->where('purpose', TimestampToken::PURPOSE_SIGNATURE)
            ->orderBy('id')
            ->get()
            ->map(fn (TimestampToken $token): array => [
                'tsa_kind' => $token->tsa_kind->value,
                'label' => $token->tsa_kind->label(),
                'serial' => $token->serial,
                'gen_time' => $token->gen_time->toIso8601ZuluString('millisecond'),
                'policy_oid' => $token->policy_oid,
                'announced_profile' => 'PAdES-B-B',
            ])
            ->values()
            ->all();

        unset($staging);

        return (string) json_encode([
            'format' => 'assinavelox-validation/1',
            'signature_status' => $record?->signature_status->value,
            'signature_profile' => $record?->signature_profile,
            'recorded_at_completion' => $record?->validation_result,
            'revalidated_at_build' => $revalidation,
            'signature_timestamps' => $signatureStamps,
            'notes' => [
                'revocation' => 'Revogação (CRL/OCSP) não é verificada pelo pipeline: o pdftool roda sem rede.',
                'profile' => 'O perfil anunciado é PAdES-B-B. Nenhum perfil acima de B-B é afirmado (roadmap T2).',
                'icp_brasil' => 'Nada neste dossiê afirma validade ICP-Brasil.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * @param  array<string, array{source: string, sha256: string, size: int, meta: array<string, mixed>}>  $files
     * @param  list<array<string, mixed>>  $documentsMeta
     */
    private function manifest(Envelope $envelope, DossierRedaction $redaction, array $files, array $documentsMeta): string
    {
        $record = $envelope->verificationRecord;
        $organization = $envelope->organization;

        $participants = $envelope->recipients
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->map(fn (Recipient $recipient): array => [
                'name' => $recipient->name,
                'email' => $redaction->email($recipient->email),
                'role' => $recipient->role->value,
                'role_label' => $recipient->role->label(),
                'status' => $recipient->status->value,
                'auth_method' => ($recipient->acceptance->auth_method ?? $recipient->auth_method)->value,
                'accepted_at' => $recipient->acceptance?->accepted_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'refused_at' => $recipient->refused_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'ip' => $redaction->ip($recipient->acceptance?->ip_address, $organization),
                'accepted_document_sha256' => $recipient->acceptance?->document_sha256,
                'terms_version' => $recipient->acceptance?->terms_version,
            ])
            ->values()
            ->all();

        $list = [];

        foreach ($files as $name => $file) {
            $list[] = ['path' => $name, 'sha256' => $file['sha256'], 'size_bytes' => $file['size'], ...$file['meta']];
        }

        return (string) json_encode([
            'format' => self::FORMAT,
            'generator' => 'AssinaVelox',
            'hash_algorithm' => 'sha256',
            'envelope' => [
                'display_code' => $envelope->display_code,
                'title' => $envelope->title,
                'status' => $envelope->status->value,
                'verification_code' => $envelope->formatted_verification_code,
                'verify_url' => $envelope->verification_code ? route('verify.show', ['code' => $envelope->verification_code]) : null,
                'sent_at' => $envelope->sent_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'completed_at' => $envelope->completed_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'terms_version' => $envelope->terms_version,
                'signature_status' => $record?->signature_status->value,
                'signature_profile' => $record?->signature_profile,
            ],
            'organization' => ['name' => $organization->name],
            'ip_policy' => $redaction->ipMode(),
            'email_policy' => $redaction->ipMode() === 'full' ? 'full' : 'masked',
            'published_hashes' => [
                'sent_sha256' => $record?->sent_sha256,
                'final_sha256' => $record?->final_sha256,
                'documents' => $record === null ? [] : $record->documents->map(fn (VerificationRecordDocument $row): array => [
                    'position' => (int) $row->position,
                    'name' => $row->name,
                    'sent_sha256' => $row->sent_sha256,
                    'final_sha256' => $row->final_sha256,
                ])->values()->all(),
            ],
            'participants' => $participants,
            'documents' => $documentsMeta,
            'files' => $list,
            'outside_manifest' => [
                'carimbo/manifesto.tsr' => 'Token RFC 3161 sobre o SHA-256 deste manifest.json (emitido depois dele).',
                'carimbo/carimbo.json' => 'Metadados do carimbo e resultado da conferência.',
                'carimbo/cadeia-tsa.pem' => 'Cadeia pública da TSA da operadora.',
            ],
            'never_included' => self::NEVER_INCLUDED,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * @return array{0: string, 1: int|null, 2: array<string, array{string?: string, file?: string}>}
     */
    private function stampManifest(Envelope $envelope, string $manifestSha256, bool $stamp, ?int $dossierExportId, string $correlationId): array
    {
        if (! $stamp || ! TimestampFeatures::operatorTsa()) {
            return ['disabled', null, []];
        }

        try {
            $issued = $this->tsa->stampDigest($manifestSha256, 'sha256', 'dossier_manifest', (int) $envelope->organization_id, $correlationId);
        } catch (TsaException $exception) {
            $this->logger->warning('Dossiê montado SEM carimbo: TSA da operadora indisponível.', [
                'envelope_id' => $envelope->getKey(),
                'error_code' => $exception->errorCode,
                'correlation_id' => $correlationId,
            ]);

            return ['unavailable', null, ['carimbo/INDISPONIVEL.txt' => ['string' => "O carimbo do tempo da operadora estava indisponível na montagem deste dossiê (código {$exception->errorCode}).\nO manifest.json e os arquivos continuam conferíveis pelos resumos SHA-256.\n"]]];
        }

        $token = $this->tokens->recordOperator((int) $envelope->organization_id, $issued, TimestampToken::PURPOSE_DOSSIER_MANIFEST, $envelope, $dossierExportId);

        $verification = null;

        try {
            $verification = TimestampVerifier::summary($this->verifier->verify($issued->tokenDer, $manifestSha256, 'sha256', $issued->policyOid));
            $token->forceFill(['verification' => $verification])->save();
        } catch (Throwable) {
            $verification = ['valid' => null, 'note' => 'Conferência automática indisponível na montagem.'];
        }

        $entries = [
            'carimbo/manifesto.tsr' => ['string' => $issued->responseDer],
            'carimbo/carimbo.json' => ['string' => (string) json_encode([
                'tsa_kind' => TsaKind::Operator->value,
                'label' => TsaKind::Operator->label(),
                'statement' => TsaKind::Operator->statement(),
                'test_tsa' => $issued->testCertificate || $issued->environment !== 'production',
                'stamped_file' => self::MANIFEST,
                'stamped_sha256' => $manifestSha256,
                'hash_algorithm' => $issued->hashAlgorithm,
                'serial' => $issued->serial,
                'gen_time' => $issued->genTime,
                'accuracy_ms' => $issued->accuracyMs,
                'policy_oid' => $issued->policyOid,
                'tsa_subject' => $issued->tsaSubject,
                'tsa_cert_fingerprint_sha256' => $issued->tsaCertFingerprint,
                'verification_at_build' => $verification,
                'how_to_verify' => 'openssl ts -verify -data manifest.json -in carimbo/manifesto.tsr -CAfile <raiz da AC interna da operadora>',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"],
        ];

        // Só os blocos CERTIFICATE do arquivo configurado — nunca o arquivo byte a byte: o PEM
        // extraído com `openssl pkcs12 -nodes` traz a CHAVE PRIVADA da TSA junto.
        $chain = $this->tsaConfig->chainCertificatesPem();

        if ($chain !== null) {
            $entries['carimbo/cadeia-tsa.pem'] = ['string' => $chain];
        }

        return ['granted', (int) $token->getKey(), $entries];
    }

    private function readme(Envelope $envelope, bool $stamped): string
    {
        $lines = [
            'DOSSIÊ DO DOCUMENTO '.$envelope->display_code.' — AssinaVelox',
            str_repeat('=', 60),
            '',
            'Este arquivo reúne os arquivos do envelope, a trilha de auditoria, o resultado das',
            'validações criptográficas e o manifesto com o resumo SHA-256 de cada arquivo.',
            '',
            '1. Conferir um arquivo',
            '   Calcule o SHA-256 do arquivo e compare com o valor em manifest.json ("files"):',
            '     Linux/macOS:  sha256sum documentos/01-.../v4-final.pdf',
            '     Windows:      certutil -hashfile documentos\\01-...\\v4-final.pdf SHA256',
            '   O resumo do arquivo final também está publicado em '.($envelope->verification_code ? route('verify.show', ['code' => $envelope->verification_code]) : 'verificação pública').'.',
            '',
            '2. Um resumo SHA-256 não é uma assinatura: ele só prova que dois arquivos são idênticos.',
            '',
            '3. Carimbo do tempo',
        ];

        if ($stamped) {
            $lines = [...$lines,
                '   carimbo/manifesto.tsr é um carimbo do tempo RFC 3161 sobre o SHA-256 de manifest.json.',
                '   '.TsaKind::Operator->label().'.',
                '   Ele prova apenas que a AssinaVelox atesta que o manifesto existia no horário indicado.',
                '   Conferência independente (OpenSSL):',
                '     openssl ts -verify -data manifest.json -in carimbo/manifesto.tsr -CAfile <raiz-da-AC-interna.pem>',
                '   A cadeia pública da TSA, quando configurada, está em carimbo/cadeia-tsa.pem.',
                '   Se existir carimbo/INDISPONIVEL.txt, a TSA estava fora do ar e o dossiê saiu sem carimbo.',
            ];
        } else {
            $lines[] = '   Este dossiê não contém carimbo do tempo (recurso desligado nesta instalação).';
        }

        return implode("\n", [...$lines,
            '',
            '4. Perfil da assinatura: o único perfil afirmado é PAdES-B-B. Nada neste dossiê afirma',
            '   validade ICP-Brasil, e a revogação de certificados não é verificada.',
            '',
            '5. Nunca fazem parte do dossiê:',
            ...array_map(fn (string $item): string => '   - '.$item, self::NEVER_INCLUDED),
            '',
        ]);
    }

    /**
     * @param  array<string, array{source: string, sha256: string, size: int, meta: array<string, mixed>}>  $files
     * @param  array<string, mixed>  $meta
     */
    private function addString(array &$files, string $staging, string $name, string $contents, array $meta): void
    {
        $local = $staging.DIRECTORY_SEPARATOR.'s-'.substr(hash('sha256', $name), 0, 16);
        file_put_contents($local, $contents, LOCK_EX);

        $files[$name] = ['source' => $local, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents), 'meta' => $meta];
    }

    /**
     * @param  array<string, array{string?: string, file?: string}>  $entries
     */
    private function writeZip(string $zipPath, array $entries, int $mtime): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DossierException('Não foi possível criar o arquivo do dossiê.', 'zip_failed');
        }

        foreach ($entries as $name => $entry) {
            $added = isset($entry['string'])
                ? $zip->addFromString($name, $entry['string'])
                : $zip->addFile((string) ($entry['file'] ?? ''), $name);

            if (! $added) {
                $zip->close();

                throw new DossierException('Não foi possível incluir um arquivo no dossiê.', 'zip_failed');
            }

            $zip->setCompressionName($name, ZipArchive::CM_DEFLATE);
            $zip->setMtimeName($name, $mtime);
        }

        if (! $zip->close()) {
            throw new DossierException('Não foi possível finalizar o arquivo do dossiê.', 'zip_failed');
        }
    }

    private function slug(string $value): string
    {
        $slug = Str::slug(Str::limit($value, 60, ''), '-');

        return $slug !== '' ? $slug : 'documento';
    }
}
