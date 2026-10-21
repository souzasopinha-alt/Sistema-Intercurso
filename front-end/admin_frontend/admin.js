/* Admin alinhado ao pedido (pedido (1).html)
   Campos do pedido:
   - curso/modelo: Informatica, Contabilidade, Enfermagem
   - numero: 1 a 99
   - tamanho: PP, P, M, G, GG
   - nome nas costas: até 20 caracteres
*/

const ENDPOINTS = {
    auth: '../../backend/admin_backend/auth/verificar_sessao.php',
    logout: '../../backend/admin_backend/auth/logout.php',
    pedidos: '../../backend/admin_backend/admin_pedidos.php',
    resumo: '../../backend/admin_backend/admin.php',
    votos: '../../backend/admin_backend/admin_votos.php',
    aprovar: '../../backend/pagamentos/aprovar.php',
    recusar: '../../backend/pagamentos/recusar.php',
    usuarios: '../../backend/usuarios.php',
    iniciarVotacao: '../../backend/admin_backend/iniciar_votacao.php',
    estadoVotacao: '../../backend/admin_backend/estado_votacao.php',
    encerrarVotacao: '../../backend/admin_backend/encerrar_votacao.php',
    reabrirEnvios: '../../backend/admin_backend/reabrir_envios.php',
    reabrirVotacao: '../../backend/admin_backend/reabrir_votacao.php',
    excluirUsuario: '../../backend/admin_backend/excluir_usuario.php',
    estadoAcoes:     '../../backend/admin_backend/estado_acoes.php',
    iniciarPedidos:  '../../backend/admin_backend/iniciar_pedidos.php',
    encerrarPedidos: '../../backend/admin_backend/encerrar_pedidos.php',
    reabrirPedidos:  '../../backend/admin_backend/reabrir_pedidos.php',
    excluirPedido: '../../backend/admin_backend/excluir_pedido.php',
    adicionarOpcaoVotacao: '../../backend/admin_backend/adicionar_opcao_votacao.php',
    excluirOpcaoVotacao: '../../backend/admin_backend/excluir_opcao_votacao.php'
};

let pedidosCache = [];
let pedidoSelecionado = null;

const CURSOS = {
  Informatica: 'Informática',
  Contabilidade: 'Contabilidade',
  Enfermagem: 'Enfermagem'
};

function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
  }[c]));
}

function normalizarPedido(p) {
  return {
    ...p,
    id: p.id,
    aluno: p.aluno ?? '',
    email: p.email ?? '',
    modelo: p.modelo ?? '',
    numero: p.numero ?? '',
    tamanho: p.tamanho ?? '',
    nomeCamisa: p.nome_camisa ?? p.nomeCamisa ?? '',
    status: p.status_pagamento ?? p.status ?? 'PENDENTE',
    metodoPagamento: p.metodo_pagamento ?? null,
    data: p.data_pedido ?? p.data ?? '',

    comprovanteId: p.comprovante_id ?? null,
    comprovanteArquivo: p.comprovante_arquivo ?? null,
    comprovanteData: p.comprovante_data ?? null
  };
}

function nomeCurso(v) {
  return CURSOS[v] || v || '—';
}

function nomeStatus(v) {
  return v === 'APROVADO' ? 'Aprovado' : v === 'RECUSADO' ? 'Recusado' : 'Pendente';
}

function statusPill(v) {
  const classe = v === 'APROVADO' ? 'aprovado' : v === 'RECUSADO' ? 'recusado' : 'pendente';
  return `<span class="status-pill status-pill--${classe}">${nomeStatus(v)}</span>`;
}

function formatarData(v) {
  if (!v) return '—';
  const d = String(v).split(' ')[0].split('T')[0];
  const partes = d.split('-');
  return partes.length === 3 ? `${partes[2]}/${partes[1]}/${partes[0]}` : v;
}

async function buscarPedidos() {
  const resp = await fetch(ENDPOINTS.pedidos, {
    credentials: 'include'
  });

  const texto = await resp.text();

  console.log('Status:', resp.status);
  console.log('Resposta de admin_pedidos.php:', texto);

  if (!resp.ok) {
    throw new Error(
      `Erro ${resp.status} ao carregar pedidos: ${texto}`
    );
  }

  let dados;

  try {
    dados = JSON.parse(texto);
  } catch (e) {
    throw new Error(
      'admin_pedidos.php não retornou JSON válido. Veja a resposta no Console.'
    );
  }

  pedidosCache = Array.isArray(dados)
    ? dados.map(normalizarPedido)
    : [];

  return pedidosCache;
}

async function atualizarPedidos() {
  try {
    await buscarPedidos();
  } catch (e) {
    console.error(e);
    pedidosCache = [];
    mostrarToast('Não foi possível carregar os pedidos.');
  }

  renderPedidos();
  renderPendentes();
  atualizarResumoLocal();
  atualizarRelatorio();
}


