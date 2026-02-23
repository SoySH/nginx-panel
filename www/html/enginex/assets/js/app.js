/**
 * NGINX Dashboard - JavaScript (Versión Segura con CSRF)
 */

var currentFile = null
var originalContent = ""
var hasUnsavedChanges = false
var editorModal = null
var bootstrap = window.bootstrap
var Swal = window.Swal

document.addEventListener("DOMContentLoaded", () => {
  // Verificar dependencias
  if (typeof bootstrap === "undefined") {
    console.error("Bootstrap not loaded")
    alert("Error: Bootstrap no cargó correctamente")
    return
  }
  if (typeof Swal === "undefined") {
    console.error("SweetAlert2 not loaded")
    alert("Error: SweetAlert2 no cargó correctamente")
    return
  }

  // Verificar CSRF token
  if (typeof window.CSRF_TOKEN === "undefined") {
    console.error("CSRF token not found")
    alert("Error: Token de seguridad no disponible")
    return
  }

  // Inicializar modal
  var modalEl = document.getElementById("editorModal")
  if (modalEl) {
    editorModal = new bootstrap.Modal(modalEl, {
      backdrop: "static",
      keyboard: false,
    })
  }

  // Eventos del editor
  var editor = document.getElementById("editor")
  if (editor) {
    editor.addEventListener("input", () => {
      hasUnsavedChanges = editor.value !== originalContent
      updateEditorStatus()
      updateLineCount()
    })

    editor.addEventListener("keydown", (e) => {
      // Tab = 4 espacios
      if (e.key === "Tab") {
        e.preventDefault()
        var start = editor.selectionStart
        var end = editor.selectionEnd
        editor.value = editor.value.substring(0, start) + "    " + editor.value.substring(end)
        editor.selectionStart = editor.selectionEnd = start + 4
        hasUnsavedChanges = editor.value !== originalContent
        updateEditorStatus()
      }
      // Ctrl+S = Guardar
      if ((e.ctrlKey || e.metaKey) && e.key === "s") {
        e.preventDefault()
        saveFile()
      }
    })
  }

  // Botón guardar
  var saveBtn = document.getElementById("saveBtn")
  if (saveBtn) {
    saveBtn.addEventListener("click", () => {
      saveFile()
    })
  }

  // Delegación de eventos para botones de acción
  document.body.addEventListener("click", (e) => {
    var btn = e.target.closest("[data-action]")
    if (!btn) return

    var action = btn.getAttribute("data-action")
    var file = btn.getAttribute("data-file")

    if (action === "edit") {
      openFile(file)
    } else if (action === "toggle") {
      toggleSite(btn, file)
    } else if (action === "rename") {
      renameSite(file)
    } else if (action === "delete") {
      deleteSite(file)
    }
  })

  // Botón crear sitio
  var btnCreate = document.getElementById("btnCreate")
  if (btnCreate) {
    btnCreate.addEventListener("click", (e) => {
      e.preventDefault()
      createSite()
    })
  }

  var btnCreateEmpty = document.getElementById("btnCreateEmpty")
  if (btnCreateEmpty) {
    btnCreateEmpty.addEventListener("click", (e) => {
      e.preventDefault()
      createSite()
    })
  }

  // Advertencia al cerrar modal con cambios
  var modalElement = document.getElementById("editorModal")
  if (modalElement) {
    modalElement.addEventListener("hide.bs.modal", (e) => {
      if (hasUnsavedChanges) {
        e.preventDefault()
        Swal.fire({
          title: "Cerrar sin guardar?",
          text: "Tienes cambios sin guardar que se perderán",
          icon: "warning",
          showCancelButton: true,
          confirmButtonText: "Sí, cerrar",
          cancelButtonText: "Seguir editando",
          confirmButtonColor: "#ef4444",
        }).then((result) => {
          if (result.isConfirmed) {
            hasUnsavedChanges = false
            editorModal.hide()
          }
        })
      }
    })
  }

  // Advertencia al cerrar página con cambios
  window.addEventListener("beforeunload", (e) => {
    if (hasUnsavedChanges) {
      e.preventDefault()
      e.returnValue = ""
    }
  })
})

