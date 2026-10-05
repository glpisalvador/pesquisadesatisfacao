<?php

/**
 * Plugin Pesquisa de Satisfação - endpoint AJAX (sempre JSON)
 *   GET  previa           prévia de um modelo de e-mail (admin)
 *   POST criar           cria a pesquisa de um chamado solucionado e envia o convite
 *   POST reenviar        reenvia o e-mail de uma pesquisa pendente
 *   POST excluir         exclui a pesquisa (admin)
 *   POST email_teste     envia um modelo de teste (admin)
 *   POST logo_enviar     grava o logo (admin)
 *   POST logo_remover    remove o logo (admin)
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

register_shutdown_function(function () {
    $erro = error_get_last();
    if ($erro !== null && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'mensagem' => 'Erro interno: ' . $erro['message']]);
    }
});

$C = PluginPesquisadesatisfacaoConfig::class;
$P = PluginPesquisadesatisfacaoPesquisa::class;
$M = PluginPesquisadesatisfacaoEmail::class;

$responder = function (array $dados) use ($C): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $dados['new_token'] = strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $C::tokenCsrf() : '';
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!Session::getLoginUserID()) {
    $responder(['success' => false, 'mensagem' => 'Sessão expirada. Recarregue a página.']);
}

$acao = (string) ($_REQUEST['action'] ?? '');
$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/** A pesquisa existe e o usuário enxerga a entidade dela? */
$pesquisaPermitida = function (int $id) use ($P): ?array {
    $pesquisa = $P::porId($id);
    if ($pesquisa === null || !Session::haveAccessToEntity((int) $pesquisa['entities_id'])) {
        return null;
    }
    return $pesquisa;
};