async function atualizarRelatorio() {
  try {
    const resp = await fetch(ENDPOINTS.resumo, {
      credentials: 'include'
    });

    if (!resp.ok) {
      throw new Error('Erro ao carregar relatório.');
    }

    const dados = await resp.json();

    const reportModelo = document.getElementById('reportModelo');
    const reportTamanho = document.getElementById('reportTamanho');
    const reportStatus = document.getElementById('reportStatus');

    // ==============================
    // POR CURSO
    // ==============================

    reportModelo.innerHTML = (dados.por_modelo || []).map(item => {
  const curso = String(item.modelo || '').toLowerCase();

  let classeCurso = 'curso-outro';

  if (curso === 'informatica') {
    classeCurso = 'curso-informatica';
  } else if (curso === 'contabilidade') {
    classeCurso = 'curso-contabilidade';
  } else if (curso === 'enfermagem') {
    classeCurso = 'curso-enfermagem';
  }

  return `
    <li class="${classeCurso}">
      <span>${esc(nomeCurso(item.modelo))}</span>
      <strong>${esc(item.quantidade)}</strong>
    </li>
  `;
}).join('');

    // ==============================
    // POR TAMANHO
    // ==============================

    reportTamanho.innerHTML = (dados.por_tamanho || []).map(item => `
      <li>
        <span>${esc(item.tamanho || '—')}</span>
        <strong>${esc(item.quantidade)}</strong>
      </li>
    `).join('');

    // ==============================
    // POR STATUS
    // ==============================

    const nomesStatus = {
      PENDENTE: 'Pendente',
      APROVADO: 'Aprovado',
      RECUSADO: 'Recusado'
    };

    reportStatus.innerHTML = (dados.por_status || []).map(item => `
      <li>
        <span>${esc(
          nomesStatus[item.status_pagamento] || item.status_pagamento
        )}</span>
        <strong>${esc(item.quantidade)}</strong>
      </li>
    `).join('');

  } catch (e) {
    console.error('Erro ao atualizar relatório:', e);

    document.getElementById('reportModelo').innerHTML =
      '<li>Não foi possível carregar.</li>';

    document.getElementById('reportTamanho').innerHTML =
      '<li>Não foi possível carregar.</li>';

    document.getElementById('reportStatus').innerHTML =
      '<li>Não foi possível carregar.</li>';
  }
}

function filtrarPedidos() {
  const status = document.getElementById('filtroStatus').value;
  const curso = document.getElementById('filtroModelo').value;
  const tamanho = document.getElementById('filtroTamanho').value;

  return pedidosCache.filter(p =>
    (!status || p.status === status) &&
    (!curso || p.modelo === curso) &&
    (!tamanho || p.tamanho === tamanho)
  );
}

function renderPedidos() {
  const tbody = document.getElementById('tabelaPedidos');
  const lista = filtrarPedidos();

  if (!lista.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="table__empty">Nenhum pedido encontrado.</td></tr>';
    return;
  }

  tbody.innerHTML = lista.map(p => `
    <tr>
      <td>
        <strong>${esc(p.aluno || '—')}</strong>
        ${p.email ? `<span class="table-email">${esc(p.email)}</span>` : ''}
      </td>
      <td>${esc(nomeCurso(p.modelo))}</td>
      <td>${esc(p.numero || '—')}</td>
      <td>${esc(p.tamanho || '—')}</td>
      <td>${esc(p.nomeCamisa || '—')}</td>
      <td>${statusPill(p.status)}</td>
      <td>${esc(formatarData(p.data))}</td>
                  <td>
        <div class="order-actions">
          ${p.status === 'PENDENTE'
            ? `<button class="btn btn--danger btn--small btn-icone" data-recusar="${esc(p.id)}" title="Recusar" aria-label="Recusar">✕</button>
               <button class="btn btn--success btn--small btn-icone" data-aprovar="${esc(p.id)}" title="Aceitar" aria-label="Aceitar">✓</button>`
            : `<span class="action-muted">Sem ações</span>`}

          <button type="button"
                  class="btn-excluir-pedido btn-icone"
                  data-excluir="${esc(p.id)}"
                  data-aluno="${esc(p.aluno || '')}"
                  title="Excluir"
                  aria-label="Excluir">🗑</button>
        </div>
      </td>
    </tr>
  `).join('');

  tbody.querySelectorAll('[data-aprovar]').forEach(b =>
    b.addEventListener('click', () => abrirConfirmacao(b.dataset.aprovar, 'APROVADO'))
  );
  tbody.querySelectorAll('[data-recusar]').forEach(b =>
    b.addEventListener('click', () => abrirConfirmacao(b.dataset.recusar, 'RECUSADO'))
  );

    tbody.querySelectorAll('[data-excluir]').forEach(b =>
    b.addEventListener('click', () =>
      excluirPedido(Number(b.dataset.excluir), b.dataset.aluno)
    )
  );
}