// Botón Telegram Challenge
var btnTelegram = document.getElementById("btnTelegramChallenge")
if (btnTelegram) {
  btnTelegram.addEventListener("click", (e) => {
    e.preventDefault()
    toggleVisudo()
  })
}


// ============================================
// FUNCIONES AUXILIARES
// ============================================

function showToast(type, title, message) {
  var container = document.getElementById("toastContainer")
  if (!container) return

  var icons = {
    success: "fa-circle-check",
    error: "fa-circle-xmark",
    warning: "fa-triangle-exclamation",
  }

  var toast = document.createElement("div")
  toast.className = "toast toast-" + type
  toast.innerHTML =
    '<i class="toast-icon fa-solid ' +
    icons[type] +
    '"></i>' +
    '<div class="toast-content">' +
    '<div class="toast-title">' +
    escapeHtml(title) +
    "</div>" +
    '<div class="toast-message">' +
    escapeHtml(message) +
    "</div>" +
    "</div>"

  container.appendChild(toast)

  setTimeout(() => {
    toast.classList.add("hiding")
    setTimeout(() => {
      if (toast.parentNode) toast.remove()
    }, 300)
  }, 5000)
}

function api(data, callback) {
  var formData = new FormData()
  for (var key in data) {
    if (data.hasOwnProperty(key)) {
      formData.append(key, data[key])
    }
  }

  fetch("api.php", {
    method: "POST",
    body: formData,
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error('HTTP error ' + response.status)
      }
      return response.json()
    })
    .then((result) => {
      callback(result)
    })
    .catch((error) => {
      console.error("API Error:", error)
      callback({ ok: false, msg: "Error de conexión con el servidor" })
    })
}

function updateEditorStatus() {
  var statusEl = document.getElementById("editorStatus")
  if (!statusEl) return

  if (hasUnsavedChanges) {
    statusEl.className = "editor-status modified"
    statusEl.innerHTML = '<i class="fa-solid fa-circle-dot"></i> Cambios sin guardar'
  } else {
    statusEl.className = "editor-status saved"
    statusEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Sin cambios'
  }
}

function updateLineCount() {
  var editor = document.getElementById("editor")
  var lineCountEl = document.getElementById("lineCount")
  if (!editor || !lineCountEl) return

  var lines = editor.value.split("\n").length
  lineCountEl.textContent = "Líneas: " + lines
}

function escapeHtml(text) {
  var div = document.createElement("div")
  div.textContent = text
  return div.innerHTML
}

// ============================================
// FUNCIONES PRINCIPALES
// ============================================

function openFile(filename) {
  currentFile = filename

  var fileNameEl = document.getElementById("editingFileName")
  if (fileNameEl) {
    fileNameEl.textContent = filename
  }

  api({ action: "read", file: filename }, (res) => {
    if (!res.ok) {
      Swal.fire("Error", res.msg, "error")
      return
    }

    var editor = document.getElementById("editor")
    if (editor) {
      editor.value = res.content
      originalContent = res.content
      hasUnsavedChanges = false
      updateEditorStatus()
      updateLineCount()
    }

    if (editorModal) {
      editorModal.show()
    }
  })
}

function saveFile() {
  var editor = document.getElementById("editor")
  if (!editor || !currentFile) return

  var content = editor.value
  var statusEl = document.getElementById("editorStatus")
  var saveBtn = document.getElementById("saveBtn")

  if (statusEl) {
    statusEl.className = "editor-status saving"
    statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...'
  }
  if (saveBtn) saveBtn.disabled = true

  api(
    {
      action: "save",
      file: currentFile,
      content: content,
    },
    (res) => {
      if (saveBtn) saveBtn.disabled = false

      if (!res.ok) {
        Swal.fire({
          title: "Error de Sintaxis",
          html:
            '<p style="margin-bottom: 12px; color: #f59e0b;">Los cambios fueron revertidos automáticamente.</p>' +
            '<pre style="text-align: left; font-size: 12px; background: #1e1e1e; padding: 16px; border-radius: 8px; overflow-x: auto; color: #ff6b6b; max-height: 300px;">' +
            escapeHtml(res.msg) +
            "</pre>",
          icon: "error",
          confirmButtonText: "Corregir",
          width: "700px",
        })

        if (statusEl) {
          statusEl.className = "editor-status modified"
          statusEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Error - Corregir'
        }

        showToast("error", "Error de sintaxis", "Revisa la configuración e intenta de nuevo")
      } else {
        showToast("success", "Guardado", "Configuración aplicada correctamente")
        originalContent = content
        hasUnsavedChanges = false
        updateEditorStatus()
      }
    },
  )
}

