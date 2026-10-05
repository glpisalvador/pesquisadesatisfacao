<?php

/**
 * Plugin Pesquisa de Satisfação - e-mails: modelos configuráveis, envio pelo GLPIMailer e histórico
 */
class PluginPesquisadesatisfacaoEmail extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_pesquisadesatisfacao_envios';

    public const CONVITE      = 1;
    public const LEMBRETE     = 2;
    public const ENCERRAMENTO = 3;
    public const ALERTA       = 4;
    public const TESTE        = 5;

    public const TIPOS = [
        self::CONVITE      => 'Convite',
        self::LEMBRETE     => 'Lembrete',
        self::ENCERRAMENTO => 'Encerramento',
        self::ALERTA       => 'Alerta de avaliação negativa',
        self::TESTE        => 'Teste',
    ];

    /** Nos testes automatizados o envio é simulado e as mensagens ficam aqui */
    public static bool $simular = false;
    public static array $simulados = [];

    public static function getTypeName($nb = 0): string
    {
        return 'E-mails da pesquisa';
    }

    // =====================================================================
    // Envio e histórico
    // =====================================================================

    /** Envia um e-mail HTML; retorna ['ok' => bool, 'erro' => string] */
    public static function enviar(string $para, string $assunto, string $html): array
    {
        $para = trim($para);
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'erro' => 'Endereço de e-mail inválido: ' . $para];
        }
        $remetente = PluginPesquisadesatisfacaoConfig::remetente();
        if ($remetente['email'] === '') {
            return ['ok' => false, 'erro' => 'Remetente não configurado (defina na configuração do plugin ou nas notificações do GLPI).'];
        }
        $texto = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</tr>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto);

        if (self::$simular) {
            self::$simulados[] = ['para' => $para, 'assunto' => $assunto, 'html' => $html, 'de' => $remetente['email']];
            return ['ok' => true, 'erro' => ''];
        }
        try {
            $mail = new GLPIMailer();
            $email = $mail->getEmail();
            $email->from(new \Symfony\Component\Mime\Address($remetente['email'], $remetente['nome']));
            $email->to(new \Symfony\Component\Mime\Address($para));
            $email->subject($assunto);
            $email->html($html, 'utf-8');
            $email->text($texto !== '' ? $texto : $assunto, 'utf-8');
            $cabecalhos = $email->getHeaders();
            $cabecalhos->addTextHeader('Auto-Submitted', 'auto-generated');
            $cabecalhos->addTextHeader('X-Auto-Response-Suppress', 'All');
            if ($mail->send()) {
                return ['ok' => true, 'erro' => ''];
            }
            $erro = method_exists($mail, 'getError') ? (string) $mail->getError() : '';
            return ['ok' => false, 'erro' => $erro !== '' ? $erro : 'O servidor de e-mail recusou o envio.'];
        } catch (\Throwable $ex) {
            return ['ok' => false, 'erro' => $ex->getMessage()];
        }
    }

    public static function registrar(int $pesquisas_id, int $tickets_id, int $tipo, string $email, bool $ok, string $erro = '', int $users_id = 0): void
    {
        global $DB;
        $DB->insert(self::TABELA, [
            'pesquisas_id'  => $pesquisas_id,
            'tickets_id'    => $tickets_id,
            'tipo'          => $tipo,
            'email_destino' => mb_substr($email, 0, 255),
            'sucesso'       => $ok ? 1 : 0,
            'mensagem_erro' => $erro !== '' ? mb_substr($erro, 0, 2000) : null,
            'users_id'      => $users_id,
            'data_envio'    => date('Y-m-d H:i:s'),
        ]);
    }

    // =====================================================================
    // Modelos
    // =====================================================================

    /** Valores das variáveis para uma pesquisa */
    public static function variaveis(array $pesquisa): array
    {
        global $DB;
        $C = PluginPesquisadesatisfacaoConfig::class;
        $titulo = '';
        foreach ($DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => (int) $pesquisa['tickets_id']], 'LIMIT' => 1]) as $r) {
            $titulo = (string) $r['name'];
        }
        $requerente = $C::nomeUsuario((int) $pesquisa['users_id_requerente']);
        $link = $C::urlPublica((string) $pesquisa['token']);
        return [
            '{CHAMADO_ID}'     => (string) (int) $pesquisa['tickets_id'],
            '{CHAMADO_TITULO}' => $titulo,
            '{REQUERENTE}'     => $requerente !== '' ? $requerente : (string) $pesquisa['email_destino'],
            '{TECNICO}'        => $C::nomeUsuario((int) $pesquisa['users_id_tecnico']),
            '{ENTIDADE}'       => (string) Dropdown::getDropdownName('glpi_entities', (int) $pesquisa['entities_id']),
            '{ORGANIZACAO}'    => $C::texto('org_nome'),
            '{LINK}'           => $link,
            '{BOTAO}'          => '',
        ];
    }

    /** Valores de exemplo para a prévia e o e-mail de teste */
    public static function variaveisExemplo(): array
    {
        $C = PluginPesquisadesatisfacaoConfig::class;
        $usuario = $C::nomeUsuario((int) Session::getLoginUserID());
        return [
            '{CHAMADO_ID}'     => '1234',
            '{CHAMADO_TITULO}' => 'Impressora do setor financeiro sem conexão',
            '{REQUERENTE}'     => $usuario !== '' ? $usuario : 'Maria Souza',
            '{TECNICO}'        => 'João Lima',
            '{ENTIDADE}'       => 'Entidade de exemplo',
            '{ORGANIZACAO}'    => $C::texto('org_nome'),
            '{LINK}'           => $C::urlBase() . '/plugins/pesquisadesatisfacao/front/publico.php?t=exemplo',
            '{BOTAO}'          => '',
        ];
    }

    public static function botao(string $link): string
    {
        return '<a href="' . PluginPesquisadesatisfacaoConfig::e($link) . '" style="display:inline-block;background:#e5a54b;border:1px solid #e5a54b;color:#fff;text-decoration:none;padding:10px 26px;border-radius:4px;font-weight:600;font-size:14px;">Responder pesquisa</a>';
    }

    /** Assunto e HTML completo (com cabeçalho e rodapé) de um modelo */
    public static function montar(int $tipo, array $vars): array
    {
        $C = PluginPesquisadesatisfacaoConfig::class;
        $e = [$C, 'e'];
        $vars['{BOTAO}'] = self::botao($vars['{LINK}']);
        // Nos textos as variáveis viram texto seguro, exceto o botão
        $seguras = array_map(fn($v) => $e($v), $vars);
        $seguras['{BOTAO}'] = $vars['{BOTAO}'];
        $seguras['{LINK}'] = '<a href="' . $e($vars['{LINK}']) . '">' . $e($vars['{LINK}']) . '</a>';

        $assunto = strtr(str_replace('{BOTAO}', '', $C::texto('email_assunto_' . $tipo)), $vars);
        $assunto = trim((string) preg_replace('/[\r\n]+/', ' ', strip_tags($assunto)));
        $corpo = strtr($C::texto('email_corpo_' . $tipo), $seguras);
        return ['assunto' => $assunto, 'html' => self::layout($corpo, $tipo !== self::ENCERRAMENTO ? $vars['{LINK}'] : '')];
    }

    /** Moldura neutra do e-mail: logo e organização no topo, aviso de mensagem automática no rodapé */
    public static function layout(string $corpo, string $linkAlternativo = ''): string
    {
        $C = PluginPesquisadesatisfacaoConfig::class;
        $e = [$C, 'e'];
        $logo = $C::urlLogo(true);
        $org = $C::texto('org_nome');

        $h = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;padding:0;background:#f5f7fb;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;color:#333;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border:1px solid #dee2e6;border-radius:6px;">';
        if ($logo !== '' || $org !== '') {
            $h .= '<tr><td style="padding:18px 24px;border-bottom:1px solid #eef0f3;">';
            if ($logo !== '') {
                $h .= '<img src="' . $e($logo) . '" alt="' . $e($org) . '" style="max-height:48px;max-width:220px;border:0;display:block;">';
            }
            if ($org !== '') {
                $h .= '<div style="font-size:13px;font-weight:600;color:#495057;margin-top:' . ($logo !== '' ? '8' : '0') . 'px;">' . $e($org) . '</div>';
            }
            $h .= '</td></tr>';
        }
        $h .= '<tr><td style="padding:22px 24px;font-size:14px;line-height:1.6;color:#333;">' . $corpo;
        if ($linkAlternativo !== '') {
            $h .= '<p style="font-size:12px;color:#6c757d;margin:18px 0 0;">Se o botão não funcionar, copie este endereço no navegador:<br>'
                . '<span style="color:#495057;word-break:break-all;">' . $e($linkAlternativo) . '</span></p>';
        }
        $h .= '</td></tr>'
            . '<tr><td style="padding:12px 24px;background:#f8f9fa;border-top:1px solid #eef0f3;border-radius:0 0 6px 6px;font-size:11px;color:#6c757d;">Mensagem automática, não responda este e-mail.</td></tr>'
            . '</table></td></tr></table></body></html>';
        return $h;
    }

    // =====================================================================
    // Disparos
    // =====================================================================

    /** Convite, lembrete ou encerramento de uma pesquisa */
    public static function enviarPesquisa(int $pesquisas_id, int $tipo, int $users_id = 0): bool
    {
        global $DB;
        $pesquisa = PluginPesquisadesatisfacaoPesquisa::porId($pesquisas_id);
        if ($pesquisa === null || !in_array($tipo, [self::CONVITE, self::LEMBRETE, self::ENCERRAMENTO], true)) {
            return false;
        }
        $msg = self::montar($tipo, self::variaveis($pesquisa));
        $r = self::enviar((string) $pesquisa['email_destino'], $msg['assunto'], $msg['html']);
        self::registrar($pesquisas_id, (int) $pesquisa['tickets_id'], $tipo, (string) $pesquisa['email_destino'], $r['ok'], $r['erro'], $users_id);

        if ($tipo !== self::ENCERRAMENTO) {
            // Registra a tentativa mesmo com falha, para não repetir a cada execução da tarefa
            $campos = ['data_ultimo_envio' => date('Y-m-d H:i:s')];
            if ($tipo === self::LEMBRETE && $r['ok']) {
                $campos['lembretes'] = new \Glpi\DBAL\QueryExpression($DB->quoteName('lembretes') . ' + 1');
            }
            $DB->update(PluginPesquisadesatisfacaoPesquisa::TABELA, $campos, ['id' => $pesquisas_id]);
        }
        return $r['ok'];
    }

    /** Destinatários do alerta de avaliação negativa */
    public static function destinatariosAlerta(array $pesquisa): array
    {
        $C = PluginPesquisadesatisfacaoConfig::class;
        $emails = [];
        if ($C::ligado('alerta_tecnico') && (int) $pesquisa['users_id_tecnico'] > 0) {
            $emails[] = $C::emailUsuario((int) $pesquisa['users_id_tecnico']);
        }
        foreach ($C::ids('alerta_usuarios') as $uid) {
            $emails[] = $C::emailUsuario($uid);
        }
        foreach (preg_split('/[\s,;]+/', (string) $C::getConfig('alerta_emails')) as $email) {
            $emails[] = trim($email);
        }
        $validos = [];
        foreach ($emails as $email) {
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validos[mb_strtolower($email)] = $email;
            }
        }
        return array_values($validos);
    }

    public static function enviarAlerta(int $pesquisas_id): int
    {
        $pesquisa = PluginPesquisadesatisfacaoPesquisa::porId($pesquisas_id);
        if ($pesquisa === null) {
            return 0;
        }
        $C = PluginPesquisadesatisfacaoConfig::class;
        $e = [$C, 'e'];
        $vars = self::variaveis($pesquisa);
        $linkChamado = $C::urlBase() . '/front/ticket.form.php?id=' . (int) $pesquisa['tickets_id'] . '&forcetab=PluginPesquisadesatisfacaoPesquisa$1';
        $assunto = 'Avaliação negativa na pesquisa de satisfação - Chamado #' . (int) $pesquisa['tickets_id'];
        $corpo = '<p>O chamado <strong>#' . $e($vars['{CHAMADO_ID}']) . ' - ' . $e($vars['{CHAMADO_TITULO}']) . '</strong> recebeu uma avaliação negativa'
            . ($vars['{REQUERENTE}'] !== '' ? ' de <strong>' . $e($vars['{REQUERENTE}']) . '</strong>' : '') . '.</p>'
            . ($vars['{TECNICO}'] !== '' ? '<p>Técnico: ' . $e($vars['{TECNICO}']) . '</p>' : '')
            . PluginPesquisadesatisfacaoPesquisa::resumoHtml($pesquisas_id)
            . '<p><a href="' . $e($linkChamado) . '" style="display:inline-block;background:#fff;border:1px solid #dee2e6;color:#333;text-decoration:none;padding:8px 18px;border-radius:4px;font-weight:600;">Abrir o chamado</a></p>';
        $html = self::layout($corpo);
        $enviados = 0;
        foreach (self::destinatariosAlerta($pesquisa) as $email) {
            $r = self::enviar($email, $assunto, $html);
            self::registrar($pesquisas_id, (int) $pesquisa['tickets_id'], self::ALERTA, $email, $r['ok'], $r['erro']);
            $enviados += $r['ok'] ? 1 : 0;
        }
        return $enviados;
    }

    /** E-mail de teste de um modelo para o endereço informado */
    public static function enviarTeste(string $email, int $tipo): array
    {
        if (!in_array($tipo, [self::CONVITE, self::LEMBRETE, self::ENCERRAMENTO], true)) {
            return ['ok' => false, 'erro' => 'Modelo inválido.'];
        }
        $msg = self::montar($tipo, self::variaveisExemplo());
        $r = self::enviar($email, '[Teste] ' . $msg['assunto'], $msg['html']);
        self::registrar(0, 0, self::TESTE, $email, $r['ok'], $r['erro'], (int) Session::getLoginUserID());
        return $r;
    }

    // =====================================================================
    // Histórico
    // =====================================================================

    /**
     * Envios mais recentes.
     * Filtros: pesquisas_id, desde, ate (Y-m-d), where (critérios extras sobre p = pesquisas).
     */
    public static function listar(array $filtros = [], int $limite = 100): array
    {
        global $DB;
        $where = [];
        if (!empty($filtros['pesquisas_id'])) {
            $where['e.pesquisas_id'] = (int) $filtros['pesquisas_id'];
        }
        if (!empty($filtros['desde'])) {
            $where[] = ['e.data_envio' => ['>=', $filtros['desde'] . ' 00:00:00']];
        }
        if (!empty($filtros['ate'])) {
            $where[] = ['e.data_envio' => ['<=', $filtros['ate'] . ' 23:59:59']];
        }
        foreach ((array) ($filtros['where'] ?? []) as $k => $v) {
            if (is_int($k)) {
                $where[] = $v;
            } else {
                $where[$k] = $v;
            }
        }
        $lista = [];
        foreach ($DB->request([
            'SELECT'    => ['e.*', 't.name AS titulo'],
            'FROM'      => self::TABELA . ' AS e',
            'LEFT JOIN' => [
                PluginPesquisadesatisfacaoPesquisa::TABELA . ' AS p' => ['ON' => ['p' => 'id', 'e' => 'pesquisas_id']],
                'glpi_tickets AS t' => ['ON' => ['t' => 'id', 'e' => 'tickets_id']],
            ],
            'WHERE'     => $where,
            'ORDER'     => ['e.data_envio DESC', 'e.id DESC'],
            'LIMIT'     => $limite,
        ]) as $r) {
            $lista[] = $r;
        }
        return $lista;
    }

    public static function tabelaEnvios(array $envios, bool $comChamado = true): string
    {
        $C = PluginPesquisadesatisfacaoConfig::class;
        $e = [$C, 'e'];
        if (!$envios) {
            return '<p class="text-muted small"><i class="ti ti-info-circle"></i> Nenhum envio registrado.</p>';
        }
        $nomes = $C::nomesUsuarios(array_column($envios, 'users_id'));
        $h = '<div class="table-responsive"><table class="table table-sm table-hover pesquisadesatisfacao-tabela"><thead><tr><th>Data</th>'
            . ($comChamado ? '<th>Chamado</th>' : '') . '<th>Tipo</th><th>Destinatário</th><th>Resultado</th><th>Disparado por</th></tr></thead><tbody>';
        foreach ($envios as $r) {
            $h .= '<tr><td class="text-nowrap">' . $e(Html::convDateTime((string) $r['data_envio'])) . '</td>';
            if ($comChamado) {
                $h .= '<td>' . ((int) $r['tickets_id'] > 0
                    ? '<a href="' . $e(Ticket::getFormURLWithID((int) $r['tickets_id'])) . '">#' . (int) $r['tickets_id'] . '</a> ' . $e(mb_strimwidth((string) $r['titulo'], 0, 50, '…'))
                    : '—') . '</td>';
            }
            $h .= '<td>' . $e(self::TIPOS[(int) $r['tipo']] ?? '—') . '</td><td>' . $e($r['email_destino'] !== '' ? $r['email_destino'] : '—') . '</td><td>';
            $h .= $r['sucesso']
                ? '<span class="pesquisadesatisfacao-selo pesquisadesatisfacao-tom-ok">Enviado</span>'
                : '<span class="pesquisadesatisfacao-selo pesquisadesatisfacao-tom-erro">Falhou</span> <span class="small text-muted">' . $e((string) $r['mensagem_erro']) . '</span>';
            $h .= '</td><td>' . ((int) $r['users_id'] > 0 ? $e($nomes[(int) $r['users_id']] ?? '') : '<span class="text-muted">Automático</span>') . '</td></tr>';
        }
        return $h . '</tbody></table></div>';
    }
}