function renderPendentes() {
  const box = document.getElementById('listaPagamentos');
  const lista = pedidosCache.filter(p => p.status === 'PENDENTE');

  if (!lista.length) {
    box.innerHTML = '<p class="table__empty">Não há pedidos pendentes.</p>';
    return;
  }

  box.innerHTML = lista.map(p => `
    <article class="payment-card">
      <div class="payment-card__student">
        ${esc(p.aluno || 'Aluno não informado')}
      </div>

      <div class="payment-card__row">
        <span>Modelo:</span>
        <strong>${esc(nomeCurso(p.modelo))}</strong>
      </div>

      <div class="payment-card__row">
        <span>Pedido:</span>
        <strong>#${esc(p.id)}</strong>
      </div>

      <div class="payment-card__actions">
        <button class="btn btn--danger" data-recusar="${esc(p.id)}">
          Recusar
        </button>
        <button class="btn btn--success" data-aprovar="${esc(p.id)}">
          Analisar pedido
        </button>
      </div>
    </article>
  `).join('');

  box.querySelectorAll('[data-aprovar]').forEach(b =>
    b.addEventListener('click', () =>
      abrirConfirmacao(b.dataset.aprovar, 'APROVADO')
    )
  );

  box.querySelectorAll('[data-recusar]').forEach(b =>
    b.addEventListener('click', () =>
      abrirConfirmacao(b.dataset.recusar, 'RECUSADO')
    )
  );
}


function atualizarResumoLocal() {
  const total = pedidosCache.length;
  const pend = pedidosCache.filter(p => p.status === 'PENDENTE').length;
  const aprov = pedidosCache.filter(p => p.status === 'APROVADO').length;
  const rec = pedidosCache.filter(p => p.status === 'RECUSADO').length;
  document.getElementById('sumTotalPedidos').textContent = total;
  document.getElementById('sumPendentes').textContent = pend;
  document.getElementById('sumAprovados').textContent = aprov;
  document.getElementById('sumRecusados').textContent = rec;
}

function abrirConfirmacao(id, acao) {
  const p = pedidosCache.find(x => String(x.id) === String(id));
  if (!p) return;

  pedidoSelecionado = { id: p.id, acao };
  const comprovanteUrl = p.comprovanteId
  ? `../../backend/pagamentos/visualizar_comprovante.php?id=${encodeURIComponent(p.comprovanteId)}`
  : null;

let comprovanteHtml = '';

if (!p.comprovanteId) {
  comprovanteHtml = `
    <div class="comprovante-modal">
      <h3>Comprovante de pagamento</h3>
      <p class="comprovante-modal__vazio">
        Nenhum comprovante enviado.
      </p>
    </div>
  `;
} else if (
  p.comprovanteArquivo &&
  /\.(jpg|jpeg|png|webp)$/i.test(p.comprovanteArquivo)
) {
  comprovanteHtml = `
    <div class="comprovante-modal">
      <h3>Comprovante de pagamento</h3>

      <div class="comprovante-modal__visualizacao">
        <img
          src="${comprovanteUrl}"
          alt="Comprovante de pagamento"
          class="comprovante-modal__imagem"
        >
      </div>
    </div>
  `;
} else if (
  p.comprovanteArquivo &&
  /\.pdf$/i.test(p.comprovanteArquivo)
) {
  comprovanteHtml = `
    <div class="comprovante-modal">
      <h3>Comprovante de pagamento</h3>

      <div class="comprovante-modal__visualizacao">
        <iframe
          src="${comprovanteUrl}"
          class="comprovante-modal__pdf"
          title="Comprovante de pagamento"
        ></iframe>
      </div>
    </div>
  `;
} else {
  comprovanteHtml = `
    <div class="comprovante-modal">
      <h3>Comprovante de pagamento</h3>
      <p>
        <a
          href="${comprovanteUrl}"
          target="_blank"
          rel="noopener noreferrer"
        >
          Abrir comprovante
        </a>
      </p>
    </div>
  `;
}

document.getElementById('pagamentoDetalhe').innerHTML = `
  <div class="payment-card__row">
    <span>Aluno: </span>
    <strong>${esc(p.aluno || '—')}</strong>
  </div>

  <div class="payment-card__row">
    <span>E-mail: </span>
    <strong>${esc(p.email || '—')}</strong>
  </div>


<div class="payment-card__row">
    <span>Método de pagamento:</span>
    <strong>${
        p.metodoPagamento === 'PIX'
            ? 'PIX'
            : p.metodoPagamento === 'DINHEIRO'
                ? 'Dinheiro'
                : 'Não informado'
    }</strong>
</div>


  <div class="payment-card__row">
    <span>Curso / modelo: </span>
    <strong>${esc(nomeCurso(p.modelo))}</strong>
  </div>

  <div class="payment-card__row">
    <span>Número: </span>
    <strong>${esc(p.numero || '—')}</strong>
  </div>

  <div class="payment-card__row">
    <span>Tamanho: </span>
    <strong>${esc(p.tamanho || '—')}</strong>
  </div>

  <div class="payment-card__row">
    <span>Nome nas costas: </span>
    <strong>${esc(p.nomeCamisa || '—')}</strong>
  </div>

  <div class="payment-card__row">
    <span>Status atual: </span>
    <strong>${statusPill(p.status)}</strong>
  </div>

  <div class="payment-card__row">
    <span>Data do pedido: </span>
    <strong>${esc(formatarData(p.data))}</strong>
  </div>

  ${comprovanteHtml}
`;

  document.getElementById('btnAprovarModal').style.display = acao === 'APROVADO' ? '' : 'none';
  document.getElementById('btnRecusarModal').style.display = acao === 'RECUSADO' ? '' : 'none';
  abrirModal();
}

