const formulario = document.getElementById('formLogin');

formulario.addEventListener('submit', async function (event) {
    event.preventDefault();

    const email = document.getElementById('email').value.trim();
    const senha = document.getElementById('senha').value;
    const mensagem = document.getElementById('mensagem');

    mensagem.textContent = 'Enviando dados...';
    mensagem.className = '';

    try {
        const resposta = await fetch('../backend/login.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams({
                email: email,
                senha: senha
            }),
            credentials: 'include'
        });

        const texto = await resposta.text();
        let resultado;

        try {
            resultado = JSON.parse(texto);
        } catch (erro) {
            console.error('Resposta recebida do login.php:', texto);
            throw new Error('O servidor não retornou um JSON válido.');
        }

        if (!resposta.ok || !resultado.sucesso) {
            throw new Error(
                resultado.erro ||
                resultado.mensagem ||
                'E-mail ou senha incorretos.'
            );
        }

        console.log('Login realizado:', {
            usuario_id: resultado.usuario_id,
            nome: resultado.nome,
            email: resultado.email,
            tipo: resultado.tipo
        });

        mensagem.textContent = 'Login realizado com sucesso!';
        mensagem.className = 'sucesso';

        window.location.href = 'uploud_frontend/uploud_camisas.html';

    } catch (erro) {
        console.error('Erro no login:', erro);
        mensagem.textContent = erro.message || 'Não foi possível realizar o login.';
        mensagem.className = 'erro';
    }
});
