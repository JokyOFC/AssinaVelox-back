<?php

use App\Models\Envelope;
use App\Services\Templates\VariableFormatter;
use App\Services\Templates\VariableType;
use App\Services\Templates\VariableValues;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/TemplateHelpers.php';

function normalizeVariable(VariableType $type, mixed $value, array $options = [], bool $required = true): array
{
    return app(VariableValues::class)->normalize($type, $options, $value, 'Campo', $required);
}

describe('validação por tipo (servidor)', function () {
    test('CPF e CNPJ usam as mesmas regras de app/Rules', function () {
        expect(normalizeVariable(VariableType::Cpf, '529.982.247-25'))->toBe(['52998224725', null])
            ->and(normalizeVariable(VariableType::Cpf, '111.111.111-11')[1])->toBe('Informe um CPF válido em "Campo".')
            ->and(normalizeVariable(VariableType::Cpf, '529.982.247-24')[1])->not->toBeNull()
            ->and(normalizeVariable(VariableType::Cnpj, '11.222.333/0001-81'))->toBe(['11222333000181', null])
            ->and(normalizeVariable(VariableType::Cnpj, '11.222.333/0001-82')[1])->toBe('Informe um CNPJ válido em "Campo".');
    });

    test('data inválida é recusada; aceita dd/mm/aaaa e aaaa-mm-dd', function () {
        expect(normalizeVariable(VariableType::Date, '2026-02-30')[1])->toBe('Informe uma data válida em "Campo" (dd/mm/aaaa).')
            ->and(normalizeVariable(VariableType::Date, '31/04/2026')[1])->not->toBeNull()
            ->and(normalizeVariable(VariableType::Date, 'amanhã')[1])->not->toBeNull()
            ->and(normalizeVariable(VariableType::Date, '05/09/2026'))->toBe(['2026-09-05', null])
            ->and(normalizeVariable(VariableType::Date, '2026-09-05'))->toBe(['2026-09-05', null])
            ->and(normalizeVariable(VariableType::Date, '2026-01-01', ['min' => '2026-06-01'])[1])->toBe('"Campo" não pode ser anterior a 01/06/2026.');
    });

    test('número fora do limite e casas decimais demais são recusados', function () {
        expect(normalizeVariable(VariableType::Number, '13', ['min' => 1, 'max' => 12, 'decimals' => 0])[1])->toBe('"Campo" deve ser no máximo 12.')
            ->and(normalizeVariable(VariableType::Number, '0', ['min' => 1, 'max' => 12, 'decimals' => 0])[1])->toBe('"Campo" deve ser no mínimo 1.')
            ->and(normalizeVariable(VariableType::Number, '2,5', ['decimals' => 0])[1])->toBe('"Campo" aceita apenas números inteiros.')
            ->and(normalizeVariable(VariableType::Number, 'doze')[1])->toBe('Informe um número válido em "Campo".')
            ->and(normalizeVariable(VariableType::Number, '1.234,5', ['decimals' => 2]))->toBe([1234.5, null]);
    });

    test('lista: valor fora das opções é recusado', function () {
        $options = ['choices' => ['Mensal', 'Anual']];

        expect(normalizeVariable(VariableType::Select, 'Anual', $options))->toBe(['Anual', null])
            ->and(normalizeVariable(VariableType::Select, 'Semanal', $options)[1])->toBe('Escolha uma das opções de "Campo".')
            ->and(normalizeVariable(VariableType::Select, 'anual', $options)[1])->not->toBeNull();
    });

    test('valor em reais vira centavos; negativo e três casas são recusados', function () {
        expect(normalizeVariable(VariableType::Currency, 'R$ 1.234,56'))->toBe([123456, null])
            ->and(normalizeVariable(VariableType::Currency, '1500'))->toBe([150000, null])
            ->and(normalizeVariable(VariableType::Currency, '10,5'))->toBe([1050, null])
            ->and(normalizeVariable(VariableType::Currency, '-10')[1])->not->toBeNull()
            ->and(normalizeVariable(VariableType::Currency, '10,555')[1])->toBe('"Campo" aceita no máximo 2 casas decimais (centavos).')
            ->and(normalizeVariable(VariableType::Currency, '50', ['max_cents' => 1000])[1])->toBe('"Campo" deve ser no máximo R$ 10,00.');
    });

    test('e-mail, telefone, texto, obrigatório e booleano', function () {
        expect(normalizeVariable(VariableType::Email, 'Ana@Example.COM'))->toBe(['ana@example.com', null])
            ->and(normalizeVariable(VariableType::Email, 'ana@')[1])->not->toBeNull()
            ->and(normalizeVariable(VariableType::Phone, '+55 (11) 98765-4321'))->toBe(['11987654321', null])
            ->and(normalizeVariable(VariableType::Phone, '1234')[1])->not->toBeNull()
            ->and(normalizeVariable(VariableType::Text, "  linha\numa  "))->toBe(['linha uma', null])
            ->and(normalizeVariable(VariableType::Text, str_repeat('a', 11), ['max_length' => 10])[1])->toBe('"Campo" aceita no máximo 10 caracteres.')
            ->and(normalizeVariable(VariableType::Text, '   ')[1])->toBe('Preencha "Campo".')
            ->and(normalizeVariable(VariableType::Text, '', [], false))->toBe([null, null])
            ->and(normalizeVariable(VariableType::Boolean, 'true'))->toBe([true, null])
            ->and(normalizeVariable(VariableType::Boolean, null))->toBe([false, null])
            ->and(normalizeVariable(VariableType::Boolean, 'talvez')[1])->not->toBeNull();
    });

    test('formatação PT-BR ao inserir no documento', function () {
        expect(VariableFormatter::format(VariableType::Currency, [], 123456))->toBe('R$ 1.234,56')
            ->and(VariableFormatter::format(VariableType::Date, [], '2026-09-05'))->toBe('05/09/2026')
            ->and(VariableFormatter::format(VariableType::Cpf, [], '52998224725'))->toBe('529.982.247-25')
            ->and(VariableFormatter::format(VariableType::Cnpj, [], '11222333000181'))->toBe('11.222.333/0001-81')
            ->and(VariableFormatter::format(VariableType::Phone, [], '11987654321'))->toBe('(11) 98765-4321')
            ->and(VariableFormatter::format(VariableType::Phone, [], '1134567890'))->toBe('(11) 3456-7890')
            ->and(VariableFormatter::format(VariableType::Number, ['decimals' => 2], 1234.5))->toBe('1.234,50')
            ->and(VariableFormatter::format(VariableType::Number, [], 12.0))->toBe('12')
            ->and(VariableFormatter::format(VariableType::Boolean, [], true))->toBe('Sim')
            ->and(VariableFormatter::format(VariableType::Boolean, [], false))->toBe('Não')
            ->and(VariableFormatter::format(VariableType::Text, [], null))->toBe('');
    });
});