function abrirModal() {
  const m = document.getElementById('modalPagamento');
  m.classList.add('is-open');
  m.setAttribute('aria-hidden', 'false');
}

function fecharModal() {
  const m = document.getElementById('modalPagamento');
  m.classList.remove('is-open');
  m.setAttribute('aria-hidden', 'true');
  pedidoSelecionado = null;
}

async function enviarDecisao(acao) {
  if (!pedidoSelecionado) return;
  const { id } = pedidoSelecionado;
  const endpoint = acao === 'APROVADO' ? ENDPOINTS.aprovar : ENDPOINTS.recusar;

  try {
    const resp = await fetch(endpoint, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });

    if (!resp.ok) throw new Error('Falha ao atualizar o pedido.');

    fecharModal();
    await atualizarPedidos();
    mostrarToast(acao === 'APROVADO' ? 'Pedido aceito.' : 'Pedido recusado.');
  } catch (e) {
    console.error(e);
    mostrarToast('Não foi possível atualizar o pedido.');
  }
}

async function excluirPedido(id, aluno) {
  const ok = confirm(
    `Excluir o pedido #${id}${aluno ? ` de "${aluno}"` : ''}?\n\n` +
    'Isso remove também o comprovante anexado. Essa ação não pode ser desfeita.'
  );
  if (!ok) return;

  try {
    const resp = await fetch(ENDPOINTS.excluirPedido, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
    const dados = await resp.json();

    if (!resp.ok || !dados.success) {
      throw new Error(dados.message || 'Não foi possível excluir o pedido.');
    }

    await atualizarPedidos();
    mostrarToast('Pedido excluído.');
  } catch (e) {
    console.error(e);
    mostrarToast(e.message || 'Não foi possível excluir o pedido.');
  }
}

async function carregarUsuarios() {
  const tbody = document.getElementById('tabelaUsuarios');
  try {
    const resp = await fetch(ENDPOINTS.usuarios, { credentials: 'include' });
    if (!resp.ok) throw new Error();
    const dados = await resp.json();

    if (!Array.isArray(dados) || !dados.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="table__empty">Nenhum usuário cadastrado.</td></tr>';
      return;
    }

    tbody.innerHTML = dados.map(u => `
      <tr>
        <td>${esc(u.nome)}</td>
        <td>${esc(u.email)}</td>
        <td>${esc(u.tipo)}</td>
        <td>${esc(u.situacao)}</td>
        <td>
          <button type="button" class="btn-excluir-usuario"
                  data-id="${Number(u.id)}"
                  data-nome="${esc(u.nome)}">
            Excluir
          </button>
        </td>
      </tr>
    `).join('');

    tbody.querySelectorAll('.btn-excluir-usuario').forEach(btn => {
      btn.addEventListener('click', () => {
        excluirUsuario(Number(btn.dataset.id), btn.dataset.nome);
      });
    });
  } catch (e) {
    console.error(e);
    tbody.innerHTML = '<tr><td colspan="5" class="table__empty">Não foi possível carregar os usuários.</td></tr>';
  }
}

async function excluirUsuario(id, nome) {
  const ok = confirm(
    `Excluir o usuário "${nome}"?\n\n` +
    'Isso remove também as propostas, os votos e os pedidos relacionados. ' +
    'Essa ação não pode ser desfeita.'
  );
  if (!ok) return;

  try {
    const resp = await fetch(ENDPOINTS.excluirUsuario, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
    const dados = await resp.json();

    if (!resp.ok || !dados.success) {
      throw new Error(dados.message || 'Não foi possível excluir o usuário.');
    }

    await carregarUsuarios();
  } catch (e) {
    console.error(e);
    alert(e.message || 'Não foi possível excluir o usuário.');
  }
}

/* ============ IMPORTAÇÃO DE ALUNOS ============ */

const formImportarAlunos = document.getElementById('formImportarAlunos');

if (formImportarAlunos) {
  formImportarAlunos.addEventListener('submit', async (event) => {
    event.preventDefault();

    const inputArquivo = document.getElementById('arquivoAlunos');
    const resultado = document.getElementById('resultadoImportacao');
    const botao = document.getElementById('btnImportarAlunos');

    if (!inputArquivo.files.length) {
      resultado.textContent = 'Selecione um arquivo CSV.';
      return;
    }

    const arquivo = inputArquivo.files[0];
    if (arquivo.size > 5 * 1024 * 1024) {
      resultado.textContent = 'O arquivo não pode ultrapassar 5 MB.';
      return;
    }

    botao.disabled = true;
    resultado.textContent = 'Importando alunos...';

    try {
  const conteudo = await new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1]);
    reader.onerror = () => reject(new Error('Não foi possível ler o CSV.'));
    reader.readAsDataURL(arquivo);
  });
  const resposta = await fetch('../../backend/importar_alunos.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ nome: arquivo.name, conteudo }),
    credentials: 'same-origin'
  });

  // Lê primeiro como texto para identificar erros HTML do servidor.
  const texto = await resposta.text();

  let dados;

  try {
    dados = JSON.parse(texto);
  } catch {
    console.error(
      'Resposta não JSON:',
      resposta.status,
      texto
    );

    throw new Error(
      `O servidor retornou uma resposta inválida (HTTP ${resposta.status}).`
    );
  }

  if (!resposta.ok || !dados.sucesso) {
    throw new Error(
      dados.erro || `Falha na importação (HTTP ${resposta.status}).`
    );
  }

  resultado.textContent =
    `Importação concluída! Importados: ${dados.importados}; ` +
    `duplicados: ${dados.duplicados}; inválidos: ${dados.invalidos}.`;

  inputArquivo.value = '';

  await carregarUsuarios();

} catch (erro) {
  console.error('Erro ao importar alunos:', erro);

  resultado.textContent =
    erro.message || 'Erro ao importar os alunos.';
} finally {
  botao.disabled = false;
}

  });
}

