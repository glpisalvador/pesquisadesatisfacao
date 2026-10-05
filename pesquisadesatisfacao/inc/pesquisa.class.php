<?php

/**
 * Plugin Pesquisa de Satisfação - ciclo de vida da pesquisa:
 * criação ao solucionar, respostas pela página pública, lembretes, encerramento,
 * fechamento do chamado, bloqueio de novos chamados e aba no chamado.
 */
class PluginPesquisadesatisfacaoPesquisa extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_pesquisadesatisfacao_pesquisas';
    public const TABELA_RESPOSTAS = 'glpi_plugin_pesquisadesatisfacao_respostas';

    public const PENDENTE   = 1;
    public const RESPONDIDA = 2;
    public const ENCERRADA  = 3;

    /** Rótulo e tom de cada status */
    public const STATUS = [
        self::PENDENTE   => ['Pendente', 'aviso'],
        self::RESPONDIDA => ['Respondida', 'ok'],
        self::ENCERRADA  => ['Encerrada sem resposta', 'neutro'],
    ];

    /** Liberado só enquanto o próprio plugin fecha o chamado */
    private static bool $fechandoPeloPlugin = false;

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Pesquisas de satisfação' : 'Pesquisa de satisfação';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function getIcon(): string
    {
        return 'ti ti-mood-smile';
    }

    public static function canView(): bool
    {
        return PluginPesquisadesatisfacaoConfig::podeVerRelatorios();
    }

    public static function canCreate(): bool
    {
        return PluginPesquisadesatisfacaoConfig::podeVerRelatorios();
    }

    public static function canUpdate(): bool
    {
        return PluginPesquisadesatisfacaoConfig::podeVerRelatorios();
    }

    public static function canDelete(): bool
    {
        return PluginPesquisadesatisfacaoConfig::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return PluginPesquisadesatisfacaoConfig::ehAdmin();
    }

    // =====================================================================
    // Consultas simples
    // =====================================================================

    public static function porChamado(int $tickets_id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['tickets_id' => $tickets_id], 'LIMIT' => 1]) as $r) {
            return $r;
        }
        return null;
    }

    public static function porId(int $id): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $r) {
            return $r;
        }
        return null;
    }

    public static function porToken(string $token): ?array
    {
        global $DB;
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['token' => $token], 'LIMIT' => 1]) as $r) {
            return $r;
        }
        return null;
    }

    /** Respostas de uma pesquisa, na ordem das perguntas */
    public static function respostas(int $pesquisas_id): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT'    => ['r.*', 'p.ordem'],
            'FROM'      => self::TABELA_RESPOSTAS . ' AS r',
            'LEFT JOIN' => [PluginPesquisadesatisfacaoPergunta::TABELA . ' AS p' => ['ON' => ['p' => 'id', 'r' => 'perguntas_id']]],
            'WHERE'     => ['r.pesquisas_id' => $pesquisas_id],
            'ORDER'     => ['p.ordem ASC', 'r.id ASC'],
        ]) as $r) {
            $lista[] = $r;
        }
        return $lista;
    }

    public static function pendentesDoUsuario(int $users_id): array
    {
        global $DB;
        $lista = [];
        if ($users_id <= 0) {
            return $lista;
        }
        foreach ($DB->request([
            'SELECT'    => ['p.id', 'p.tickets_id', 'p.token', 'p.date_creation', 't.name AS titulo', 't.solvedate'],
            'FROM'      => self::TABELA . ' AS p',
            'LEFT JOIN' => ['glpi_tickets AS t' => ['ON' => ['t' => 'id', 'p' => 'tickets_id']]],
            'WHERE'     => ['p.users_id_requerente' => $users_id, 'p.status' => self::PENDENTE],
            'ORDER'     => 'p.date_creation ASC',
        ]) as $r) {
            $lista[] = $r;
        }
        return $lista;
    }

    public static function contarPendentes(int $users_id): int
    {
        global $DB;
        return count($DB->request(['SELECT' => ['id'], 'FROM' => self::TABELA, 'WHERE' => ['users_id_requerente' => $users_id, 'status' => self::PENDENTE]]));
    }

    /** Mensagem no histórico nativo do chamado */
    public static function historico(int $tickets_id, string $mensagem): void
    {
        Log::history($tickets_id, 'Ticket', [0, '', $mensagem], '', Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }

    public static function gerarToken(): string
    {
        do {
            $token = bin2hex(random_bytes(24));
        } while (self::porToken($token) !== null);
        return $token;
    }

    // =====================================================================
    // Criação
    // =====================================================================

    /** Atores do chamado usados na pesquisa: requerente, técnico e grupo */
    public static function atores(int $tickets_id): array
    {
        global $DB;
        $atores = ['requerente' => 0, 'email' => '', 'tecnico' => 0, 'grupo' => 0];

        foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $tickets_id, 'type' => CommonITILActor::REQUESTER], 'ORDER' => 'id ASC']) as $r) {
            $uid = (int) $r['users_id'];
            $email = $uid > 0 ? PluginPesquisadesatisfacaoConfig::emailUsuario($uid) : '';
            if ($email === '' && filter_var(trim((string) $r['alternative_email']), FILTER_VALIDATE_EMAIL)) {
                $email = trim((string) $r['alternative_email']);
            }
            if ($uid > 0 || $email !== '') {
                $atores['requerente'] = $uid;
                $atores['email'] = $email;
                break;
            }
        }
        // Técnico: o último atribuído
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $tickets_id, 'type' => CommonITILActor::ASSIGN, 'users_id' => ['>', 0]], 'ORDER' => 'id DESC', 'LIMIT' => 1]) as $r) {
            $atores['tecnico'] = (int) $r['users_id'];
        }
        // Grupo: o atribuído; sem ele, o observador
        foreach ($DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $tickets_id, 'type' => [CommonITILActor::ASSIGN, CommonITILActor::OBSERVER]], 'ORDER' => ['type ASC', 'id ASC'], 'LIMIT' => 1]) as $r) {
            $atores['grupo'] = (int) $r['groups_id'];
        }
        return $atores;
    }

    /**
     * Cria a pesquisa do chamado e envia o convite.
     * $manual: criada pela aba do chamado (ignora tipo e entidades excluídas).
     */
    public static function criar(int $tickets_id, bool $manual = false): array
    {
        global $DB;
        $C = PluginPesquisadesatisfacaoConfig::class;

        if (self::porChamado($tickets_id) !== null) {
            return ['ok' => false, 'mensagem' => 'Este chamado já tem pesquisa.'];
        }
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || !empty($ticket->fields['is_deleted'])) {
            return ['ok' => false, 'mensagem' => 'Chamado não encontrado.'];
        }
        if (!$manual) {
            if (!in_array((int) $ticket->fields['type'], array_map('intval', $C::getArrayConfig('tipos')), true)) {
                return ['ok' => false, 'mensagem' => 'Tipo de chamado fora do disparo.'];
            }
            if (in_array((int) $ticket->fields['entities_id'], $C::entidadesExcluidasComFilhas(), true)) {
                return ['ok' => false, 'mensagem' => 'Entidade excluída do disparo.'];
            }
        }
        $atores = self::atores($tickets_id);
        if ($atores['requerente'] <= 0 && $atores['email'] === '') {
            return ['ok' => false, 'mensagem' => 'O chamado não tem requerente com usuário ou e-mail.'];
        }

        $agora = date('Y-m-d H:i:s');
        $DB->insert(self::TABELA, [
            'tickets_id'          => $tickets_id,
            'entities_id'         => (int) $ticket->fields['entities_id'],
            'users_id_requerente' => $atores['requerente'],
            'users_id_tecnico'    => $atores['tecnico'],
            'groups_id'           => $atores['grupo'],
            'email_destino'       => $atores['email'],
            'token'               => self::gerarToken(),
            'status'              => self::PENDENTE,
            'date_creation'       => $agora,
        ]);
        $id = (int) $DB->insertId();
        if ($id <= 0) {
            return ['ok' => false, 'mensagem' => 'Não foi possível gravar a pesquisa.'];
        }
        self::historico($tickets_id, 'Pesquisa de satisfação criada' . ($manual ? ' manualmente' : ''));

        $enviado = $atores['email'] !== '' && PluginPesquisadesatisfacaoEmail::enviarPesquisa($id, PluginPesquisadesatisfacaoEmail::CONVITE, $manual ? (int) Session::getLoginUserID() : 0);
        if ($atores['email'] === '') {
            PluginPesquisadesatisfacaoEmail::registrar($id, $tickets_id, PluginPesquisadesatisfacaoEmail::CONVITE, '', false, 'O requerente não tem e-mail: a pesquisa fica disponível em "Minhas pesquisas pendentes".');
        }
        return [
            'ok'       => true,
            'id'       => $id,
            'enviado'  => $enviado,
            'mensagem' => $enviado ? 'Pesquisa criada e convite enviado.' : 'Pesquisa criada, mas o convite não foi enviado (veja o histórico de envios).',
        ];
    }

    /** Apaga a pesquisa, as respostas e o histórico de envios */
    public static function excluir(int $id): bool
    {
        global $DB;
        if (self::porId($id) === null) {
            return false;
        }
        $DB->delete(self::TABELA_RESPOSTAS, ['pesquisas_id' => $id]);
        $DB->delete(PluginPesquisadesatisfacaoEmail::TABELA, ['pesquisas_id' => $id]);
        $DB->delete(self::TABELA, ['id' => $id]);
        return true;
    }

    // =====================================================================
    // Resposta (página pública)
    // =====================================================================

    /**
     * Valida e grava as respostas.
     * $valores [perguntas_id => 1..3], $justificativas [perguntas_id => texto].
     * Retorna ['ok' => bool, 'erros' => [perguntas_id|'geral' => mensagem]].
     */
    public static function responder(string $token, array $valores, array $justificativas, string $comentario = ''): array
    {
        global $DB;
        $C = PluginPesquisadesatisfacaoConfig::class;

        $pesquisa = self::porToken($token);
        if ($pesquisa === null) {
            return ['ok' => false, 'erros' => ['geral' => 'Pesquisa não encontrada.']];
        }
        if ((int) $pesquisa['status'] !== self::PENDENTE) {
            return ['ok' => false, 'erros' => ['geral' => 'Esta pesquisa já foi respondida ou foi encerrada.']];
        }

        $perguntas = PluginPesquisadesatisfacaoPergunta::listar();
        $rotulos = $C::rotulos();
        $erros = [];
        $linhas = [];
        foreach ($perguntas as $pid => $p) {
            $valor = (int) ($valores[$pid] ?? 0);
            $just = mb_substr(trim((string) ($justificativas[$pid] ?? '')), 0, 2000);
            if ($valor < 1 || $valor > 3) {
                $erros[$pid] = 'Escolha uma resposta.';
                continue;
            }
            if ($valor === 1 && $p['justificativa'] === 'ruim' && $just === '') {
                $erros[$pid] = 'Conte o motivo da resposta "' . $rotulos[1] . '".';
                continue;
            }
            $linhas[] = [
                'perguntas_id'  => $pid,
                'pergunta'      => mb_substr((string) $p['texto'], 0, 500),
                'valor'         => $valor,
                'justificativa' => $p['justificativa'] === 'nao' || $just === '' ? null : $just,
            ];
        }
        if ($erros) {
            return ['ok' => false, 'erros' => $erros];
        }

        $nota = round(array_sum(array_map(fn($l) => ($l['valor'] - 1) * 50, $linhas)) / max(1, count($linhas)), 1);
        $negativa = count(array_filter($linhas, fn($l) => $l['valor'] === 1)) > 0;
        $comentario = $C::ligado('comentario_final') ? mb_substr(trim($comentario), 0, 2000) : '';

        // Só uma resposta vale: a troca de status é feita apenas se ainda estiver pendente
        $DB->update(self::TABELA, [
            'status'        => self::RESPONDIDA,
            'data_resposta' => date('Y-m-d H:i:s'),
            'nota'          => $nota,
            'negativa'      => $negativa ? 1 : 0,
            'comentario'    => $comentario !== '' ? $comentario : null,
        ], ['id' => (int) $pesquisa['id'], 'status' => self::PENDENTE]);
        if ($DB->affectedRows() < 1) {
            return ['ok' => false, 'erros' => ['geral' => 'Esta pesquisa já foi respondida.']];
        }
        foreach ($linhas as $l) {
            $DB->insert(self::TABELA_RESPOSTAS, $l + ['pesquisas_id' => (int) $pesquisa['id']]);
        }
        self::historico((int) $pesquisa['tickets_id'], 'Pesquisa de satisfação respondida: ' . $C::pct($nota, 0) . ($negativa ? ' (avaliação negativa)' : ''));

        if ($negativa && $C::ligado('alerta_ativo')) {
            PluginPesquisadesatisfacaoEmail::enviarAlerta((int) $pesquisa['id']);
            if ($C::ligado('alerta_acompanhamento')) {
                self::acompanhamentoNegativo((int) $pesquisa['id']);
            }
        }
        // Com 0 dias fecha já; pela página pública (sem login) o GLPI não deixa mudar o status,
        // e a tarefa automática fecha na execução seguinte
        if ($C::ligado('bloquear_fechamento') && $C::inteiro('dias_para_fechar', 0, 365) === 0 && Session::getLoginUserID()) {
            self::fecharChamado((int) $pesquisa['tickets_id']);
        }
        return ['ok' => true, 'erros' => []];
    }

    /** Resumo das respostas em HTML (alerta e acompanhamento) */
    public static function resumoHtml(int $pesquisas_id): string
    {
        $pesquisa = self::porId($pesquisas_id);
        if ($pesquisa === null) {
            return '';
        }
        $rotulos = PluginPesquisadesatisfacaoConfig::rotulos();
        $e = [PluginPesquisadesatisfacaoConfig::class, 'e'];
        $h = '<p><strong>Satisfação: ' . PluginPesquisadesatisfacaoConfig::pct($pesquisa['nota'], 0) . '</strong></p><ul>';
        foreach (self::respostas($pesquisas_id) as $r) {
            $h .= '<li>' . $e($r['pergunta']) . ': <strong>' . $e($rotulos[(int) $r['valor']] ?? '') . '</strong>'
                . (trim((string) $r['justificativa']) !== '' ? '<br><em>' . nl2br($e($r['justificativa'])) . '</em>' : '') . '</li>';
        }
        $h .= '</ul>';
        if (trim((string) $pesquisa['comentario']) !== '') {
            $h .= '<p>Comentário: <em>' . nl2br($e($pesquisa['comentario'])) . '</em></p>';
        }
        return $h;
    }

    /** Acompanhamento privado no chamado com a avaliação negativa (não reabre: é criado sem usuário logado) */
    public static function acompanhamentoNegativo(int $pesquisas_id): bool
    {
        $pesquisa = self::porId($pesquisas_id);
        if ($pesquisa === null) {
            return false;
        }
        $followup = new ITILFollowup();
        return (bool) $followup->add([
            'itemtype'      => 'Ticket',
            'items_id'      => (int) $pesquisa['tickets_id'],
            'content'       => '<p><strong>Pesquisa de satisfação com avaliação negativa</strong></p>' . self::resumoHtml($pesquisas_id),
            'is_private'    => 1,
            'users_id'      => 0,
            '_no_reopen'    => 1,
            '_disablenotif' => true,
        ]);
    }

    // =====================================================================
    // Fechamento do chamado
    // =====================================================================

    public static function fecharChamado(int $tickets_id): bool
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || (int) $ticket->fields['status'] !== Ticket::SOLVED) {
            return false;
        }
        self::$fechandoPeloPlugin = true;
        try {
            return (bool) $ticket->update(['id' => $tickets_id, 'status' => Ticket::CLOSED, '_disablenotif' => true]);
        } finally {
            self::$fechandoPeloPlugin = false;
        }
    }

    /** Segura o chamado em Solucionado enquanto a pesquisa pede (pendente, ou dentro do prazo após a resposta) */
    public static function motivoParaSegurar(int $tickets_id): string
    {
        $C = PluginPesquisadesatisfacaoConfig::class;
        if (!$C::ligado('bloquear_fechamento')) {
            return '';
        }
        $pesquisa = self::porChamado($tickets_id);
        if ($pesquisa === null) {
            return '';
        }
        if ((int) $pesquisa['status'] === self::PENDENTE) {
            return 'O chamado continua solucionado até a pesquisa de satisfação ser respondida.';
        }
        $dias = $C::inteiro('dias_para_fechar', 0, 365);
        if ((int) $pesquisa['status'] === self::RESPONDIDA && $dias > 0 && !empty($pesquisa['data_resposta'])
            && strtotime((string) $pesquisa['data_resposta']) + $dias * 86400 > time()) {
            return 'O chamado será fechado automaticamente ' . $dias . ' dia(s) após a resposta da pesquisa.';
        }
        return '';
    }

    // =====================================================================
    // Hooks do chamado
    // =====================================================================

    /** pre_item_update: impede Solucionado -> Fechado enquanto a pesquisa pede */
    public static function antesAtualizarChamado(Ticket $ticket): void
    {
        if (self::$fechandoPeloPlugin || !is_array($ticket->input) || !isset($ticket->input['status'])) {
            return;
        }
        if ((int) $ticket->input['status'] !== Ticket::CLOSED || (int) ($ticket->fields['status'] ?? 0) !== Ticket::SOLVED) {
            return;
        }
        $motivo = self::motivoParaSegurar((int) $ticket->fields['id']);
        if ($motivo === '') {
            return;
        }
        unset($ticket->input['status'], $ticket->input['closedate']);
        if (Session::getLoginUserID() && empty($ticket->input['_auto_update'])) {
            Session::addMessageAfterRedirect($motivo, false, WARNING);
        }
    }

    /** item_update: cria ao solucionar; descarta a pendente se o chamado for reaberto */
    public static function aposAtualizarChamado(Ticket $ticket): void
    {
        if (!in_array('status', (array) ($ticket->updates ?? []), true)) {
            return;
        }
        $novo = (int) $ticket->fields['status'];
        $antigo = (int) ($ticket->oldvalues['status'] ?? 0);
        $id = (int) $ticket->fields['id'];

        if ($novo === Ticket::SOLVED && $antigo !== Ticket::SOLVED) {
            self::criar($id);
            return;
        }
        if ($antigo === Ticket::SOLVED && !in_array($novo, [Ticket::SOLVED, Ticket::CLOSED], true)) {
            $pesquisa = self::porChamado($id);
            if ($pesquisa !== null && (int) $pesquisa['status'] === self::PENDENTE) {
                self::excluir((int) $pesquisa['id']);
            }
        }
    }

    public static function aposExcluirChamado(Ticket $ticket): void
    {
        $pesquisa = self::porChamado((int) $ticket->fields['id']);
        if ($pesquisa !== null) {
            self::excluir((int) $pesquisa['id']);
        }
    }

    /** Requerentes informados no formulário (campos do GLPI 11/12 e da API) */
    public static function requerentesDoInput(array $input): array
    {
        $ids = [];
        foreach ((array) ($input['_users_id_requester'] ?? []) as $v) {
            $ids[] = (int) $v;
        }
        foreach ((array) ($input['_actors']['requester'] ?? []) as $ator) {
            if (is_array($ator) && ($ator['itemtype'] ?? '') === 'User') {
                $ids[] = (int) ($ator['items_id'] ?? 0);
            }
        }
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }

    /** pre_item_add: quem tem pesquisas pendentes demais responde antes de abrir outro chamado */
    public static function antesCriarChamado(Ticket $ticket): void
    {
        $C = PluginPesquisadesatisfacaoConfig::class;
        $uid = (int) Session::getLoginUserID();
        if (!$C::ligado('bloqueio_ativo') || $uid <= 0 || !is_array($ticket->input)) {
            return; // coletor de e-mail, tarefas e API sem usuário não são bloqueados
        }
        $requerentes = self::requerentesDoInput($ticket->input);
        if ($requerentes && !in_array($uid, $requerentes, true)) {
            return; // técnico abrindo chamado para outra pessoa
        }
        $max = $C::inteiro('max_pendentes', 1, 100);
        $total = self::contarPendentes($uid);
        if ($total < $max) {
            return;
        }
        $ticket->input = false;
        Session::addMessageAfterRedirect(
            'Você tem ' . $total . ' pesquisa(s) de satisfação pendente(s). Responda para abrir um novo chamado.',
            false,
            ERROR
        );
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if (!$ajax && str_contains($uri, '/front/') && !str_contains($uri, '/ajax/')) {
            Html::redirect($C::url('pendentes.php'));
        }
    }

    // =====================================================================
    // Tarefa automática
    // =====================================================================

    public static function cronInfo($name): array
    {
        return ['description' => 'Pesquisa de satisfação: lembretes, encerramento sem resposta e fechamento dos chamados'];
    }

    public static function cronProcessar(CronTask $task): int
    {
        $feitos = self::processar();
        $task->addVolume($feitos['total']);
        $task->log(sprintf('Lembretes: %d, encerradas: %d, chamados fechados: %d', $feitos['lembretes'], $feitos['encerradas'], $feitos['fechados']));
        return $feitos['total'] > 0 ? 1 : 0;
    }

    public static function processar(int $limite = 200): array
    {
        global $DB;
        $C = PluginPesquisadesatisfacaoConfig::class;
        $feitos = ['lembretes' => 0, 'encerradas' => 0, 'fechados' => 0, 'total' => 0];
        $agora = time();

        // 1. Encerramento por falta de resposta
        $diasEncerrar = $C::inteiro('dias_encerrar', 0, 365);
        if ($diasEncerrar > 0) {
            foreach ($DB->request([
                'SELECT' => ['id', 'email_destino'],
                'FROM'   => self::TABELA,
                'WHERE'  => ['status' => self::PENDENTE, 'date_creation' => ['<=', date('Y-m-d H:i:s', $agora - $diasEncerrar * 86400)]],
                'LIMIT'  => $limite,
            ]) as $r) {
                $DB->update(self::TABELA, ['status' => self::ENCERRADA, 'data_encerramento' => date('Y-m-d H:i:s')], ['id' => (int) $r['id'], 'status' => self::PENDENTE]);
                if ($DB->affectedRows() > 0) {
                    if (trim((string) $r['email_destino']) !== '') {
                        PluginPesquisadesatisfacaoEmail::enviarPesquisa((int) $r['id'], PluginPesquisadesatisfacaoEmail::ENCERRAMENTO);
                    }
                    $feitos['encerradas']++;
                }
            }
        }

        // 2. Lembretes a cada N dias enquanto pendente
        $diasLembrete = $C::inteiro('dias_lembrete', 0, 365);
        if ($diasLembrete > 0) {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::TABELA,
                'WHERE'  => [
                    'status'        => self::PENDENTE,
                    'email_destino' => ['<>', ''],
                    'OR'            => [
                        ['data_ultimo_envio' => null],
                        ['data_ultimo_envio' => ['<=', date('Y-m-d H:i:s', $agora - $diasLembrete * 86400)]],
                    ],
                ],
                'LIMIT'  => $limite,
            ]) as $r) {
                PluginPesquisadesatisfacaoEmail::enviarPesquisa((int) $r['id'], PluginPesquisadesatisfacaoEmail::LEMBRETE);
                $feitos['lembretes']++;
            }
        }

        // 3. Fecha os chamados que o plugin segurou em Solucionado
        if ($C::ligado('bloquear_fechamento')) {
            $diasFechar = $C::inteiro('dias_para_fechar', 0, 365);
            foreach ($DB->request([
                'SELECT'     => ['p.tickets_id'],
                'FROM'       => self::TABELA . ' AS p',
                'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => ['t' => 'id', 'p' => 'tickets_id']]],
                'WHERE'      => [
                    't.status' => Ticket::SOLVED,
                    'OR'       => [
                        ['p.status' => self::ENCERRADA],
                        ['p.status' => self::RESPONDIDA, 'p.data_resposta' => ['<=', date('Y-m-d H:i:s', $agora - $diasFechar * 86400)]],
                    ],
                ],
                'LIMIT'      => $limite,
            ]) as $r) {
                if (self::fecharChamado((int) $r['tickets_id'])) {
                    $feitos['fechados']++;
                }
            }
        }

        $feitos['total'] = $feitos['lembretes'] + $feitos['encerradas'] + $feitos['fechados'];
        return $feitos;
    }

    // =====================================================================
    // Busca de chamados (colunas do plugin)
    // =====================================================================

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $s = (int) ($values[$field] ?? 0);
            return isset(self::STATUS[$s]) ? self::STATUS[$s][0] : '';
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $opcoes = array_map(fn($s) => $s[0], self::STATUS);
            return Dropdown::showFromArray($name, $opcoes, ['value' => $values[$field] ?? 0, 'display' => false, 'display_emptychoice' => true]);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    // =====================================================================
    // Aba no chamado
    // =====================================================================

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof Ticket || $item->isNewItem() || !PluginPesquisadesatisfacaoConfig::podeVerRelatorios()) {
            return '';
        }
        return self::createTabEntry(self::getTypeName(1), 0, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Ticket && PluginPesquisadesatisfacaoConfig::podeVerRelatorios()) {
            echo PluginPesquisadesatisfacaoConfig::assets();
            echo self::conteudoAba($item);
        }
        return true;
    }

    public static function selo(int $status): string
    {
        [$rotulo, $tom] = self::STATUS[$status] ?? ['—', 'neutro'];
        return '<span class="pesquisadesatisfacao-selo pesquisadesatisfacao-tom-' . $tom . '">' . PluginPesquisadesatisfacaoConfig::e($rotulo) . '</span>';
    }

    /** Tom da nota: boa (80+), média (50+) ou ruim */
    public static function tomNota($nota): string
    {
        if ($nota === null || $nota === '') {
            return 'neutro';
        }
        return (float) $nota >= 80 ? 'ok' : ((float) $nota >= 50 ? 'aviso' : 'erro');
    }

    public static function conteudoAba(Ticket $ticket): string
    {
        $C = PluginPesquisadesatisfacaoConfig::class;
        $e = [$C, 'e'];
        $tid = (int) $ticket->getID();
        $pesquisa = self::porChamado($tid);

        $h = '<div class="pesquisadesatisfacao-aba" data-pesquisadesatisfacao-aba data-ajax="' . $e($C::url('ajax.php')) . '" data-token="' . $e($C::tokenCsrf()) . '">';

        if ($pesquisa === null) {
            $h .= '<div class="pesquisadesatisfacao-vazio"><i class="ti ti-mood-empty"></i><div>Este chamado não tem pesquisa de satisfação.';
            if (in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true)) {
                $h .= '<div class="mt-2"><button type="button" class="btn btn-sm pesquisadesatisfacao-btn-principal" data-pesquisadesatisfacao-acao="criar" data-chamado="' . $tid . '">'
                    . '<i class="ti ti-send"></i><span>Criar pesquisa e enviar convite</span></button></div>';
            } else {
                $h .= '<div class="text-muted small mt-1">Ela é criada automaticamente quando o chamado é solucionado.</div>';
            }
            return $h . '</div></div></div>';
        }

        $pid = (int) $pesquisa['id'];
        $status = (int) $pesquisa['status'];
        $nomes = $C::nomesUsuarios([(int) $pesquisa['users_id_requerente'], (int) $pesquisa['users_id_tecnico']]);
        $data = fn($v) => $v ? Html::convDateTime((string) $v) : '—';

        // Resumo
        $h .= '<div class="pesquisadesatisfacao-resumo">';
        $h .= '<div class="pesquisadesatisfacao-resumo-nota pesquisadesatisfacao-tom-' . self::tomNota($pesquisa['nota']) . '">'
            . '<span>' . ($pesquisa['nota'] === null ? '—' : $C::pct($pesquisa['nota'], 0)) . '</span><small>satisfação</small></div>';
        $h .= '<dl class="pesquisadesatisfacao-dados">';
        $h .= '<div><dt>Status</dt><dd>' . self::selo($status) . ($pesquisa['negativa'] ? ' <span class="pesquisadesatisfacao-selo pesquisadesatisfacao-tom-erro">Avaliação negativa</span>' : '') . '</dd></div>';
        $h .= '<div><dt>Requerente</dt><dd>' . $e($nomes[(int) $pesquisa['users_id_requerente']] ?? '—') . ($pesquisa['email_destino'] !== '' ? ' <span class="text-muted">&lt;' . $e($pesquisa['email_destino']) . '&gt;</span>' : '') . '</dd></div>';
        $h .= '<div><dt>Técnico</dt><dd>' . $e($nomes[(int) $pesquisa['users_id_tecnico']] ?? '—') . '</dd></div>';
        $h .= '<div><dt>Grupo</dt><dd>' . ((int) $pesquisa['groups_id'] > 0 ? $e(Dropdown::getDropdownName('glpi_groups', (int) $pesquisa['groups_id'])) : '—') . '</dd></div>';
        $h .= '<div><dt>Criada em</dt><dd>' . $data($pesquisa['date_creation']) . '</dd></div>';
        $h .= '<div><dt>Último envio</dt><dd>' . $data($pesquisa['data_ultimo_envio']) . ' <span class="text-muted">(' . (int) $pesquisa['lembretes'] . ' lembrete(s))</span></dd></div>';
        if ($status === self::RESPONDIDA) {
            $h .= '<div><dt>Respondida em</dt><dd>' . $data($pesquisa['data_resposta']) . '</dd></div>';
        }
        if ($status === self::ENCERRADA) {
            $h .= '<div><dt>Encerrada em</dt><dd>' . $data($pesquisa['data_encerramento']) . '</dd></div>';
        }
        $h .= '</dl></div>';

        $motivo = self::motivoParaSegurar($tid);
        if ($motivo !== '' && (int) $ticket->fields['status'] === Ticket::SOLVED) {
            $h .= '<div class="pesquisadesatisfacao-aviso pesquisadesatisfacao-aviso-info"><i class="ti ti-info-circle"></i><span>' . $e($motivo) . '</span></div>';
        }

        // Ações
        $h .= '<div class="pesquisadesatisfacao-acoes">';
        if ($status === self::PENDENTE) {
            $link = $C::urlPublica((string) $pesquisa['token']);
            $h .= '<div class="input-group input-group-sm pesquisadesatisfacao-link"><input type="text" class="form-control" readonly value="' . $e($link) . '">'
                . '<button type="button" class="btn btn-outline-secondary" data-pesquisadesatisfacao-copiar title="Copiar link"><i class="ti ti-copy"></i><span>Copiar link</span></button></div>';
            $h .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-pesquisadesatisfacao-acao="reenviar" data-pesquisa="' . $pid . '"' . ($pesquisa['email_destino'] === '' ? ' disabled title="O requerente não tem e-mail"' : '') . '>'
                . '<i class="ti ti-mail"></i><span>Reenviar e-mail agora</span></button>';
        }
        if ($C::ehAdmin()) {
            $h .= '<button type="button" class="btn btn-sm btn-outline-danger" data-pesquisadesatisfacao-acao="excluir" data-pesquisa="' . $pid . '" data-confirmar="Excluir a pesquisa deste chamado, com respostas e histórico de envios?">'
                . '<i class="ti ti-trash"></i><span>Excluir pesquisa</span></button>';
        }
        $h .= '</div>';

        // Respostas
        if ($status === self::RESPONDIDA) {
            $rotulos = $C::rotulos();
            $h .= '<h3 class="pesquisadesatisfacao-subtitulo"><i class="ti ti-list-check"></i> Respostas</h3><div class="pesquisadesatisfacao-respostas">';
            foreach (self::respostas($pid) as $r) {
                $v = (int) $r['valor'];
                $h .= '<div class="pesquisadesatisfacao-resposta"><div class="pesquisadesatisfacao-resposta-valor pesquisadesatisfacao-valor-' . $v . '">'
                    . PluginPesquisadesatisfacaoPergunta::carinha($v, 26) . '<span>' . $e($rotulos[$v] ?? '') . '</span></div>'
                    . '<div class="pesquisadesatisfacao-resposta-texto"><strong>' . $e($r['pergunta']) . '</strong>'
                    . (trim((string) $r['justificativa']) !== '' ? '<p>' . nl2br($e($r['justificativa'])) . '</p>' : '') . '</div></div>';
            }
            $h .= '</div>';
            if (trim((string) $pesquisa['comentario']) !== '') {
                $h .= '<div class="pesquisadesatisfacao-comentario"><i class="ti ti-message"></i><div><strong>Comentário</strong><p>' . nl2br($e($pesquisa['comentario'])) . '</p></div></div>';
            }
        }

        // Histórico de envios
        $h .= '<h3 class="pesquisadesatisfacao-subtitulo"><i class="ti ti-history"></i> Envios</h3>';
        $h .= PluginPesquisadesatisfacaoEmail::tabelaEnvios(PluginPesquisadesatisfacaoEmail::listar(['pesquisas_id' => $pid], 50), false);

        return $h . '</div>';
    }
}
