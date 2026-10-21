(() => {
  const scriptUrl = document.currentScript?.src || new URL('votacao-redirect.js', window.location.href).href;
  const endpoint = new URL('../backend/votacao_backend/votacao.php', scriptUrl);
  const paginaVotacao = new URL('votacao_frontend/votacao.html', scriptUrl);
  let consultando = false;

  async function verificarVotacaoAberta() {
    if (consultando || window.location.pathname === paginaVotacao.pathname) return;
    consultando = true;
    try {
      const resposta = await fetch(endpoint, {
        credentials: 'include',
        cache: 'no-store'
      });
      if (!resposta.ok) return;
      const dados = await resposta.json();
      if (dados.success && dados.votacao_aberta) {
        window.location.replace(paginaVotacao.href);
      }
    } catch (erro) {
      console.warn('Não foi possível verificar se a votação está aberta.', erro);
    } finally {
      consultando = false;
    }
  }

  verificarVotacaoAberta();
  window.setInterval(verificarVotacaoAberta, 10000);
})();