/* ============ VOTAÇÃO ============ */
// helper de URL igual ao do aluno
function urlImagem(path) {
  return '../../backend/' +
    String(path || '').split('/').map(encodeURIComponent).join('/');
}

async function carregarVotos() {
  const tbody = document.getElementById('tabelaVotos');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="4" class="table__empty">Carregando votos...</td></tr>';

  try {
    const resp = await fetch(ENDPOINTS.votos, { credentials: 'include', cache: 'no-store' });
    const dados = await resp.json();

    if (!resp.ok || !dados.success) throw new Error(dados.message || 'Não foi possível carregar os votos.');

    if (!Array.isArray(dados.opcoes) || !dados.opcoes.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="table__empty">Nenhuma opção encontrada.</td></tr>';
      return;
    }

    tbody.innerHTML = dados.opcoes.map((v, i) => `
      <tr>
        <td><strong>${esc(v.nome || `Opção ${v.numero}`)}</strong></td>
        <td><strong>${Number(v.total_votos || 0)}</strong></td>
        <td>
          <div class="votos-imagem-cell">
            <img class="voto-thumb" src="${esc(urlImagem(v.imagem1))}" alt="Imagem da opção ${Number(v.numero)}">
            <button type="button" class="btn-ver-camisas" data-index="${i}">Ver imagens</button>
          </div>
        </td>
        <td>${v.criado_por_admin
          ? `<button type="button" class="btn btn--danger btn--small" data-excluir-opcao="${Number(v.id)}" data-nome-opcao="${esc(v.nome || `Opção ${v.numero}`)}">Excluir</button>`
          : '<span class="action-muted">—</span>'}</td>
      </tr>
    `).join('');

    tbody.querySelectorAll('.btn-ver-camisas').forEach(btn => {
      btn.addEventListener('click', () => {
        const item = dados.opcoes[Number(btn.dataset.index)];
        abrirModalCamisas(item);
      });
    });

    tbody.querySelectorAll('[data-excluir-opcao]').forEach(btn => {
      btn.addEventListener('click', () => excluirOpcaoVotacao(
        Number(btn.dataset.excluirOpcao), btn.dataset.nomeOpcao
      ));
    });
  } catch (e) {
    console.error(e);
    tbody.innerHTML =
      '<tr><td colspan="4" class="table__empty">' +
      esc(e.message || 'Não foi possível carregar os votos.') +
      '</td></tr>';
  }
}

async function excluirOpcaoVotacao(id, nome) {
  if (!confirm(`Excluir a opção "${nome}"? Os votos dela também serão removidos.`)) return;
  try {
    const resp = await fetch(ENDPOINTS.excluirOpcaoVotacao, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });
    const dados = await resp.json();
    if (!resp.ok || !dados.success) throw new Error(dados.message || 'Não foi possível excluir a opção.');
    mostrarToast(dados.message || 'Opção excluída.');
    await carregarVotos();
  } catch (erro) {
    mostrarToast(erro.message || 'Não foi possível excluir a opção.');
  }
}

