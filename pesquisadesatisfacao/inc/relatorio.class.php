<?php

/**
 * Plugin Pesquisa de Satisfação - indicadores, gráficos, rankings, listas e CSV.
 * Tudo restrito às entidades ativas do usuário.
 */
class PluginPesquisadesatisfacaoRelatorio extends CommonGLPI
{
    public const POR_PAGINA = 25;

    public static function getTypeName($nb = 0): string
    {
        return 'Painel da pesquisa de satisfação';
    }

    public static function canView(): bool
    {
        return PluginPesquisadesatisfacaoConfig::podeVerRelatorios();
    }

    // =====================================================================
    // Filtros
    // =====================================================================

    /** Normaliza os filtros vindos da URL (período padrão: últimos 6 meses) */
    public static function filtros(array $req): array
    {
        $data = function ($v, string $padrao): string {
            $v = trim((string) $v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : $padrao;
        };
        $f = [
            'desde'   => $data($req['desde'] ?? '', date('Y-m-01', strtotime('-5 months'))),
            'ate'     => $data($req['ate'] ?? '', date('Y-m-d')),
            'entidade' => max(0, (int) ($req['entidade'] ?? 0)),
            'tecnico' => max(0, (int) ($req['tecnico'] ?? 0)),
            'grupo'   => max(0, (int) ($req['grupo'] ?? 0)),
            'status'  => (int) ($req['status'] ?? 0),
            'negativas' => !empty($req['negativas']) ? 1 : 0,
        ];
        if (!isset(PluginPesquisadesatisfacaoPesquisa::STATUS[$f['status']])) {
            $f['status'] = 0;
        }
        if ($f['desde'] > $f['ate']) {
            [$f['desde'], $f['ate']] = [$f['ate'], $f['desde']];
        }
        return $f;
    }

    /** Parâmetros de URL dos filtros (para links, paginação e CSV) */
    public static function parametros(array $f): array
    {
        return array_filter($f, fn($v) => $v !== 0 && $v !== '');
    }

    /** Critérios sobre p (pesquisas); com ou sem o filtro de status */
    public static function criterios(array $f, bool $comStatus = true): array
    {
        $where = [
            ['p.date_creation' => ['>=', $f['desde'] . ' 00:00:00']],
            ['p.date_creation' => ['<=', $f['ate'] . ' 23:59:59']],
        ];
        $restricao = (new DbUtils())->getEntitiesRestrictCriteria('p', 'entities_id', '', false);
        if ($restricao) {
            $where[] = $restricao;
        }
        if ($f['entidade'] > 0) {
            $where[] = ['p.entities_id' => array_values(array_map('intval', getSonsOf('glpi_entities', $f['entidade'])))];
        }
        if ($f['tecnico'] > 0) {
            $where[] = ['p.users_id_tecnico' => $f['tecnico']];
        }
        if ($f['grupo'] > 0) {
            $where[] = ['p.groups_id' => $f['grupo']];
        }
        if ($comStatus && $f['status'] > 0) {
            $where[] = ['p.status' => $f['status']];
        }
        if ($comStatus && $f['negativas']) {
            $where[] = ['p.negativa' => 1];
        }
        return $where;
    }

    private static function tabela(): string
    {
        return PluginPesquisadesatisfacaoPesquisa::TABELA . ' AS p';
    }

    private static function q(string $sql): \Glpi\DBAL\QueryExpression
    {
        return new \Glpi\DBAL\QueryExpression($sql);
    }

    // =====================================================================
    // Indicadores
    // =====================================================================

    public static function indicadores(array $f): array
    {
        global $DB;
        $P = PluginPesquisadesatisfacaoPesquisa::class;
        $i = ['total' => 0, 'pendentes' => 0, 'respondidas' => 0, 'encerradas' => 0, 'negativas' => 0, 'taxa' => null, 'satisfacao' => null];
        foreach ($DB->request([
            'SELECT'  => ['p.status', self::q('COUNT(*) AS ' . $DB->quoteName('n')), self::q('SUM(' . $DB->quoteName('p.negativa') . ') AS ' . $DB->quoteName('neg')), self::q('AVG(' . $DB->quoteName('p.nota') . ') AS ' . $DB->quoteName('media'))],
            'FROM'    => self::tabela(),
            'WHERE'   => self::criterios($f, false),
            'GROUPBY' => ['p.status'],
        ]) as $r) {
            $n = (int) $r['n'];
            $i['total'] += $n;
            match ((int) $r['status']) {
                $P::PENDENTE   => $i['pendentes'] = $n,
                $P::RESPONDIDA => $i['respondidas'] = $n,
                $P::ENCERRADA  => $i['encerradas'] = $n,
                default        => null,
            };
            if ((int) $r['status'] === $P::RESPONDIDA) {
                $i['negativas'] = (int) $r['neg'];
                $i['satisfacao'] = $r['media'] === null ? null : round((float) $r['media'], 1);
            }
        }
        $i['taxa'] = $i['total'] > 0 ? round($i['respondidas'] / $i['total'] * 100, 1) : null;
        return $i;
    }

    /** Distribuição das respostas por pergunta (respondidas no filtro) */
    public static function porPergunta(array $f): array
    {
        global $DB;
        $perguntas = PluginPesquisadesatisfacaoPergunta::listar(false);
        $linhas = [];
        foreach ($DB->request([
            'SELECT'     => ['r.perguntas_id', 'r.valor', self::q('COUNT(*) AS ' . $DB->quoteName('n')), self::q('MAX(' . $DB->quoteName('r.pergunta') . ') AS ' . $DB->quoteName('texto'))],
            'FROM'       => PluginPesquisadesatisfacaoPesquisa::TABELA_RESPOSTAS . ' AS r',
            'INNER JOIN' => [self::tabela() => ['ON' => ['p' => 'id', 'r' => 'pesquisas_id']]],
            'WHERE'      => array_merge(self::criterios($f), [['p.status' => PluginPesquisadesatisfacaoPesquisa::RESPONDIDA]]),
            'GROUPBY'    => ['r.perguntas_id', 'r.valor'],
        ]) as $r) {
            $pid = (int) $r['perguntas_id'];
            $linhas[$pid] ??= [
                'id'     => $pid,
                'texto'  => isset($perguntas[$pid]) ? (string) $perguntas[$pid]['texto'] : (string) $r['texto'],
                'ordem'  => isset($perguntas[$pid]) ? (int) $perguntas[$pid]['ordem'] : 9999,
                'ativa'  => isset($perguntas[$pid]) && $perguntas[$pid]['is_active'],
                'contagem' => [1 => 0, 2 => 0, 3 => 0],
            ];
            $linhas[$pid]['contagem'][(int) $r['valor']] = (int) $r['n'];
        }
        foreach ($linhas as &$l) {
            $total = array_sum($l['contagem']);
            $l['total'] = $total;
            $l['satisfacao'] = $total > 0 ? round(($l['contagem'][2] * 50 + $l['contagem'][3] * 100) / $total, 1) : null;
        }
        unset($l);
        uasort($linhas, fn($a, $b) => [$a['ordem'], $a['id']] <=> [$b['ordem'], $b['id']]);
        return array_values($linhas);
    }

    /** Evolução mês a mês: enviadas, respondidas e satisfação média */
    public static function evolucao(array $f): array
    {
        global $DB;
        $meses = [];
        $inicio = strtotime(date('Y-m-01', strtotime($f['desde'])));
        $fim = strtotime(date('Y-m-01', strtotime($f['ate'])));
        for ($t = $inicio; $t <= $fim; $t = strtotime('+1 month', $t)) {
            $meses[date('Y-m', $t)] = ['mes' => date('m/Y', $t), 'enviadas' => 0, 'respondidas' => 0, 'satisfacao' => null];
        }
        $mes = 'DATE_FORMAT(' . $DB->quoteName('p.date_creation') . ", '%Y-%m')";
        foreach ($DB->request([
            'SELECT'  => [
                self::q($mes . ' AS ' . $DB->quoteName('mes')),
                self::q('COUNT(*) AS ' . $DB->quoteName('n')),
                self::q('SUM(CASE WHEN ' . $DB->quoteName('p.status') . ' = ' . PluginPesquisadesatisfacaoPesquisa::RESPONDIDA . ' THEN 1 ELSE 0 END) AS ' . $DB->quoteName('resp')),
                self::q('AVG(' . $DB->quoteName('p.nota') . ') AS ' . $DB->quoteName('media')),
            ],
            'FROM'    => self::tabela(),
            'WHERE'   => self::criterios($f),
            'GROUPBY' => ['mes'],
        ]) as $r) {
            if (isset($meses[$r['mes']])) {
                $meses[$r['mes']]['enviadas'] = (int) $r['n'];
                $meses[$r['mes']]['respondidas'] = (int) $r['resp'];
                $meses[$r['mes']]['satisfacao'] = $r['media'] === null ? null : round((float) $r['media'], 1);
            }
        }
        return array_values($meses);
    }

    /** Ranking por técnico ou por grupo (pesquisas respondidas) */
    public static function ranking(array $f, string $por): array
    {
        global $DB;
        $campo = $por === 'grupo' ? 'p.groups_id' : 'p.users_id_tecnico';
        $lista = [];
        foreach ($DB->request([
            'SELECT'  => [
                $campo . ' AS chave',
                self::q('COUNT(*) AS ' . $DB->quoteName('n')),
                self::q('AVG(' . $DB->quoteName('p.nota') . ') AS ' . $DB->quoteName('media')),
                self::q('SUM(' . $DB->quoteName('p.negativa') . ') AS ' . $DB->quoteName('neg')),
            ],
            'FROM'    => self::tabela(),
            'WHERE'   => array_merge(self::criterios($f), [['p.status' => PluginPesquisadesatisfacaoPesquisa::RESPONDIDA], [$campo => ['>', 0]]]),
            'GROUPBY' => [$campo],
        ]) as $r) {
            $lista[] = ['id' => (int) $r['chave'], 'respostas' => (int) $r['n'], 'satisfacao' => round((float) $r['media'], 1), 'negativas' => (int) $r['neg']];
        }
        if ($por === 'grupo') {
            foreach ($lista as &$l) {
                $l['nome'] = (string) Dropdown::getDropdownName('glpi_groups', $l['id']);
            }
        } else {
            $nomes = PluginPesquisadesatisfacaoConfig::nomesUsuarios(array_column($lista, 'id'));
            foreach ($lista as &$l) {
                $l['nome'] = $nomes[$l['id']] ?? ('#' . $l['id']);
            }
        }
        unset($l);
        usort($lista, fn($a, $b) => [$b['satisfacao'], $b['respostas']] <=> [$a['satisfacao'], $a['respostas']]);
        return $lista;
    }

    /** Justificativas e comentários mais recentes */
    public static function comentarios(array $f, int $limite = 40): array
    {
        global $DB;
        $base = array_merge(self::criterios($f), [['p.status' => PluginPesquisadesatisfacaoPesquisa::RESPONDIDA]]);
        $lista = [];
        foreach ($DB->request([
            'SELECT'     => ['p.id', 'p.tickets_id', 'p.data_resposta', 'p.users_id_tecnico', 'r.valor', 'r.pergunta', 'r.justificativa AS texto'],
            'FROM'       => PluginPesquisadesatisfacaoPesquisa::TABELA_RESPOSTAS . ' AS r',
            'INNER JOIN' => [self::tabela() => ['ON' => ['p' => 'id', 'r' => 'pesquisas_id']]],
            'WHERE'      => array_merge($base, [['NOT' => ['r.justificativa' => null]], ['r.justificativa' => ['<>', '']]]),
            'ORDER'      => 'p.data_resposta DESC',
            'LIMIT'      => $limite,
        ]) as $r) {
            $lista[] = $r;
        }
        foreach ($DB->request([
            'SELECT' => ['p.id', 'p.tickets_id', 'p.data_resposta', 'p.users_id_tecnico', 'p.nota', 'p.comentario AS texto'],
            'FROM'   => self::tabela(),
            'WHERE'  => array_merge($base, [['NOT' => ['p.comentario' => null]], ['p.comentario' => ['<>', '']]]),
            'ORDER'  => 'p.data_resposta DESC',
            'LIMIT'  => $limite,
        ]) as $r) {
            $r['valor'] = null;
            $r['pergunta'] = 'Comentário final';
            $lista[] = $r;
        }
        usort($lista, fn($a, $b) => strcmp((string) $b['data_resposta'], (string) $a['data_resposta']));
        return array_slice($lista, 0, $limite);
    }

    // =====================================================================
    // Lista de pesquisas
    // =====================================================================

    public static function contar(array $f): int
    {
        global $DB;
        return count($DB->request(['SELECT' => ['p.id'], 'FROM' => self::tabela(), 'WHERE' => self::criterios($f)]));
    }

    public static function listar(array $f, int $inicio = 0, int $limite = self::POR_PAGINA): array
    {
        global $DB;
        $lista = [];
        $criterio = [
            'SELECT'    => ['p.*', 't.name AS titulo', 't.status AS chamado_status', 'e.completename AS entidade'],
            'FROM'      => self::tabela(),
            'LEFT JOIN' => [
                'glpi_tickets AS t'  => ['ON' => ['t' => 'id', 'p' => 'tickets_id']],
                'glpi_entities AS e' => ['ON' => ['e' => 'id', 'p' => 'entities_id']],
            ],
            'WHERE'     => self::criterios($f),
            'ORDER'     => ['p.date_creation DESC', 'p.id DESC'],
        ];
        if ($limite > 0) {
            $criterio['START'] = max(0, $inicio);
            $criterio['LIMIT'] = $limite;
        }
        foreach ($DB->request($criterio) as $r) {
            $lista[] = $r;
        }
        return $lista;
    }

    /** CSV (ponto e vírgula, UTF-8 com BOM) com as pesquisas e as respostas de cada pergunta */
    public static function csv(array $f): string
    {
        $C = PluginPesquisadesatisfacaoConfig::class;
        $P = PluginPesquisadesatisfacaoPesquisa::class;
        $rotulos = $C::rotulos();
        $lista = self::listar($f, 0, 0);
        $perguntas = [];
        $respostas = [];
        foreach ($lista as $l) {
            foreach ($P::respostas((int) $l['id']) as $r) {
                $perguntas[(int) $r['perguntas_id']] ??= (string) $r['pergunta'];
                $respostas[(int) $l['id']][(int) $r['perguntas_id']] = $r;
            }
        }
        $nomes = $C::nomesUsuarios(array_merge(array_column($lista, 'users_id_requerente'), array_column($lista, 'users_id_tecnico')));
        $campo = fn($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $cab = ['Chamado', 'Título', 'Entidade', 'Requerente', 'E-mail', 'Técnico', 'Grupo', 'Status', 'Satisfação (%)', 'Criada em', 'Respondida em', 'Lembretes'];
        foreach ($perguntas as $texto) {
            $cab[] = $texto;
            $cab[] = 'Justificativa';
        }
        $cab[] = 'Comentário';
        $linhas = [implode(';', array_map($campo, $cab))];
        foreach ($lista as $l) {
            $v = [
                $l['tickets_id'], $l['titulo'], $l['entidade'], $nomes[(int) $l['users_id_requerente']] ?? '', $l['email_destino'],
                $nomes[(int) $l['users_id_tecnico']] ?? '', (int) $l['groups_id'] > 0 ? Dropdown::getDropdownName('glpi_groups', (int) $l['groups_id']) : '',
                $P::STATUS[(int) $l['status']][0] ?? '', $l['nota'] === null ? '' : number_format((float) $l['nota'], 1, ',', ''),
                $l['date_creation'] ? Html::convDateTime((string) $l['date_creation']) : '', $l['data_resposta'] ? Html::convDateTime((string) $l['data_resposta']) : '', $l['lembretes'],
            ];
            foreach (array_keys($perguntas) as $pid) {
                $r = $respostas[(int) $l['id']][$pid] ?? null;
                $v[] = $r ? ($rotulos[(int) $r['valor']] ?? '') : '';
                $v[] = $r ? (string) $r['justificativa'] : '';
            }
            $v[] = (string) $l['comentario'];
            $linhas[] = implode(';', array_map($campo, $v));
        }
        return "\u{FEFF}" . implode("\r\n", $linhas) . "\r\n";
    }
}
