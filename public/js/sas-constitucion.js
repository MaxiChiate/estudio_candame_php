var NACIONALIDADES = [
    "Argentina", "Alemana", "Boliviana", "Brasileña", "Británica", "Canadiense",
    "Chilena", "China", "Colombiana", "Coreana (Corea del Sur)", "Costarricense",
    "Cubana", "Dominicana", "Ecuatoriana", "Egipcia", "Salvadoreña", "Eslovaca",
    "Española", "Estadounidense", "Filipina", "Finlandesa", "Francesa", "Griega",
    "Guatemalteca", "Haitiana", "Hondureña", "Hindú (India)", "Holandesa",
    "Húngara", "Indonesia", "Iraní", "Iraquí", "Irlandesa", "Israelí", "Italiana",
    "Japonesa", "Libanesa", "Mexicana", "Nicaragüense", "Noruega", "Panameña",
    "Paraguaya", "Peruana", "Polaca", "Portuguesa", "Puertorriqueña", "Rumana",
    "Rusa", "Sueca", "Suiza", "Sudafricana", "Turca", "Ucraniana", "Uruguaya",
    "Venezolana", "Vietnamita",
];

// Un <select> nativo con ~55 opciones puede quedar parcialmente inaccesible: si no
// entra debajo del campo, algunos navegadores (Chrome) lo parten en columnas en vez
// de scrollear, y las ultimas nacionalidades quedan fuera de pantalla. Por eso se
// arma un combo propio con una lista posicionada que siempre scrollea y se abre
// hacia arriba si no hay lugar debajo.
function closeCombo(combo) {
    var list = combo.querySelector("[data-combo-list]");
    list.hidden = true;
    combo.querySelector("[data-combo-button]").setAttribute("aria-expanded", "false");
}

function openCombo(combo) {
    document.querySelectorAll("[data-nacionalidad-combo]").forEach(function (otro) {
        if (otro !== combo) {
            closeCombo(otro);
        }
    });

    var list = combo.querySelector("[data-combo-list]");
    var button = combo.querySelector("[data-combo-button]");
    list.hidden = false;
    button.setAttribute("aria-expanded", "true");

    var buttonRect = button.getBoundingClientRect();
    var espacioAbajo = window.innerHeight - buttonRect.bottom;
    var espacioArriba = buttonRect.top;
    var abrirHaciaArriba = espacioAbajo < 200 && espacioArriba > espacioAbajo;
    list.classList.toggle("comboBoxListUp", abrirHaciaArriba);
    list.style.maxHeight = Math.max(120, (abrirHaciaArriba ? espacioArriba : espacioAbajo) - 16) + "px";
}

function populateNacionalidadCombos(scope) {
    scope.querySelectorAll("[data-nacionalidad-combo]").forEach(function (combo) {
        var list = combo.querySelector("[data-combo-list]");
        var button = combo.querySelector("[data-combo-button]");
        var label = combo.querySelector("[data-combo-label]");
        var hiddenInput = combo.querySelector('[data-field="nacionalidad"]');
        if (list.children.length > 0) {
            return;
        }

        function seleccionar(nacionalidad) {
            hiddenInput.value = nacionalidad;
            label.textContent = nacionalidad;
            list.querySelectorAll("li").forEach(function (li) {
                li.classList.toggle("active", li.textContent === nacionalidad);
            });
            closeCombo(combo);
        }

        NACIONALIDADES.forEach(function (nacionalidad) {
            var item = document.createElement("li");
            item.textContent = nacionalidad;
            item.setAttribute("role", "option");
            item.addEventListener("click", function () {
                seleccionar(nacionalidad);
            });
            list.appendChild(item);
        });

        button.addEventListener("click", function (event) {
            event.stopPropagation();
            if (list.hidden) {
                openCombo(combo);
            } else {
                closeCombo(combo);
            }
        });

        seleccionar("Argentina");
    });
}

document.addEventListener("click", function () {
    document.querySelectorAll("[data-nacionalidad-combo]").forEach(closeCombo);
});

