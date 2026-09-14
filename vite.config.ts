import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins, type Plugin } from 'vite-plus';

/**
 * Une as faces duplicadas do CSS de fontes emitido pelo `laravel-vite-plugin`.
 *
 * O provedor (Bunny) devolve DUAS regras `@font-face` por face — uma `.woff2` e uma
 * `.woff` — com a mesma família, o mesmo peso, o mesmo estilo e o mesmo `unicode-range`,
 * e o plugin as reemite tal como vieram. Pela cascata do CSS a última regra vence, então
 * o navegador usava sempre o `.woff` (o formato maior) — mas quem entra no
 * `<link rel="preload">` do manifesto é o `.woff2`. Resultado: dez arquivos woff2
 * (~179 KB) baixados e descartados em TODA página, inclusive a página pública de
 * assinatura, aberta em dados móveis por quem só quer assinar um contrato.
 *
 * Aqui as duas viram uma regra só, com os dois formatos no mesmo `src` e o woff2 na
 * frente: o navegador escolhe o menor formato que entende, que é exatamente o arquivo
 * que o preload já baixou.
 */
function mergeFontFaceFormats(): Plugin {
    const PRIORITY: Record<string, number> = { woff2: 0, woff: 1 };
    const MARK = '/*@assinavelox-face:';

    const formatOf = (src: string): string =>
        /format\("([^"]+)"\)/.exec(src)?.[1] ?? '';

    const declarationsOf = (body: string): [string, string][] =>
        body
            .split(';')
            .map((line) => line.trim())
            .filter((line) => line !== '')
            .map((line) => {
                const at = line.indexOf(':');

                return [
                    line.slice(0, at).trim(),
                    line.slice(at + 1).trim(),
                ] as [string, string];
            });

    const mergeSrc = (current: string, incoming: string): string =>
        [...current.split(','), ...incoming.split(',')]
            .map((part) => part.trim())
            .filter((part) => part !== '')
            .filter((part, index, all) => all.indexOf(part) === index)
            .sort(
                (a, b) =>
                    (PRIORITY[formatOf(a)] ?? 9) - (PRIORITY[formatOf(b)] ?? 9),
            )
            .join(', ');

    const merge = (css: string): string => {
        const faces = new Map<string, [string, string][]>();
        const pieces: string[] = [];
        const pattern = /@font-face\s*\{([^}]*)\}/g;
        let cursor = 0;
        let match: RegExpExecArray | null;

        while ((match = pattern.exec(css)) !== null) {
            pieces.push(css.slice(cursor, match.index));
            cursor = match.index + match[0].length;

            const props = declarationsOf(match[1]);
            const read = (name: string) =>
                props.find(([key]) => key === name)?.[1] ?? '';
            const key = [
                'font-family',
                'font-style',
                'font-weight',
                'unicode-range',
            ]
                .map(read)
                .join('|');

            const existing = faces.get(key);

            if (existing === undefined) {
                faces.set(key, props);
                pieces.push(`${MARK}${faces.size - 1}*/`);

                continue;
            }

            // Duplicata: só o `src` acrescenta informação; o resto é idêntico.
            const src = existing.find(([name]) => name === 'src');

            if (src !== undefined) {
                src[1] = mergeSrc(src[1], read('src'));
            }
        }

        pieces.push(css.slice(cursor));

        let output = pieces.join('');

        [...faces.values()].forEach((props, index) => {
            const body = props
                .map(([name, value]) => `  ${name}: ${value};`)
                .join('\n');

            output = output.replace(
                `${MARK}${index}*/`,
                `@font-face {\n${body}\n}`,
            );
        });

        return output.replace(/\n{3,}/g, '\n\n');
    };

    // `writeBundle` e não `generateBundle`: o CSS de fontes é emitido pelo próprio
    // `generateBundle` do laravel-vite-plugin, e um asset emitido ali não aparece no
    // objeto `bundle` dos hooks seguintes. Depois de escrito no disco, é só reescrever.
    return {
        name: 'assinavelox:merge-font-face-formats',
        apply: 'build',
        writeBundle(options) {
            const dir = options.dir ?? 'public/build';

            for (const base of [dir, join(dir, 'assets')]) {
                if (!existsSync(base)) {
                    continue;
                }

                for (const name of readdirSync(base)) {
                    if (!/^fonts-.*\.css$/.test(name)) {
                        continue;
                    }

                    const file = join(base, name);
                    const css = readFileSync(file, 'utf-8');
                    const merged = merge(css);

                    if (merged !== css) {
                        writeFileSync(file, merged, 'utf-8');
                    }
                }
            }
        },
    };
}

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Exo 2', {
                    weights: [400, 500, 600, 700, 800],
                    styles: ['normal', 'italic'],
                }),
                // DESIGN_SYSTEM §1.2: família manuscrita (--font-hand), usada só
                // para renderizar assinaturas — mesma face do mock (peso 600).
                // Sem preload: aparece em poucas telas e `font-display: swap`
                // evita bloquear o restante da interface.
                bunny('Caveat', {
                    weights: [600],
                    styles: ['normal'],
                    preload: false,
                }),
            ],
        }),
        mergeFontFaceFormats(),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        /*
         * IPv4 explícito. Sem isto o Vite resolve "localhost" para [::1] no Windows e grava
         * http://[::1]:5173 em public/hot; a CSP (SecurityHeaders) copia essa origem, mas a
         * gramática de host-source da CSP não aceita IPv6 literal e o navegador a descarta —
         * resultado: todo script, CSS e fonte do Vite bloqueado e a página sem estilo.
         */
        host: '127.0.0.1',
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            // Mocks de design (material de referência, não código da aplicação).
            'docs/design/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            // Documentos-contrato e mocks de design: material de referência, não código.
            'docs/design/**',
            'docs/arquitetura.md',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            entryPoint: 'resources/css/app.css',
        },
    },
});