function configurarFormularioOpcaoAdmin() {
  const form = document.getElementById('formOpcaoAdmin');
  if (!form) return;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const botao = document.getElementById('btnAdicionarOpcaoAdmin');
    const resultado = document.getElementById('resultadoOpcaoAdmin');
    botao.disabled = true;
    resultado.textContent = 'Enviando imagens...';
    try {
      const resp = await fetch(ENDPOINTS.adicionarOpcaoVotacao, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(await (async () => {
          const arquivos = [...form.querySelectorAll('input[type="file"]')];
          const total = arquivos.reduce((soma, campo) => soma + (campo.files[0]?.size || 0), 0);
          if (total > 12 * 1024 * 1024) {
            throw new Error('O tamanho total das três imagens deve ser de até 12 MB.');
          }
          const codificados = await Promise.all(arquivos.map(async campo => {
            const arquivo = campo.files[0];
            if (!arquivo) throw new Error('Selecione as três imagens da opção.');
            if (arquivo.size > 10 * 1024 * 1024) throw new Error('Cada imagem deve ter até 10 MB.');
            const data = await new Promise((resolve, reject) => {
              const reader = new FileReader();
              reader.onload = () => resolve(String(reader.result).split(',')[1]);
              reader.onerror = () => reject(new Error('Não foi possível ler uma das imagens.'));
              reader.readAsDataURL(arquivo);
            });
            return { name: arquivo.name, data };
          }));
          return {
            nome: form.elements.nome.value,
            imagem1: codificados[0], imagem2: codificados[1], imagem3: codificados[2]
          };
        })())
      });
      const dados = await resp.json();
      if (!resp.ok || !dados.success) throw new Error(dados.message || 'Não foi possível adicionar a opção.');
      resultado.textContent = dados.message || 'Opção adicionada.';
      form.reset();
      await carregarVotos();
    } catch (erro) {
      resultado.textContent = erro.message || 'Não foi possível adicionar a opção.';
    } finally {
      botao.disabled = false;
    }
  });
}

const modalCamisas = document.getElementById('modalCamisas');
const modalCamisasGrid = document.getElementById('modalCamisasGrid');
const modalCamisasTitulo = document.getElementById('modalCamisasTitulo');
const fecharModalCamisas = document.getElementById('fecharModalCamisas');

function abrirModalCamisas(item) {
  if (!modalCamisas) return;

  modalCamisasTitulo.textContent = item.nome || `Opção ${item.numero}`;
  modalCamisasGrid.innerHTML = ['imagem1', 'imagem2', 'imagem3'].map((k, idx) => {
    const src = urlImagem(item[k]);
    const nome = `${(item.nome || 'opcao').replace(/\s+/g, '_')}_${idx + 1}.jpg`;
    return `
      <div class="camisa-slot">
        <img src="${esc(src)}" alt="Camisa ${idx + 1}">
        <a class="btn-download" href="${esc(src)}" download="${esc(nome)}">Baixar</a>
      </div>
    `;
  }).join('');

  modalCamisas.hidden = false;
}

if (fecharModalCamisas) {
  fecharModalCamisas.addEventListener('click', () => {
    modalCamisas.hidden = true;
  });
}

if (modalCamisas) {
  modalCamisas.addEventListener('click', e => {
    if (e.target === modalCamisas) modalCamisas.hidden = true;
  });
}

function renderRelatorio() {
  const contar = campo => pedidosCache.reduce((acc, p) => {
    const v = p[campo] || 'Não informado';
    acc[v] = (acc[v] || 0) + 1;
    return acc;
  }, {});

  const preencher = (id, dados, transform = x => x) => {
    const el = document.getElementById(id);
    el.innerHTML = Object.entries(dados).map(([k,v]) =>
      `<li><span>${esc(transform(k))}</span><strong>${v}</strong></li>`
    ).join('') || '<li><span>Sem dados</span></li>';
  };

  preencher('reportModelo', contar('modelo'), nomeCurso);
  preencher('reportTamanho', contar('tamanho'));
  preencher('reportStatus', contar('status'), nomeStatus);
}

async function protegerPainel() {
  try {
    const resp = await fetch(ENDPOINTS.auth, { credentials: 'include' });
    if (!resp.ok) throw new Error();
    const d = await resp.json();
    if (!d.logado || !d.admin) {
      window.location.href = '../login.html';
      return;
    }
    document.getElementById('userName').textContent = d.nome || 'Administrador';
    document.getElementById('userRole').textContent = 'Administrador';
  } catch (e) {
    document.getElementById('userName').textContent = 'Administrador';
    document.getElementById('userRole').textContent = 'Administrador';
  }
}

async function sair() {
  try {
    await fetch(ENDPOINTS.logout, { method:'POST', credentials:'include' });
  } finally {
    window.location.href = '../login.html';
  }
}

function mostrarToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('is-visible');
  setTimeout(() => t.classList.remove('is-visible'), 2600);
}

