/**
 * Plugin Pesquisa de Satisfação - telas internas: abas, multiselect, painel (ECharts do GLPI),
 * ações da aba do chamado e configuração (ordem das perguntas, logo, prévia e teste de e-mail).
 */
(function () {
    'use strict';

    if (window.pesquisadesatisfacaoJs) { return; }
    window.pesquisadesatisfacaoJs = true;

    // ------------------------------------------------------------------ comunicação
    function lerJson(r) {
        return r.text().then(function (t) {
            try { return JSON.parse(t); } catch (e) {
                var m = t.match(/\{[\s\S]*\}\s*$/);
                if (m) { try { return JSON.parse(m[0]); } catch (e2) { /* segue */ } }
                return { success: false, mensagem: 'Resposta inválida do servidor.' };
            }
        });
    }

    function avisar(ok, msg) {
        if (!msg) { return; }
        var f = ok ? window.glpi_toast_info : window.glpi_toast_error;
        if (typeof f === 'function') { f(msg); }
    }

    /** POST para o ajax.php; o token (GLPI 11) fica no data-token do contêiner e é renovado a cada resposta */
    function enviar(raiz, dados) {
        var fd = dados instanceof FormData ? dados : new FormData();
        if (!(dados instanceof FormData)) {
            Object.keys(dados).forEach(function (k) { fd.append(k, dados[k]); });
        }
        var token = raiz.getAttribute('data-token') || '';
        var cab = { 'X-Requested-With': 'XMLHttpRequest' };
        if (token) {
            fd.set('_glpi_csrf_token', token);
            cab['X-Glpi-Csrf-Token'] = token;
        }
        return fetch(raiz.getAttribute('data-ajax'), { method: 'POST', body: fd, credentials: 'same-origin', headers: cab })
            .then(lerJson)
            .then(function (r) {
                if (r && r.new_token) { raiz.setAttribute('data-token', r.new_token); }
                return r || { success: false, mensagem: 'Sem resposta do servidor.' };
            })
            .catch(function () { return { success: false, mensagem: 'Falha de comunicação com o servidor.' }; });
    }

    function ocupado(botao, sim) {
        if (!botao) { return; }
        botao.disabled = sim;
        var i = botao.querySelector('i');
        if (!i) { return; }
        if (sim) {
            i.setAttribute('data-classe', i.className);
            i.className = 'ti ti-loader';
        } else if (i.getAttribute('data-classe')) {
            i.className = i.getAttribute('data-classe');
        }
    }

    /** Confirmação no padrão Bootstrap (sem confirm() do navegador) */
    function confirmar(mensagem, aoConfirmar) {
        var modal = document.getElementById('pesquisadesatisfacao-confirmar');
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'pesquisadesatisfacao-confirmar';
            modal.tabIndex = -1;
            modal.innerHTML = '<div class="modal-dialog"><div class="modal-content">'
                + '<div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="ti ti-alert-triangle"></i> Confirmar</h5>'
                + '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div>'
                + '<div class="modal-body"><div class="alert alert-warning mb-0" data-mensagem></div></div>'
                + '<div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>'
                + '<button type="button" class="btn btn-sm btn-danger" data-confirmar>Confirmar</button></div></div></div>';
            document.body.appendChild(modal);
        }
        modal.querySelector('[data-mensagem]').textContent = mensagem;
        var instancia = window.bootstrap.Modal.getOrCreateInstance(modal);
        var botao = modal.querySelector('[data-confirmar]');
        var novo = botao.cloneNode(true);
        botao.parentNode.replaceChild(novo, botao);
        novo.addEventListener('click', function () { instancia.hide(); aoConfirmar(); });
        instancia.show();
    }

    // ------------------------------------------------------------------ multiselect
    function opcoes(c) { return Array.prototype.slice.call(c.querySelectorAll('.pesquisadesatisfacao-ms-opcao')); }

    function pesquisadesatisfacaoUpdateSelectAll(c) {
        var todos = c.querySelector('[data-pesquisadesatisfacao-ms-todos]');
        if (!todos) { return; }
        var vis = opcoes(c).filter(function (o) { return o.style.display !== 'none'; });
        var marc = vis.filter(function (o) { return o.querySelector('input').checked; });
        todos.checked = vis.length > 0 && marc.length === vis.length;
        todos.indeterminate = marc.length > 0 && marc.length < vis.length;
    }

    function pesquisadesatisfacaoUpdateMultiselectCount(c) {
        var marc = opcoes(c).filter(function (o) { return o.querySelector('input').checked; });
        var texto = c.querySelector('.pesquisadesatisfacao-ms-texto');
        texto.textContent = !marc.length ? (c.getAttribute('data-placeholder') || 'Selecione...')
            : (marc.length <= 2 ? marc.map(function (o) { return o.querySelector('span').textContent; }).join(', ') : marc.length + ' selecionados');
        c.querySelector('.pesquisadesatisfacao-ms-contador').textContent = marc.length + ' de ' + opcoes(c).length + ' selecionado(s)';
        pesquisadesatisfacaoUpdateSelectAll(c);
    }

    function pesquisadesatisfacaoReorderMultiselectOptions(c) {
        var lista = c.querySelector('.pesquisadesatisfacao-ms-opcoes');
        opcoes(c).sort(function (a, b) {
            var ca = a.querySelector('input').checked, cb = b.querySelector('input').checked;
            if (ca !== cb) { return ca ? -1 : 1; }
            return a.getAttribute('data-label').localeCompare(b.getAttribute('data-label'), 'pt-BR');
        }).forEach(function (o) { lista.appendChild(o); });
    }

    function pesquisadesatisfacaoFilterMultiselect(c, termo) {
        termo = (termo || '').toLowerCase().trim();
        opcoes(c).forEach(function (o) { o.style.display = !termo || o.getAttribute('data-label').indexOf(termo) !== -1 ? 'flex' : 'none'; });
        pesquisadesatisfacaoUpdateSelectAll(c);
    }

    function fecharMultiselect(c) { c.querySelector('.pesquisadesatisfacao-ms-dropdown').hidden = true; }

    function pesquisadesatisfacaoToggleMultiselect(c) {
        var dd = c.querySelector('.pesquisadesatisfacao-ms-dropdown');
        var abrir = dd.hidden;
        document.querySelectorAll('[data-pesquisadesatisfacao-ms]').forEach(function (o) { if (o !== c) { fecharMultiselect(o); } });
        dd.hidden = !abrir;
        if (abrir) { c.querySelector('.pesquisadesatisfacao-ms-busca').focus(); }
    }

    function pesquisadesatisfacaoToggleAllMultiselect(c, marcar) {
        opcoes(c).forEach(function (o) {
            if (o.style.display === 'none') { return; }
            o.querySelector('input').checked = marcar;
            o.classList.toggle('selected', marcar);
        });
        pesquisadesatisfacaoReorderMultiselectOptions(c);
        pesquisadesatisfacaoUpdateMultiselectCount(c);
    }

    function pesquisadesatisfacaoHandleMultiselectChange(c, opcao) {
        opcao.classList.toggle('selected', opcao.querySelector('input').checked);
        var busca = c.querySelector('.pesquisadesatisfacao-ms-busca');
        if (busca.value !== '') {
            busca.value = '';
            pesquisadesatisfacaoFilterMultiselect(c, '');
            busca.focus();
        }
        pesquisadesatisfacaoReorderMultiselectOptions(c);
        pesquisadesatisfacaoUpdateMultiselectCount(c);
    }

    function iniciarMultiselects(raiz) {
        (raiz || document).querySelectorAll('[data-pesquisadesatisfacao-ms]').forEach(function (c) {
            if (c.getAttribute('data-iniciado')) { return; }
            c.setAttribute('data-iniciado', '1');
            pesquisadesatisfacaoUpdateMultiselectCount(c);
        });
    }

    document.addEventListener('click', function (ev) {
        var abrir = ev.target.closest('[data-pesquisadesatisfacao-ms-abrir]');
        if (abrir) {
            ev.preventDefault();
            pesquisadesatisfacaoToggleMultiselect(abrir.closest('[data-pesquisadesatisfacao-ms]'));
            return;
        }
        document.querySelectorAll('[data-pesquisadesatisfacao-ms]').forEach(function (c) {
            if (!c.contains(ev.target)) { fecharMultiselect(c); }
        });
    });

    document.addEventListener('change', function (ev) {
        var c = ev.target.closest('[data-pesquisadesatisfacao-ms]');
        if (!c) { return; }
        if (ev.target.hasAttribute('data-pesquisadesatisfacao-ms-todos')) {
            pesquisadesatisfacaoToggleAllMultiselect(c, ev.target.checked);
        } else if (ev.target.closest('.pesquisadesatisfacao-ms-opcao')) {
            pesquisadesatisfacaoHandleMultiselectChange(c, ev.target.closest('.pesquisadesatisfacao-ms-opcao'));
        }
    });

    document.addEventListener('input', function (ev) {
        if (ev.target.classList.contains('pesquisadesatisfacao-ms-busca')) {
            pesquisadesatisfacaoFilterMultiselect(ev.target.closest('[data-pesquisadesatisfacao-ms]'), ev.target.value);
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.target.classList.contains('pesquisadesatisfacao-ms-busca') && ev.key === 'Enter') {
            ev.preventDefault(); // não envia o formulário ao pesquisar
        }
    });

    // ------------------------------------------------------------------ abas
    document.addEventListener('click', function (ev) {
        var link = ev.target.closest('[data-pesquisadesatisfacao-abas] [data-aba]');
        if (!link) { return; }
        ev.preventDefault();
        var lista = link.closest('[data-pesquisadesatisfacao-abas]');
        var grupo = lista.getAttribute('data-grupo') || '';
        var aba = link.getAttribute('data-aba');
        lista.querySelectorAll('[data-aba]').forEach(function (a) { a.classList.toggle('active', a === link); });
        document.querySelectorAll('[data-aba-painel]').forEach(function (p) {
            if ((p.getAttribute('data-grupo') || '') === grupo) { p.hidden = p.getAttribute('data-aba-painel') !== aba; }
        });
        if (!grupo && window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('aba', aba);
            window.history.replaceState(null, '', url.toString());
        }
    });

    // ------------------------------------------------------------------ entidades filhas ocultas nos dropdowns
    function filtrarEntidades() {
        var ocultas = window.pesquisadesatisfacaoEntidadesOcultas;
        if (!ocultas || !ocultas.length || !window.jQuery || window.pesquisadesatisfacaoFiltroEntidades) { return; }
        window.pesquisadesatisfacaoFiltroEntidades = true;
        var mapa = {};
        ocultas.forEach(function (id) { mapa[String(id)] = true; });
        window.jQuery.ajaxPrefilter(function (opcoesAjax) {
            var dados = typeof opcoesAjax.data === 'string' ? opcoesAjax.data : '';
            if (!opcoesAjax.url || opcoesAjax.url.indexOf('getDropdownValue') === -1 || dados.indexOf('itemtype=Entity') === -1) { return; }
            var original = opcoesAjax.success;
            opcoesAjax.success = function (data) {
                var filtrar = function (itens) {
                    return (itens || []).filter(function (item) {
                        if (item.children) {
                            item.children = filtrar(item.children);
                            return item.children.length > 0;
                        }
                        return !mapa[String(item.id)];
                    });
                };
                if (data && data.results) { data.results = filtrar(data.results); }
                if (typeof original === 'function') { return original.apply(this, arguments); }
                if (Array.isArray(original)) { var args = arguments, ctx = this; original.forEach(function (f) { f.apply(ctx, args); }); }
            };
        });
    }

    // ------------------------------------------------------------------ gráficos do painel
    var graficos = [];

    function redimensionarGraficos() {
        graficos.forEach(function (g) { g.resize(); });
    }

    function iniciarGraficos() {
        var fonte = document.getElementById('pesquisadesatisfacao-graficos');
        if (!fonte || !window.echarts || fonte.getAttribute('data-iniciado')) { return; }
        fonte.setAttribute('data-iniciado', '1');
        var dados;
        try { dados = JSON.parse(fonte.textContent); } catch (e) { return; }
        var cores = ['rgba(220, 53, 69, 0.55)', 'rgba(255, 193, 7, 0.6)', 'rgba(25, 135, 84, 0.55)'];
        var texto = { color: '#6c757d', fontSize: 11 };
        var rotulos = [dados.rotulos[1], dados.rotulos[2], dados.rotulos[3]];

        var el = document.querySelector('[data-pesquisadesatisfacao-grafico="perguntas"]');
        if (el && dados.perguntas.length) {
            var g = window.echarts.init(el, null, { renderer: 'svg' });
            var nomes = dados.perguntas.map(function (p) { return p.texto; });
            g.setOption({
                color: cores,
                grid: { left: 8, right: 16, top: 30, bottom: 8, containLabel: true },
                legend: { top: 0, textStyle: texto, itemWidth: 12, itemHeight: 10 },
                tooltip: {
                    trigger: 'axis', axisPointer: { type: 'shadow' },
                    formatter: function (itens) {
                        var p = dados.perguntas[itens[0].dataIndex];
                        var total = p.contagem.reduce(function (a, b) { return a + b; }, 0);
                        return '<strong>' + p.texto + '</strong><br>' + itens.map(function (i) {
                            var n = p.contagem[i.seriesIndex];
                            return i.marker + i.seriesName + ': ' + n + ' (' + (total ? Math.round(n / total * 100) : 0) + '%)';
                        }).join('<br>') + '<br>Satisfação: ' + (p.satisfacao === null ? '—' : String(p.satisfacao).replace('.', ',') + '%');
                    }
                },
                xAxis: { type: 'value', max: 100, axisLabel: Object.assign({ formatter: '{value}%' }, texto), splitLine: { lineStyle: { color: '#f0f0f0' } } },
                yAxis: {
                    type: 'category', inverse: true, data: nomes,
                    axisLabel: Object.assign({ width: 220, overflow: 'truncate' }, texto), axisLine: { lineStyle: { color: '#dee2e6' } }, axisTick: { show: false }
                },
                series: [0, 1, 2].map(function (v) {
                    return {
                        name: rotulos[v], type: 'bar', stack: 'total', barMaxWidth: 26,
                        data: dados.perguntas.map(function (p) {
                            var total = p.contagem.reduce(function (a, b) { return a + b; }, 0);
                            return total ? Math.round(p.contagem[v] / total * 1000) / 10 : 0;
                        }),
                        label: { show: true, color: '#333', fontSize: 10, formatter: function (x) { return x.value >= 8 ? Math.round(x.value) + '%' : ''; } }
                    };
                })
            });
            graficos.push(g);
        }

        el = document.querySelector('[data-pesquisadesatisfacao-grafico="evolucao"]');
        if (el) {
            var ev = window.echarts.init(el, null, { renderer: 'svg' });
            ev.setOption({
                grid: { left: 8, right: 8, top: 34, bottom: 8, containLabel: true },
                legend: { top: 0, textStyle: texto, itemWidth: 12, itemHeight: 10 },
                tooltip: { trigger: 'axis' },
                xAxis: { type: 'category', data: dados.evolucao.map(function (m) { return m.mes; }), axisLabel: texto, axisLine: { lineStyle: { color: '#dee2e6' } }, axisTick: { show: false } },
                yAxis: [
                    { type: 'value', minInterval: 1, axisLabel: texto, splitLine: { lineStyle: { color: '#f0f0f0' } } },
                    { type: 'value', min: 0, max: 100, axisLabel: Object.assign({ formatter: '{value}%' }, texto), splitLine: { show: false } }
                ],
                series: [
                    { name: 'Enviadas', type: 'bar', barMaxWidth: 22, itemStyle: { color: 'rgba(108, 117, 125, 0.25)', borderRadius: [3, 3, 0, 0] }, data: dados.evolucao.map(function (m) { return m.enviadas; }) },
                    { name: 'Respondidas', type: 'bar', barMaxWidth: 22, itemStyle: { color: 'rgba(229, 165, 75, 0.55)', borderRadius: [3, 3, 0, 0] }, data: dados.evolucao.map(function (m) { return m.respondidas; }) },
                    {
                        name: 'Satisfação (%)', type: 'line', yAxisIndex: 1, smooth: true, connectNulls: true, symbolSize: 6,
                        itemStyle: { color: 'rgba(25, 135, 84, 0.8)' }, lineStyle: { width: 2 },
                        data: dados.evolucao.map(function (m) { return m.satisfacao; })
                    }
                ]
            });
            graficos.push(ev);
        }
        window.addEventListener('resize', redimensionarGraficos);
    }

    // ------------------------------------------------------------------ aba do chamado
    document.addEventListener('click', function (ev) {
        var copiar = ev.target.closest('[data-pesquisadesatisfacao-copiar]');
        if (copiar) {
            var campo = copiar.closest('.input-group').querySelector('input');
            var ok = function () { avisar(true, 'Link copiado.'); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(campo.value).then(ok, function () { campo.select(); document.execCommand('copy'); ok(); });
            } else {
                campo.select();
                document.execCommand('copy');
                ok();
            }
            return;
        }

        var botao = ev.target.closest('[data-pesquisadesatisfacao-acao]');
        if (!botao) { return; }
        var raiz = botao.closest('[data-pesquisadesatisfacao-aba]');
        var acao = botao.getAttribute('data-pesquisadesatisfacao-acao');
        var dados = { action: acao };
        if (botao.getAttribute('data-pesquisa')) { dados.pesquisas_id = botao.getAttribute('data-pesquisa'); }
        if (botao.getAttribute('data-chamado')) { dados.tickets_id = botao.getAttribute('data-chamado'); }
        var executar = function () {
            ocupado(botao, true);
            enviar(raiz, dados).then(function (r) {
                ocupado(botao, false);
                avisar(r.success, r.mensagem);
                if (r.success) { window.setTimeout(function () { window.location.reload(); }, 700); }
            });
        };
        if (botao.getAttribute('data-confirmar')) {
            confirmar(botao.getAttribute('data-confirmar'), executar);
        } else {
            executar();
        }
    });

    // ------------------------------------------------------------------ configuração
    document.addEventListener('click', function (ev) {
        var raiz = ev.target.closest('[data-pesquisadesatisfacao-config]');
        if (!raiz) { return; }

        // Ordem das perguntas (a linha "nova" fica sempre por último)
        var mover = ev.target.closest('[data-mover]');
        if (mover) {
            ev.preventDefault();
            var linha = mover.closest('tr');
            var alvo = mover.getAttribute('data-mover') === '-1' ? linha.previousElementSibling : linha.nextElementSibling;
            if (!alvo || alvo.classList.contains('pesquisadesatisfacao-nova')) { return; }
            if (mover.getAttribute('data-mover') === '-1') {
                linha.parentNode.insertBefore(linha, alvo);
            } else {
                linha.parentNode.insertBefore(alvo, linha);
            }
            return;
        }

        // Logo
        var enviarLogo = ev.target.closest('[data-logo-enviar]');
        if (enviarLogo) {
            var bloco = enviarLogo.closest('[data-pesquisadesatisfacao-logo]');
            var arquivo = bloco.querySelector('[data-logo-arquivo]').files[0];
            if (!arquivo) { avisar(false, 'Escolha uma imagem.'); return; }
            if (arquivo.size > 2 * 1024 * 1024) { avisar(false, 'A imagem passa de 2 MB.'); return; }
            var fd = new FormData();
            fd.append('action', 'logo_enviar');
            fd.append('logo', arquivo);
            ocupado(enviarLogo, true);
            enviar(raiz, fd).then(function (r) {
                ocupado(enviarLogo, false);
                avisar(r.success, r.mensagem);
                if (r.success) {
                    bloco.querySelector('.pesquisadesatisfacao-logo-previa').innerHTML = '<img src="' + r.url + '" alt="">';
                    bloco.querySelector('[data-logo-remover]').hidden = false;
                    bloco.querySelector('[data-logo-arquivo]').value = '';
                }
            });
            return;
        }
        var removerLogo = ev.target.closest('[data-logo-remover]');
        if (removerLogo) {
            confirmar('Remover o logo da página de resposta e dos e-mails?', function () {
                ocupado(removerLogo, true);
                enviar(raiz, { action: 'logo_remover' }).then(function (r) {
                    ocupado(removerLogo, false);
                    avisar(r.success, r.mensagem);
                    if (r.success) {
                        var b = removerLogo.closest('[data-pesquisadesatisfacao-logo]');
                        b.querySelector('.pesquisadesatisfacao-logo-previa').innerHTML = '<span class="text-muted small">Sem logo</span>';
                        removerLogo.hidden = true;
                    }
                });
            });
            return;
        }

        // Prévia do modelo salvo
        var previa = ev.target.closest('[data-previa]');
        if (previa) {
            ocupado(previa, true);
            fetch(raiz.getAttribute('data-ajax') + '?action=previa&tipo=' + encodeURIComponent(previa.getAttribute('data-previa')), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(lerJson)
                .then(function (r) {
                    ocupado(previa, false);
                    if (!r.success) { avisar(false, r.mensagem); return; }
                    var modal = document.getElementById('pesquisadesatisfacao-modal-previa');
                    modal.querySelector('[data-previa-assunto]').textContent = r.assunto;
                    modal.querySelector('iframe').srcdoc = r.html;
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                })
                .catch(function () { ocupado(previa, false); avisar(false, 'Falha de comunicação com o servidor.'); });
            return;
        }

        // E-mail de teste
        var teste = ev.target.closest('[data-teste]');
        if (teste) {
            var tipo = teste.getAttribute('data-teste');
            var email = raiz.querySelector('[data-teste-email="' + tipo + '"]').value.trim();
            if (!email) { avisar(false, 'Informe o e-mail para o teste.'); return; }
            ocupado(teste, true);
            enviar(raiz, { action: 'email_teste', tipo: tipo, email: email }).then(function (r) {
                ocupado(teste, false);
                avisar(r.success, r.mensagem);
            });
        }
    });

    // ------------------------------------------------------------------ início
    function iniciar() {
        iniciarMultiselects(document);
        filtrarEntidades();
        iniciarGraficos();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
}());
