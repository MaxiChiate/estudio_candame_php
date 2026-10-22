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
            // Asignar .value por codigo no dispara eventos: se avisa a mano para que
            // el borrador local se entere del cambio.
            hiddenInput.dispatchEvent(new Event("change", { bubbles: true }));

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
            setFieldValue(input, value);
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
            setFieldValue(input, domicilio[field]);
        });
    }

    function setFieldValue(field, value) {
        field.value = value === null || value === undefined ? "" : String(value);
        // Un select sin opcion para ese valor queda sin nada elegido: vuelve a la primera.
        if (field.tagName === "SELECT" && field.selectedIndex === -1) {
            field.selectedIndex = 0;
        }
    }

    function writeCardOwnFields(card, values) {
        card.querySelectorAll(":scope > .formGrid [data-field]").forEach(function (input) {
            setFieldValue(input, values[input.getAttribute("data-field")]);
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
            scheduleDraftSave();
        });

        container.appendChild(card);
        renumber(container, selector, label);
        if (container === sociosList) {
            updatePorcentajeTotal();
        }
        if (onListChanged) {
            onListChanged();
        }
        scheduleDraftSave();

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
            scheduleDraftSave();
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

    // --- Borrador local ------------------------------------------------------
    // Lo cargado se guarda en localStorage (no sale del navegador hasta el envio)
    // y se restaura solo al volver. Toda falla del storage se traga: el form tiene
    // que andar igual y sin mostrarle ningun error al usuario.
    var DRAFT_KEY = "candame.constitucion.borrador";
    var DRAFT_VERSION = 1;
    var DRAFT_MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;
    var DRAFT_SAVE_DELAY_MS = 500;

    var draftStorage = detectDraftStorage();
    var draftSaveTimer = null;
    var draftLabelTimer = null;
    // En false no se guarda nada: durante la carga, la restauracion y el reseteo
    // (que tocan los campos por codigo) y despues de un envio exitoso.
    var draftSavingEnabled = false;
    // El form tal como queda en una carga nueva: igual a esto no es un borrador.
    var pristineFormJson = null;
    var draftNotice = document.getElementById("borradorAviso");
    var draftLabel = document.getElementById("borradorEtiqueta");

    function detectDraftStorage() {
        try {
            var storage = window.localStorage;
            var probeKey = DRAFT_KEY + ".prueba";
            storage.setItem(probeKey, "1");
            storage.removeItem(probeKey);
            return storage;
        } catch (error) {
            return null;
        }
    }

    function removeDraft() {
        if (!draftStorage) {
            return;
        }
        try {
            draftStorage.removeItem(DRAFT_KEY);
        } catch (error) {
            // Sin storage no hay borrador que borrar.
        }
    }

    function saveDraftNow() {
        clearTimeout(draftSaveTimer);
        draftSaveTimer = null;
        if (!draftStorage || !draftSavingEnabled) {
            return;
        }
        try {
            var datos = collectFormData();
            if (JSON.stringify(datos) === pristineFormJson) {
                draftStorage.removeItem(DRAFT_KEY);
                return;
            }
            draftStorage.setItem(DRAFT_KEY, JSON.stringify({
                v: DRAFT_VERSION,
                guardadoEn: new Date().toISOString(),
                datos: datos,
            }));
        } catch (error) {
            // Storage lleno o bloqueado a mitad de camino: se sigue sin borrador.
        }
    }

    function scheduleDraftSave() {
        if (!draftStorage || !draftSavingEnabled) {
            return;
        }
        clearTimeout(draftSaveTimer);
        draftSaveTimer = setTimeout(saveDraftNow, DRAFT_SAVE_DELAY_MS);
    }

    function flushDraftSave() {
        if (draftSaveTimer !== null) {
            saveDraftNow();
        }
    }

    // Devuelve los datos del borrador, o null. Un borrador ilegible, de otra version
    // o vencido se borra en silencio.
    function readDraft() {
        var raw = null;
        try {
            raw = draftStorage.getItem(DRAFT_KEY);
        } catch (error) {
            return null;
        }
        if (raw === null) {
            return null;
        }

        var draft = null;
        try {
            draft = JSON.parse(raw);
        } catch (error) {
            draft = null;
        }

        var valido = !!draft && typeof draft === "object"
            && draft.v === DRAFT_VERSION
            && typeof draft.guardadoEn === "string"
            && !!draft.datos && typeof draft.datos === "object";
        if (valido) {
            var guardadoEn = Date.parse(draft.guardadoEn);
            valido = !isNaN(guardadoEn) && Date.now() - guardadoEn <= DRAFT_MAX_AGE_MS;
        }
        if (!valido) {
            removeDraft();
            return null;
        }
        return draft.datos;
    }

    function setCardCount(container, selector, count, addCardFn) {
        var cards = container.querySelectorAll(selector);
        for (var i = cards.length; i < count; i++) {
            addCardFn();
        }
        for (var j = cards.length - 1; j >= count; j--) {
            cards[j].remove();
        }
    }

    function refreshDerivedState() {
        var tipo = tipoActualSeleccionado();
        renumber(sociosList, "[data-socio]", "Socio");
        actualizarEtiquetasOrgano(tipo);
        actualizarCapitalHint(tipo);
        updatePorcentajeTotal();
        actualizarTodosLosSelectCopiar();
    }

    // Orden: tipo societario (las tarjetas nuevas toman sus etiquetas), cantidad de
    // tarjetas, valores, y al final lo que se recalcula a partir de los valores.
    function restoreDraft(datos) {
        var tipo = Object.prototype.hasOwnProperty.call(ORGANO_LABELS, datos.tipoSocietario) ? datos.tipoSocietario : "SAS";
        document.querySelectorAll('input[name="tipoSocietario"]').forEach(function (radio) {
            radio.checked = radio.value === tipo;
        });

        var socios = Array.isArray(datos.socios) ? datos.socios : [];
        var administradores = Array.isArray(datos.administradores) ? datos.administradores : [];
        setCardCount(sociosList, "[data-socio]", Math.max(1, socios.length), addSocioCard);
        setCardCount(administradoresList, "[data-administrador]", Math.max(1, administradores.length), addAdministradorCard);

        var contacto = datos.contacto || {};
        setFieldValue(document.getElementById("contactoNombre"), contacto.nombre);
        setFieldValue(document.getElementById("contactoEmail"), contacto.email);
        setFieldValue(document.getElementById("contactoTelefono"), contacto.telefono);
        setFieldValue(document.getElementById("contactoRol"), contacto.rol);

        var sociedad = datos.sociedad || {};
        ["nombreOpcion1", "nombreOpcion2", "nombreOpcion3", "objetoSocial", "capitalSocial", "duracionAnios",
            "cierreEjercicioDia", "cierreEjercicioMes", "emailSociedad", "telefonoSociedad"].forEach(function (campo) {
            setFieldValue(document.getElementById(campo), sociedad[campo]);
        });
        var sede = sociedad.sede || {};
        setFieldValue(document.getElementById("sedeCalle"), sede.calle);
        setFieldValue(document.getElementById("sedeNumero"), sede.numero);
        setFieldValue(document.getElementById("sedePiso"), sede.piso);
        setFieldValue(document.getElementById("sedeDepto"), sede.depto);
        document.getElementById("urgente").checked = sociedad.urgente === true;

        sociosList.querySelectorAll("[data-socio]").forEach(function (card, index) {
            if (socios[index]) {
                writePersonaToCard(card, socios[index]);
                writeCardOwnFields(card, socios[index]);
            }
        });
        administradoresList.querySelectorAll("[data-administrador]").forEach(function (card, index) {
            if (administradores[index]) {
                writePersonaToCard(card, administradores[index]);
                writeCardOwnFields(card, administradores[index]);
            }
        });

        refreshDerivedState();
    }

    // Deja el form como en una carga nueva, sin recargar (Firefox restaura los
    // valores de los inputs al recargar). No toca el storage.
    function resetFormToFresh() {
        var savingEnabled = draftSavingEnabled;
        draftSavingEnabled = false;
        clearTimeout(draftSaveTimer);
        draftSaveTimer = null;

        form.reset();
        sociosList.innerHTML = "";
        administradoresList.innerHTML = "";
        addSocioCard();
        addAdministradorCard();
        refreshDerivedState();
        form.querySelectorAll("[data-help-panel]").forEach(function (panel) {
            panel.hidden = true;
        });
        form.querySelectorAll("[data-help-trigger]").forEach(function (trigger) {
            trigger.setAttribute("aria-expanded", "false");
        });
        limpiarErrores();

        draftSavingEnabled = savingEnabled;
    }

    function setDraftLabelVisible(visible) {
        if (!draftLabel) {
            return;
        }
        clearTimeout(draftLabelTimer);
        if (!visible) {
            draftLabel.hidden = true;
            return;
        }
        // Con una pausa: un aria-live que cambia en el mismo instante de la carga
        // suele no anunciarse.
        draftLabelTimer = setTimeout(function () {
            draftLabel.hidden = false;
        }, 100);
    }

    function discardDraftAfterSubmit() {
        draftSavingEnabled = false;
        clearTimeout(draftSaveTimer);
        draftSaveTimer = null;
        removeDraft();
    }

    function initDraft() {
        if (!draftStorage) {
            if (draftNotice) {
                draftNotice.hidden = true;
            }
            return;
        }

        // Se parte de un form recien reseteado: si Firefox restauro valores al
        // recargar, la foto de "form vacio" no los tiene que incluir.
        resetFormToFresh();
        pristineFormJson = JSON.stringify(collectFormData());

        var datos = readDraft();
        if (datos) {
            try {
                restoreDraft(datos);
                setDraftLabelVisible(true);
            } catch (error) {
                // Datos con una forma inesperada (editados a mano, por ejemplo): se
                // descartan en silencio y se arranca de cero.
                removeDraft();
                resetFormToFresh();
            }
        }

        draftSavingEnabled = true;
        form.addEventListener("input", scheduleDraftSave);
        form.addEventListener("change", scheduleDraftSave);
        // Lo tipeado en el ultimo medio segundo antes de cerrar o cambiar de pestania.
        window.addEventListener("pagehide", flushDraftSave);
        document.addEventListener("visibilitychange", function () {
            if (document.visibilityState === "hidden") {
                flushDraftSave();
            }
        });
    }

    addSocioCard();
    addAdministradorCard();
    initDraft();

    // --- Borrar todo y empezar de nuevo -------------------------------------
    var clearButton = document.getElementById("borradorLimpiar");
    var clearDialog = document.getElementById("borradorDialogo");
    var clearCancel = document.getElementById("borradorCancelar");
    var clearConfirm = document.getElementById("borradorConfirmar");

    // Sin mensaje posterior: el form vacio y la etiqueta que desaparece alcanzan.
    function clearDraftAndForm() {
        removeDraft();
        resetFormToFresh();
        setDraftLabelVisible(false);
        (form.closest(".tramiteFormPanel") || form).scrollIntoView({ behavior: "smooth", block: "start" });
        var primerCampo = form.querySelector('input[name="tipoSocietario"]:checked');
        if (primerCampo) {
            primerCampo.focus({ preventScroll: true });
        }
    }

    function normalizarTexto(el) {
        return el ? el.textContent.replace(/\s+/g, " ").trim() : "";
    }

    if (clearButton && clearDialog && clearCancel && clearConfirm) {
        clearButton.addEventListener("click", function () {
            // Navegadores sin <dialog>: el confirm nativo, con los mismos textos.
            if (typeof clearDialog.showModal !== "function") {
                var pregunta = normalizarTexto(document.getElementById("borradorDialogoTitulo"))
                    + "\n\n" + normalizarTexto(document.getElementById("borradorDialogoTexto"));
                if (window.confirm(pregunta)) {
                    clearDraftAndForm();
                }
                return;
            }
            clearDialog.showModal();
            clearCancel.focus();
        });
        clearCancel.addEventListener("click", function () {
            clearDialog.close();
        });
        clearConfirm.addEventListener("click", function () {
            clearDialog.close();
            clearDraftAndForm();
        });
        // Click en el fondo (fuera de la caja) cancela. Escape lo resuelve el <dialog>.
        clearDialog.addEventListener("click", function (event) {
            if (event.target === clearDialog) {
                clearDialog.close();
            }
        });
    }

    // Solo los datos que carga el usuario: es lo que guarda el borrador local, que
    // nunca debe llevar el token CSRF, el timestamp de servido ni el honeypot. Los
    // numeros van como texto (tal cual estan en el campo) para que un campo vacio
    // se restaure vacio y no como 0; la conversion la hace buildSubmitPayload().
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

        return {
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
                capitalSocial: document.getElementById("capitalSocial").value.trim(),
                duracionAnios: document.getElementById("duracionAnios").value.trim(),
                cierreEjercicioDia: document.getElementById("cierreEjercicioDia").value.trim(),
                cierreEjercicioMes: document.getElementById("cierreEjercicioMes").value.trim(),
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
        };
    }

    // Lo que viaja en el POST: los datos del form con los numeros ya convertidos,
    // mas los campos antispam. El contrato es el mismo de siempre.
    function buildSubmitPayload() {
        var payload = collectFormData();
        ["capitalSocial", "duracionAnios", "cierreEjercicioDia", "cierreEjercicioMes"].forEach(function (campo) {
            payload.sociedad[campo] = parseInt(payload.sociedad[campo], 10) || 0;
        });
        payload.csrfToken = document.getElementById("csrfToken").value;
        payload.formularioServidoEn = parseInt(document.getElementById("formularioServidoEn").value, 10) || 0;

        var honeypotInput = document.getElementById("honeypotField");
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

    // Feedback de envio: generar la ficha y mandar los dos mails tarda. envio-form.js
    // se carga despues que este archivo (va en el layout), por eso se busca en el
    // momento del submit y no al cargar. Si no esta, el flag local igual frena el
    // doble envio, solo que sin spinner.
    var botonEnviar = form.querySelector('button[type="submit"]');
    var envioEnCurso = false;

    function terminarEnvio() {
        envioEnCurso = false;
        if (window.EnvioForm) {
            window.EnvioForm.restaurar(botonEnviar);
        }
    }

    form.addEventListener("submit", function (event) {
        event.preventDefault();
        if (envioEnCurso) {
            return;
        }
        envioEnCurso = true;
        if (window.EnvioForm) {
            window.EnvioForm.iniciar(botonEnviar);
        }

        limpiarErrores();
        successBox.style.display = "none";

        fetch(form.getAttribute("action") || window.location.pathname, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(buildSubmitPayload()),
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (data) {
                if (response.ok) {
                    discardDraftAfterSubmit();
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
                    // Exito: el form se oculta y el boton queda deshabilitado a
                    // proposito -- no hay nada mas que enviar.
                    return;
                }

                terminarEnvio();
                if (data && Array.isArray(data.errores) && data.errores.length) {
                    mostrarErrores(data.errores);
                } else {
                    mostrarErrores([{ campo: "", mensaje: "Hubo un problema al enviar la consulta. Intente nuevamente." }]);
                }
            });
        }).catch(function () {
            terminarEnvio();
            mostrarErrores([{ campo: "", mensaje: "No se pudo conectar con el servidor. Intente nuevamente." }]);
        });
    });
});