function configurar() {
  configurarFormularioOpcaoAdmin();
  document.querySelectorAll('.sidebar__link').forEach(link => {
    link.addEventListener('click', async () => {
      document.querySelectorAll('.sidebar__link').forEach(x => x.classList.remove('is-active'));
      link.classList.add('is-active');
      document.querySelectorAll('.view').forEach(x => x.classList.remove('is-active'));
      document.getElementById(`view-${link.dataset.view}`).classList.add('is-active');

            if (link.dataset.view === 'pedidos' || link.dataset.view === 'pendentes') await atualizarPedidos();
      if (link.dataset.view === 'usuarios') await carregarUsuarios();
      if (link.dataset.view === 'relatorio') { await atualizarPedidos(); renderRelatorio(); }
      if (link.dataset.view === 'votacao' || link.dataset.view === 'acoes') await atualizarEstado();
      if (link.dataset.view === 'votacao' || link.dataset.view === 'acoes') {
    await atualizarEstado();
    if (link.dataset.view === 'votacao') await carregarVotos();
}
    });
  });

  ['filtroStatus','filtroModelo','filtroTamanho'].forEach(id =>
    document.getElementById(id).addEventListener('change', renderPedidos)
  );

  document.getElementById('btnLimparFiltros').addEventListener('click', () => {
    document.getElementById('filtroStatus').value = '';
    document.getElementById('filtroModelo').value = '';
    document.getElementById('filtroTamanho').value = '';
    renderPedidos();
  });

  document.getElementById('btnLogout').addEventListener('click', sair);
  document.getElementById('btnLogoutSide').addEventListener('click', sair);

  document.querySelectorAll('[data-close-modal]').forEach(el =>
    el.addEventListener('click', fecharModal)
  );

  document.getElementById('btnAprovarModal').addEventListener('click', () => enviarDecisao('APROVADO'));
  document.getElementById('btnRecusarModal').addEventListener('click', () => enviarDecisao('RECUSADO'));
}

document.addEventListener('DOMContentLoaded', async () => {
  configurar();
  await protegerPainel();
  await atualizarPedidos();
  renderRelatorio();
  window.setInterval(() => {
    const painelVotacao = document.getElementById('view-votacao');
    if (painelVotacao?.classList.contains('is-active')) carregarVotos();
  }, 10000);
});

/* ---------- Estado global das ações (admin) ---------- */

let _estadoAtual = {
    envios_abertos: false,
    votacao_aberta: false,
    pedidos_abertos: false
};

async function consultarEstadoAcoes() {
    const resp = await fetch(ENDPOINTS.estadoAcoes, {
        credentials: 'include',
        cache: 'no-store'
    });
    const dados = await resp.json();
    if (!resp.ok || !dados.success) {
        throw new Error(dados.message || 'Erro ao consultar estado.');
    }
    return dados;
}

function aplicarEstado(dados) {
    _estadoAtual = {
        envios_abertos:  !!dados.envios_abertos,
        votacao_aberta:  !!dados.votacao_aberta,
        pedidos_abertos: !!dados.pedidos_abertos
    };

    const { envios_abertos, votacao_aberta, pedidos_abertos } = _estadoAtual;

    const pegar = id => document.getElementById(id);

    // --- View Votação ---
    const btnIniciar = pegar('btnIniciarVotacao');
    const btnEncerrar = pegar('btnEncerrarVotacao');
    const btnReabrirEnvios = pegar('btnReabrirEnvios');
    const btnReabrirVotacao = pegar('btnReabrirVotacao');
    const estadoVot = pegar('estadoVotacao');

    if (btnIniciar) {
        btnIniciar.disabled = !envios_abertos || votacao_aberta || pedidos_abertos;
        if (votacao_aberta) btnIniciar.textContent = 'Votação iniciada';
        else if (!envios_abertos) btnIniciar.textContent = 'Envios encerrados';
        else btnIniciar.textContent = 'Encerrar envios e iniciar votação';
    }

    if (btnEncerrar) {
        btnEncerrar.disabled = !votacao_aberta;
        btnEncerrar.textContent = votacao_aberta ? 'Encerrar votação' : 'Votação encerrada';
    }

    if (btnReabrirEnvios) {
        btnReabrirEnvios.disabled = envios_abertos || votacao_aberta || pedidos_abertos;
    }

    if (btnReabrirVotacao) {
        btnReabrirVotacao.disabled = votacao_aberta || envios_abertos || pedidos_abertos;
    }

    if (estadoVot) {
        if (votacao_aberta)          estadoVot.textContent = 'A votação está aberta.';
        else if (pedidos_abertos)    estadoVot.textContent = 'A votação terminou. Pedidos estão abertos.';
        else if (!envios_abertos)    estadoVot.textContent = 'Os envios estão encerrados, mas a votação não está aberta.';
        else                         estadoVot.textContent = 'Os envios de camisas estão abertos.';
    }

    // --- View Ações ---
    const btnIniPed = pegar('btnIniciarPedidos');
    const btnEncPed = pegar('btnEncerrarPedidos');
    const btnReaPed = pegar('btnReabrirPedidos');
    const estadoAc = pegar('estadoAcoes');

    const podeAbrirPedidos = !votacao_aberta && !envios_abertos && !pedidos_abertos;

    if (btnIniPed) btnIniPed.disabled = !podeAbrirPedidos;
    if (btnReaPed) btnReaPed.disabled = !podeAbrirPedidos;
    if (btnEncPed) {
        btnEncPed.disabled = !pedidos_abertos;
        btnEncPed.textContent = pedidos_abertos ? 'Encerrar pedidos' : 'Pedidos encerrados';
    }

    if (estadoAc) {
        if (pedidos_abertos)   estadoAc.textContent = 'Os pedidos estão abertos. Alunos podem acessar o fluxo pedido → pagamento → perfil.';
        else if (votacao_aberta) estadoAc.textContent = 'A votação está em andamento. Encerre-a para liberar os pedidos.';
        else if (envios_abertos) estadoAc.textContent = 'Os envios estão abertos. Encerre-os e conclua a votação antes de abrir pedidos.';
        else                   estadoAc.textContent = 'Os pedidos estão encerrados.';
    }
}

