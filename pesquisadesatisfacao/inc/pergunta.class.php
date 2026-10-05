<?php

/**
 * Plugin Pesquisa de Satisfação - perguntas configuráveis (escala de 3 carinhas)
 */
class PluginPesquisadesatisfacaoPergunta extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_pesquisadesatisfacao_perguntas';

    /** Quando pedir justificativa */
    public const JUSTIFICATIVAS = [
        'ruim'     => 'Obrigatória quando a resposta for a pior',
        'opcional' => 'Opcional (o requerente decide)',
        'nao'      => 'Não pedir',
    ];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Perguntas' : 'Pergunta';
    }

    public static function canView(): bool
    {
        return PluginPesquisadesatisfacaoConfig::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return PluginPesquisadesatisfacaoConfig::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return PluginPesquisadesatisfacaoConfig::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return PluginPesquisadesatisfacaoConfig::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return PluginPesquisadesatisfacaoConfig::ehAdmin();
    }

    /** Perguntas da primeira instalação (as do plugin original) */
    public static function iniciais(): array
    {
        return [
            'Qual foi a qualidade do atendimento prestado?',
            'O tempo de atendimento foi satisfatório?',
            'De modo geral, você ficou satisfeito com o atendimento?',
        ];
    }

    /** Perguntas em ordem; só as ativas por padrão */
    public static function listar(bool $somenteAtivas = true): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'FROM'  => self::TABELA,
            'WHERE' => $somenteAtivas ? ['is_active' => 1] : [],
            'ORDER' => ['ordem ASC', 'id ASC'],
        ]) as $r) {
            $r['id'] = (int) $r['id'];
            $r['is_active'] = (int) $r['is_active'];
            if (!isset(self::JUSTIFICATIVAS[$r['justificativa']])) {
                $r['justificativa'] = 'opcional';
            }
            $lista[$r['id']] = $r;
        }
        return $lista;
    }

    /**
     * Grava a lista vinda da configuração.
     * $linhas: [id => ['texto', 'justificativa', 'ativa', 'excluir']], id "novo" para incluir.
     * Retorna mensagem de erro ou ''.
     */
    public static function salvarLista(array $linhas, array $ordem): string
    {
        global $DB;
        $existentes = self::listar(false);
        $posicao = array_flip(array_values(array_map('strval', $ordem)));
        $ativas = 0;
        $gravar = [];

        foreach ($linhas as $chave => $linha) {
            $chave = (string) $chave;
            $texto = trim(preg_replace('/\s+/', ' ', (string) ($linha['texto'] ?? '')));
            $excluir = !empty($linha['excluir']);
            if ($chave === 'novo') {
                if ($texto === '') {
                    continue;
                }
            } elseif (!isset($existentes[(int) $chave])) {
                continue;
            }
            if (!$excluir && $texto === '') {
                return 'O texto das perguntas não pode ficar vazio.';
            }
            $ativa = !$excluir && !empty($linha['ativa']);
            $ativas += $ativa ? 1 : 0;
            $gravar[$chave] = [
                'texto'         => mb_substr($texto, 0, 500),
                'justificativa' => isset(self::JUSTIFICATIVAS[$linha['justificativa'] ?? '']) ? $linha['justificativa'] : 'ruim',
                'is_active'     => $ativa ? 1 : 0,
                'ordem'         => ($posicao[$chave] ?? 999) + 1,
                'excluir'       => $excluir,
            ];
        }
        if ($ativas === 0) {
            return 'Mantenha pelo menos uma pergunta ativa.';
        }

        foreach ($gravar as $chave => $g) {
            $excluir = $g['excluir'];
            unset($g['excluir']);
            if ($chave === 'novo') {
                $DB->insert(self::TABELA, $g);
            } elseif ($excluir) {
                // As respostas já dadas guardam o texto da pergunta e continuam nos relatórios
                $DB->delete(self::TABELA, ['id' => (int) $chave]);
            } else {
                $DB->update(self::TABELA, $g, ['id' => (int) $chave]);
            }
        }
        return '';
    }

    /** Carinha em SVG (1 ruim, 2 regular, 3 bom), sem depender de fonte de ícones */
    public static function carinha(int $valor, int $tamanho = 40): string
    {
        $boca = match ($valor) {
            1       => 'M8 16.5c1.2-1.6 2.5-2.2 4-2.2s2.8.6 4 2.2',
            2       => 'M8.5 15.5h7',
            default => 'M8 14c1.2 1.7 2.5 2.4 4 2.4s2.8-.7 4-2.4',
        };
        return '<svg class="pesquisadesatisfacao-carinha" width="' . $tamanho . '" height="' . $tamanho . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<circle cx="12" cy="12" r="9"/><path d="M9 9.5h.01M15 9.5h.01" stroke-width="2.4"/><path d="' . $boca . '"/></svg>';
    }
}