// Componente de ayuda (?): un boton que muestra/oculta un panel de texto al lado del
// campo. A diferencia del combo de nacionalidad no hace falta cerrarlo al hacer click
// afuera: son paneles inline que no se superponen a otros campos.
function bindHelpTriggers(scope) {
    scope.querySelectorAll("[data-help-trigger]").forEach(function (trigger) {
        if (trigger.dataset.bound) {
            return;
        }
        trigger.dataset.bound = "true";
        trigger.addEventListener("click", function () {
            var panel = trigger.closest(".formRow").querySelector("[data-help-panel]");
            var abierto = !panel.hidden;
            panel.hidden = abierto;
            trigger.setAttribute("aria-expanded", String(!abierto));
        });
    });
}

function actualizarConyuge(personaSlot) {
    var estadoCivilSelect = personaSlot.querySelector('[data-field="estadoCivil"]');
    var conyugeInput = personaSlot.querySelector('[data-field="conyuge"]');
    if (!estadoCivilSelect || !conyugeInput) {
        return;
    }
    var casado = estadoCivilSelect.value === "Casado";
    conyugeInput.disabled = !casado;
    if (!casado) {
        conyugeInput.value = "";
    }
}

document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("sasForm");
    if (!form) {
        return;
    }

    var accionistasList = document.getElementById("accionistasList");
    var administradoresList = document.getElementById("administradoresList");
    var personaTemplate = document.getElementById("personaFieldsTemplate");
    var accionistaTemplate = document.getElementById("accionistaTemplate");
    var administradorTemplate = document.getElementById("administradorTemplate");
    var errorBox = document.getElementById("tramiteError");
    var successBox = document.getElementById("tramiteSuccess");
    var porcentajeTotalEl = document.getElementById("porcentajeTotal");

    bindHelpTriggers(document);

    var tipoObjetoSelect = document.getElementById("tipoObjeto");
    var objetoEspecificoRow = document.getElementById("objetoEspecificoRow");

    function actualizarObjetoSocial() {
        objetoEspecificoRow.style.display = tipoObjetoSelect.value === "ESPECIFICO" ? "" : "none";
    }

    tipoObjetoSelect.addEventListener("change", actualizarObjetoSocial);
    actualizarObjetoSocial();

    function renumber(container, selector, label) {
        var cards = container.querySelectorAll(selector);
        cards.forEach(function (card, index) {
            card.querySelector(".tramiteCardTitle").textContent = label + " " + (index + 1);
        });
    }

    function updatePorcentajeTotal() {
        if (!porcentajeTotalEl) {
            return;
        }
        var total = 0;
        accionistasList.querySelectorAll('[data-field="porcentajeParticipacion"]').forEach(function (input) {
            var valor = parseFloat(input.value.replace(",", "."));
            if (!isNaN(valor)) {
                total += valor;
            }
        });
        var totalRedondeado = Math.round(total * 100) / 100;
        var completo = Math.abs(totalRedondeado - 100) <= 0.01;
        porcentajeTotalEl.textContent = "Total participación: " + totalRedondeado + "%" + (completo ? "" : " (debe sumar 100%)");
        porcentajeTotalEl.classList.toggle("formHintError", !completo);
    }

    function addCard(container, cardTemplate, selector, label) {
        var card = cardTemplate.content.firstElementChild.cloneNode(true);
        var personaSlot = card.querySelector("[data-persona-slot]");
        var personaFragment = personaTemplate.content.cloneNode(true);
        personaSlot.appendChild(personaFragment);
        populateNacionalidadCombos(personaSlot);
        bindHelpTriggers(personaSlot);

        var estadoCivilSelect = personaSlot.querySelector('[data-field="estadoCivil"]');
        actualizarConyuge(personaSlot);
        estadoCivilSelect.addEventListener("change", function () {
            actualizarConyuge(personaSlot);
        });

        card.querySelector("[data-remove]").addEventListener("click", function () {
            if (container.querySelectorAll(selector).length <= 1) {
                return;
            }
            card.remove();
            renumber(container, selector, label);
            if (container === accionistasList) {
                updatePorcentajeTotal();
            }
        });

        container.appendChild(card);
        renumber(container, selector, label);
        if (container === accionistasList) {
            updatePorcentajeTotal();
        }
        return card;
    }

    document.getElementById("addAccionista").addEventListener("click", function () {
        addCard(accionistasList, accionistaTemplate, "[data-accionista]", "Accionista");
    });
    document.getElementById("addAdministrador").addEventListener("click", function () {
        addCard(administradoresList, administradorTemplate, "[data-administrador]", "Administrador");
    });

    accionistasList.addEventListener("input", function (event) {
        if (event.target.matches('[data-field="porcentajeParticipacion"]')) {
            updatePorcentajeTotal();
        }
    });

    addCard(accionistasList, accionistaTemplate, "[data-accionista]", "Accionista");
    addCard(administradoresList, administradorTemplate, "[data-administrador]", "Administrador");

    function readPersona(card) {
        var persona = {};
        var slot = card.querySelector("[data-persona-slot]");
        slot.querySelectorAll(":scope > .formGrid [data-field]").forEach(function (input) {
            var field = input.getAttribute("data-field");
            var value = input.value.trim();
            // fechaNacimiento is the only nullable field on the server; every other
            // field is a non-null Kotlin String, so it must stay "" rather than null.
            persona[field] = field === "fechaNacimiento" && value === "" ? null : value;
        });

        var domicilio = {};
        slot.querySelectorAll("[data-domicilio-slot] [data-field]").forEach(function (input) {
            domicilio[input.getAttribute("data-field")] = input.value.trim();
        });
        persona.domicilio = domicilio;

        return persona;
    }

    function readCardOwnFields(card) {
        var values = {};
        card.querySelectorAll(":scope > .formGrid [data-field]").forEach(function (input) {
            values[input.getAttribute("data-field")] = input.value.trim();
        });
        return values;
    }

    function collectFormData() {
        var accionistas = [];
        accionistasList.querySelectorAll("[data-accionista]").forEach(function (card) {
            var own = readCardOwnFields(card);
            accionistas.push({
                persona: readPersona(card),
                porcentajeParticipacion: own.porcentajeParticipacion || "",
            });
        });

        var administradores = [];
        administradoresList.querySelectorAll("[data-administrador]").forEach(function (card) {
            var own = readCardOwnFields(card);
            administradores.push({
                persona: readPersona(card),
                cargo: own.cargo || "Titular",
            });
        });

        return {
            accionistas: accionistas,
            nombreOpcion1: form.nombreOpcion1.value.trim(),
            nombreOpcion2: form.nombreOpcion2.value.trim(),
            nombreOpcion3: form.nombreOpcion3.value.trim(),
            tipoObjeto: form.tipoObjeto.value,
            objetoSocial: form.objetoSocial.value.trim(),
            cierreEjercicioMes: form.cierreEjercicioMes.value,
            domicilio: {
                calle: form.domicilioCalle.value.trim(),
                altura: form.domicilioAltura.value.trim(),
                piso: form.domicilioPiso.value.trim(),
                departamento: form.domicilioDepto.value.trim(),
                cuerpo: form.domicilioCuerpo.value.trim(),
            },
            duracionSociedad: parseInt(form.duracionSociedad.value, 10) || 0,
            emailSociedad: form.emailSociedad.value.trim(),
            telefonoSociedad: form.telefonoSociedad.value.trim(),
            administradores: administradores,
        };
    }

    function parseFilename(contentDisposition, fallback) {
        if (!contentDisposition) {
            return fallback;
        }
        var match = /filename="?([^"]+)"?/.exec(contentDisposition);
        return match ? match[1] : fallback;
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.style.display = "block";
        successBox.style.display = "none";
    }

    form.addEventListener("submit", function (event) {
        event.preventDefault();
        errorBox.style.display = "none";
        successBox.style.display = "none";

        fetch(form.getAttribute("action") || window.location.pathname, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(collectFormData()),
        }).then(function (response) {
            if (response.ok) {
                var disposition = response.headers.get("Content-Disposition");
                return response.blob().then(function (blob) {
                    var url = window.URL.createObjectURL(blob);
                    var link = document.createElement("a");
                    link.href = url;
                    link.download = parseFilename(disposition, "constitucion-sas.zip");
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    window.URL.revokeObjectURL(url);
                    successBox.style.display = "block";
                });
            }

            return response.json().catch(function () {
                return null;
            }).then(function (data) {
                var mensaje = "Hubo un problema al generar los documentos.";
                if (data && data.errores && data.errores.length) {
                    mensaje += " " + data.errores.join(" | ");
                }
                showError(mensaje);
            });
        }).catch(function () {
            showError("No se pudo conectar con el servidor. Intente nuevamente.");
        });
    });
});