function toggleSite(btn, file) {
  var state = btn.getAttribute("data-state")
  var apiAction = state === "on" ? "disable" : "enable"
  var actionText = state === "on" ? "deshabilitar" : "habilitar"
  var titleText = state === "on" ? "Deshabilitar" : "Habilitar"

  Swal.fire({
    title: titleText + " sitio?",
    text: "Vas a " + actionText + " " + file,
    icon: "question",
    showCancelButton: true,
    confirmButtonText: "Sí, " + actionText,
    cancelButtonText: "Cancelar",
  }).then((result) => {
    if (!result.isConfirmed) return

    api({ action: apiAction, file: file }, (res) => {
      if (res.ok) {
        showToast("success", titleText + "do", res.msg)
        setTimeout(() => {
          location.reload()
        }, 1000)
      } else {
        Swal.fire("Error", res.msg, "error")
      }
    })
  })
}

function renameSite(file) {
  Swal.fire({
    title: "Renombrar sitio",
    html: '<p style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 12px;">El nombre se guardará exactamente como lo escribas</p>',
    input: "text",
    inputValue: file,
    inputPlaceholder: "nuevo-nombre",
    showCancelButton: true,
    confirmButtonText: "Renombrar",
    cancelButtonText: "Cancelar",
    inputValidator: (value) => {
      if (!value) return "Debes escribir un nombre"
      if (value.trim() === file) return "El nombre es el mismo"
    },
  }).then((result) => {
    if (!result.isConfirmed || !result.value) return

    api(
      {
        action: "rename",
        file: file,
        newname: result.value.trim(),
      },
      (res) => {
        if (res.ok) {
          showToast("success", "Renombrado", res.msg)
          setTimeout(() => {
            location.reload()
          }, 1000)
        } else {
          Swal.fire("Error", res.msg, "error")
        }
      },
    )
  })
}

function deleteSite(file) {
  Swal.fire({
    title: "Eliminar sitio?",
    html:
      "<p>Eliminarás permanentemente <strong>" +
      escapeHtml(file) +
      "</strong></p>" +
      '<p style="color: #f59e0b; font-size: 0.9rem;"><i class="fa-solid fa-triangle-exclamation"></i> Esta acción no se puede deshacer</p>',
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Sí, eliminar",
    cancelButtonText: "Cancelar",
    confirmButtonColor: "#ef4444",
  }).then((result) => {
    if (!result.isConfirmed) return

    api({ action: "delete", file: file }, (res) => {
      if (res.ok) {
        showToast("success", "Eliminado", res.msg)
        setTimeout(() => {
          location.reload()
        }, 1000)
      } else {
        Swal.fire("Error", res.msg, "error")
      }
    })
  })
}

function createSite() {
  Swal.fire({
    title: "Crear nuevo sitio",
    html: '<p style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 12px;">Se agregará .conf automáticamente si no lo incluyes</p>',
    input: "text",
    inputPlaceholder: "mi-sitio",
    showCancelButton: true,
    confirmButtonText: "Crear",
    cancelButtonText: "Cancelar",
    inputValidator: (value) => {
      if (!value) return "Debes escribir un nombre"
    },
  }).then((result) => {
    if (!result.isConfirmed || !result.value) return

    api({ action: "create", name: result.value.trim() }, (res) => {
      if (res.ok) {
        showToast("success", "Sitio creado", res.msg)
        setTimeout(() => {
          location.reload()
        }, 1000)
      } else {
        Swal.fire("Error", res.msg, "error")
      }
    })
  })
}

