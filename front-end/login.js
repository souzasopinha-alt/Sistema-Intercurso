let formulario = document.getElementById("formLogin");

formulario.addEventListener("submit", function(event) {
    event.preventDefault();

    let email = document.getElementById("email").value;
    let senha = document.getElementById("senha").value;

    let mensagem = document.getElementById("mensagem");

    mensagem.textContent = "Enviando dados...";
    mensagem.className = "";

    const botao = formulario.querySelector('button[type="submit"]');
    botao.disabled = true;

    fetch("../backend/login.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "application/json"
        },
        body: new URLSearchParams({
            email: email,
            senha: senha
        }),
        credentials: "same-origin",
        cache: "no-store"
    })
    .then(async function(resposta) {
        const tipoConteudo = resposta.headers.get("content-type") || "";
        let resultado;

        try {
            resultado = await resposta.json();
        } catch (erro) {
            console.error("Resposta de login incompatível com JSON.", {
                status: resposta.status,
                contentType: tipoConteudo,
                erro: erro
            });
            if (resposta.status === 404) {
                throw new Error("O endpoint de login não foi encontrado. Confira se o projeto está sendo servido pelo Apache do XAMPP.");
            }
            throw new Error("O servidor não retornou uma resposta válida. Confira o Apache e os logs do PHP.");
        }

        if (!resposta.ok && !resultado.erro && !resultado.mensagem) {
            throw new Error(`O servidor recusou a solicitação (HTTP ${resposta.status}).`);
        }
        return resultado;
    })
    .then(function(resultado) {

        if (resultado.sucesso) {

            mensagem.textContent =
                "Login realizado com sucesso!";

            mensagem.className = "sucesso";

            const tipo = String(resultado.tipo || "").trim().toUpperCase();

            if (tipo === "ADMIN") {
                window.location.replace("admin_frontend/admin.html");
            } else if (tipo === "ALUNO" || tipo === "LIDER") {
                const destinosAluno = [
                    "uploud_frontend/uploud_camisas.html",
                    "votacao_frontend/votacao.html",
                    "pedido.html",
                    "perfil.html"
                ];
                const proximoPasso = destinosAluno.includes(resultado.proximo_passo)
                    ? resultado.proximo_passo
                    : "uploud_frontend/uploud_camisas.html";

                window.location.replace(proximoPasso);
            } else {
                mensagem.textContent = "O perfil recebido para esta conta não é válido.";
                mensagem.className = "erro";
            }

        } else {

            mensagem.textContent =
                resultado.erro ||
                resultado.mensagem ||
                "Não foi possível realizar o login.";

            mensagem.className = "erro";
        }

    })
    .catch(function(erro) {

        console.error("Falha na solicitação de login.", {
            mensagem: erro.message,
            origem: window.location.origin,
            endpoint: new URL("../backend/login.php", window.location.href).href
        });

        mensagem.textContent = erro instanceof TypeError
            ? "Não foi possível acessar o servidor PHP. Confira se o Apache do XAMPP está iniciado e abra o site por http://localhost."
            : erro.message || "Não foi possível realizar o login. Tente novamente.";

        mensagem.className = "erro";
    })
    .finally(function() {
        botao.disabled = false;
    });
});