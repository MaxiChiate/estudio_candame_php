// Estado "enviando" para los botones de formularios lentos (SMTP, generacion de la
// ficha). Deshabilita el boton, cambia el texto por "Enviando…" con un spinner CSS
// (.btnSpinner en main.css) y bloquea el doble envio.
//
// Dos formas de usarlo:
// - Form clasico (POST con recarga): alcanza con data-envio-lento en el <form>. Si este
//   script no carga, el form se envia igual, solo que sin el feedback.
// - Form que envia por fetch: llamar EnvioForm.iniciar(boton) al empezar y
//   EnvioForm.restaurar(boton) si el envio falla.
//
// El texto se puede cambiar por boton con data-texto-enviando.
var EnvioForm = (function () {
    var TEXTO_POR_DEFECTO = "Enviando…";

    function enCurso(boton) {
        return !!boton && boton.classList.contains("is-enviando");
    }

    // Devuelve false si el boton ya estaba enviando: quien llama no debe mandar otro.
    function iniciar(boton) {
        if (!boton || enCurso(boton)) {
            return false;
        }

        boton.dataset.textoOriginal = boton.textContent;
        boton.textContent = boton.dataset.textoEnviando || TEXTO_POR_DEFECTO;
        var spinner = document.createElement("span");
        spinner.className = "btnSpinner";
        spinner.setAttribute("aria-hidden", "true");
        boton.insertBefore(spinner, boton.firstChild);
        boton.classList.add("is-enviando");
        boton.setAttribute("aria-busy", "true");

        // El disabled va diferido: en un form clasico, un submitter deshabilitado
        // durante el evento submit queda afuera de los datos enviados. El doble click
        // ya lo frena la clase is-enviando, que se pone en el acto.
        setTimeout(function () {
            if (enCurso(boton)) {
                boton.disabled = true;
            }
        }, 0);

        return true;
    }

    function restaurar(boton) {
        if (!enCurso(boton)) {
            return;
        }
        boton.textContent = boton.dataset.textoOriginal || "";
        delete boton.dataset.textoOriginal;
        boton.classList.remove("is-enviando");
        boton.removeAttribute("aria-busy");
        boton.disabled = false;
    }

    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (!form.hasAttribute("data-envio-lento")) {
            return;
        }
        if (form.querySelector(".is-enviando")) {
            // Segundo click (o Enter) mientras el primero sigue en vuelo.
            event.preventDefault();
            return;
        }
        if (event.defaultPrevented) {
            return;
        }
        iniciar(event.submitter || form.querySelector('[type="submit"]'));
    });

    // Volver con el boton "atras" puede restaurar la pagina desde el bfcache con el
    // boton todavia en "Enviando…".
    window.addEventListener("pageshow", function (event) {
        if (event.persisted) {
            document.querySelectorAll(".is-enviando").forEach(restaurar);
        }
    });

    return { iniciar: iniciar, restaurar: restaurar, enCurso: enCurso };
})();