// ============================================
// VISUDO TOGGLE + TELEGRAM CHALLENGE (CON CSRF)
// ============================================
window.toggleVisudo = async function() {
  try {
    // 1️⃣ Confirmación inicial
    const confirmResult = await Swal.fire({
      title: 'Confirmación requerida',
      text: 'Se enviará un código por Telegram',
      icon: 'info',
      showCancelButton: true,
      confirmButtonText: 'Enviar código',
      cancelButtonText: 'Cancelar',
      allowOutsideClick: false,
      allowEscapeKey: false
    });

    if (!confirmResult.isConfirmed) return;

    // 2️⃣ Pedir challenge al servidor
    Swal.fire({
      title: 'Generando código...',
      text: 'Por favor espera',
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => {
        Swal.showLoading();
      }
    });

    const challengeRes = await fetch('index.php?ajax=challenge-request', {
      method: 'GET',
      headers: {
        'Accept': 'application/json'
      }
    });
    
    if (!challengeRes.ok) {
      const errorText = await challengeRes.text();
      console.error('Challenge response error:', errorText);
      throw new Error('Error HTTP ' + challengeRes.status);
    }
    
    const challengeJson = await challengeRes.json();

    if (!challengeJson.ok) {
      Swal.fire('Error', challengeJson.msg || 'No se pudo enviar el código', 'error');
      return;
    }

    // 3️⃣ Modal para ingresar código recibido
    const codeResult = await Swal.fire({
      title: 'Código de verificación',
      text: 'Ingresa el código recibido en Telegram',
      input: 'text',
      inputPlaceholder: 'ABC123',
      showCancelButton: true,
      confirmButtonText: 'Validar',
      cancelButtonText: 'Cancelar',
      allowOutsideClick: false,
      allowEscapeKey: false,
      inputValidator: (value) => !value && 'Debes ingresar el código'
    });

    if (!codeResult.isConfirmed) return;

    // 4️⃣ Verificar código en servidor (CON CSRF TOKEN)
    Swal.fire({
      title: 'Verificando código...',
      text: 'Por favor espera',
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => {
        Swal.showLoading();
      }
    });

    const verifyFormData = new FormData();
    verifyFormData.append('code', codeResult.value.trim().toUpperCase());
    verifyFormData.append('csrf_token', window.CSRF_TOKEN);

    const verifyRes = await fetch('index.php?ajax=challenge-verify', {
      method: 'POST',
      body: verifyFormData
    });

    if (!verifyRes.ok) {
      const errorText = await verifyRes.text();
      console.error('Verify response error:', errorText);
      throw new Error('Error HTTP ' + verifyRes.status);
    }
    
    const verifyJson = await verifyRes.json();

    if (!verifyJson.ok) {
      Swal.fire('Acceso denegado', verifyJson.msg || 'Código inválido', 'error');
      return;
    }

    // 5️⃣ Aplicar visudo solo después de verificar código (CON CSRF TOKEN)
    Swal.fire({
      title: 'Aplicando permisos...',
      text: 'Por favor espera',
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => {
        Swal.showLoading();
      }
    });

    const visudoFormData = new FormData();
    visudoFormData.append('csrf_token', window.CSRF_TOKEN);

    const visudoRes = await fetch('index.php?ajax=visudo', { 
      method: 'POST',
      body: visudoFormData
    });
    
    if (!visudoRes.ok) {
      const errorText = await visudoRes.text();
      console.error('Visudo response error:', errorText);
      throw new Error('Error HTTP ' + visudoRes.status);
    }
    
    const visudoJson = await visudoRes.json();

    if (visudoJson.ok) {
      Swal.fire('Listo', visudoJson.msg, 'success');
    } else {
      Swal.fire('Error', visudoJson.msg || 'No se pudo aplicar visudo', 'error');
    }

  } catch (err) {
    console.error('Error en toggleVisudo:', err);
    Swal.fire('Error', 'Error de comunicación con el servidor: ' + err.message, 'error');
  }
};

function confirmLogout() {
  Swal.fire({
    icon: 'warning',
    title: 'Cerrar sesión',
    text: '¿Estás seguro de que deseas salir del panel?',
    showCancelButton: true,
    confirmButtonText: 'Sí, salir',
    cancelButtonText: 'Cancelar',
    customClass: {
      popup: 'logout-popup',
      title: 'logout-title',
      htmlContainer: 'logout-text',
      confirmButton: 'logout-confirm',
      cancelButton: 'logout-cancel'
    }
  }).then((result) => {
    if (result.isConfirmed) {
      window.location.href = 'log_out.php'
    }
  })
}
