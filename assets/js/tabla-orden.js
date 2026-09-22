/**
 * tabla-orden.js — Ordenamiento de tablas en el cliente (sin recargar).
 *
 * Uso: agrega la clase "js-tabla-orden" al <table>.
 *   - Por defecto cada <th> del primer <thead> es ordenable.
 *   - <th data-no-orden>  -> no ordenable (ej. columnas de foto/acciones).
 *   - <th data-orden-tipo="num|fecha|texto">  -> fuerza el tipo de orden.
 *   - <td data-orden="VALOR">  -> valor exacto a usar para ordenar esa celda.
 *
 * Detección automática (si no se declara tipo): número, fecha (es), o texto.
 * Funciona con tablas cargadas dinámicamente: llama window.TablaOrden.init().
 */
(function () {
    'use strict';

    var MESES = {
        ene: 0, feb: 1, mar: 2, abr: 3, may: 4, jun: 5,
        jul: 6, ago: 7, sep: 8, oct: 9, nov: 10, dic: 11
    };

    // Convierte "8,087.3", "$919.60", "200 km", "40.00L" -> número. "—"/"" -> NaN.
    function aNumero(txt) {
        if (txt == null) return NaN;
        var limpio = String(txt).replace(/[^0-9.\-]/g, '');
        if (limpio === '' || limpio === '-' || limpio === '.') return NaN;
        return parseFloat(limpio);
    }

    // Convierte "18 Jun 2026, 11:39" o "2026-06-18 11:39" -> timestamp. Si no, NaN.
    function aFecha(txt) {
        if (txt == null) return NaN;
        var s = String(txt).trim();
        if (s === '' || s === '—' || s === '-') return NaN;

        // ISO / formato BD: 2026-06-18 11:39:00  ó  2026-06-18T11:39
        var iso = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (iso) {
            return new Date(+iso[1], +iso[2] - 1, +iso[3], +(iso[4] || 0), +(iso[5] || 0)).getTime();
        }

        // Formato amigable es: "18 Jun 2026, 11:39"  ó  "18 Jun 2026"
        var m = s.match(/^(\d{1,2})\s+([A-Za-zÁÉÍÓÚáéíóú]{3})[A-Za-zÁÉÍÓÚáéíóú]*\.?\s+(\d{4})(?:,?\s+(\d{1,2}):(\d{2}))?/);
        if (m) {
            var mes = MESES[m[2].toLowerCase().substring(0, 3)
                .replace('á', 'a').replace('é', 'e').replace('í', 'i').replace('ó', 'o').replace('ú', 'u')];
            if (mes != null) {
                return new Date(+m[3], mes, +m[1], +(m[4] || 0), +(m[5] || 0)).getTime();
            }
        }
        return NaN;
    }

    // Valor de orden de una celda según el tipo de la columna.
    function valorCelda(td, tipo) {
        var raw = td.getAttribute('data-orden');
        var txt = (raw != null) ? raw : td.textContent.trim();
        if (tipo === 'num')   return aNumero(txt);
        if (tipo === 'fecha') return aFecha(txt);
        return txt.toLowerCase();
    }

    // Decide el tipo de una columna analizando sus valores.
    function detectarTipo(filas, idx) {
        var hayDato = false, todoNum = true, todoFecha = true;
        for (var i = 0; i < filas.length; i++) {
            var celdas = filas[i].children;
            if (idx >= celdas.length) continue;
            var td = celdas[idx];
            var raw = td.getAttribute('data-orden');
            var txt = (raw != null ? raw : td.textContent).trim();
            if (txt === '' || txt === '—' || txt === '-') continue;
            hayDato = true;
            if (isNaN(aNumero(txt)))  todoNum = false;
            if (isNaN(aFecha(txt)))   todoFecha = false;
            if (!todoNum && !todoFecha) break;
        }
        if (!hayDato)   return 'texto';
        if (todoFecha)  return 'fecha';
        if (todoNum)    return 'texto'; // los números puros suelen ser códigos; texto es más seguro salvo que se declare data-orden-tipo="num"
        return 'texto';
    }

    function ordenarPor(tabla, th, idx) {
        var tbody = tabla.tBodies[0];
        if (!tbody) return;
        var filas = Array.prototype.slice.call(tbody.rows).filter(function (r) {
            return !r.hasAttribute('data-no-orden') && r.children.length > idx;
        });
        if (filas.length < 2) return;

        var tipo = th.getAttribute('data-orden-tipo') || detectarTipo(filas, idx);
        var dir = th.getAttribute('data-orden-dir') === 'asc' ? 'desc' : 'asc';
        var factor = dir === 'asc' ? 1 : -1;

        // Guardar orden original para estabilidad
        filas.forEach(function (f, i) { f._idxOrig = i; });

        filas.sort(function (a, b) {
            var va = valorCelda(a.children[idx], tipo);
            var vb = valorCelda(b.children[idx], tipo);
            var cmp;
            if (tipo === 'texto') {
                // vacíos al final
                if (va === '' && vb !== '') return 1;
                if (vb === '' && va !== '') return -1;
                cmp = va.localeCompare(vb, 'es', { numeric: true, sensitivity: 'base' });
            } else {
                var na = isNaN(va), nb = isNaN(vb);
                if (na && nb) cmp = 0;
                else if (na) return 1;   // NaN/vacío siempre al final
                else if (nb) return -1;
                else cmp = va - vb;
            }
            if (cmp === 0) return a._idxOrig - b._idxOrig;
            return cmp * factor;
        });

        filas.forEach(function (f) { tbody.appendChild(f); });

        // Estado e indicadores
        var ths = th.parentNode.children;
        for (var i = 0; i < ths.length; i++) {
            ths[i].removeAttribute('data-orden-dir');
            var ind = ths[i].querySelector('.orden-ind');
            if (ind) ind.textContent = '';
        }
        th.setAttribute('data-orden-dir', dir);
        var indic = th.querySelector('.orden-ind');
        if (indic) indic.textContent = dir === 'asc' ? ' ▲' : ' ▼';
    }

    function prepararTabla(tabla) {
        if (tabla._ordenListo) return;
        tabla._ordenListo = true;
        var thead = tabla.tHead;
        if (!thead || !thead.rows.length) return;
        var fila = thead.rows[0];
        Array.prototype.forEach.call(fila.children, function (th, idx) {
            if (th.hasAttribute('data-no-orden')) return;
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';
            th.title = 'Ordenar por esta columna';
            if (!th.querySelector('.orden-ind')) {
                var span = document.createElement('span');
                span.className = 'orden-ind';
                span.style.opacity = '0.7';
                span.style.fontSize = '0.75em';
                th.appendChild(span);
            }
            th.addEventListener('click', function () { ordenarPor(tabla, th, idx); });
        });
    }

    function init(raiz) {
        var ctx = raiz || document;
        var tablas = ctx.querySelectorAll('table.js-tabla-orden');
        Array.prototype.forEach.call(tablas, prepararTabla);
    }

    window.TablaOrden = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
})();
