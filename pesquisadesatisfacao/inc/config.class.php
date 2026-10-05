<?php

/**
 * Plugin Pesquisa de Satisfação - configurações, acesso e utilitários comuns
 */
class PluginPesquisadesatisfacaoConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_pesquisadesatisfacao_configs';

    /** Variáveis aceitas nos assuntos e textos dos e-mails */
    public const VARIAVEIS = [
        '{CHAMADO_ID}'     => 'Número do chamado',
        '{CHAMADO_TITULO}' => 'Título do chamado',
        '{REQUERENTE}'     => 'Nome do requerente',
        '{TECNICO}'        => 'Técnico que atendeu',
        '{ENTIDADE}'       => 'Entidade do chamado',
        '{ORGANIZACAO}'    => 'Nome da organização (configuração)',
        '{LINK}'           => 'Endereço da pesquisa',
        '{BOTAO}'          => 'Botão "Responder pesquisa"',
    ];

    public static function getTypeName($nb = 0): string
    {
        return 'Pesquisa de satisfação';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    // =====================================================================
    // Padrões e chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            // Disparo
            'tipos'                 => [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE],
            'entidades_excluidas'   => [],
            'dias_lembrete'         => '2',
            'dias_encerrar'         => '0',
            'bloquear_fechamento'   => '1',
            'dias_para_fechar'      => '2',
            'bloqueio_ativo'        => '1',
            'max_pendentes'         => '5',
            // Perguntas
            'comentario_final'      => '1',
            'rotulo_1'              => 'Ruim',
            'rotulo_2'              => 'Regular',
            'rotulo_3'              => 'Bom',
            // Aparência
            'org_nome'              => '',
            'logo'                  => '',
            'remetente_email'       => '',
            'remetente_nome'        => '',
            'pagina_titulo'         => 'Pesquisa de satisfação',
            'pagina_texto'          => 'Sua opinião é muito importante para melhorarmos o atendimento. Leva menos de um minuto.',
            'pagina_agradecimento'  => 'Obrigado! Sua avaliação foi registrada e nos ajuda a melhorar o atendimento.',
            'email_assunto_1'       => 'Pesquisa de satisfação - Chamado #{CHAMADO_ID}',
            'email_corpo_1'         => '<p>Olá, {REQUERENTE}.</p><p>Seu chamado <strong>#{CHAMADO_ID} - {CHAMADO_TITULO}</strong> foi solucionado. Gostaríamos de saber como foi o atendimento: a pesquisa leva menos de um minuto.</p><p>{BOTAO}</p>',
            'email_assunto_2'       => 'Lembrete: pesquisa de satisfação - Chamado #{CHAMADO_ID}',
            'email_corpo_2'         => '<p>Olá, {REQUERENTE}.</p><p>A pesquisa de satisfação do chamado <strong>#{CHAMADO_ID} - {CHAMADO_TITULO}</strong> ainda aguarda a sua resposta.</p><p>{BOTAO}</p>',
            'email_assunto_3'       => 'Pesquisa de satisfação encerrada - Chamado #{CHAMADO_ID}',
            'email_corpo_3'         => '<p>Olá, {REQUERENTE}.</p><p>A pesquisa de satisfação do chamado <strong>#{CHAMADO_ID} - {CHAMADO_TITULO}</strong> foi encerrada por falta de resposta e o link não é mais válido.</p><p>Se quiser relatar algo sobre esse atendimento, abra um novo chamado.</p>',
            // Alertas de avaliação negativa
            'alerta_ativo'          => '1',
            'alerta_tecnico'        => '1',
            'alerta_usuarios'       => [],
            'alerta_emails'         => '',
            'alerta_acompanhamento' => '1',
            // Acesso ao painel e à aba do chamado
            'acesso_perfis'         => [],
            'acesso_usuarios'       => [],
        ];
    }

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['value'], 'FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1]) as $row) {
            return $row['value'];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            return (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        }
        return (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value)));
    }

    /** Lista de ids inteiros positivos, sem repetição */
    public static function ids(string $name): array
    {
        return array_values(array_unique(array_filter(array_map('intval', self::getArrayConfig($name)), fn($v) => $v > 0)));
    }

    public static function inteiro(string $name, int $min, int $max): int
    {
        return max($min, min($max, (int) self::getConfig($name)));
    }

    public static function ligado(string $name): bool
    {
        return (string) self::getConfig($name) === '1';
    }

    public static function texto(string $name): string
    {
        $v = trim((string) self::getConfig($name));
        return $v !== '' ? $v : (string) (self::padroes()[$name] ?? '');
    }

    /** Rótulos da escala: 1 => Ruim, 2 => Regular, 3 => Bom */
    public static function rotulos(): array
    {
        return [1 => self::texto('rotulo_1'), 2 => self::texto('rotulo_2'), 3 => self::texto('rotulo_3')];
    }

    // =====================================================================
    // Acesso
    // =====================================================================

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    /** Painel, aba do chamado e colunas da busca: administradores e perfis/usuários liberados */
    public static function podeVerRelatorios(): bool
    {
        if (!Session::getLoginUserID()) {
            return false;
        }
        if (self::ehAdmin()) {
            return true;
        }
        $perfil = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        return in_array($perfil, self::ids('acesso_perfis'), true)
            || in_array((int) Session::getLoginUserID(), self::ids('acesso_usuarios'), true);
    }

    // =====================================================================
    // Listas para a configuração
    // =====================================================================

    public static function listarPerfis(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']) as $r) {
            $lista[(int) $r['id']] = (string) $r['name'];
        }
        return $lista;
    }

    public static function listarUsuarios(): array
    {
        global $DB;
        $lista = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['realname ASC', 'firstname ASC', 'name ASC'],
        ]) as $u) {
            $partes = array_filter([trim((string) $u['firstname']), trim((string) $u['realname'])]);
            $nome = $partes ? implode(' ', $partes) : (string) $u['name'];
            $lista[(int) $u['id']] = $nome . ($partes ? ' (' . $u['name'] . ')' : '');
        }
        return $lista;
    }

    /** Entidades pai e entidades sem filho (as filhas ficam ocultas; ao excluir uma pai, as filhas vão junto) */
    public static function listarEntidadesPai(): array
    {
        global $DB;
        $filhas = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]) as $r) {
            $filhas[(int) $r['id']] = true;
        }
        $lista = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => ['>', 0]], 'ORDER' => 'completename ASC']) as $r) {
            if (!isset($filhas[(int) $r['id']])) {
                $lista[(int) $r['id']] = (string) $r['completename'];
            }
        }
        return $lista;
    }

    /** Ids das filhas das entidades pai (para o filtro jQuery dos dropdowns nativos) */
    public static function idsEntidadesFilhas(): array
    {
        global $DB;
        $ids = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    /** Entidades excluídas do disparo, incluindo as filhas */
    public static function entidadesExcluidasComFilhas(): array
    {
        $ids = [];
        foreach (self::ids('entidades_excluidas') as $id) {
            foreach (getSonsOf('glpi_entities', $id) as $filha) {
                $ids[(int) $filha] = (int) $filha;
            }
        }
        return array_values($ids);
    }

    // =====================================================================
    // E-mail e aparência
    // =====================================================================

    /** Remetente: o configurado no plugin ou o do GLPI */
    public static function remetente(): array
    {
        global $CFG_GLPI;
        $candidatos = [
            [self::getConfig('remetente_email'), self::getConfig('remetente_nome')],
            [$CFG_GLPI['from_email'] ?? '', $CFG_GLPI['from_email_name'] ?? ''],
            [$CFG_GLPI['admin_email'] ?? '', $CFG_GLPI['admin_email_name'] ?? ''],
        ];
        foreach ($candidatos as [$email, $nome]) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $nome = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[<>"\r\n,;]/', ' ', (string) $nome)));
                return ['email' => $email, 'nome' => $nome !== '' ? $nome : self::texto('org_nome')];
            }
        }
        return ['email' => '', 'nome' => ''];
    }

    public static function pastaArquivos(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/pesquisadesatisfacao';
    }

    public static function caminhoLogo(): string
    {
        $arquivo = basename((string) self::getConfig('logo'));
        if ($arquivo === '' || !preg_match('/^logo\.(png|jpe?g|gif|webp|svg)$/', $arquivo)) {
            return '';
        }
        $caminho = self::pastaArquivos() . '/' . $arquivo;
        return is_file($caminho) ? $caminho : '';
    }

    /** Endereço absoluto do GLPI (necessário nos e-mails) */
    public static function urlBase(): string
    {
        global $CFG_GLPI;
        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
    }

    /** Logo público com versão (a data do arquivo), usado na página e nos e-mails */
    public static function urlLogo(bool $absoluta = false): string
    {
        global $CFG_GLPI;
        $caminho = self::caminhoLogo();
        if ($caminho === '') {
            return '';
        }
        $base = $absoluta ? self::urlBase() : (string) $CFG_GLPI['root_doc'];
        return $base . '/plugins/pesquisadesatisfacao/front/publico.php?logo=' . filemtime($caminho);
    }

    public static function urlPublica(string $token, bool $absoluta = true): string
    {
        global $CFG_GLPI;
        $base = $absoluta ? self::urlBase() : (string) $CFG_GLPI['root_doc'];
        return $base . '/plugins/pesquisadesatisfacao/front/publico.php?t=' . rawurlencode($token);
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/pesquisadesatisfacao/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL de arquivo de public/ com versão (o GLPI 11/12 serve public/ em /plugins/<nome>/) */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/pesquisadesatisfacao/' . preg_replace('#^public/#', '', $caminho) . '?v=' . PLUGIN_PESQUISADESATISFACAO_VERSION;
    }

    public static function assets(): string
    {
        return '<link rel="stylesheet" href="' . self::e(self::urlAsset('public/css/pesquisadesatisfacao.css')) . '">'
            . '<script src="' . self::e(self::urlAsset('public/js/pesquisadesatisfacao.js')) . '"></script>';
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    public static function campoCsrf(): string
    {
        $token = self::tokenCsrf();
        return $token === '' ? '' : '<input type="hidden" name="_glpi_csrf_token" value="' . self::e($token) . '">';
    }

    public static function nomeUsuario(int $id): string
    {
        global $DB;
        if ($id <= 0) {
            return '';
        }
        foreach ($DB->request(['SELECT' => ['name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $u) {
            $nome = trim(trim((string) $u['firstname']) . ' ' . trim((string) $u['realname']));
            return $nome !== '' ? $nome : (string) $u['name'];
        }
        return '';
    }

    /** Nomes de vários usuários de uma vez */
    public static function nomesUsuarios(array $ids): array
    {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        $nomes = [];
        if ($ids) {
            foreach ($DB->request(['SELECT' => ['id', 'name', 'firstname', 'realname'], 'FROM' => 'glpi_users', 'WHERE' => ['id' => $ids]]) as $u) {
                $nome = trim(trim((string) $u['firstname']) . ' ' . trim((string) $u['realname']));
                $nomes[(int) $u['id']] = $nome !== '' ? $nome : (string) $u['name'];
            }
        }
        return $nomes;
    }

    /** E-mail principal do usuário (o padrão ou o primeiro cadastrado) */
    public static function emailUsuario(int $id): string
    {
        global $DB;
        if ($id <= 0) {
            return '';
        }
        foreach ($DB->request(['SELECT' => ['email'], 'FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => $id], 'ORDER' => ['is_default DESC', 'id ASC']]) as $r) {
            $email = trim((string) $r['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }
        return '';
    }

    /** Porcentagem no formato brasileiro */
    public static function pct($valor, int $casas = 1): string
    {
        return $valor === null ? '—' : number_format((float) $valor, $casas, ',', '.') . '%';
    }

    /** Multiselect com pesquisa, marcar todos e selecionados primeiro */
    public static function multiselect(string $name, array $opcoes, array $selecionados, string $placeholder = 'Selecione...'): string
    {
        $selecionados = array_map('intval', $selecionados);
        $marcados = [];
        $demais = [];
        foreach ($opcoes as $id => $rotulo) {
            if (in_array((int) $id, $selecionados, true)) {
                $marcados[$id] = $rotulo;
            } else {
                $demais[$id] = $rotulo;
            }
        }
        asort($marcados, SORT_NATURAL | SORT_FLAG_CASE);
        asort($demais, SORT_NATURAL | SORT_FLAG_CASE);

        $h = '<div class="pesquisadesatisfacao-ms" data-pesquisadesatisfacao-ms data-placeholder="' . self::e($placeholder) . '">';
        $h .= '<input type="hidden" name="' . self::e($name) . '[]" value="0">';
        $h .= '<button type="button" class="pesquisadesatisfacao-ms-cabecalho form-select form-select-sm" data-pesquisadesatisfacao-ms-abrir><span class="pesquisadesatisfacao-ms-texto"></span></button>';
        $h .= '<div class="pesquisadesatisfacao-ms-dropdown" hidden>';
        $h .= '<div class="pesquisadesatisfacao-ms-topo"><input type="text" class="form-control form-control-sm pesquisadesatisfacao-ms-busca" placeholder="Pesquisar..." autocomplete="off"></div>';
        $h .= '<label class="pesquisadesatisfacao-ms-todos"><input type="checkbox" class="pesquisadesatisfacao-check" data-pesquisadesatisfacao-ms-todos> Marcar/desmarcar todos</label>';
        $h .= '<div class="pesquisadesatisfacao-ms-opcoes">';
        foreach ($marcados + $demais as $id => $rotulo) {
            $marcado = isset($marcados[$id]);
            $h .= '<label class="pesquisadesatisfacao-ms-opcao' . ($marcado ? ' selected' : '') . '" data-label="' . self::e(mb_strtolower((string) $rotulo)) . '">'
                . '<input type="checkbox" class="pesquisadesatisfacao-check" name="' . self::e($name) . '[]" value="' . (int) $id . '"' . ($marcado ? ' checked' : '') . '>'
                . '<span>' . self::e($rotulo) . '</span></label>';
        }
        $h .= '</div></div><div class="pesquisadesatisfacao-ms-contador"></div></div>';
        return $h;
    }
}