try {
    switch ($acao) {
        case 'previa':
            if (!$C::ehAdmin()) {
                $responder(['success' => false, 'mensagem' => 'Sem permissão.']);
            }
            $tipo = (int) ($_GET['tipo'] ?? 0);
            if (!in_array($tipo, [$M::CONVITE, $M::LEMBRETE, $M::ENCERRAMENTO], true)) {
                $responder(['success' => false, 'mensagem' => 'Modelo inválido.']);
            }
            $msg = $M::montar($tipo, $M::variaveisExemplo());
            $responder(['success' => true, 'assunto' => $msg['assunto'], 'html' => $msg['html']]);
            break;

        case 'criar':
            if ($metodo !== 'POST' || !$C::podeVerRelatorios()) {
                $responder(['success' => false, 'mensagem' => 'Sem permissão.']);
            }
            $tid = (int) ($_POST['tickets_id'] ?? 0);
            $ticket = new Ticket();
            if (!$ticket->getFromDB($tid) || !$ticket->canViewItem()) {
                $responder(['success' => false, 'mensagem' => 'Chamado não encontrado.']);
            }
            if (!in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true)) {
                $responder(['success' => false, 'mensagem' => 'A pesquisa só pode ser criada para chamados solucionados ou fechados.']);
            }
            $r = $P::criar($tid, true);
            $responder(['success' => $r['ok'], 'mensagem' => $r['mensagem']]);
            break;

        case 'reenviar':
            if ($metodo !== 'POST' || !$C::podeVerRelatorios()) {
                $responder(['success' => false, 'mensagem' => 'Sem permissão.']);
            }
            $pesquisa = $pesquisaPermitida((int) ($_POST['pesquisas_id'] ?? 0));
            if ($pesquisa === null) {
                $responder(['success' => false, 'mensagem' => 'Pesquisa não encontrada.']);
            }
            if ((int) $pesquisa['status'] !== $P::PENDENTE) {
                $responder(['success' => false, 'mensagem' => 'Só pesquisas pendentes podem ser reenviadas.']);
            }
            if (trim((string) $pesquisa['email_destino']) === '') {
                $responder(['success' => false, 'mensagem' => 'O requerente não tem e-mail.']);
            }
            $ok = $M::enviarPesquisa((int) $pesquisa['id'], $M::LEMBRETE, (int) Session::getLoginUserID());
            $responder(['success' => $ok, 'mensagem' => $ok ? 'E-mail reenviado para ' . $pesquisa['email_destino'] . '.' : 'O envio falhou. Veja o motivo no histórico de envios.']);
            break;

        case 'excluir':
            if ($metodo !== 'POST' || !$C::ehAdmin()) {
                $responder(['success' => false, 'mensagem' => 'Sem permissão.']);
            }
            $pesquisa = $pesquisaPermitida((int) ($_POST['pesquisas_id'] ?? 0));
            if ($pesquisa === null || !$P::excluir((int) $pesquisa['id'])) {
                $responder(['success' => false, 'mensagem' => 'Pesquisa não encontrada.']);
            }
            $P::historico((int) $pesquisa['tickets_id'], 'Pesquisa de satisfação excluída');
            $responder(['success' => true, 'mensagem' => 'Pesquisa excluída.']);
            break;

        case 'email_teste':
            if ($metodo !== 'POST' || !$C::ehAdmin()) {
                $responder(['success' => false, 'mensagem' => 'Sem permissão.']);
            }
            $email = trim((string) ($_POST['email'] ?? ''));
            $r = $M::enviarTeste($email, (int) ($_POST['tipo'] ?? 0));
            $responder(['success' => $r['ok'], 'mensagem' => $r['ok'] ? 'E-mail de teste enviado para ' . $email . '.' : 'O envio falhou: ' . $r['erro']]);
            break;

        case 'logo_enviar':
            if ($metodo !== 'POST' || !$C::ehAdmin()) {
                $responder(['success' => false, 'mensagem' => 'Sem permissão.']);
            }
            $arquivo = $_FILES['logo'] ?? null;
            if (!is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $arquivo['tmp_name'])) {
                $responder(['success' => false, 'mensagem' => 'Escolha uma imagem de até 2 MB.']);
            }
            if ((int) $arquivo['size'] > 2 * 1024 * 1024) {
                $responder(['success' => false, 'mensagem' => 'A imagem passa de 2 MB.']);
            }
            $tipos = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $arquivo['tmp_name']);
            if ($mime === 'text/xml' || $mime === 'application/xml' || $mime === 'text/plain') {
                $mime = str_ends_with(strtolower((string) $arquivo['name']), '.svg') ? 'image/svg+xml' : $mime;
            }
            if (!isset($tipos[$mime])) {
                $responder(['success' => false, 'mensagem' => 'Formato não aceito. Use PNG, JPG, GIF, WEBP ou SVG.']);
            }
            if ($mime === 'image/svg+xml') {
                $svg = (string) file_get_contents((string) $arquivo['tmp_name']);
                if (preg_match('/<script|on\w+\s*=|javascript:|<foreignObject/i', $svg)) {
                    $responder(['success' => false, 'mensagem' => 'O SVG contém scripts e não pode ser usado.']);
                }
            }
            $pasta = $C::pastaArquivos();
            if (!is_dir($pasta)) {
                @mkdir($pasta, 0755, true);
            }
            foreach (glob($pasta . '/logo.*') ?: [] as $antigo) {
                @unlink($antigo);
            }
            $nome = 'logo.' . $tipos[$mime];
            if (!move_uploaded_file((string) $arquivo['tmp_name'], $pasta . '/' . $nome)) {
                $responder(['success' => false, 'mensagem' => 'Não foi possível gravar o arquivo.']);
            }
            $C::setConfig('logo', $nome);
            $responder(['success' => true, 'mensagem' => 'Logo atualizado.', 'url' => $C::urlLogo()]);
            break;

        case 'logo_remover':
            if ($metodo !== 'POST' || !$C::ehAdmin()) {
                $responder(['success' => false, 'mensagem' => 'Sem permissão.']);
            }
            foreach (glob($C::pastaArquivos() . '/logo.*') ?: [] as $antigo) {
                @unlink($antigo);
            }
            $C::setConfig('logo', '');
            $responder(['success' => true, 'mensagem' => 'Logo removido.']);
            break;

        default:
            $responder(['success' => false, 'mensagem' => 'Ação desconhecida.']);
    }
} catch (\Glpi\Exception\RedirectException $ex) {
    throw $ex;
} catch (\Throwable $ex) {
    $responder(['success' => false, 'mensagem' => 'Erro: ' . $ex->getMessage()]);
}
