let formulario = document.getElementById("formCadastro");


formulario.addEventListener("submit", function(event) {

    event.preventDefault();


    let nome = document.getElementById("nome").value;

    let email = document.getElementById("email").value;

    let senha = document.getElementById("senha").value;


    let mensagem = document.getElementById(
        "mensagemCadastro"
    );


    mensagem.textContent = "Enviando cadastro...";


    fetch("cadastro.php", {

        method: "POST",

        headers: {

            "Content-Type": "application/json"

        },

        body: JSON.stringify({

            nome: nome,

            email: email,

            senha: senha

        })

    })

    .then(function(resposta) {

        return resposta.json();

    })

    .then(function(resultado) {

        if (resultado.sucesso) {

            mensagem.textContent =
                "Cadastro realizado com sucesso!";

            mensagem.className = "sucesso";


            formulario.reset();

        } else {

            mensagem.textContent =
                resultado.mensagem;

            mensagem.className = "erro";

        }

    })

    .catch(function() {

        mensagem.textContent =
            "Não foi possível realizar o cadastro.";

        mensagem.className = "erro";

    });

});