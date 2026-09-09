<?php

/*
|--------------------------------------------------------------------------
| Helpers da suíte de navegador (T-BROWSER)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
|
| Regras que valem para tudo aqui:
|
| - nada de espera por tempo fixo. Toda espera é por condição: ou uma asserção
|   do plugin (que já repete até o timeout do Playwright), ou `browserWaitFor`,
|   que consulta o banco/estado até a condição valer;
| - cada teste monta os próprios dados. Os seeders (owner@horizonte.demo etc.)
|   NÃO rodam na suíte: o `RefreshDatabase` do `phpunit.xml` migra sem semear, e
|   depender de um seeder tornaria o teste sensível a mudanças de demonstração;
| - o disco `documents` recebe uma raiz exclusiva por teste (a mesma tática de
|   tests/Feature/Documents/Support/helpers.php: `Storage::fake` compartilha a
|   raiz e a limpeza recursiva falha de forma intermitente no Windows).
*/

use App\Enums\DocumentProcessingStatus;
use App\Models\Envelope;
use App\Notifications\Envelopes\RecipientInvitationNotification;
use App\Notifications\Signing\SignerOtpNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pest\Browser\Execution;
use PHPUnit\Framework\ExpectationFailedException;

if (! function_exists('browserWorkspace')) {
    /** Diretório de trabalho exclusivo do teste (removido no afterEach). */
    function browserWorkspace(): string
    {
        $path = storage_path('app/tmp/browser/'.(string) Str::ulid());

        if (! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("Não foi possível criar {$path}");
        }

        return $path;
    }
}

if (! function_exists('browserCleanup')) {
    function browserCleanup(?string $path): void
    {
        if ($path === null || ! is_dir($path)) {
            return;
        }

        $filesystem = new Filesystem;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                if ($filesystem->deleteDirectory($path)) {
                    return;
                }
            } catch (Throwable) {
                // O Windows pode ainda segurar um handle; tenta de novo.
            }

            usleep(50_000);
        }
    }
}

if (! function_exists('browserDocumentsDisk')) {
    /** Disco `documents` local com raiz exclusiva do teste. */
    function browserDocumentsDisk(string $root): void
    {
        config()->set('filesystems.disks.documents', [
            'driver' => 'local',
            'root' => $root,
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ]);

        Storage::forgetDisk('documents');
    }
}

if (! function_exists('browserPdf')) {
    /**
     * PDF de uma página, válido e **pequeno**.
     *
     * `PdfFixtures::onePagePdf()` embute a DejaVu Sans e passa de 850 KB. Aqui a
     * folha de estilo pede Helvetica, uma das 14 fontes-base que o PDF não
     * precisa embutir: o arquivo fica em ~1,3 KB. O tamanho importa porque cada
     * byte do documento atravessa o servidor embutido do plugin em memória.
     */
    function browserPdf(string $path, string $title = 'Contrato de teste'): string
    {
        $html = sprintf(
            '<!doctype html><html><head><meta charset="utf-8">'
            .'<style>body{font-family:Helvetica,sans-serif;font-size:12pt}</style></head>'
            .'<body><h1>%s</h1><p>Documento gerado em teste automatizado.</p></body></html>',
            htmlspecialchars($title, ENT_QUOTES),
        );

        file_put_contents($path, Pdf::loadHTML($html)->setPaper('a4')->output());

        return $path;
    }
}

if (! function_exists('browserPassword')) {
    function browserPassword(): string
    {
        return 'password';
    }
}

if (! function_exists('browserLogin')) {
    /**
     * Entra pela tela real de login e devolve a página já no painel.
     *
     * Não existe atalho: `actingAs()` autentica o *cliente de teste*, não o
     * navegador, que tem cookies próprios. Passar pelo formulário é o único
     * caminho honesto — e é justamente o que o teste de autenticação verifica.
     *
     * @param  object  $page  página aberta em `/login`
     */
    function browserLogin(object $page, string $email, string $password = 'password'): object
    {
        $page->fill('#email', $email)
            ->fill('#password', $password)
            ->click('[data-test=login-button]');

        return $page;
    }
}

