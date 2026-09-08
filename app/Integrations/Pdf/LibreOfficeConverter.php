<?php

namespace App\Integrations\Pdf;

use App\Enums\DocumentSourceType;
use App\Integrations\Contracts\PdfConverter;
use App\Integrations\Dto\ConversionRequest;
use App\Integrations\Dto\ConversionResult;
use App\Integrations\Exceptions\ConverterNotConfiguredException;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Exceptions\UnsupportedSourceTypeException;
use App\Services\Pdf\Exceptions\PdfToolInputRejectedException;
use App\Services\Pdf\PdfToolClient;
use App\Services\Pdf\Support\ProcessEnvironment;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * DOCX → PDF com LibreOffice headless, como processo isolado:
 *
 * - argumentos em array (sem shell): --headless --norestore --nologo
 *   --nodefault --nolockcheck --convert-to pdf --outdir <tmp>/out
 *   -env:UserInstallation=file:///<tmp>/profile <tmp>/in/input.docx
 * - diretório temporário exclusivo por conversão (entrada copiada com nome
 *   neutro, saída e PERFIL DE USUÁRIO isolado), removido em finally;
 * - perfil pré-semeado com registrymodifications.xcu que desliga macros
 *   (MacroSecurityLevel=3, DisableMacrosExecution), atualização de links,
 *   proxy manual sem host e verificação de atualização. Isso é defesa em
 *   profundidade: o bloqueio de rede confiável exige firewall/namespace do SO e
 *   o LibreOffice deve rodar como usuário sem privilégios (docs/pdf-pipeline.md);
 * - ambiente mínimo (ProcessEnvironment) + extras de config
 *   (pdftool.libreoffice.env, ex.: SAL_USE_VCLPLUGIN=svp);
 * - timeout (pdftool.libreoffice.timeout_seconds);
 * - o PDF resultante precisa existir e passar em `pdftool inspect`.
 *
 * Não instalado no ambiente de desenvolvimento local: o adaptador é exercitado
 * por um binário falso em tests/Fixtures (que grava os argumentos recebidos) e
 * o comportamento real deve ser verificado no servidor com `pdftool:selftest`.
 */
class LibreOfficeConverter implements PdfConverter
{
    public const NAME = 'libreoffice';

    public const ARGUMENTS = ['--headless', '--norestore', '--nologo', '--nodefault', '--nolockcheck'];

    public const INPUT_EXTENSIONS = ['docx', 'doc', 'odt', 'rtf'];

