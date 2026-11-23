// assets/app.js - funções compartilhadas
// Cada página define antes: const CSRF_TOKEN = "...";

// Envia uma ação por POST com token CSRF
function postAcao(dados) {
    dados.csrf_token = CSRF_TOKEN;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'actions.php';
    Object.keys(dados).forEach(function(k) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = k;
        input.value = dados[k];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}

// Máscara dinâmica de número de telefone
function mascaraNumero(input) {
    let v = input.value.replace(/\D/g, ""); // Remove não-números

    if (v.length > 13) v = v.slice(0, 13); // Trava em 13 dígitos

    // 13 Dígitos: +55 (11) 91234-5678
    if (v.length > 12) {
        v = v.replace(/^(\d{2})(\d{2})(\d{5})(\d{4})/, "+$1 ($2) $3-$4");
    }
    // 12 Dígitos: +55 (11) 5196-3908
    else if (v.length > 11) {
        v = v.replace(/^(\d{2})(\d{2})(\d{4})(\d{4})/, "+$1 ($2) $3-$4");
    }
    // 11 Dígitos: (11) 91234-5678
    else if (v.length > 10) {
        v = v.replace(/^(\d{2})(\d{5})(\d{4})/, "($1) $2-$3");
    }
    // 10 Dígitos: (11) 1234-5678
    else if (v.length > 9) {
        v = v.replace(/^(\d{2})(\d{4})(\d{4})/, "($1) $2-$3");
    }
    // 9 Dígitos: 91234-5678
    else if (v.length > 8) {
        v = v.replace(/^(\d{5})(\d{4})/, "$1-$2");
    }
    // 8 Dígitos: 1234-5678
    else if (v.length > 4) {
        v = v.replace(/^(\d{4})(\d{0,4})/, "$1-$2");
    }

    input.value = v;
}

// Mostra/esconde o campo de dias de maturação conforme o checkbox "Número Maturado?"
function toggleDias(chkId, divId) {
    const chk = document.getElementById(chkId);
    const div = document.getElementById(divId);
    const input = div.querySelector('input');
    if (chk.checked) {
        div.style.display = "block";
        input.required = true;
    } else {
        div.style.display = "none";
        input.required = false;
        input.value = "";
    }
}