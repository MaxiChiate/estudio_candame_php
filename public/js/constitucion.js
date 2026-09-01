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

var ORGANO_LABELS = {
    SAS: { organo: "Administración", singular: "administrador", titular: "Administrador titular", suplente: "Administrador suplente" },
    SRL: { organo: "Gerencia", singular: "gerente", titular: "Gerente titular", suplente: "Gerente suplente" },
    SA: { organo: "Directorio", singular: "director", titular: "Director titular", suplente: "Director suplente" },
};

function capitalizar(texto) {
    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

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

            // El tipo de documento no se elige: DNI para argentinos, pasaporte para
            // el resto. El <select> queda disabled en el HTML, solo lo actualiza esto.
            var formGrid = combo.closest(".formGrid");
            var tipoDocumentoSelect = formGrid ? formGrid.querySelector('[data-field="tipoDocumento"]') : null;
            if (tipoDocumentoSelect) {
                tipoDocumentoSelect.value = nacionalidad === "Argentina" ? "DNI" : "PASAPORTE";
            }
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

        combo.seleccionarNacionalidad = seleccionar;
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
    var casado = estadoCivilSelect.value === "CASADO";
    conyugeInput.disabled = !casado;
    if (!casado) {
        conyugeInput.value = "";
    }
}

document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("consultaForm");
    if (!form) {
        return;
    }

    var sociosList = document.getElementById("sociosList");
    var administradoresList = document.getElementById("administradoresList");
    var personaTemplate = document.getElementById("personaFieldsTemplate");
    var socioTemplate = document.getElementById("socioTemplate");
    var administradorTemplate = document.getElementById("administradorTemplate");
    var errorBox = document.getElementById("tramiteError");
    var errorResumenEl = document.getElementById("tramiteErrorResumen");
    var errorListaEl = document.getElementById("tramiteErrorLista");
    var successBox = document.getElementById("tramiteSuccess");
    var porcentajeTotalEl = document.getElementById("porcentajeTotal");

    bindHelpTriggers(document);

    var capitalInfoData = {};
    try {
        var capitalInfoScript = document.getElementById("capitalInfoData");
        capitalInfoData = capitalInfoScript ? JSON.parse(capitalInfoScript.textContent || "{}") : {};
    } catch (error) {
        capitalInfoData = {};
    }

    function tipoActualSeleccionado() {
        var checked = document.querySelector('input[name="tipoSocietario"]:checked');
        return checked ? checked.value : "SAS";
    }

    function actualizarCapitalHint(tipoValue) {
        var hintEl = document.getElementById("capitalHint");
        if (!hintEl) {
            return;
        }
        var info = capitalInfoData[tipoValue];
        hintEl.classList.remove("formHintError");
        if (!info || info.piso === null || info.piso === undefined) {
            hintEl.textContent = "";
            return;
        }
        var pisoFormateado = "$ " + Number(info.piso).toLocaleString("es-AR");
        if (info.bloqueante) {
            hintEl.textContent = "Es el mínimo legal para una " + tipoValue + ": " + pisoFormateado + ". " + (info.detalle || "");
            hintEl.classList.add("formHintError");
        } else if (info.detalle) {
            hintEl.textContent = "Mínimo sugerido para una " + tipoValue + ": " + pisoFormateado + ". " + info.detalle;
        } else {
            hintEl.textContent = "";
        }
    }

    function actualizarEtiquetasOrgano(tipoValue) {
        var labels = ORGANO_LABELS[tipoValue] || ORGANO_LABELS.SAS;

        var titulo = document.getElementById("administradoresTitulo");
        if (titulo) {
            titulo.textContent = labels.organo;
        }

        var addBtn = document.getElementById("addAdministrador");
        if (addBtn) {
            addBtn.textContent = "Agregar " + labels.singular;
        }

        administradoresList.querySelectorAll("[data-administrador]").forEach(function (card) {
            var select = card.querySelector('[data-field="cargo"]');
            if (!select) {
                return;
            }
            var titularOption = select.querySelector('[data-cargo-option="TITULAR"]');
            var suplenteOption = select.querySelector('[data-cargo-option="SUPLENTE"]');
            if (titularOption) {
                titularOption.textContent = labels.titular;
            }
            if (suplenteOption) {
                suplenteOption.textContent = labels.suplente;
            }
        });

        renumber(administradoresList, "[data-administrador]", capitalizar(labels.singular));
    }

    document.querySelectorAll('input[name="tipoSocietario"]').forEach(function (radio) {
        radio.addEventListener("change", function () {
            if (!radio.checked) {
                return;
            }
            actualizarEtiquetasOrgano(radio.value);
            actualizarCapitalHint(radio.value);
        });
    });
    actualizarEtiquetasOrgano(tipoActualSeleccionado());
    actualizarCapitalHint(tipoActualSeleccionado());

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
        sociosList.querySelectorAll('[data-field="porcentajeParticipacion"]').forEach(function (input) {
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

    function actualizarSelectCopiarDeSocio(administradorCard) {
        var select = administradorCard.querySelector("[data-copiar-de-socio]");
        if (!select) {
            return;
        }
        var seleccionPrevia = select.value;
        select.innerHTML = "";
        var vacio = document.createElement("option");
        vacio.value = "";
        vacio.textContent = "Elegir socio a copiar…";
        select.appendChild(vacio);
        sociosList.querySelectorAll("[data-socio]").forEach(function (socioCard, index) {
            var option = document.createElement("option");
            option.value = String(index);
            option.textContent = socioCard.querySelector(".tramiteCardTitle").textContent;
            select.appendChild(option);
        });
        select.value = seleccionPrevia;
    }

    function actualizarTodosLosSelectCopiar() {
        administradoresList.querySelectorAll("[data-administrador]").forEach(actualizarSelectCopiarDeSocio);
    }

    function readPersonaFromCard(card) {
        var persona = {};
        var slot = card.querySelector("[data-persona-slot]");
        slot.querySelectorAll(":scope > .formGrid [data-field]").forEach(function (input) {
            var field = input.getAttribute("data-field");
            var value = input.value.trim();
            persona[field] = field === "fechaNacimiento" && value === "" ? null : value;
        });

        var domicilioReal = {};
        slot.querySelectorAll("[data-domicilio-slot] [data-field]").forEach(function (input) {
            domicilioReal[input.getAttribute("data-field")] = input.value.trim();
        });
        persona.domicilioReal = domicilioReal;

        return persona;
    }

    function writePersonaToCard(card, persona) {
        var slot = card.querySelector("[data-persona-slot]");
        slot.querySelectorAll(":scope > .formGrid [data-field]").forEach(function (input) {
            var field = input.getAttribute("data-field");
            var value = persona[field];
            input.value = value === null || value === undefined ? "" : value;
            if (field === "nacionalidad") {
                var combo = input.closest("[data-nacionalidad-combo]");
                if (combo && combo.seleccionarNacionalidad) {
                    combo.seleccionarNacionalidad(value || "Argentina");
                }
            }
        });
        actualizarConyuge(slot);

        var domicilio = persona.domicilioReal || {};
        slot.querySelectorAll("[data-domicilio-slot] [data-field]").forEach(function (input) {
            var field = input.getAttribute("data-field");
            input.value = domicilio[field] || "";
        });
    }

    function readCardOwnFields(card) {
        var values = {};
        card.querySelectorAll(":scope > .formGrid [data-field]").forEach(function (input) {
            values[input.getAttribute("data-field")] = input.value.trim();
        });
        return values;
    }

    function addCard(container, cardTemplate, selector, label, onListChanged) {
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
            if (container === sociosList) {
                updatePorcentajeTotal();
            }
            if (onListChanged) {
                onListChanged();
            }
        });

        container.appendChild(card);
        renumber(container, selector, label);
        if (container === sociosList) {
            updatePorcentajeTotal();
        }
        if (onListChanged) {
            onListChanged();
        }

        return card;
    }

    function addSocioCard() {
        return addCard(sociosList, socioTemplate, "[data-socio]", "Socio", actualizarTodosLosSelectCopiar);
    }

    function addAdministradorCard() {
        var labels = ORGANO_LABELS[tipoActualSeleccionado()] || ORGANO_LABELS.SAS;
        var card = addCard(administradoresList, administradorTemplate, "[data-administrador]", capitalizar(labels.singular));

        var cargoSelect = card.querySelector('[data-field="cargo"]');
        var titularOption = cargoSelect.querySelector('[data-cargo-option="TITULAR"]');
        var suplenteOption = cargoSelect.querySelector('[data-cargo-option="SUPLENTE"]');
        titularOption.textContent = labels.titular;
        suplenteOption.textContent = labels.suplente;

        actualizarSelectCopiarDeSocio(card);
        card.querySelector("[data-copiar-btn]").addEventListener("click", function () {
            var select = card.querySelector("[data-copiar-de-socio]");
            var indice = parseInt(select.value, 10);
            var socioCards = sociosList.querySelectorAll("[data-socio]");
            if (isNaN(indice) || !socioCards[indice]) {
                return;
            }
            writePersonaToCard(card, readPersonaFromCard(socioCards[indice]));
        });

        return card;
    }

    document.getElementById("addSocio").addEventListener("click", addSocioCard);
    document.getElementById("addAdministrador").addEventListener("click", addAdministradorCard);

    sociosList.addEventListener("input", function (event) {
        if (event.target.matches('[data-field="porcentajeParticipacion"]')) {
            updatePorcentajeTotal();
        }
    });

    addSocioCard();
    addAdministradorCard();

    function collectFormData() {
        var socios = [];
        sociosList.querySelectorAll("[data-socio]").forEach(function (card) {
            var persona = readPersonaFromCard(card);
            var own = readCardOwnFields(card);
            persona.porcentajeParticipacion = own.porcentajeParticipacion || "";
            socios.push(persona);
        });

        var administradores = [];
        administradoresList.querySelectorAll("[data-administrador]").forEach(function (card) {
            var persona = readPersonaFromCard(card);
            var own = readCardOwnFields(card);
            persona.cargo = own.cargo || "TITULAR";
            administradores.push(persona);
        });

        var honeypotInput = document.getElementById("honeypotField");

        var payload = {
            tipoSocietario: tipoActualSeleccionado(),
            contacto: {
                nombre: document.getElementById("contactoNombre").value.trim(),
                email: document.getElementById("contactoEmail").value.trim(),
                telefono: document.getElementById("contactoTelefono").value.trim(),
                rol: document.getElementById("contactoRol").value,
            },
            sociedad: {
                nombreOpcion1: document.getElementById("nombreOpcion1").value.trim(),
                nombreOpcion2: document.getElementById("nombreOpcion2").value.trim(),
                nombreOpcion3: document.getElementById("nombreOpcion3").value.trim(),
                objetoSocial: document.getElementById("objetoSocial").value.trim(),
                capitalSocial: parseInt(document.getElementById("capitalSocial").value, 10) || 0,
                duracionAnios: parseInt(document.getElementById("duracionAnios").value, 10) || 0,
                cierreEjercicioDia: parseInt(document.getElementById("cierreEjercicioDia").value, 10) || 0,
                cierreEjercicioMes: parseInt(document.getElementById("cierreEjercicioMes").value, 10) || 0,
                sede: {
                    calle: document.getElementById("sedeCalle").value.trim(),
                    numero: document.getElementById("sedeNumero").value.trim(),
                    piso: document.getElementById("sedePiso").value.trim(),
                    depto: document.getElementById("sedeDepto").value.trim(),
                },
                sedeJurisdiccion: "CABA",
                emailSociedad: document.getElementById("emailSociedad").value.trim(),
                telefonoSociedad: document.getElementById("telefonoSociedad").value.trim(),
                urgente: document.getElementById("urgente").checked,
            },
            socios: socios,
            administradores: administradores,
            csrfToken: document.getElementById("csrfToken").value,
            formularioServidoEn: parseInt(document.getElementById("formularioServidoEn").value, 10) || 0,
        };
        payload[honeypotInput.name] = honeypotInput.value;

        return payload;
    }

    function limpiarErrores() {
        document.querySelectorAll(".fieldError").forEach(function (el) {
            el.textContent = "";
        });
        errorListaEl.innerHTML = "";
        errorBox.style.display = "none";
    }

    function mostrarErrores(errores) {
        limpiarErrores();
        errorResumenEl.textContent = "Revise los siguientes datos:";

        errores.forEach(function (error) {
            var li = document.createElement("li");
            li.textContent = error.mensaje;
            errorListaEl.appendChild(li);

            var matchLista = /^(socios|administradores)\[(\d+)\]\.(.+)$/.exec(error.campo || "");
            var destino = null;
            if (matchLista) {
                var contenedor = matchLista[1] === "socios" ? sociosList : administradoresList;
                var selectorCard = matchLista[1] === "socios" ? "[data-socio]" : "[data-administrador]";
                var card = contenedor.querySelectorAll(selectorCard)[parseInt(matchLista[2], 10)];
                if (card) {
                    destino = card.querySelector('[data-field-error="' + matchLista[3] + '"]');
                }
            } else if (error.campo) {
                destino = document.querySelector('[data-field-error="' + error.campo + '"]');
            }
            if (destino) {
                destino.textContent = error.mensaje;
            }
        });

        errorBox.style.display = "block";
        successBox.style.display = "none";
        errorBox.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    form.addEventListener("submit", function (event) {
        event.preventDefault();
        limpiarErrores();
        successBox.style.display = "none";

        fetch(form.getAttribute("action") || window.location.pathname, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(collectFormData()),
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (data) {
                if (response.ok) {
                    form.style.display = "none";
                    errorBox.style.display = "none";
                    successBox.style.display = "block";

                    var seccion = form.closest("section");
                    var cabecera = seccion ? seccion.querySelector(".sectionHead") : null;
                    (cabecera || seccion || document.body).scrollIntoView({ behavior: "smooth", block: "start" });

                    var titulo = document.getElementById("tramiteSuccessTitulo");
                    if (titulo) {
                        titulo.focus();
                    }
                    return;
                }

                if (data && Array.isArray(data.errores) && data.errores.length) {
                    mostrarErrores(data.errores);
                } else {
                    mostrarErrores([{ campo: "", mensaje: "Hubo un problema al enviar la consulta. Intente nuevamente." }]);
                }
            });
        }).catch(function () {
            mostrarErrores([{ campo: "", mensaje: "No se pudo conectar con el servidor. Intente nuevamente." }]);
        });
    });
});