if (! function_exists('browserCaptureNotifications')) {
    /**
     * Escuta o envio de notificações e guarda, em claro, o que só existe dentro
     * delas: o código por e-mail (o banco guarda apenas o HMAC) e a URL do
     * convite (o banco guarda apenas o digest do token).
     *
     * Sem `Notification::fake()`, de propósito: o canal rastreado precisa rodar
     * de verdade para que as linhas de `delivery_attempts` continuem existindo.
     *
     * O servidor HTTP do plugin roda no mesmo processo do teste, então o
     * listener registrado aqui é o mesmo que dispara dentro da requisição feita
     * pelo navegador.
     *
     * @return array{codes: ArrayObject<int, string>, invites: ArrayObject<string, string>}
     */
    function browserCaptureNotifications(): array
    {
        /** @var ArrayObject<int, string> $codes */
        $codes = new ArrayObject;
        /** @var ArrayObject<string, string> $invites */
        $invites = new ArrayObject;

        Event::listen(NotificationSending::class, function (NotificationSending $event) use ($codes, $invites): void {
            if ($event->notification instanceof SignerOtpNotification) {
                $codes[] = $event->notification->code;
            }

            if ($event->notification instanceof RecipientInvitationNotification) {
                $invites[$event->notification->recipient->email] = $event->notification->signingUrl;
            }
        });

        return ['codes' => $codes, 'invites' => $invites];
    }
}

if (! function_exists('browserWaitFor')) {
    /**
     * Espera por uma **condição**, nunca por um tempo fixo.
     *
     * O servidor HTTP do plugin vive no mesmo processo, então o teste não pode
     * simplesmente dormir: enquanto ele dorme, o servidor não atende ninguém.
     * `Execution::tick()` cede o controle ao laço de eventos do Amp, deixando as
     * requisições em voo do navegador serem atendidas entre uma checagem e a
     * seguinte.
     *
     * @template T
     *
     * @param  callable(): T  $condition  devolve algo "truthy" quando a condição vale
     * @return T
     */
    function browserWaitFor(callable $condition, string $description, float $timeoutSeconds = 30.0): mixed
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $result = $condition();

            if ($result) {
                return $result;
            }

            Execution::instance()->tick();
            usleep(25_000);
        } while (microtime(true) < $deadline);

        throw new ExpectationFailedException(
            sprintf('Tempo esgotado (%.0fs) esperando por: %s', $timeoutSeconds, $description)
        );
    }
}

if (! function_exists('browserWaitForDocumentReady')) {
    /**
     * Espera o pipeline documental terminar. `QUEUE_CONNECTION=sync` faz o job
     * rodar dentro da própria requisição de upload, mas a asserção continua
     * sendo por estado (e não por "o upload retornou"), porque o wizard só
     * libera o passo 3 quando `processing_status` chega a `ready`.
     */
    function browserWaitForDocumentReady(Envelope $envelope, float $timeoutSeconds = 60.0): void
    {
        browserWaitFor(
            function () use ($envelope): bool {
                $document = $envelope->fresh()?->document;

                if ($document === null) {
                    return false;
                }

                if ($document->processing_status === DocumentProcessingStatus::Failed) {
                    throw new ExpectationFailedException(
                        'O processamento do documento falhou: '.($document->failure_code ?? 'sem código').'.'
                    );
                }

                return $document->processing_status === DocumentProcessingStatus::Ready;
            },
            'o documento do envelope ficar pronto',
            $timeoutSeconds,
        );
    }
}

if (! function_exists('browserDrawOnCanvas')) {
    /**
     * Desenha um traço num `<canvas>` do `signature_pad` simulando eventos de
     * ponteiro reais.
     *
     * Por que eventos sintéticos e não um arrasto do Playwright: o `dragTo` do
     * plugin move o mouse do centro de um elemento ao centro de outro, o que
     * daria um traço de comprimento zero dentro do mesmo canvas. Aqui o teste
     * controla a curva inteira.
     *
     * Detalhes que a biblioteca exige (signature_pad 5.1):
     *
     * - `pointerdown` vai no próprio canvas; `pointermove`/`pointerup` vão na
     *   `window` (é lá que ela registra os dois durante o traço);
     * - `buttons: 1` em down/move e `buttons: 0` em up, senão ela ignora;
     * - há um `throttle` de 16 ms entre pontos, por isso cada passo espera um
     *   frame — sem isso o traço vira um segmento reto de dois pontos.
     *
     * @param  object  $page  página do plugin (AwaitableWebpage)
     * @param  string  $element  expressão JS que devolve o `<canvas>`
     */
    function browserDrawOnCanvas(object $page, string $element, int $points = 14): void
    {
        $script = <<<'JS'
        (async () => {
            const canvas = __ELEMENT__;

            if (!canvas) {
                return 'canvas-ausente';
            }

            const box = canvas.getBoundingClientRect();
            const frame = () => new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 20)));

            const at = (i) => {
                const t = i / (__POINTS__ - 1);

                return {
                    clientX: box.left + box.width * (0.15 + 0.7 * t),
                    clientY: box.top + box.height * (0.5 - 0.25 * Math.sin(t * Math.PI * 2)),
                };
            };

            const options = (extra) => ({
                bubbles: true,
                cancelable: true,
                composed: true,
                pointerId: 1,
                pointerType: 'mouse',
                isPrimary: true,
                button: 0,
                pressure: 0.5,
                ...extra,
            });

            const first = at(0);
            canvas.dispatchEvent(new PointerEvent('pointerdown', options({ ...first, buttons: 1 })));
            await frame();

            for (let i = 1; i < __POINTS__; i++) {
                window.dispatchEvent(new PointerEvent('pointermove', options({ ...at(i), buttons: 1 })));
                await frame();
            }

            const last = at(__POINTS__ - 1);
            window.dispatchEvent(new PointerEvent('pointerup', options({ ...last, buttons: 0, pressure: 0 })));
            await frame();

            return 'ok';
        })()
        JS;

        $result = $page->script(str_replace(
            ['__ELEMENT__', '__POINTS__'],
            [$element, (string) $points],
            $script,
        ));

        expect($result)->toBe('ok', "Não foi possível desenhar no canvas [{$element}].");
    }
}