describe('formulário "Usar modelo"', function () {
    beforeEach(function () {
        $this->work = templatesWorkspace();
        ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
        templatesEnable($this->organization);
        actingAsMember($this->owner, $this->organization);

        $this->template = templateHtml($this->organization, $this->owner, '<p>{{nome}} {{cpf}} {{inicio}} {{parcelas}} {{plano}}</p>', [
            ['key' => 'nome', 'label' => 'Nome completo', 'type' => 'text', 'required' => true],
            ['key' => 'cpf', 'label' => 'CPF', 'type' => 'cpf', 'required' => true],
            ['key' => 'inicio', 'label' => 'Início', 'type' => 'date', 'required' => true],
            ['key' => 'parcelas', 'label' => 'Parcelas', 'type' => 'number', 'required' => true, 'options' => ['min' => 1, 'max' => 12, 'decimals' => 0]],
            ['key' => 'plano', 'label' => 'Plano', 'type' => 'select', 'required' => true, 'options' => ['choices' => ['Mensal', 'Anual']]],
        ]);
    });

    afterEach(fn () => PdfFixtures::cleanup($this->work ?? null));

    test('valores inválidos voltam com erro por variável e nenhum envelope é criado', function () {
        $roleIds = templateRoleIds($this->template);

        $this->post(route('templates.use', $this->template), [
            'values' => ['nome' => '', 'cpf' => '123.456.789-00', 'inicio' => '2026-02-30', 'parcelas' => '13', 'plano' => 'Semanal'],
            'participants' => [$roleIds['Locatário'] => ['name' => 'Ana Souza', 'email' => 'ana@example.com']],
        ])->assertSessionHasErrors([
            'values.nome' => 'Preencha "Nome completo".',
            'values.cpf' => 'Informe um CPF válido em "CPF".',
            'values.inicio' => 'Informe uma data válida em "Início" (dd/mm/aaaa).',
            'values.parcelas' => '"Parcelas" deve ser no máximo 12.',
            'values.plano' => 'Escolha uma das opções de "Plano".',
        ]);

        expect(Envelope::query()->count())->toBe(0);
    });

    test('participante sem e-mail válido ou com e-mail repetido é recusado', function () {
        $this->template = templateHtml($this->organization, $this->owner, '<p>{{nome}}</p>', [
            ['key' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true],
        ], [
            ['ref' => 'a', 'name' => 'Locatário', 'participant_role' => 'signer'],
            ['ref' => 'b', 'name' => 'Locador', 'participant_role' => 'signer'],
        ]);
        $roleIds = templateRoleIds($this->template);

        $this->post(route('templates.use', $this->template), [
            'values' => ['nome' => 'Ana'],
            'participants' => [
                $roleIds['Locatário'] => ['name' => 'Ana', 'email' => 'nao-e-email'],
                $roleIds['Locador'] => ['name' => '', 'email' => 'b@example.com'],
            ],
        ])->assertSessionHasErrors([
            "participants.{$roleIds['Locatário']}.email",
            "participants.{$roleIds['Locador']}.name",
        ]);

        $this->post(route('templates.use', $this->template), [
            'values' => ['nome' => 'Ana'],
            'participants' => [
                $roleIds['Locatário'] => ['name' => 'Ana', 'email' => 'mesmo@example.com'],
                $roleIds['Locador'] => ['name' => 'Beto', 'email' => 'MESMO@example.com'],
            ],
        ])->assertSessionHasErrors(["participants.{$roleIds['Locador']}.email" => 'Este e-mail já está em outro participante deste documento.']);

        expect(Envelope::query()->count())->toBe(0);
    });

    test('valor padrão inválido para o tipo é recusado ao salvar o modelo', function () {
        $this->put(route('templates.update', $this->template), [
            'html_body' => '<p>{{cpf}}</p>',
            'variables' => [['key' => 'cpf', 'label' => 'CPF', 'type' => 'cpf', 'required' => true, 'default_value' => '000.000.000-00']],
            'roles' => [['ref' => 'r1', 'name' => 'Locatário', 'participant_role' => 'signer']],
        ])->assertSessionHasErrors('variables.0.default_value');
    });
});
