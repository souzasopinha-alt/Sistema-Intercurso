let formulario = document.getElementById("formLogin");


formulario.addEventListener("submit", function(event) {

    event.preventDefault();


    let email = document.getElementById("email").value;

    let senha = document.getElementById("senha").value;


    let mensagem = document.getElementById("mensagem");

    mensagem.textContent = "Enviando dados...";


    fetch("login.php", {

        method: "POST",

        headers: {

            "Content-Type": "application/json"

        },

        body: JSON.stringify({

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
                "Login realizado com sucesso!";

            mensagem.className = "sucesso";


        } else {

            mensagem.textContent =
                resultado.mensagem;

            mensagem.className = "erro";

        }

    })

    .catch(function() {

        mensagem.textContent =
            "Não foi possível realizar o login.";

        mensagem.className = "erro";

    });

});