    public function __construct(
        private readonly PdfToolClient $client,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function supports(DocumentSourceType|string $sourceType): bool
    {
        return ImageToPdfConverter::normalizeType($sourceType) === DocumentSourceType::Docx->value;
    }

    public function binary(): string
    {
        return trim((string) $this->config->get('pdftool.libreoffice.binary', ''));
    }

    public function timeout(): int
    {
        return max(1, (int) $this->config->get('pdftool.libreoffice.timeout_seconds', 120));
    }

    /**
     * Binário resolvido (caminho absoluto existente ou executável no PATH), ou null.
     */
    public function resolvedBinary(): ?string
    {
        $binary = $this->binary();
        if ($binary === '') {
            return null;
        }

        if (is_file($binary)) {
            return $binary;
        }

        if (! str_contains($binary, '/') && ! str_contains($binary, '\\')) {
            $found = (new ExecutableFinder)->find($binary);

            return $found !== null && is_file($found) ? $found : null;
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return $this->resolvedBinary() !== null && $this->client->isAvailable();
    }

    public function convert(ConversionRequest $request): ConversionResult
    {
        if (! $this->supports($request->sourceType)) {
            throw UnsupportedSourceTypeException::for($request->sourceTypeValue(), self::NAME);
        }

        $binary = $this->resolvedBinary();
        if ($binary === null) {
            throw ConverterNotConfiguredException::for(
                self::NAME,
                'defina LIBREOFFICE_BIN com o caminho do soffice (ex.: /usr/bin/soffice ou C:\\Program Files\\LibreOffice\\program\\soffice.exe).',
            );
        }
        if (! $this->client->isAvailable()) {
            throw ConverterNotConfiguredException::for(self::NAME, 'pdftool indisponível (venv em tools/pdftool/.venv) para validar o PDF gerado.');
        }

        $correlationId = $request->correlationId ?? (string) Str::ulid();
        $workDir = $this->client->temporaryDirectory('lo-');

        try {
            $inputDir = $workDir->subdirectory('in');
            $outDir = $workDir->subdirectory('out');
            $profileDir = $workDir->subdirectory('profile');

            $input = $inputDir.DIRECTORY_SEPARATOR.'input.'.$this->inputExtension($request);
            if (! @copy($request->inputPath, $input)) {
                throw new IntegrationException('Não foi possível copiar o documento para o diretório de conversão.');
            }

            $this->seedProfile($profileDir);

            $argv = [
                $binary,
                ...self::ARGUMENTS,
                '--convert-to', 'pdf',
                '--outdir', $outDir,
                '-env:UserInstallation='.self::fileUri($profileDir),
                $input,
            ];

            $process = new Process(
                $argv,
                $workDir->path(),
                ProcessEnvironment::minimal($workDir->path(), $this->extraEnvironment()),
                null,
                $this->timeout(),
            );

            $startedAt = microtime(true);

            try {
                $process->run();
            } catch (ProcessTimedOutException $exception) {
                $this->logger->error('LibreOfficeConverter: tempo limite excedido', [
                    'argv' => $argv,
                    'timeout_seconds' => $this->timeout(),
                    'correlation_id' => $correlationId,
                ]);

                return ConversionResult::failed(self::NAME, 'timeout', ConversionMessages::for('timeout'), $correlationId, [
                    'timeout_seconds' => $this->timeout(),
                ]);
            } catch (ProcessException $exception) {
                throw new IntegrationException('Não foi possível executar o LibreOffice: '.$exception->getMessage(), 0, $exception);
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $exitCode = $process->getExitCode();
            $stderr = $this->excerpt($process->getErrorOutput());
            $stdout = $this->excerpt($process->getOutput());
            $expected = $outDir.DIRECTORY_SEPARATOR.'input.pdf';

            $this->logger->log($exitCode === 0 && is_file($expected) ? 'debug' : 'warning', 'LibreOfficeConverter: processo encerrado', [
                'argv' => $argv,
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
                'output_exists' => is_file($expected),
                'correlation_id' => $correlationId,
                'stdout' => $stdout !== '' ? $stdout : null,
                'stderr' => $stderr !== '' ? $stderr : null,
            ]);

            if ($exitCode !== 0) {
                return ConversionResult::failed(self::NAME, 'libreoffice_failed', ConversionMessages::for('libreoffice_failed'), $correlationId, [
                    'exit_code' => $exitCode,
                    'stderr' => $stderr,
                ]);
            }

            if (! is_file($expected) || filesize($expected) === 0) {
                return ConversionResult::failed(self::NAME, 'no_output', ConversionMessages::for('no_output'), $correlationId, [
                    'exit_code' => $exitCode,
                    'stderr' => $stderr,
                ]);
            }

            try {
                $inspection = $this->client->inspect($expected, $correlationId);
            } catch (PdfToolInputRejectedException $exception) {
                return ConversionResult::failed(self::NAME, 'invalid_output_pdf', ConversionMessages::for('invalid_output_pdf'), $correlationId, [
                    'pdftool_error' => $exception->errorCode,
                ]);
            }

            if (! $inspection->openable || $inspection->pageCount < 1) {
                return ConversionResult::failed(self::NAME, 'invalid_output_pdf', ConversionMessages::for('invalid_output_pdf'), $correlationId);
            }

            if (! @copy($expected, $request->outputPath)) {
                throw new IntegrationException('Não foi possível gravar o PDF convertido no destino.');
            }

            return ConversionResult::ready(self::NAME, $request->outputPath, $inspection, $correlationId, [
                'duration_ms' => $durationMs,
                'binary' => basename($binary),
            ]);
        } finally {
            $workDir->delete();
        }
    }

    /**
     * URI file:/// aceita pelo -env:UserInstallation (Windows: file:///C:/...).
     */
    public static function fileUri(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', ltrim($normalized, '/'));

        $encoded = [];
        foreach ($segments as $index => $segment) {
            if ($index === 0 && preg_match('/^[A-Za-z]:$/', $segment) === 1) {
                $encoded[] = $segment;

                continue;
            }
            $encoded[] = rawurlencode($segment);
        }

        return 'file:///'.implode('/', $encoded);
    }

    /**
     * registrymodifications.xcu do perfil isolado: macros desligadas, sem
     * atualização de links, proxy manual sem host, sem verificação de versão.
     */
    public static function profileRegistry(): string
    {
        $items = [
            ['/org.openoffice.Office.Common/Security/Scripting', 'MacroSecurityLevel', 'xs:int', '3'],
            ['/org.openoffice.Office.Common/Security/Scripting', 'DisableMacrosExecution', 'xs:boolean', 'true'],
            ['/org.openoffice.Office.Common/Security/Scripting', 'OfficeBasic', 'xs:int', '0'],
            ['/org.openoffice.Office.Writer/Content/Update', 'Link', 'xs:int', '0'],
            ['/org.openoffice.Inet/Settings', 'ooInetProxyType', 'xs:int', '1'],
            ['/org.openoffice.Inet/Settings', 'ooInetHTTPProxyName', 'xs:string', ''],
            ['/org.openoffice.Inet/Settings', 'ooInetHTTPSProxyName', 'xs:string', ''],
            ['/org.openoffice.Office.Common/Misc', 'FirstRun', 'xs:boolean', 'false'],
            ["/org.openoffice.Office.Jobs/Jobs/org.openoffice.Office.Jobs:Job['UpdateCheck']/Arguments", 'AutoCheckEnabled', 'xs:boolean', 'false'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<oor:items xmlns:oor="http://openoffice.org/2001/registry" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'."\n";
        foreach ($items as [$path, $name, $type, $value]) {
            $xml .= sprintf(
                '<item oor:path="%s"><prop oor:name="%s" oor:op="fuse" oor:type="%s"><value>%s</value></prop></item>'."\n",
                htmlspecialchars($path, ENT_QUOTES | ENT_XML1),
                htmlspecialchars($name, ENT_QUOTES | ENT_XML1),
                $type,
                htmlspecialchars($value, ENT_QUOTES | ENT_XML1),
            );
        }
        $xml .= '</oor:items>'."\n";

        return $xml;
    }

    private function seedProfile(string $profileDir): void
    {
        $userDir = $profileDir.DIRECTORY_SEPARATOR.'user';
        if (! is_dir($userDir) && ! @mkdir($userDir, 0700, true) && ! is_dir($userDir)) {
            throw new IntegrationException('Não foi possível preparar o perfil isolado do LibreOffice.');
        }

        if (file_put_contents($userDir.DIRECTORY_SEPARATOR.'registrymodifications.xcu', self::profileRegistry(), LOCK_EX) === false) {
            throw new IntegrationException('Não foi possível gravar o perfil isolado do LibreOffice.');
        }
    }

    private function inputExtension(ConversionRequest $request): string
    {
        $candidate = strtolower(pathinfo($request->originalFilename ?? $request->inputPath, PATHINFO_EXTENSION));

        return in_array($candidate, self::INPUT_EXTENSIONS, true) ? $candidate : 'docx';
    }

    /**
     * @return array<string, string>
     */
    private function extraEnvironment(): array
    {
        $extra = [];
        foreach ((array) $this->config->get('pdftool.libreoffice.env', []) as $name => $value) {
            if (is_string($name) && $name !== '' && is_scalar($value)) {
                $extra[$name] = (string) $value;
            }
        }

        return $extra;
    }

    private function excerpt(string $text): string
    {
        $limit = max(200, (int) $this->config->get('pdftool.stderr_log_limit', 4000));
        $text = trim((string) preg_replace('/[^\P{C}\n\t]/u', '', $text));

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).' […truncado]' : $text;
    }
}