if (! function_exists('browserAttachFile')) {
    /**
     * Anexa um arquivo local a um `<input type=file>`.
     *
     * O `attach()` do plugin manda `localPaths` ao Playwright, e o servidor
     * recusa (`localPaths are not allowed when the client is not local`) porque
     * o plugin sobe o Playwright em modo `run-server` — o cliente é remoto do
     * ponto de vista dele, mesmo estando na mesma máquina.
     *
     * O contorno é montar o `File` dentro da página a partir dos bytes e
     * colocá-lo no input por um `DataTransfer`, disparando `change` em seguida.
     * O React escuta o evento nativo delegado, então o `onChange` do componente
     * roda igual ao de um arquivo escolhido à mão.
     *
     * @param  object  $page  página do plugin (AwaitableWebpage)
     * @param  string  $element  expressão JS que devolve o `<input type=file>`
     */
    function browserAttachFile(object $page, string $element, string $path, ?string $name = null, string $mime = 'application/pdf'): void
    {
        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException("Não foi possível ler {$path}.");
        }

        $script = <<<'JS'
        (() => {
            const input = __ELEMENT__;

            if (!input) {
                return 'input-ausente';
            }

            const binary = atob(__BASE64__);
            const bytes = new Uint8Array(binary.length);

            for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }

            const file = new File([bytes], __NAME__, { type: __MIME__ });
            const transfer = new DataTransfer();
            transfer.items.add(file);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));

            return 'ok';
        })()
        JS;

        $result = $page->script(str_replace(
            ['__ELEMENT__', '__BASE64__', '__NAME__', '__MIME__'],
            [
                $element,
                json_encode(base64_encode($bytes)),
                json_encode($name ?? basename($path)),
                json_encode($mime),
            ],
            $script,
        ));

        expect($result)->toBe('ok', "Não foi possível anexar o arquivo em [{$element}].");
    }
}

if (! function_exists('browserAssertNoEnglish')) {
    /**
     * Recusa palavras em inglês que costumam escapar de uma interface em PT-BR.
     *
     * A lista é curta e específica de propósito: rótulos de framework
     * (`Submit`, `Loading…`), mensagens de validação não traduzidas
     * (`This field is required`) e telas de erro do Laravel (`Server Error`).
     * Palavras que existem em português técnico (`e-mail`, `login`, `PDF`) não
     * entram, e nomes próprios do produto também não.
     *
     * @param  object  $page  página do plugin (AwaitableWebpage)
     */
    function browserAssertNoEnglish(object $page, string $screen): void
    {
        $forbidden = [
            'Server Error',
            'Whoops',
            'Something went wrong',
            'This field is required',
            'The given data was invalid',
            'These credentials do not match our records',
            'Page Expired',
            'Not Found',
            'Forbidden',
            'Loading...',
            'Submit',
            'Sign in',
            'Sign up',
            'Log out',
            'Try again',
        ];

        // "Cancel" ficou de fora de propósito: é prefixo de "Cancelar".

        $text = (string) $page->script('document.body.innerText');

        // Sem isto a verificação seria vazia numa tela em branco: "não contém
        // inglês" é verdade trivial quando não há texto nenhum.
        expect(mb_strlen(trim($text)))->toBeGreaterThan(
            40,
            sprintf('A tela [%s] não tem texto suficiente para ser verificada.', $screen)
        );

        foreach ($forbidden as $needle) {
            // `toContain` aceita vários agulheiros e não uma mensagem, por isso a
            // asserção é feita sobre o booleano.
            expect(str_contains($text, $needle))->toBeFalse(
                sprintf('A tela [%s] mostra texto em inglês: "%s".', $screen, $needle)
            );
        }
    }
}