async function atualizarEstado() {
    try {
        const dados = await consultarEstadoAcoes();
        aplicarEstado(dados);
    } catch (erro) {
        const estadoVot = document.getElementById('estadoVotacao');
        const estadoAc = document.getElementById('estadoAcoes');
        if (estadoVot) estadoVot.textContent = erro.message;
        if (estadoAc)  estadoAc.textContent  = erro.message;
    }
}

async function executarAcao(endpoint, confirmacao, progresso) {
    if (!confirm(confirmacao)) return;

    const estadoVot = document.getElementById('estadoVotacao');
    const estadoAc = document.getElementById('estadoAcoes');

    if (estadoVot) estadoVot.textContent = progresso;
    if (estadoAc)  estadoAc.textContent  = progresso;

    try {
        const resp = await fetch(endpoint, {
            method: 'POST',
            credentials: 'include',
            cache: 'no-store'
        });
        const dados = await resp.json();
        if (!resp.ok || !dados.success) {
            throw new Error(dados.message || 'Falha na operação.');
        }
        await atualizarEstado();
        if (typeof carregarVotos === 'function') {
            await carregarVotos();
        }
    } catch (erro) {
        if (estadoVot) estadoVot.textContent = erro.message;
        if (estadoAc)  estadoAc.textContent  = erro.message;
        await atualizarEstado();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    atualizarEstado();

    document.getElementById('btnIniciarVotacao')?.addEventListener('click', () =>
        executarAcao(
            ENDPOINTS.iniciarVotacao,
            'Encerrar os envios e iniciar a votação? Depois disso, os alunos não poderão enviar novas propostas.',
            'Iniciando votação...'
        )
    );

    document.getElementById('btnEncerrarVotacao')?.addEventListener('click', () =>
        executarAcao(
            ENDPOINTS.encerrarVotacao,
            'Deseja encerrar a votação? Os alunos não poderão mais votar.',
            'Encerrando votação...'
        )
    );

    document.getElementById('btnReabrirEnvios')?.addEventListener('click', () =>
        executarAcao(
            ENDPOINTS.reabrirEnvios,
            'Reabrir os envios de camisas? Os alunos poderão enviar novas propostas novamente.',
            'Reabrindo envios...'
        )
    );

    document.getElementById('btnReabrirVotacao')?.addEventListener('click', () =>
        executarAcao(
            ENDPOINTS.reabrirVotacao,
            'Reabrir a votação? Alunos que já votaram continuam sem poder votar de novo.',
            'Reabrindo votação...'
        )
    );

    document.getElementById('btnIniciarPedidos')?.addEventListener('click', () =>
        executarAcao(
            ENDPOINTS.iniciarPedidos,
            'Iniciar os pedidos? Os alunos passarão a ter acesso ao fluxo pedido → pagamento → perfil.',
            'Iniciando pedidos...'
        )
    );

    document.getElementById('btnEncerrarPedidos')?.addEventListener('click', () =>
        executarAcao(
            ENDPOINTS.encerrarPedidos,
            'Encerrar os pedidos? Novos pedidos não poderão ser feitos.',
            'Encerrando pedidos...'
        )
    );

        document.getElementById('btnAtualizarVotos')?.addEventListener('click', () => {
        if (typeof carregarVotos === 'function') carregarVotos();
    });

    document.getElementById('btnReabrirPedidos')?.addEventListener('click', () =>
        executarAcao(
            ENDPOINTS.reabrirPedidos,
            'Reabrir os pedidos? Alunos voltarão a poder acessar o fluxo.',
            'Reabrindo pedidos...'
        )
    );
});

// ---------- Lightbox do admin ----------
const lightboxAdmin = document.getElementById('lightboxAdmin');
const lightboxAdminImg = document.getElementById('lightboxAdminImg');
const lightboxAdminClose = document.getElementById('lightboxAdminClose');

function abrirLightboxAdmin(src) {
    lightboxAdminImg.src = src;
    lightboxAdmin.hidden = false;
}

function fecharLightboxAdmin() {
    lightboxAdmin.hidden = true;
    lightboxAdminImg.removeAttribute('src');
}

if (lightboxAdminClose) {
    lightboxAdminClose.addEventListener('click', fecharLightboxAdmin);
}

if (lightboxAdmin) {
    lightboxAdmin.addEventListener('click', event => {
        if (event.target === lightboxAdmin) fecharLightboxAdmin();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !lightboxAdmin.hidden) {
            fecharLightboxAdmin();
        }
    });

    document.addEventListener('click', event => {
        if (event.target.matches('.camisa-slot img')) {
            abrirLightboxAdmin(event.target.src);
        }
    });
}
