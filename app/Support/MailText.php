<?php

namespace App\Support;

/**
 * Texto escrito por gente, colocado dentro de um e-mail que é renderizado como Markdown.
 *
 * As linhas de `Illuminate\Notifications\Messages\MailMessage` passam por
 * `Illuminate\Mail\Markdown` antes de virar HTML. O Blade escapa o HTML bruto — um
 * `<img onerror=…>` sai como texto —, mas a SINTAXE MARKDOWN continua ativa: `[texto](url)`
 * vira uma âncora de verdade.
 *
 * Isso importa porque campos como "Mensagem para os signatários" e o título do documento
 * são texto livre do remetente (validados só por tamanho) e são interpolados no MESMO
 * parágrafo do botão legítimo do convite. Sem neutralizar a sintaxe, qualquer conta —
 * inclusive uma Grátis recém-criada — poderia usar o domínio, o SPF/DKIM/DMARC e a
 * reputação de envio da plataforma para entregar um link de phishing escolhido por ela, e
 * o destinatário não teria como distinguir esse link do link real do convite.
 *
 * `escape()` põe uma contrabarra à frente dos caracteres que abrem marcação inline:
 * contrabarra, colchetes, asterisco, sublinhado e crase. O CommonMark devolve o caractere
 * literal no HTML, então o leitor vê exatamente o que a pessoa digitou. Parênteses ficam
 * de fora de propósito: sozinhos não formam link e são comuns em texto em português — a
 * versão em texto puro do e-mail mostra as contrabarras, e não vale sujá-la à toa.
 *
 * Só se aplica a texto de terceiros. As linhas escritas por nós continuam usando `**` para
 * destacar, e o assunto do e-mail não passa por aqui: assunto é texto puro, sem Markdown.
 */
class MailText
{
    /**
     * Caracteres que abrem marcação inline no CommonMark e que, portanto, precisam de
     * contrabarra quando vêm de texto escrito pelo usuário.
     *
     * A contrabarra vem primeiro: escapá-la depois duplicaria as que acabamos de inserir.
     *
     * @var list<string>
     */
    private const SPECIALS = ['\\', '[', ']', '`', '*', '_'];

    public static function escape(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        foreach (self::SPECIALS as $character) {
            $text = str_replace($character, '\\'.$character, $text);
        }

        return $text;
    }
}
