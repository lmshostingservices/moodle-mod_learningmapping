define(['jquery'], function($) {
    return {
        init: function(mappingId, courseId, unitCode, savedDataStr, canManage, sesskey) {

            var ajaxUrl = M.cfg.wwwroot + '/mod/learningmapping/ajax.php';
            var state = {
                unitCode: unitCode || '',
                unitData: null,
                rows: [],
                columns: [],
                cells: {},
                acData: [],
                courseModules: [],
                dirty: false,
                saving: false
            };
            var lastScannedModules = null;

            var defaultColumns = [
                { id: 'col_elements', title: 'Elements & Performance Criteria', group: 'unit', locked: true, width: 260 }
            ];

            window.addEventListener('beforeunload', function(e) {
                if (state.dirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            function generateId() {
                return 'id_' + Math.random().toString(36).substring(2, 10) + Date.now().toString(36);
            }

            function loadSavedData() {
                try {
                    var saved = typeof savedDataStr === 'string' ? JSON.parse(savedDataStr) : savedDataStr;
                    if (saved && saved.rows && saved.columns) {
                        state.rows = saved.rows;
                        state.columns = saved.columns;
                        state.cells = saved.cells || {};
                        state.unitData = saved.unitData || null;

                        if (saved.acData && Array.isArray(saved.acData)) {
                            state.acData = saved.acData;
                        } else {
                            // Backward compat: migrate AC rows out of state.rows into state.acData
                            var acHeadingId = null;
                            state.rows.forEach(function(r) {
                                if (r.isElement && r.isShaded) {
                                    var t = (r.title || '').toLowerCase().trim();
                                    if (t === 'assessment conditions') { acHeadingId = r.id; }
                                }
                            });
                            if (acHeadingId) {
                                var acConditions = [];
                                state.rows.forEach(function(r) {
                                    if (r.parentId === acHeadingId) {
                                        acConditions.push({
                                            id: r.id,
                                            text: r.title || '',
                                            addressed: '',
                                            evidence: '',
                                            status: '',
                                            notes: ''
                                        });
                                    }
                                });
                                state.acData = acConditions;
                                var removeIds = [acHeadingId].concat(acConditions.map(function(c) { return c.id; }));
                                state.rows = state.rows.filter(function(r) {
                                    return removeIds.indexOf(r.id) === -1;
                                });
                            } else {
                                state.acData = [];
                            }
                        }
                        return true;
                    }
                } catch (e) { /* ignore */ }
                return false;
            }

            function initDefaultState() {
                state.columns = JSON.parse(JSON.stringify(defaultColumns));
                state.rows = [];
                state.cells = {};
            }

            function getCellKey(rowId, colId) {
                return rowId + '::' + colId;
            }

            function getCellValue(rowId, colId) {
                return state.cells[getCellKey(rowId, colId)] || '';
            }

            function setCellValue(rowId, colId, value) {
                state.cells[getCellKey(rowId, colId)] = value;
                state.dirty = true;
            }

            function addRow(title, parentId) {
                var row = {
                    id: generateId(),
                    title: title || '',
                    parentId: parentId || null,
                    isElement: !parentId,
                    isCustom: true
                };
                if (parentId) {
                    var insertIdx = -1;
                    for (var i = 0; i < state.rows.length; i++) {
                        if (state.rows[i].id === parentId || state.rows[i].parentId === parentId) {
                            insertIdx = i;
                        }
                    }
                    state.rows.splice(insertIdx + 1, 0, row);
                } else {
                    state.rows.push(row);
                }
                state.dirty = true;
                return row;
            }

            function insertRowBelow(afterRowId) {
                var afterIdx = -1;
                var afterRow = null;
                for (var i = 0; i < state.rows.length; i++) {
                    if (state.rows[i].id === afterRowId) {
                        afterIdx = i;
                        afterRow = state.rows[i];
                        break;
                    }
                }
                if (afterIdx === -1) return null;
                if (afterRow.isElement) {
                    var lastChildIdx = afterIdx;
                    for (var j = afterIdx + 1; j < state.rows.length; j++) {
                        if (state.rows[j].parentId === afterRow.id) {
                            lastChildIdx = j;
                        } else {
                            break;
                        }
                    }
                    var row = {
                        id: generateId(),
                        title: '',
                        parentId: afterRow.id,
                        isElement: false,
                        isCustom: true
                    };
                    state.rows.splice(lastChildIdx + 1, 0, row);
                } else {
                    var row = {
                        id: generateId(),
                        title: '',
                        parentId: afterRow.parentId,
                        isElement: false,
                        isCustom: true
                    };
                    state.rows.splice(afterIdx + 1, 0, row);
                }
                state.dirty = true;
                return row;
            }

            function removeRow(rowId) {
                var childIds = [];
                state.rows.forEach(function(r) {
                    if (r.parentId === rowId) childIds.push(r.id);
                });
                var removeIds = [rowId].concat(childIds);
                state.rows = state.rows.filter(function(r) {
                    return removeIds.indexOf(r.id) === -1;
                });
                removeIds.forEach(function(id) {
                    state.columns.forEach(function(col) {
                        delete state.cells[getCellKey(id, col.id)];
                    });
                });
                state.dirty = true;
            }

            function addColumn(title, group) {
                var col = {
                    id: generateId(),
                    title: title || 'New Column',
                    group: group || 'custom',
                    locked: false,
                    width: 180,
                    isCustom: true
                };
                state.columns.push(col);
                state.dirty = true;
                return col;
            }

            function removeColumn(colId) {
                state.columns = state.columns.filter(function(c) { return c.id !== colId; });
                state.rows.forEach(function(r) {
                    delete state.cells[getCellKey(r.id, colId)];
                });
                state.dirty = true;
            }

            function parseUnitDataFromPaste(text) {
                var lines = text.split('\n').map(function(l) { return l.trim(); }).filter(function(l) { return l.length > 0; });
                var elements = [];
                var currentElement = null;

                var sections = { ke: [], pe: [], fs: [], ac: [] };
                var currentSection = 'elements';
                var inAssessmentRequirements = false;
                var sectionHeaders = {
                    'knowledge evidence': 'ke',
                    'required knowledge': 'ke',
                    'performance evidence': 'pe',
                    'required skills': 'pe',
                    'foundation skills': 'fs',
                    'assessment conditions': 'ac'
                };

                var stopHeaders = [
                    'modification history', 'unit sector', 'application',
                    'prerequisite', 'co-requisite', 'companion volume',
                    'links'
                ];

                var skipPatterns = [
                    /^the candidate must/i,
                    /^in the course of the above/i,
                    /^this section describes/i,
                    /^this includes access to/i,
                    /^this includes evidence/i,
                    /^evidence of the ability to/i,
                    /^including knowledge of/i,
                    /^including evidence of/i,
                    /^assessors of this unit/i,
                    /^skills in this unit must/i,
                    /^no licensing/i,
                    /^elements describe the/i,
                    /^performance criteria describe/i,
                    /^this version first released/i,
                    /^release\s*\d/i,
                    /^release\s*comments/i,
                    /^arrow_upward/i,
                    /^back to top/i,
                    /^accessibility$/i,
                    /^copyright$/i,
                    /^privacy policy$/i,
                    /^support$/i,
                    /^terms of use$/i,
                    /^acknowledgement of country/i,
                    /^we acknowledge/i,
                    /^site version/i,
                    /^skill$/i,
                    /^description$/i,
                    /^element\s+performance criteria$/i,
                    /^access to:?$/i,
                    /^assessors must/i,
                    /^workplace or simulated/i
                ];

                function shouldSkipLine(l) {
                    for (var i = 0; i < skipPatterns.length; i++) {
                        if (skipPatterns[i].test(l)) return true;
                    }
                    return false;
                }

                lines.forEach(function(line) {
                    var lowerLine = line.toLowerCase().replace(/[:\- -  - ]/g, '').trim();

                    if (lowerLine === 'assessment requirements') {
                        inAssessmentRequirements = true;
                        currentSection = null;
                        return;
                    }

                    var isStopHeader = false;
                    for (var i = 0; i < stopHeaders.length; i++) {
                        if (lowerLine === stopHeaders[i] || lowerLine.indexOf(stopHeaders[i]) === 0) {
                            isStopHeader = true;
                            break;
                        }
                    }
                    if (isStopHeader) {
                        currentSection = null;
                        return;
                    }

                    var matchedSection = null;
                    for (var header in sectionHeaders) {
                        if (lowerLine === header || lowerLine.indexOf(header) === 0) {
                            matchedSection = sectionHeaders[header];
                            break;
                        }
                    }
                    if (matchedSection) {
                        currentSection = matchedSection;
                        return;
                    }

                    if (lowerLine === 'elements and performance criteria' ||
                        lowerLine === 'elements & performance criteria' ||
                        lowerLine === 'element' || lowerLine === 'elements') {
                        currentSection = 'elements';
                        return;
                    }

                    if (currentSection === null) return;
                    if (shouldSkipLine(line)) return;

                    if (currentSection === 'elements') {
                        var elementMatch = line.match(/^(?:Element\s+)?(\d+)\s*[-:.]\s*(.+)/i);
                        var pcMatch = line.match(/^(\d+\.\d+)\s+(.+)/);

                        if (elementMatch && !pcMatch) {
                            currentElement = {
                                number: elementMatch[1],
                                title: elementMatch[2].trim(),
                                pcs: [],
                                pcCounter: 0
                            };
                            elements.push(currentElement);
                        } else if (pcMatch && currentElement) {
                            currentElement.pcs.push({
                                code: pcMatch[1],
                                text: pcMatch[2].trim()
                            });
                            currentElement.pcCounter++;
                        } else if (currentElement && line.length > 3 && !elementMatch) {
                            currentElement.pcCounter++;
                            currentElement.pcs.push({
                                code: currentElement.number + '.' + currentElement.pcCounter,
                                text: line.trim()
                            });
                        }
                    } else if (sections[currentSection] !== undefined) {
                        var cleanLine = line.replace(/^[\u2022\u2023\u25E6\u2043\u2219****\- -  - *]\s*/, '').trim();
                        if (cleanLine.length > 2) {
                            sections[currentSection].push(cleanLine);
                        }
                    }
                });

                return { elements: elements, sections: sections };
            }

            function buildRowsFromUnitData(elements, sections) {
                state.rows = [];
                elements.forEach(function(el) {
                    var elementRow = {
                        id: generateId(),
                        title: 'Element ' + el.number + ': ' + el.title,
                        parentId: null,
                        isElement: true,
                        isCustom: false
                    };
                    state.rows.push(elementRow);

                    el.pcs.forEach(function(pc) {
                        state.rows.push({
                            id: generateId(),
                            title: (pc.code ? pc.code + ' ' : '') + pc.text,
                            parentId: elementRow.id,
                            isElement: false,
                            isCustom: false
                        });
                    });
                });

                var sectionLabels = {
                    ke: 'Knowledge Evidence',
                    pe: 'Performance Evidence',
                    fs: 'Foundation Skills'
                };
                if (sections) {
                    ['ke', 'pe', 'fs'].forEach(function(key) {
                        if (sections[key] && sections[key].length > 0) {
                            var headingRow = {
                                id: generateId(),
                                title: sectionLabels[key],
                                parentId: null,
                                isElement: true,
                                isCustom: false,
                                isShaded: true
                            };
                            state.rows.push(headingRow);

                            sections[key].forEach(function(item) {
                                state.rows.push({
                                    id: generateId(),
                                    title: item,
                                    parentId: headingRow.id,
                                    isElement: false,
                                    isCustom: false
                                });
                            });
                        }
                    });

                    // AC conditions go into their own compliance checklist  -  NOT the main mapping table
                    if (sections.ac && sections.ac.length > 0) {
                        state.acData = sections.ac.map(function(item) {
                            return {
                                id: generateId(),
                                text: item,
                                addressed: '',
                                evidence: '',
                                status: '',
                                notes: ''
                            };
                        });
                    }
                }

                state.dirty = true;
            }

            function save(callback) {
                if (state.saving) return;
                state.saving = true;
                updateSaveButton();

                var payload = {
                    action: 'save',
                    mappingid: mappingId,
                    sesskey: sesskey,
                    unitCode: state.unitCode || '',
                    mappingdata: {
                        rows: state.rows,
                        columns: state.columns,
                        cells: state.cells,
                        unitData: state.unitData,
                        acData: state.acData
                    }
                };

                $.ajax({
                    url: ajaxUrl + '?action=save&mappingid=' + mappingId + '&sesskey=' + sesskey,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    success: function(resp) {
                        if (resp && (resp.success || resp.ok)) {
                            state.dirty = false;
                            state.saving = false;
                            updateSaveButton();
                            showToast('Mapping saved successfully', 'success');
                            if (callback) callback();
                        } else {
                            state.saving = false;
                            updateSaveButton();
                            showToast('Save failed: ' + (resp && resp.error ? resp.error : 'unknown error'), 'error');
                        }
                    },
                    error: function() {
                        state.saving = false;
                        updateSaveButton();
                        showToast('Failed to save mapping', 'error');
                    }
                });
            }

            function scanCourse(callback, errorCallback) {
                $.ajax({
                    url: ajaxUrl,
                    method: 'GET',
                    dataType: 'json',
                    data: { action: 'scancourse', mappingid: mappingId, sesskey: sesskey },
                    success: function(resp) {
                        if (typeof resp === 'string') {
                            try { resp = JSON.parse(resp); } catch(e) {
                                showToast('Invalid scan response from server', 'error');
                                if (errorCallback) errorCallback();
                                return;
                            }
                        }
                        if (resp && resp.modules) {
                            state.courseModules = resp.modules;
                            if (callback) callback(resp.modules);
                        } else if (resp && resp.error) {
                            showToast(resp.error, 'error');
                            if (errorCallback) errorCallback();
                        } else {
                            showToast('Scan returned no data', 'error');
                            if (errorCallback) errorCallback();
                        }
                    },
                    error: function(xhr) {
                        var msg = 'Failed to scan course modules';
                        try {
                            var r = JSON.parse(xhr.responseText);
                            if (r && r.error) msg = r.error;
                        } catch(e) {
                            if (xhr.status === 403) msg = 'Access denied  -  you may need to log in again';
                            else if (xhr.status === 0) msg = 'Network error  -  check your connection';
                            else msg = 'Scan failed (HTTP ' + xhr.status + ')';
                        }
                        showToast(msg, 'error');
                        if (errorCallback) errorCallback();
                    }
                });
            }

            function showToast(message, type) {
                var toast = document.createElement('div');
                toast.className = 'lm-toast lm-toast-' + (type || 'info');
                toast.textContent = message;
                document.body.appendChild(toast);
                setTimeout(function() { toast.classList.add('lm-toast-show'); }, 10);
                setTimeout(function() {
                    toast.classList.remove('lm-toast-show');
                    setTimeout(function() { toast.remove(); }, 300);
                }, 3000);
            }

            function showConfirm(message, opts) {
                opts = opts || {};
                var title = opts.title || 'Confirm';
                var confirmText = opts.confirmText || 'Confirm';
                var cancelText = opts.cancelText || 'Cancel';
                var type = opts.type || 'default';
                var iconSvg = '';
                if (type === 'danger') {
                    iconSvg = '<svg class="lm-confirm-icon lm-confirm-icon-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
                } else if (type === 'warning') {
                    iconSvg = '<svg class="lm-confirm-icon lm-confirm-icon-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
                } else {
                    iconSvg = '<svg class="lm-confirm-icon lm-confirm-icon-info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
                }
                var btnClass = type === 'danger' ? 'lm-btn-danger' : 'lm-btn-primary';
                var messageHtml = message.replace(/\n/g, '<br>');

                return new Promise(function(resolve) {
                    var overlay = document.createElement('div');
                    overlay.className = 'lm-modal-overlay lm-confirm-overlay';
                    overlay.innerHTML =
                        '<div class="lm-modal lm-confirm-modal">' +
                        '<div class="lm-modal-header">' +
                        '<div class="lm-confirm-header-content">' + iconSvg + '<h3>' + title + '</h3></div>' +
                        '<button type="button" class="lm-modal-close">&times;</button>' +
                        '</div>' +
                        '<div class="lm-modal-body lm-confirm-body">' +
                        '<p class="lm-confirm-message">' + messageHtml + '</p>' +
                        '</div>' +
                        '<div class="lm-modal-footer">' +
                        '<button type="button" class="lm-btn lm-btn-secondary lm-confirm-cancel-btn">' + cancelText + '</button>' +
                        '<button type="button" class="lm-btn ' + btnClass + ' lm-confirm-ok-btn">' + confirmText + '</button>' +
                        '</div>' +
                        '</div>';

                    document.body.appendChild(overlay);
                    setTimeout(function() { overlay.classList.add('lm-modal-show'); }, 10);

                    function close(result) {
                        overlay.classList.remove('lm-modal-show');
                        setTimeout(function() { overlay.remove(); }, 200);
                        resolve(result);
                    }

                    $(overlay).on('click', '.lm-modal-close, .lm-confirm-cancel-btn', function() { close(false); });
                    $(overlay).on('click', '.lm-confirm-ok-btn', function() { close(true); });
                    $(overlay).on('click', function(e) {
                        if (e.target === overlay) close(false);
                    });
                    overlay.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') close(false);
                        if (e.key === 'Enter') close(true);
                    });
                    overlay.setAttribute('tabindex', '-1');
                    overlay.focus();
                });
            }

            function updateSaveButton() {
                var btn = document.getElementById('lm-save-btn');
                if (!btn) return;
                if (state.saving) {
                    btn.textContent = 'Saving...';
                    btn.disabled = true;
                } else if (state.dirty) {
                    btn.textContent = 'Save Mapping';
                    btn.disabled = false;
                    btn.classList.add('lm-btn-pulse');
                } else {
                    btn.textContent = 'Saved';
                    btn.disabled = true;
                    btn.classList.remove('lm-btn-pulse');
                }
            }

            function escapeHtml(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str || ''));
                return div.innerHTML;
            }

            function getGroupLabel(group) {
                switch (group) {
                    case 'unit': return 'Unit of Competency';
                    case 'learning': return 'Learning';
                    case 'assessment': return 'Assessment';
                    case 'custom': return 'Custom';
                    default: return group || 'Other';
                }
            }

            function getGroupColor(group) {
                switch (group) {
                    case 'unit': return '#6366f1';
                    case 'learning': return '#10b981';
                    case 'assessment': return '#f59e0b';
                    case 'custom': return '#8b5cf6';
                    default: return '#6b7280';
                }
            }

            function buildColGroups() {
                var groups = [];
                var currentGroup = null;
                state.columns.forEach(function(col) {
                    if (!currentGroup || currentGroup.group !== col.group) {
                        currentGroup = { group: col.group, columns: [], span: 0 };
                        groups.push(currentGroup);
                    }
                    currentGroup.columns.push(col);
                    currentGroup.span++;
                });
                return groups;
            }

            function renderTable() {
                var container = document.getElementById('lm-table-wrap');
                if (!container) return;

                var colGroups = buildColGroups();

                var html = '<div class="lm-table-scroll">';
                html += '<table class="lm-table" id="lm-mapping-table">';

                html += '<colgroup>';
                html += '<col class="lm-col-rownum" style="width:40px;">';
                state.columns.forEach(function(col) {
                    html += '<col style="width:' + (col.width || 180) + 'px;">';
                });
                html += '<col class="lm-col-actions" style="width:50px;">';
                html += '</colgroup>';

                html += '<thead>';
                html += '<tr class="lm-group-header-row">';
                html += '<th class="lm-th-corner" rowspan="2"></th>';
                colGroups.forEach(function(g) {
                    var color = getGroupColor(g.group);
                    html += '<th colspan="' + g.span + '" class="lm-group-header" style="border-bottom:3px solid ' + color + ';">';
                    html += '<span class="lm-group-dot" style="background:' + color + ';"></span>';
                    html += escapeHtml(getGroupLabel(g.group));
                    html += '</th>';
                });
                html += '<th rowspan="2" class="lm-th-add-col">';
                if (canManage) {
                    html += '<button type="button" class="lm-icon-btn lm-add-col-btn" title="Add column" data-testid="button-add-column">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
                    html += '</button>';
                }
                html += '</th>';
                html += '</tr>';

                html += '<tr class="lm-col-header-row">';
                state.columns.forEach(function(col) {
                    var color = getGroupColor(col.group);
                    html += '<th class="lm-col-header" data-col-id="' + col.id + '">';
                    html += '<span class="lm-col-title">' + escapeHtml(col.title) + '</span>';
                    if (canManage) {
                        html += '<button type="button" class="lm-col-remove-btn" data-col-id="' + col.id + '" title="Remove column">';
                        html += '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                        html += '</button>';
                    }
                    html += '</th>';
                });
                html += '</tr>';
                html += '</thead>';

                html += '<tbody>';
                if (state.rows.length === 0) {
                    html += '<tr class="lm-empty-row"><td colspan="' + (state.columns.length + 2) + '">';
                    html += '<div class="lm-empty-state">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.3;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>';
                    html += '<p>No mapping rows yet</p>';
                    html += '<p class="lm-empty-hint">Paste TGA unit data above or add rows manually</p>';
                    html += '</div>';
                    html += '</td></tr>';
                }

                var rowNum = 0;
                state.rows.forEach(function(row) {
                    rowNum++;
                    var isElement = row.isElement;
                    var rowClass = isElement ? 'lm-element-row' : 'lm-pc-row';
                    if (row.isShaded) rowClass += ' lm-shaded-row';

                    html += '<tr class="' + rowClass + '" data-row-id="' + row.id + '">';
                    html += '<td class="lm-rownum">' + rowNum + '</td>';

                    state.columns.forEach(function(col, colIdx) {
                        var cellVal = getCellValue(row.id, col.id);
                        var isFirstCol = colIdx === 0;
                        var cellClass = 'lm-cell';
                        if (isFirstCol) cellClass += isElement ? ' lm-cell-element' : ' lm-cell-pc';

                        if (isFirstCol && !cellVal) {
                            cellVal = row.title || '';
                        }

                        html += '<td class="' + cellClass + '" data-row-id="' + row.id + '" data-col-id="' + col.id + '">';
                        if (canManage) {
                            html += '<div class="lm-cell-edit" contenteditable="true" data-row-id="' + row.id + '" data-col-id="' + col.id + '" data-testid="cell-' + row.id + '-' + col.id + '">' + escapeHtml(cellVal) + '</div>';
                        } else {
                            html += '<div class="lm-cell-view">' + escapeHtml(cellVal) + '</div>';
                        }
                        html += '</td>';
                    });

                    html += '<td class="lm-row-actions">';
                    if (canManage) {
                        html += '<button type="button" class="lm-icon-btn lm-insert-row-btn" data-row-id="' + row.id + '" title="Insert row below">';
                        html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
                        html += '</button>';
                        var shadeIcon = row.isShaded
                            ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>'
                            : '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>';
                        html += '<button type="button" class="lm-icon-btn lm-shade-row-btn' + (row.isShaded ? ' lm-shade-active' : '') + '" data-row-id="' + row.id + '" title="' + (row.isShaded ? 'Remove heading shade' : 'Shade as heading row') + '">';
                        html += shadeIcon;
                        html += '</button>';
                        if (row.isCustom) {
                            html += '<button type="button" class="lm-icon-btn lm-icon-btn-danger lm-remove-row-btn" data-row-id="' + row.id + '" title="Remove row">';
                            html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                            html += '</button>';
                        }
                    }
                    html += '</td>';
                    html += '</tr>';
                });
                html += '</tbody>';

                if (canManage) {
                    html += '<tfoot>';
                    html += '<tr class="lm-add-row-tr">';
                    html += '<td colspan="' + (state.columns.length + 2) + '">';
                    html += '<div class="lm-add-row-bar">';
                    html += '<button type="button" class="lm-btn lm-btn-ghost lm-add-element-btn" data-testid="button-add-element-row">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
                    html += ' Add Element Row';
                    html += '</button>';
                    html += '<button type="button" class="lm-btn lm-btn-ghost lm-add-generic-row-btn" data-testid="button-add-row">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
                    html += ' Add Row';
                    html += '</button>';
                    html += '</div>';
                    html += '</td>';
                    html += '</tr>';
                    html += '</tfoot>';
                }

                html += '</table>';
                html += '</div>';

                container.innerHTML = html;
                bindTableEvents();
            }

            function bindTableEvents() {
                var table = document.getElementById('lm-mapping-table');
                if (!table) return;

                $(table).on('blur', '.lm-cell-edit', function() {
                    var el = this;
                    var rowId = el.getAttribute('data-row-id');
                    var colId = el.getAttribute('data-col-id');
                    var newVal = el.textContent.trim();
                    setCellValue(rowId, colId, newVal);
                    updateSaveButton();
                    updateStats();
                });

                $(table).on('keydown', '.lm-cell-edit', function(e) {
                    if (e.key === 'Tab') {
                        e.preventDefault();
                        var allCells = $(table).find('.lm-cell-edit').toArray();
                        var idx = allCells.indexOf(this);
                        var nextIdx = e.shiftKey ? idx - 1 : idx + 1;
                        if (nextIdx >= 0 && nextIdx < allCells.length) {
                            this.blur();
                            allCells[nextIdx].focus();
                        }
                    }
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.blur();
                    }
                });

                $(table).on('click', '.lm-shade-row-btn', function() {
                    var rowId = this.getAttribute('data-row-id');
                    for (var i = 0; i < state.rows.length; i++) {
                        if (state.rows[i].id === rowId) {
                            state.rows[i].isShaded = !state.rows[i].isShaded;
                            break;
                        }
                    }
                    state.dirty = true;
                    renderTable();
                    updateSaveButton();
                });

                $(table).on('click', '.lm-insert-row-btn', function() {
                    var rowId = this.getAttribute('data-row-id');
                    var newRow = insertRowBelow(rowId);
                    if (newRow) {
                        renderTable();
                        updateSaveButton();
                        var newCell = $(table).find('.lm-cell-edit[data-row-id="' + newRow.id + '"]').first();
                        if (newCell.length) newCell.focus();
                    }
                });

                $(table).on('click', '.lm-add-pc-btn', function() {
                    var parentId = this.getAttribute('data-parent-id');
                    addRow('', parentId);
                    renderTable();
                    updateSaveButton();
                });

                $(table).on('click', '.lm-remove-row-btn', function() {
                    var rowId = this.getAttribute('data-row-id');
                    showConfirm('Remove this row and all its sub-rows?', {
                        title: 'Remove Row',
                        confirmText: 'Remove',
                        type: 'danger'
                    }).then(function(ok) {
                        if (ok) {
                            removeRow(rowId);
                            renderTable();
                            updateSaveButton();
                        }
                    });
                });

                $(table).on('click', '.lm-add-element-btn', function() {
                    addRow('New Element', null);
                    renderTable();
                    updateSaveButton();
                    var lastRow = $(table).find('tbody tr:last');
                    if (lastRow.length) {
                        var firstCell = lastRow.find('.lm-cell-edit').first();
                        if (firstCell.length) firstCell.focus();
                    }
                });

                $(table).on('click', '.lm-add-generic-row-btn', function() {
                    var lastElement = null;
                    for (var i = state.rows.length - 1; i >= 0; i--) {
                        if (state.rows[i].isElement) { lastElement = state.rows[i]; break; }
                    }
                    if (lastElement) {
                        addRow('', lastElement.id);
                    } else {
                        addRow('New Row', null);
                    }
                    renderTable();
                    updateSaveButton();
                });

                $(table).on('click', '.lm-add-col-btn', function() {
                    showAddColumnDialog();
                });

                $(table).on('click', '.lm-col-remove-btn', function() {
                    var colId = this.getAttribute('data-col-id');
                    var col = state.columns.find(function(c) { return c.id === colId; });
                    if (!col) return;
                    if (state.columns.length <= 1) {
                        showToast('Cannot remove the last column', 'error');
                        return;
                    }
                    showConfirm('Remove column "' + col.title + '" and all its data?', {
                        title: 'Remove Column',
                        confirmText: 'Remove',
                        type: 'danger'
                    }).then(function(ok) {
                        if (ok) {
                            removeColumn(colId);
                            renderTable();
                            updateSaveButton();
                        }
                    });
                });
            }

            function showAddColumnDialog() {
                var overlay = document.createElement('div');
                overlay.className = 'lm-modal-overlay';
                overlay.innerHTML =
                    '<div class="lm-modal">' +
                    '<div class="lm-modal-header">' +
                    '<h3>Add Column</h3>' +
                    '<button type="button" class="lm-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="lm-modal-body">' +
                    '<label class="lm-label">Column Title</label>' +
                    '<input type="text" class="lm-input" id="lm-new-col-title" placeholder="e.g. Workplace Assessment" data-testid="input-new-col-title">' +
                    '<label class="lm-label" style="margin-top:12px;">Group</label>' +
                    '<select class="lm-select" id="lm-new-col-group" data-testid="select-new-col-group">' +
                    '<option value="learning">Learning</option>' +
                    '<option value="assessment">Assessment</option>' +
                    '<option value="custom" selected>Custom</option>' +
                    '</select>' +
                    '</div>' +
                    '<div class="lm-modal-footer">' +
                    '<button type="button" class="lm-btn lm-btn-secondary lm-modal-cancel">Cancel</button>' +
                    '<button type="button" class="lm-btn lm-btn-primary" id="lm-confirm-add-col" data-testid="button-confirm-add-column">Add Column</button>' +
                    '</div>' +
                    '</div>';

                document.body.appendChild(overlay);
                setTimeout(function() { overlay.classList.add('lm-modal-show'); }, 10);

                var titleInput = document.getElementById('lm-new-col-title');
                titleInput.focus();

                function close() {
                    overlay.classList.remove('lm-modal-show');
                    setTimeout(function() { overlay.remove(); }, 200);
                }

                $(overlay).on('click', '.lm-modal-close, .lm-modal-cancel', close);
                $(overlay).on('click', function(e) {
                    if (e.target === overlay) close();
                });

                $('#lm-confirm-add-col').on('click', function() {
                    var title = titleInput.value.trim();
                    var group = document.getElementById('lm-new-col-group').value;
                    if (!title) {
                        titleInput.style.borderColor = '#ef4444';
                        titleInput.focus();
                        return;
                    }
                    addColumn(title, group);
                    close();
                    renderTable();
                    updateSaveButton();
                    showToast('Column "' + title + '" added', 'success');
                });

                titleInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        document.getElementById('lm-confirm-add-col').click();
                    }
                });
            }

            function exportCSV() {
                var rows = [];
                var headerRow = ['#'];
                state.columns.forEach(function(col) { headerRow.push(col.title); });
                rows.push(headerRow);

                state.rows.forEach(function(row, idx) {
                    var r = [idx + 1];
                    state.columns.forEach(function(col, colIdx) {
                        var val = getCellValue(row.id, col.id);
                        if (colIdx === 0 && !val) val = row.title || '';
                        r.push(val);
                    });
                    rows.push(r);
                });

                // Add AC compliance section
                if (state.acData && state.acData.length > 0) {
                    rows.push([]);
                    rows.push(['ASSESSMENT CONDITIONS COMPLIANCE CHECKLIST']);
                    rows.push(['#', 'Assessment Condition', 'How Addressed', 'Evidence / Resources', 'Compliance Status', 'Notes']);
                    var statusLabels = { met: 'Met', partial: 'Partially Met', not_met: 'Not Met', '': 'Not Yet Assessed' };
                    state.acData.forEach(function(ac, idx) {
                        rows.push([
                            idx + 1,
                            ac.text || '',
                            ac.addressed || '',
                            ac.evidence || '',
                            statusLabels[ac.status] || 'Not Yet Assessed',
                            ac.notes || ''
                        ]);
                    });
                }

                var csv = '\uFEFF';
                rows.forEach(function(row) {
                    csv += row.map(function(cell) {
                        var s = String(cell).replace(/"/g, '""');
                        return '"' + s + '"';
                    }).join(',') + '\r\n';
                });

                var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'learning_assessment_mapping' + (state.unitCode ? '_' + state.unitCode : '') + '.csv';
                a.click();
                URL.revokeObjectURL(url);
                showToast('CSV exported', 'success');
            }

            function exportPrintPdf() {
                var colGroups = buildColGroups();
                var printHtml = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
                printHtml += '<title>AI Mapping' + (state.unitCode ? ' - ' + state.unitCode : '') + '</title>';
                printHtml += '<style>';
                printHtml += 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:20px;color:#1f2937;font-size:11px;}';
                printHtml += 'h1{font-size:18px;margin-bottom:4px;}';
                printHtml += 'h2{font-size:13px;font-weight:400;color:#6b7280;margin-bottom:16px;}';
                printHtml += 'table{width:100%;border-collapse:collapse;table-layout:fixed;}';
                printHtml += 'th,td{border:1px solid #d1d5db;padding:6px 8px;text-align:left;vertical-align:top;word-wrap:break-word;}';
                printHtml += '.group-header{background:#f3f4f6;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:0.5px;text-align:center;}';
                printHtml += '.col-header{background:#f9fafb;font-weight:600;font-size:10px;}';
                printHtml += '.element-row{background:#eef2ff;font-weight:600;}';
                printHtml += '.pc-row td:first-child{padding-left:16px;}';
                printHtml += '.shaded-row{background:#e2e8f0!important;font-weight:600;color:#1e293b;}';
                printHtml += '.footer{margin-top:12px;font-size:9px;color:#9ca3af;}';
                printHtml += '@media print{body{margin:10px;}@page{size:landscape;margin:10mm;}}';
                printHtml += '</style></head><body>';
                printHtml += '<h1>AI Mapping</h1>';
                if (state.unitCode) printHtml += '<h2>' + escapeHtml(state.unitCode) + '</h2>';
                printHtml += '<table>';

                printHtml += '<tr>';
                printHtml += '<th class="group-header" rowspan="2" style="width:30px;">#</th>';
                colGroups.forEach(function(g) {
                    var color = getGroupColor(g.group);
                    printHtml += '<th class="group-header" colspan="' + g.span + '" style="border-bottom:3px solid ' + color + ';">' + escapeHtml(getGroupLabel(g.group)) + '</th>';
                });
                printHtml += '</tr><tr>';
                state.columns.forEach(function(col) {
                    printHtml += '<th class="col-header">' + escapeHtml(col.title) + '</th>';
                });
                printHtml += '</tr>';

                state.rows.forEach(function(row, idx) {
                    var cls = row.isElement ? 'element-row' : 'pc-row';
                    if (row.isShaded) cls += ' shaded-row';
                    printHtml += '<tr class="' + cls + '">';
                    printHtml += '<td>' + (idx + 1) + '</td>';
                    state.columns.forEach(function(col, ci) {
                        var val = getCellValue(row.id, col.id);
                        if (ci === 0 && !val) val = row.title || '';
                        printHtml += '<td>' + escapeHtml(val) + '</td>';
                    });
                    printHtml += '</tr>';
                });

                printHtml += '</table>';

                // AC compliance section for PDF
                if (state.acData && state.acData.length > 0) {
                    printHtml += '<h2 style="margin-top:24px;font-size:14px;font-weight:700;color:#1e293b;border-bottom:2px solid #6366f1;padding-bottom:4px;">Assessment Conditions  -  Compliance Checklist</h2>';
                    printHtml += '<p style="font-size:10px;color:#6b7280;margin-bottom:8px;">Assessment Conditions are system-level compliance requirements that apply to the overall assessment design  -  they are not mapped to individual activities.</p>';
                    printHtml += '<table style="margin-top:8px;">';
                    printHtml += '<tr><th style="width:30px;background:#eef2ff;">#</th><th style="background:#eef2ff;">Assessment Condition</th><th style="background:#eef2ff;width:18%;">How Addressed</th><th style="background:#eef2ff;width:18%;">Evidence / Resources</th><th style="background:#eef2ff;width:100px;">Status</th><th style="background:#eef2ff;width:15%;">Notes</th></tr>';
                    var statusLabels = { met: 'Met', partial: 'Partially Met', not_met: 'Not Met', '': 'Not Yet Assessed' };
                    var statusColors = { met: '#166534', partial: '#92400e', not_met: '#991b1b', '': '#374151' };
                    var statusBgs = { met: '#dcfce7', partial: '#fef9c3', not_met: '#fee2e2', '': '#f3f4f6' };
                    state.acData.forEach(function(ac, idx) {
                        var s = ac.status || '';
                        printHtml += '<tr>';
                        printHtml += '<td style="text-align:center;">' + (idx + 1) + '</td>';
                        printHtml += '<td>' + escapeHtml(ac.text || '') + '</td>';
                        printHtml += '<td>' + escapeHtml(ac.addressed || '') + '</td>';
                        printHtml += '<td>' + escapeHtml(ac.evidence || '') + '</td>';
                        printHtml += '<td style="text-align:center;background:' + (statusBgs[s] || '#f3f4f6') + ';color:' + (statusColors[s] || '#374151') + ';font-weight:600;font-size:9px;">' + escapeHtml(statusLabels[s] || 'Not Yet Assessed') + '</td>';
                        printHtml += '<td>' + escapeHtml(ac.notes || '') + '</td>';
                        printHtml += '</tr>';
                    });
                    printHtml += '</table>';
                }

                printHtml += '<div class="footer">Generated ' + new Date().toLocaleDateString('en-AU') + ' | raiderai.app</div>';
                printHtml += '</body></html>';

                var win = window.open('', '_blank');
                if (win) {
                    win.document.write(printHtml);
                    win.document.close();
                    setTimeout(function() { win.print(); }, 400);
                }
            }

            function renderACTable() {
                var container = document.getElementById('lm-ac-wrap');
                if (!container) return;

                var addIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
                var removeIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

                var metCount = 0, partialCount = 0, notMetCount = 0;
                if (state.acData) {
                    state.acData.forEach(function(ac) {
                        if (ac.status === 'met') metCount++;
                        else if (ac.status === 'partial') partialCount++;
                        else if (ac.status === 'not_met') notMetCount++;
                    });
                }
                var acCount = state.acData ? state.acData.length : 0;

                var html = '<div class="lm-ac-section">';
                html += '<div class="lm-ac-header">';
                html += '<div class="lm-ac-header-left">';
                html += '<h3 class="lm-ac-title">Assessment Conditions  -  Compliance Checklist</h3>';
                html += '<p class="lm-ac-subtitle">System-level compliance requirements that apply to the overall assessment design. These are not mapped to individual activities.</p>';
                html += '</div>';
                if (acCount > 0) {
                    html += '<div class="lm-ac-header-right">';
                    html += '<div class="lm-ac-badges">';
                    html += '<span class="lm-ac-badge lm-ac-badge-met">' + metCount + ' Met</span>';
                    html += '<span class="lm-ac-badge lm-ac-badge-partial">' + partialCount + ' Partial</span>';
                    html += '<span class="lm-ac-badge lm-ac-badge-notmet">' + notMetCount + ' Not Met</span>';
                    html += '<span class="lm-ac-badge lm-ac-badge-pending">' + (acCount - metCount - partialCount - notMetCount) + ' Pending</span>';
                    html += '</div>';
                    if (canManage && lastScannedModules && lastScannedModules.length > 0) {
                        html += '<button type="button" class="lm-btn lm-btn-ai-action lm-ac-analyse-btn" data-testid="button-analyse-ac-conditions" title="AI analyses scanned activities and fills How Addressed and Evidence columns (30 credits)">' + aiIcon + ' AI Analyse</button>';
                    }
                    html += '</div>';
                }
                html += '</div>';

                if (!state.acData || state.acData.length === 0) {
                    html += '<div class="lm-ac-empty">';
                    html += '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.3;"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>';
                    html += '<p>No assessment conditions loaded.</p>';
                    html += '<p class="lm-ac-empty-hint">Fetch or paste TGA unit data above to auto-populate, or add conditions manually.</p>';
                    if (canManage) {
                        html += '<button type="button" class="lm-btn lm-btn-ghost lm-ac-add-btn" data-testid="button-add-ac-condition">' + addIcon + ' Add Condition</button>';
                    }
                    html += '</div>';
                } else {
                    html += '<div class="lm-ac-table-scroll">';
                    html += '<table class="lm-ac-table" id="lm-ac-table">';
                    html += '<thead>';
                    html += '<tr class="lm-ac-thead-row">';
                    html += '<th class="lm-ac-th lm-ac-th-num">#</th>';
                    html += '<th class="lm-ac-th lm-ac-th-condition">Assessment Condition</th>';
                    html += '<th class="lm-ac-th lm-ac-th-addressed">How Addressed</th>';
                    html += '<th class="lm-ac-th lm-ac-th-evidence">Evidence / Resources</th>';
                    html += '<th class="lm-ac-th lm-ac-th-status">Compliance Status</th>';
                    html += '<th class="lm-ac-th lm-ac-th-notes">Notes</th>';
                    if (canManage) { html += '<th class="lm-ac-th lm-ac-th-actions"></th>'; }
                    html += '</tr>';
                    html += '</thead>';
                    html += '<tbody>';

                    state.acData.forEach(function(ac, idx) {
                        var statusClass = '';
                        if (ac.status === 'met') statusClass = 'lm-ac-row-met';
                        else if (ac.status === 'partial') statusClass = 'lm-ac-row-partial';
                        else if (ac.status === 'not_met') statusClass = 'lm-ac-row-notmet';

                        html += '<tr class="lm-ac-row ' + statusClass + '" data-ac-id="' + ac.id + '">';
                        html += '<td class="lm-ac-td lm-ac-td-num">' + (idx + 1) + '</td>';

                        if (canManage) {
                            html += '<td class="lm-ac-td lm-ac-td-condition"><div class="lm-ac-cell-edit" contenteditable="true" data-ac-id="' + ac.id + '" data-ac-field="text" data-testid="ac-text-' + ac.id + '">' + escapeHtml(ac.text || '') + '</div></td>';
                            html += '<td class="lm-ac-td lm-ac-td-addressed"><div class="lm-ac-cell-edit" contenteditable="true" data-ac-id="' + ac.id + '" data-ac-field="addressed" data-testid="ac-addressed-' + ac.id + '" placeholder="Describe how this condition is met...">' + escapeHtml(ac.addressed || '') + '</div></td>';
                            html += '<td class="lm-ac-td lm-ac-td-evidence"><div class="lm-ac-cell-edit" contenteditable="true" data-ac-id="' + ac.id + '" data-ac-field="evidence" data-testid="ac-evidence-' + ac.id + '" placeholder="List resources, tools, documents...">' + escapeHtml(ac.evidence || '') + '</div></td>';
                            html += '<td class="lm-ac-td lm-ac-td-status">';
                            html += '<select class="lm-ac-status-select" data-ac-id="' + ac.id + '" data-testid="ac-status-' + ac.id + '">';
                            html += '<option value=""' + (!ac.status ? ' selected' : '') + '>Not Yet Assessed</option>';
                            html += '<option value="met"' + (ac.status === 'met' ? ' selected' : '') + '>Met</option>';
                            html += '<option value="partial"' + (ac.status === 'partial' ? ' selected' : '') + '>Partially Met</option>';
                            html += '<option value="not_met"' + (ac.status === 'not_met' ? ' selected' : '') + '>Not Met</option>';
                            html += '</select>';
                            html += '</td>';
                            html += '<td class="lm-ac-td lm-ac-td-notes"><div class="lm-ac-cell-edit" contenteditable="true" data-ac-id="' + ac.id + '" data-ac-field="notes" data-testid="ac-notes-' + ac.id + '">' + escapeHtml(ac.notes || '') + '</div></td>';
                            html += '<td class="lm-ac-td lm-ac-td-actions">';
                            html += '<button type="button" class="lm-icon-btn lm-icon-btn-danger lm-ac-remove-btn" data-ac-id="' + ac.id + '" title="Remove condition" data-testid="ac-remove-' + ac.id + '">' + removeIcon + '</button>';
                            html += '</td>';
                        } else {
                            html += '<td class="lm-ac-td lm-ac-td-condition"><div class="lm-ac-cell-view">' + escapeHtml(ac.text || '') + '</div></td>';
                            html += '<td class="lm-ac-td lm-ac-td-addressed"><div class="lm-ac-cell-view">' + escapeHtml(ac.addressed || '') + '</div></td>';
                            html += '<td class="lm-ac-td lm-ac-td-evidence"><div class="lm-ac-cell-view">' + escapeHtml(ac.evidence || '') + '</div></td>';
                            var statusText = { met: 'Met', partial: 'Partially Met', not_met: 'Not Met', '': 'Not Yet Assessed' }[ac.status || ''];
                            html += '<td class="lm-ac-td lm-ac-td-status"><span class="lm-ac-status-pill lm-ac-status-' + (ac.status || 'pending') + '">' + escapeHtml(statusText) + '</span></td>';
                            html += '<td class="lm-ac-td lm-ac-td-notes"><div class="lm-ac-cell-view">' + escapeHtml(ac.notes || '') + '</div></td>';
                        }

                        html += '</tr>';
                    });

                    html += '</tbody>';
                    if (canManage) {
                        html += '<tfoot>';
                        html += '<tr class="lm-ac-add-row">';
                        html += '<td colspan="7">';
                        html += '<button type="button" class="lm-btn lm-btn-ghost lm-ac-add-btn" data-testid="button-add-ac-condition">' + addIcon + ' Add Condition</button>';
                        html += '</td>';
                        html += '</tr>';
                        html += '</tfoot>';
                    }
                    html += '</table>';
                    html += '</div>';
                }

                html += '</div>';

                container.innerHTML = html;
                bindACTableEvents();
            }

            function bindACTableEvents() {
                var wrap = document.getElementById('lm-ac-wrap');
                if (!wrap) return;

                $(wrap).on('blur', '.lm-ac-cell-edit', function() {
                    var el = this;
                    var acId = el.getAttribute('data-ac-id');
                    var field = el.getAttribute('data-ac-field');
                    var newVal = el.textContent.trim();
                    for (var i = 0; i < state.acData.length; i++) {
                        if (state.acData[i].id === acId) {
                            state.acData[i][field] = newVal;
                            break;
                        }
                    }
                    state.dirty = true;
                    updateSaveButton();
                    updateStats();
                });

                $(wrap).on('change', '.lm-ac-status-select', function() {
                    var acId = this.getAttribute('data-ac-id');
                    var newStatus = this.value;
                    for (var i = 0; i < state.acData.length; i++) {
                        if (state.acData[i].id === acId) {
                            state.acData[i].status = newStatus;
                            break;
                        }
                    }
                    state.dirty = true;
                    renderACTable();
                    updateStats();
                    updateSaveButton();
                });

                $(wrap).on('click', '.lm-ac-add-btn', function() {
                    if (!state.acData) state.acData = [];
                    state.acData.push({
                        id: generateId(),
                        text: '',
                        addressed: '',
                        evidence: '',
                        status: '',
                        notes: ''
                    });
                    state.dirty = true;
                    renderACTable();
                    updateStats();
                    updateSaveButton();
                    var newRows = document.querySelectorAll('#lm-ac-table .lm-ac-cell-edit[data-ac-field="text"]');
                    if (newRows.length > 0) {
                        var lastNew = newRows[newRows.length - 1];
                        lastNew.focus();
                    }
                });

                $(wrap).on('click', '.lm-ac-remove-btn', function() {
                    var acId = this.getAttribute('data-ac-id');
                    state.acData = state.acData.filter(function(ac) { return ac.id !== acId; });
                    state.dirty = true;
                    renderACTable();
                    updateStats();
                    updateSaveButton();
                });

                $(wrap).on('click', '.lm-ac-analyse-btn', function() {
                    var btn = this;
                    btn.disabled = true;
                    btn.innerHTML = '<span class="lm-spinner" style="width:12px;height:12px;border-width:2px;margin-right:5px;"></span> Analysing...';

                    var modules = lastScannedModules || [];
                    var payload = {
                        acData: state.acData.map(function(ac) { return { id: ac.id, text: ac.text || '' }; }),
                        scannedModules: modules.map(function(m) {
                            var mod = { modname: m.modname, name: m.name, topics: m.topics || [], questions: m.questions || [], activities: m.activities || [] };
                            if (m.description) mod.description = m.description;
                            if (m.sectionName) mod.sectionName = m.sectionName;
                            if (m.scenario) mod.scenario = m.scenario;
                            if (m.skills) mod.skills = m.skills;
                            if (m.workplaceForms) mod.workplaceForms = m.workplaceForms;
                            if (m.submissionPlugins) mod.submissionPlugins = m.submissionPlugins;
                            if (m.submissionInstructions) mod.submissionInstructions = m.submissionInstructions;
                            return mod;
                        }),
                        unitCode: state.unitCode || ''
                    };

                    $.ajax({
                        url: ajaxUrl + '?action=analyseac&mappingid=' + mappingId + '&sesskey=' + sesskey,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(payload),
                        timeout: 90000,
                        success: function(resp) {
                            if (!resp || !resp.ok) {
                                showToast((resp && resp.error) || 'AI analysis of assessment conditions failed', 'error');
                                renderACTable();
                                return;
                            }
                            if (resp.results && Array.isArray(resp.results)) {
                                resp.results.forEach(function(r) {
                                    for (var i = 0; i < state.acData.length; i++) {
                                        if (state.acData[i].id === r.id) {
                                            if (r.addressed) state.acData[i].addressed = r.addressed;
                                            if (r.evidence) state.acData[i].evidence = r.evidence;
                                            if (r.status) state.acData[i].status = r.status;
                                            break;
                                        }
                                    }
                                });
                            }
                            state.dirty = true;
                            renderACTable();
                            updateSaveButton();
                            updateStats();
                            showToast('Assessment conditions analysed  -  review and save', 'success');
                        },
                        error: function(xhr) {
                            var msg = 'AI analysis failed';
                            try {
                                var r = JSON.parse(xhr.responseText);
                                if (r && r.error) msg = r.error;
                            } catch(e) {
                                if (xhr.status === 402) msg = 'Not enough credits for AI analysis (30 credits required)';
                                else if (xhr.status === 401) msg = 'Invalid API credentials  -  check Site Admin configuration';
                                else if (xhr.status === 0) msg = 'Network error  -  check your connection';
                            }
                            showToast(msg, 'error');
                            renderACTable();
                        }
                    });
                });

                $(wrap).on('keydown', '.lm-ac-cell-edit', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.blur();
                    }
                });
            }

            function renderApp() {
                var app = document.getElementById('lm-app');
                if (!app) return;

                var html = '';
                var scanIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
                var aiIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>';
                var downloadIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
                var fileIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';

                html += '<div class="lm-header">';
                html += '<div class="lm-header-top">';
                html += '<div class="lm-header-title-group">';
                html += '<h2 class="lm-title">AI Mapping</h2>';
                if (state.unitCode) {
                    html += '<span class="lm-unit-badge">' + escapeHtml(state.unitCode) + '</span>';
                }
                html += '</div>';
                html += '<div class="lm-header-actions">';
                html += '<button type="button" class="lm-btn lm-btn-secondary" id="lm-csv-btn" data-testid="button-export-csv">' + downloadIcon + ' CSV</button>';
                html += '<button type="button" class="lm-btn lm-btn-secondary" id="lm-pdf-btn" data-testid="button-export-pdf">' + fileIcon + ' PDF</button>';
                if (canManage) {
                    html += '<button type="button" class="lm-btn lm-btn-primary" id="lm-save-btn" disabled data-testid="button-save-mapping">Saved</button>';
                }
                html += '</div>';
                html += '</div>';
                html += '</div>';

                if (canManage) {
                    var hasRows = state.rows.length > 0;
                    var hasText = false;

                    html += '<div class="lm-workflow">';

                    html += '<div class="lm-step' + (hasRows ? ' lm-step-done' : '') + '">';
                    html += '<div class="lm-step-header">';
                    html += '<span class="lm-step-number">' + (hasRows ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>' : '1') + '</span>';
                    html += '<span class="lm-step-title">Add TGA Unit Data</span>';
                    html += '</div>';
                    html += '<div class="lm-step-body">';
                    html += '<p class="lm-step-desc">Fetch directly from training.gov.au by unit code, or paste raw text.</p>';
                    html += '<div class="lm-fetch-tga-row">';
                    html += '<input type="text" class="lm-input lm-fetch-tga-input" id="lm-tga-code-input" placeholder="e.g. BSBTEC201" value="' + escapeHtml(state.unitCode || '') + '" data-testid="input-tga-code" />';
                    html += '<button type="button" class="lm-btn lm-btn-primary" id="lm-fetch-tga-btn" data-testid="button-fetch-tga">' + scanIcon + ' Fetch from TGA</button>';
                    html += '</div>';
                    html += '<details class="lm-details lm-paste-details">';
                    html += '<summary class="lm-summary lm-summary-sm">Or paste text manually</summary>';
                    html += '<div class="lm-details-content lm-details-content-sm">';
                    html += '<textarea class="lm-textarea" id="lm-tga-paste" rows="6" placeholder="Paste TGA unit elements, performance criteria, foundation skills, KE, PE here..." data-testid="textarea-tga-paste"></textarea>';
                    html += '</div>';
                    html += '</details>';
                    html += '</div>';
                    html += '</div>';

                    html += '<div class="lm-step">';
                    html += '<div class="lm-step-header">';
                    html += '<span class="lm-step-number">2</span>';
                    html += '<span class="lm-step-title">Parse & Build Table</span>';
                    html += '</div>';
                    html += '<div class="lm-step-body">';
                    html += '<p class="lm-step-desc">Parse the TGA text into elements, performance criteria, KE, PE and foundation skills rows.</p>';
                    html += '<button type="button" class="lm-btn lm-btn-primary" id="lm-parse-tga-btn" data-testid="button-parse-tga">' + fileIcon + ' Parse & Build Rows</button>';
                    html += '</div>';
                    html += '</div>';

                    html += '<div class="lm-step">';
                    html += '<div class="lm-step-header">';
                    html += '<span class="lm-step-number">3</span>';
                    html += '<span class="lm-step-title">Scan Course Activities</span>';
                    html += '</div>';
                    html += '<div class="lm-step-body">';
                    html += '<p class="lm-step-desc">Scan this Moodle course to find all activities, quizzes, assignments, and AI plugin content.</p>';
                    html += '<button type="button" class="lm-btn lm-btn-secondary" id="lm-scan-btn" data-testid="button-scan-course">' + scanIcon + ' Scan Course</button>';
                    html += '</div>';
                    html += '</div>';

                    html += '<div class="lm-step">';
                    html += '<div class="lm-step-header">';
                    html += '<span class="lm-step-number">4</span>';
                    html += '<span class="lm-step-title">AI Analyse</span>';
                    html += '<span class="lm-step-badge">100 credits</span>';
                    html += '</div>';
                    html += '<div class="lm-step-body">';
                    html += '<p class="lm-step-desc">AI maps scanned activities to your TGA rows. Only empty cells are filled  -  existing entries are preserved.</p>';
                    html += '<button type="button" class="lm-btn lm-btn-ai-action" id="lm-ai-analyse-btn" data-testid="button-ai-analyse">' + aiIcon + ' AI Analyse</button>';
                    html += '</div>';
                    html += '</div>';

                    html += '<div class="lm-step">';
                    html += '<div class="lm-step-header">';
                    html += '<span class="lm-step-number">5</span>';
                    html += '<span class="lm-step-title">Review & Save</span>';
                    html += '</div>';
                    html += '<div class="lm-step-body">';
                    html += '<p class="lm-step-desc">Review the mapping table, make edits, then save. Export as CSV or PDF anytime.</p>';
                    html += '</div>';
                    html += '</div>';

                    html += '</div>';
                }

                html += '<div class="lm-stats-bar" id="lm-stats-bar">';
                html += renderStats();
                html += '</div>';

                html += '<div id="lm-table-wrap" class="lm-table-wrap"></div>';

                html += '<div id="lm-ac-wrap" class="lm-ac-wrap"></div>';

                html += '<div id="lm-scan-results" class="lm-scan-results" style="display:none;"></div>';
                html += '<div id="lm-ai-results" class="lm-ai-results" style="display:none;"></div>';

                app.innerHTML = html;

                renderTable();
                renderACTable();
                bindAppEvents();
            }

            function renderStats() {
                /* Build a row-id  ->  row lookup so we can check each PC's parent. */
                var rowMap = {};
                state.rows.forEach(function(r) { rowMap[r.id] = r; });

                /*
                 * elementCount: only real element rows.
                 * Section-header rows (KE / PE / FS / AC) are isElement:true BUT
                 * also isShaded:true  -  exclude them so they don't inflate the count.
                 */
                var elementCount = state.rows.filter(function(r) {
                    return r.isElement && !r.isShaded;
                }).length;

                /*
                 * pcCount: only rows whose parent is a real (non-shaded) element row.
                 * Without this guard, every KE / PE / FS item row (isElement:false)
                 * was being counted as a Performance Criterion, inflating pcCount
                 * by keCount + peCount + fsCount.
                 */
                var pcCount = state.rows.filter(function(r) {
                    if (r.isElement) { return false; }
                    var parent = rowMap[r.parentId];
                    return parent && parent.isElement && !parent.isShaded;
                }).length;

                /*
                 * KE / PE / FS counts: derive from actual rows rather than
                 * state.unitData.sections (which can be null for old saves or
                 * manually-built mappings).  Find shaded heading rows whose titles
                 * match the section labels, then count their immediate children.
                 */
                var sectionChildCounts = { ke: 0, pe: 0, fs: 0, ac: 0 };
                var sectionHeadingIds = {};
                var keTitles = ['knowledge evidence', 'required knowledge'];
                var peTitles = ['performance evidence', 'required skills'];
                var fsTitles = ['foundation skills'];
                var acTitles = ['assessment conditions'];
                state.rows.forEach(function(r) {
                    if (r.isElement && r.isShaded) {
                        var t = (r.title || '').toLowerCase().trim();
                        if (keTitles.indexOf(t) !== -1) { sectionHeadingIds[r.id] = 'ke'; }
                        else if (peTitles.indexOf(t) !== -1) { sectionHeadingIds[r.id] = 'pe'; }
                        else if (fsTitles.indexOf(t) !== -1) { sectionHeadingIds[r.id] = 'fs'; }
                        else if (acTitles.indexOf(t) !== -1) { sectionHeadingIds[r.id] = 'ac'; }
                    }
                });
                state.rows.forEach(function(r) {
                    if (!r.isElement && r.parentId && sectionHeadingIds[r.parentId]) {
                        sectionChildCounts[sectionHeadingIds[r.parentId]]++;
                    }
                });
                var keCount = sectionChildCounts.ke;
                var peCount = sectionChildCounts.pe;
                var fsCount = sectionChildCounts.fs;

                /*
                 * Completion %: for the first column (col_elements), cells are displayed
                 * using row.title as a fallback when the cell value is empty  -  so count
                 * those cells as filled rather than falsely treating them as blank.
                 */
                var firstColId = state.columns.length > 0 ? state.columns[0].id : null;
                var totalCells = state.rows.length * state.columns.length;
                var filledCells = 0;
                state.rows.forEach(function(r) {
                    state.columns.forEach(function(c) {
                        var val = getCellValue(r.id, c.id);
                        if (!val && c.id === firstColId) val = r.title || '';
                        if (val) filledCells++;
                    });
                });
                var completionPct = totalCells > 0 ? Math.round((filledCells / totalCells) * 100) : 0;

                var html = '';
                html += '<div class="lm-stat">';
                html += '<span class="lm-stat-value">' + elementCount + '</span>';
                html += '<span class="lm-stat-label">Elements</span>';
                html += '</div>';
                html += '<div class="lm-stat">';
                html += '<span class="lm-stat-value">' + pcCount + '</span>';
                html += '<span class="lm-stat-label">Performance Criteria</span>';
                html += '</div>';
                html += '<div class="lm-stat">';
                html += '<span class="lm-stat-value">' + keCount + '</span>';
                html += '<span class="lm-stat-label">Knowledge Evidence</span>';
                html += '</div>';
                html += '<div class="lm-stat">';
                html += '<span class="lm-stat-value">' + peCount + '</span>';
                html += '<span class="lm-stat-label">Performance Evidence</span>';
                html += '</div>';
                html += '<div class="lm-stat">';
                html += '<span class="lm-stat-value">' + fsCount + '</span>';
                html += '<span class="lm-stat-label">Foundation Skills</span>';
                html += '</div>';
                var acCount = state.acData ? state.acData.length : 0;
                var acMet = state.acData ? state.acData.filter(function(a) { return a.status === 'met'; }).length : 0;

                html += '<div class="lm-stat">';
                html += '<span class="lm-stat-value">' + state.columns.length + '</span>';
                html += '<span class="lm-stat-label">Columns</span>';
                html += '</div>';
                html += '<div class="lm-stat">';
                html += '<span class="lm-stat-value">' + completionPct + '%</span>';
                html += '<span class="lm-stat-label">Cells Filled</span>';
                html += '</div>';
                if (acCount > 0) {
                    html += '<div class="lm-stat lm-stat-ac">';
                    html += '<span class="lm-stat-value">' + acMet + '/' + acCount + '</span>';
                    html += '<span class="lm-stat-label">AC Met</span>';
                    html += '</div>';
                }
                return html;
            }

            function updateStats() {
                var bar = document.getElementById('lm-stats-bar');
                if (bar) bar.innerHTML = renderStats();
            }

            function bindAppEvents() {
                $('#lm-save-btn').on('click', function() {
                    save(function() { updateStats(); });
                });

                $('#lm-csv-btn').on('click', exportCSV);
                $('#lm-pdf-btn').on('click', exportPrintPdf);

                $('#lm-fetch-tga-btn').on('click', function() {
                    var codeInput = document.getElementById('lm-tga-code-input');
                    var code = codeInput.value.trim().toUpperCase();
                    if (!code) {
                        showToast('Enter a unit code first (e.g. BSBTEC201)', 'error');
                        codeInput.focus();
                        return;
                    }
                    var btn = this;
                    var origHtml = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<span class="lm-spinner"></span> Fetching...';
                    $.ajax({
                        url: ajaxUrl + '?action=fetchtga&mappingid=' + mappingId + '&sesskey=' + sesskey,
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ unitCode: code }),
                        dataType: 'json',
                        timeout: 30000,
                        success: function(resp) {
                            btn.disabled = false;
                            btn.innerHTML = origHtml;
                            if (typeof resp === 'string') {
                                try { resp = JSON.parse(resp); } catch(e) {
                                    showToast('Invalid response from server', 'error');
                                    return;
                                }
                            }
                            if (resp && resp.ok && resp.tgaText) {
                                document.getElementById('lm-tga-paste').value = resp.tgaText;
                                state.unitCode = resp.code || code;
                                var summ = resp.summary || {};
                                var fetchMsg = 'Fetched ' + (resp.code || code) + ': ' + (summ.elements || 0) + ' elements, ' + (summ.pcs || 0) + ' PCs, ' + (summ.ke || 0) + ' KE, ' + (summ.pe || 0) + ' PE, ' + (summ.fs || 0) + ' FS';
                                if (summ.ac) fetchMsg += ', ' + summ.ac + ' AC';
                                showToast(fetchMsg, 'success');
                                var parsed = parseUnitDataFromPaste(resp.tgaText);
                                if (parsed.elements.length > 0) {
                                    state.unitData = { raw: resp.tgaText, parsed: parsed.elements, sections: parsed.sections };
                                    var doAutoParse = function() {
                                        buildRowsFromUnitData(parsed.elements, parsed.sections);
                                        var sectionMsg = [];
                                        if (parsed.sections.ke.length) sectionMsg.push(parsed.sections.ke.length + ' KE');
                                        if (parsed.sections.pe.length) sectionMsg.push(parsed.sections.pe.length + ' PE');
                                        if (parsed.sections.fs.length) sectionMsg.push(parsed.sections.fs.length + ' FS');
                                        if (parsed.sections.ac && parsed.sections.ac.length) sectionMsg.push(parsed.sections.ac.length + ' AC');
                                        var msg = parsed.elements.length + ' elements parsed';
                                        if (sectionMsg.length) msg += ' + ' + sectionMsg.join(', ');
                                        showToast(msg, 'success');
                                        renderApp();
                                        renderTable();
                                        updateStats();
                                        updateSaveButton();
                                    };
                                    if (state.rows.length > 0) {
                                        showConfirm('This will replace all existing rows with the fetched data.', {
                                            title: 'Replace Existing Rows?',
                                            confirmText: 'Replace',
                                            type: 'warning'
                                        }).then(function(ok) {
                                            if (ok) doAutoParse();
                                        });
                                    } else {
                                        doAutoParse();
                                    }
                                } else {
                                    renderApp();
                                }
                            } else {
                                showToast(resp.error || 'Failed to fetch unit data', 'error');
                            }
                        },
                        error: function(xhr) {
                            btn.disabled = false;
                            btn.innerHTML = origHtml;
                            var msg = 'Failed to fetch from TGA';
                            try {
                                var r = JSON.parse(xhr.responseText);
                                if (r && r.error) msg = r.error;
                            } catch(e) {}
                            showToast(msg, 'error');
                        }
                    });
                });

                $('#lm-tga-code-input').on('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        $('#lm-fetch-tga-btn').click();
                    }
                });

                $('#lm-parse-tga-btn').on('click', function() {
                    var text = document.getElementById('lm-tga-paste').value.trim();
                    if (!text) {
                        showToast('Please paste TGA unit data first', 'error');
                        return;
                    }
                    var parsed = parseUnitDataFromPaste(text);
                    if (parsed.elements.length === 0) {
                        showToast('Could not parse any elements. Check the format.', 'error');
                        return;
                    }
                    state.unitData = { raw: text, parsed: parsed.elements, sections: parsed.sections };
                    var doParse = function() {
                        buildRowsFromUnitData(parsed.elements, parsed.sections);
                        var sectionMsg = [];
                        if (parsed.sections.ke.length) sectionMsg.push(parsed.sections.ke.length + ' KE');
                        if (parsed.sections.pe.length) sectionMsg.push(parsed.sections.pe.length + ' PE');
                        if (parsed.sections.fs.length) sectionMsg.push(parsed.sections.fs.length + ' FS');
                        if (parsed.sections.ac && parsed.sections.ac.length) sectionMsg.push(parsed.sections.ac.length + ' AC');
                        var msg = parsed.elements.length + ' elements parsed';
                        if (sectionMsg.length) msg += ' + ' + sectionMsg.join(', ');
                        showToast(msg, 'success');
                        renderTable();
                        renderACTable();
                        updateStats();
                        updateSaveButton();
                    };
                    if (state.rows.length > 0) {
                        showConfirm('This will replace all existing rows with newly parsed data.', {
                            title: 'Replace Existing Rows?',
                            confirmText: 'Replace',
                            type: 'warning'
                        }).then(function(ok) { if (ok) doParse(); });
                    } else {
                        doParse();
                    }
                });

                $('#lm-scan-btn').on('click', function() {
                    var btn = this;
                    var scanIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
                    btn.disabled = true;
                    btn.textContent = 'Scanning...';
                    function resetBtn() {
                        btn.disabled = false;
                        btn.innerHTML = scanIcon + ' Scan Course';
                    }
                    scanCourse(function(modules) {
                        resetBtn();
                        showScanResults(modules);
                    }, resetBtn);
                });

                $('#lm-ai-analyse-btn').on('click', function() {
                    var btn = this;
                    if (state.rows.length === 0) {
                        showToast('Add rows first  -  paste TGA unit data or add rows manually', 'error');
                        return;
                    }
                    var aiIcon = btn.innerHTML.substring(0, btn.innerHTML.indexOf('</svg>') + 6);

                    function resetBtn() {
                        btn.disabled = false;
                        btn.innerHTML = aiIcon + ' AI Analyse';
                    }

                    function doAnalyse(modules) {
                        // Sync columns BEFORE sending to AI  -  add new, remove deleted-activity columns.
                        // v1.0.46 FIX-LM-SELECT: Only send checkmarked activities to AI, but sync
                        // columns using all scanned modules so no column is accidentally removed.
                        syncColumnsForModules(modules);
                        var selectedMods = getSelectedModules(modules);
                        analyzeWithAI(selectedMods, function(result) {
                            resetBtn();
                            applyAIMappings(result.mappings);
                            renderTable();
                            updateStats();
                            updateSaveButton();
                            showAIResults(result);
                            showToast('AI analysis complete  -  ' + (result.summary ? result.summary.fullyCovered + ' fully covered' : 'mappings applied'), 'success');
                        }, function(errMsg) {
                            resetBtn();
                            showToast(errMsg || 'AI analysis failed', 'error');
                        });
                    }

                    function startAnalysis() {
                        btn.disabled = true;
                        btn.innerHTML = '<span class="lm-spinner"></span> Analysing...';
                        if (lastScannedModules) {
                            doAnalyse(lastScannedModules);
                        } else {
                            scanCourse(function(modules) {
                                doAnalyse(modules);
                            }, function() {
                                resetBtn();
                                showToast('Course scan failed  -  cannot run AI analysis without course data', 'error');
                            });
                        }
                    }

                    btn.disabled = true;
                    btn.innerHTML = '<span class="lm-spinner"></span> Checking credits...';
                    $.ajax({
                        url: ajaxUrl + '?action=getcredits&mappingid=' + mappingId + '&sesskey=' + sesskey,
                        method: 'GET',
                        dataType: 'json',
                        timeout: 15000,
                        success: function(resp) {
                            resetBtn();
                            if (resp && resp.ok) {
                                var bal = resp.credits;
                                var balDisplay = (bal === -1) ? 'Unlimited' : bal;
                                if (bal !== -1 && bal < 100) {
                                    showToast('Not enough credits. You have ' + bal + ' but need 100.', 'error');
                                    return;
                                }
                                var msg = 'AI Analyse will cost 100 credits ($10.00 AUD).\n\nYour balance: ' + balDisplay + ' credits';
                                if (bal !== -1) {
                                    msg += '\nAfter analysis: ' + (bal - 100) + ' credits';
                                }
                                msg += '\n\nOnly empty cells will be filled  -  existing entries are preserved.';
                                showConfirm(msg, {
                                    title: 'Run AI Analysis',
                                    confirmText: 'Analyse',
                                    type: 'default'
                                }).then(function(ok) { if (ok) startAnalysis(); });
                            } else {
                                showConfirm('AI Analyse will cost 100 credits ($10.00 AUD).\n\nCould not check your balance (' + (resp.error || 'unknown error') + ').\n\nProceed anyway?', {
                                    title: 'Run AI Analysis',
                                    confirmText: 'Proceed',
                                    type: 'warning'
                                }).then(function(ok) { if (ok) startAnalysis(); });
                            }
                        },
                        error: function() {
                            resetBtn();
                            showConfirm('AI Analyse will cost 100 credits ($10.00 AUD).\n\nCould not check your credit balance.\n\nProceed anyway?', {
                                title: 'Run AI Analysis',
                                confirmText: 'Proceed',
                                type: 'warning'
                            }).then(function(ok) { if (ok) startAnalysis(); });
                        }
                    });
                });
            }

            function analyzeWithAI(modules, successCallback, errorCallback) {
                var payload = {
                    rows: state.rows.map(function(r) {
                        return {
                            id: r.id,
                            title: r.title || '',
                            isElement: !!r.isElement,
                            isShaded: !!r.isShaded,
                            parentId: r.parentId || null
                        };
                    }),
                    columns: state.columns.map(function(c) {
                        return { id: c.id, title: c.title, group: c.group || 'custom' };
                    }),
                    cells: state.cells,
                    scannedModules: (modules || []).map(function(m) {
                        var mod = {
                            modname: m.modname,
                            name: m.name,
                            topics: m.topics || [],
                            questions: m.questions || [],
                            activities: m.activities || []
                        };
                        if (m.description) mod.description = m.description;
                        if (m.pages) mod.pages = m.pages;
                        if (m.chapters) mod.chapters = m.chapters;
                        if (m.sectionName) mod.sectionName = m.sectionName;
                        // Practical Assessment fields.
                        if (m.unitcode) mod.unitcode = m.unitcode;
                        if (m.unitname) mod.unitname = m.unitname;
                        if (m.industry) mod.industry = m.industry;
                        if (m.jobrole) mod.jobrole = m.jobrole;
                        if (m.scenario) mod.scenario = m.scenario;
                        if (m.skills) mod.skills = m.skills;
                        if (m.workplaceForms) mod.workplaceForms = m.workplaceForms;
                        if (m.mappingHints) mod.mappingHints = m.mappingHints;
                        if (m.occasions) mod.occasions = m.occasions;
                        // Assignment extra fields.
                        if (m.embeddedUrls) mod.embeddedUrls = m.embeddedUrls;
                        if (m.submissionInstructions) mod.submissionInstructions = m.submissionInstructions;
                        if (m.submissionPlugins) mod.submissionPlugins = m.submissionPlugins;
                        return mod;
                    }),
                    unitCode: state.unitCode || ''
                };

                /* v1.0.46 FIX-LM-SYNC: Use sync analyzemapping action directly  -  the async
                   /start endpoint is not available on the production server. Direct POST
                   with 155s timeout matches the PHP server's 150s CURLOPT_TIMEOUT. */
                $.ajax({
                    url: ajaxUrl + '?action=analyzemapping&mappingid=' + mappingId + '&sesskey=' + sesskey,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    timeout: 155000,
                    success: function(result) {
                        if (result && result.ok && result.mappings) {
                            if (successCallback) successCallback(result);
                        } else {
                            if (errorCallback) errorCallback((result && result.error) || 'AI analysis returned no mappings');
                        }
                    },
                    error: function(xhr) {
                        var msg = 'AI analysis request failed';
                        try {
                            var r = JSON.parse(xhr.responseText);
                            if (r && r.error) msg = r.error;
                        } catch(e) {
                            if (xhr.status === 402) msg = 'Not enough credits for AI analysis';
                            else if (xhr.status === 401) msg = 'Invalid API credentials  -  check Site Admin > AI Grader Central Config';
                            else if (xhr.status === 0) msg = 'Network error  -  check your connection';
                            else msg = 'AI analysis failed (HTTP ' + xhr.status + ')';
                        }
                        if (errorCallback) errorCallback(msg);
                    }
                });
            }

            function sanitiseCellValue(val) {
                if (!val || typeof val !== 'string') return '';
                val = val.trim();
                var codePattern = /\b(T\d+|Q\d+|Act\s*\d+|Ch\d+|Sub-Ch\d+|P\d+|A\d+)\b/gi;
                var matches = val.match(codePattern);
                if (matches && matches.length > 0) {
                    var seen = {};
                    var unique = [];
                    matches.forEach(function(m) {
                        var norm = m.replace(/\s+/g, ' ').trim();
                        norm = norm.replace(/^act\s*/i, 'Act ');
                        var key = norm.toUpperCase();
                        if (!seen[key]) {
                            seen[key] = true;
                            unique.push(norm);
                        }
                    });
                    return unique.join(', ');
                }
                // No short codes  -  accept very short values (PC codes like "1.1"), reject everything else
                if (val.length <= 10) return val;
                return '';
            }

            function applyAIMappings(mappings) {
                if (!mappings || !Array.isArray(mappings)) return;

                mappings.forEach(function(mapping) {
                    if (!mapping.rowId || !mapping.suggestions) return;

                    var row = state.rows.find(function(r) { return r.id === mapping.rowId; });
                    if (!row) return;
                    if (row.isElement) return;

                    for (var colId in mapping.suggestions) {
                        if (!mapping.suggestions.hasOwnProperty(colId)) continue;
                        var col = state.columns.find(function(c) { return c.id === colId; });
                        if (!col) continue;
                        if (colId === 'col_elements') continue;

                        var suggestion = mapping.suggestions[colId];
                        if (suggestion && suggestion.trim()) {
                            var cleaned = sanitiseCellValue(suggestion);
                            if (cleaned) {
                                var key = getCellKey(row.id, colId);
                                var existing = state.cells[key] || '';
                                if (!existing.trim()) {
                                    state.cells[key] = cleaned;
                                }
                            }
                        }
                    }
                });

                state.dirty = true;
            }

            function showAIResults(result) {
                var container = document.getElementById('lm-ai-results');
                if (!container) return;

                var summary = result.summary;
                var mappings = result.mappings || [];
                var html = '<div class="lm-ai-results-inner">';

                html += '<h3 class="lm-ai-title">AI Analysis Results</h3>';

                if (summary) {
                    html += '<div class="lm-ai-summary-grid">';
                    html += '<div class="lm-ai-stat lm-ai-stat-total">';
                    html += '<span class="lm-ai-stat-value">' + (summary.totalRows || 0) + '</span>';
                    html += '<span class="lm-ai-stat-label">Total Rows</span>';
                    html += '</div>';
                    html += '<div class="lm-ai-stat lm-ai-stat-covered">';
                    html += '<span class="lm-ai-stat-value">' + (summary.fullyCovered || 0) + '</span>';
                    html += '<span class="lm-ai-stat-label">Fully Covered</span>';
                    html += '</div>';
                    html += '<div class="lm-ai-stat lm-ai-stat-partial">';
                    html += '<span class="lm-ai-stat-value">' + (summary.partiallyCovered || 0) + '</span>';
                    html += '<span class="lm-ai-stat-label">Partially Covered</span>';
                    html += '</div>';
                    html += '<div class="lm-ai-stat lm-ai-stat-gap">';
                    html += '<span class="lm-ai-stat-value">' + (summary.notCovered || 0) + '</span>';
                    html += '<span class="lm-ai-stat-label">Not Covered</span>';
                    html += '</div>';
                    html += '</div>';

                    if (summary.recommendations && summary.recommendations.length > 0) {
                        html += '<div class="lm-ai-recommendations">';
                        html += '<h4>Recommendations</h4>';
                        html += '<ul class="lm-ai-rec-list">';
                        summary.recommendations.forEach(function(rec) {
                            html += '<li>' + escapeHtml(rec) + '</li>';
                        });
                        html += '</ul>';
                        html += '</div>';
                    }
                }

                var gapRows = mappings.filter(function(m) { return m.gaps && m.gaps.trim(); });
                if (gapRows.length > 0) {
                    html += '<div class="lm-ai-gaps">';
                    html += '<h4>Identified Gaps</h4>';
                    html += '<div class="lm-ai-gap-list">';
                    gapRows.forEach(function(m) {
                        var conf = m.confidence || 'medium';
                        var confClass = conf === 'high' ? 'lm-conf-high' : conf === 'low' ? 'lm-conf-low' : 'lm-conf-medium';
                        html += '<div class="lm-ai-gap-item">';
                        html += '<div class="lm-ai-gap-row">';
                        html += '<span class="lm-ai-gap-title">' + escapeHtml(m.rowTitle || '') + '</span>';
                        html += '<span class="lm-ai-conf-badge ' + confClass + '">' + conf + '</span>';
                        html += '</div>';
                        html += '<div class="lm-ai-gap-desc">' + escapeHtml(m.gaps) + '</div>';
                        html += '</div>';
                    });
                    html += '</div>';
                    html += '</div>';
                }

                if (result.creditsUsed) {
                    html += '<div class="lm-ai-credits">';
                    html += 'Credits used: ' + result.creditsUsed;
                    if (result.remainingCredits !== undefined && result.remainingCredits !== 'unlimited') {
                        html += ' | Remaining: ' + result.remainingCredits;
                    }
                    html += '</div>';
                }

                html += '</div>';

                container.innerHTML = html;
                container.style.display = 'block';
            }

            // Master map: modname -> { title, group } for auto-column creation
            var ACTIVITY_COL_DEFS = {
                contentcreator:       { title: 'Content Creator',       group: 'learning'   },
                aiactivities:         { title: 'Learning Activities',    group: 'learning'   },
                aiknowledgecheck:     { title: 'Knowledge Check',       group: 'assessment' },
                essaymaker:           { title: 'Quiz',                   group: 'assessment' },
                practicalassessment:  { title: 'Practical Assessment',  group: 'assessment' },
                assign:               { title: 'Assignments',            group: 'assessment' },
                quiz:             { title: 'Quiz',               group: 'assessment' },
                lesson:           { title: 'Lessons',             group: 'learning'   },
                book:             { title: 'Books',               group: 'learning'   },
                page:             { title: 'Resources',           group: 'learning'   },
                resource:         { title: 'Resources',           group: 'learning'   },
                url:              { title: 'Resources',           group: 'learning'   },
                forum:            { title: 'Forums',              group: 'learning'   },
                workshop:         { title: 'Workshops',           group: 'assessment' },
                glossary:         { title: 'Resources',           group: 'learning'   },
                wiki:             { title: 'Resources',           group: 'learning'   },
                h5pactivity:      { title: 'Resources',           group: 'learning'   },
                scorm:            { title: 'SCORM',               group: 'learning'   },
                lti:              { title: 'Resources',           group: 'learning'   }
            };

            function syncColumnsForModules(modules) {
                // Legacy fixed IDs used by the old default columns (backward compat).
                // When an activity of the matching modname is scanned, the legacy column
                // is adopted (tagged with cmid + renamed to actual activity name) rather
                // than deleted and recreated  -  preserving any existing cell data.
                var LEGACY_COL_ID_FOR_MODNAME = {
                    contentcreator:   'col_cc',
                    aiactivities:     'col_la',
                    aiknowledgecheck: 'col_kc',
                    essaymaker:       'col_em'
                };
                var LEGACY_IDS = { col_cc: true, col_la: true, col_kc: true, col_em: true };

                // Build fast-lookup maps of existing columns
                var colByCmid     = {};  // cmid (number|string) -> column object
                var colByLegacyId = {};  // 'col_cc' etc -> column object
                state.columns.forEach(function(col) {
                    if (col.cmid) { colByCmid[col.cmid] = col; }
                    if (LEGACY_IDS[col.id]) { colByLegacyId[col.id] = col; }
                });

                // Only process activities we know how to handle
                var relevantModules = modules.filter(function(mod) {
                    return ACTIVITY_COL_DEFS[mod.modname] !== undefined;
                });

                var neededCmids = {};
                var added   = [];
                var renamed = [];

                relevantModules.forEach(function(mod) {
                    var def = ACTIVITY_COL_DEFS[mod.modname];
                    neededCmids[mod.cmid] = true;

                    // Case 1  -  column already tracked by cmid
                    if (colByCmid[mod.cmid]) {
                        var existing = colByCmid[mod.cmid];
                        if (existing.title !== mod.name) {
                            renamed.push(mod.name);
                            existing.title = mod.name;
                        }
                        return;
                    }

                    // Case 2  -  adopt a matching legacy column (backward compat)
                    var legacyId = LEGACY_COL_ID_FOR_MODNAME[mod.modname];
                    if (legacyId && colByLegacyId[legacyId] && !colByLegacyId[legacyId].cmid) {
                        var legacyCol = colByLegacyId[legacyId];
                        legacyCol.cmid  = mod.cmid;
                        if (legacyCol.title !== mod.name) {
                            renamed.push(mod.name);
                            legacyCol.title = mod.name;
                        }
                        colByCmid[mod.cmid] = legacyCol;
                        return;
                    }

                    // Case 3  -  create a fresh per-activity column
                    var col = {
                        id:     'col_cm_' + mod.cmid,
                        title:  mod.name,
                        group:  def.group,
                        locked: false,
                        width:  180,
                        cmid:   mod.cmid
                    };
                    state.columns.push(col);
                    colByCmid[mod.cmid] = col;
                    added.push(mod.name);
                });

                // Remove cmid-tracked columns whose activity is no longer in the course,
                // but ONLY when the column contains no cell data (nothing would be lost).
                var removed = [];
                var toRemove = state.columns.filter(function(col) {
                    if (col.locked) { return false; }
                    if (!col.cmid)  { return false; }   // not cmid-tracked; leave custom columns alone
                    if (neededCmids[col.cmid]) { return false; }
                    var hasData = state.rows.some(function(row) {
                        return !!(getCellValue(row.id, col.id));
                    });
                    return !hasData;
                });
                toRemove.forEach(function(col) {
                    removeColumn(col.id);
                    removed.push(col.title);
                });

                if (added.length > 0 || removed.length > 0 || renamed.length > 0) {
                    renderTable();
                    updateStats();
                    if (removed.length > 0) {
                        showToast('Removed ' + removed.length + ' column' + (removed.length > 1 ? 's' : '') + ': ' + removed.join(', '), 'info');
                    }
                    if (added.length > 0) {
                        showToast('Added ' + added.length + ' column' + (added.length > 1 ? 's' : '') + ': ' + added.join(', '), 'info');
                    }
                    if (renamed.length > 0) {
                        showToast('Updated column titles: ' + renamed.join(', '), 'info');
                    }
                    state.dirty = true;
                    save();
                }
                return { added: added, removed: removed };
            }

            // Kept for backward compatibility  -  delegates to syncColumnsForModules
            function ensureColumnsForModules(modules) {
                return syncColumnsForModules(modules);
            }

            // Returns the column title this modname maps to (for display in scan cards)
            function getColumnTitleForMod(modname) {
                var def = ACTIVITY_COL_DEFS[modname];
                return def ? def.title : null;
            }

            function getModuleFriendlyName(modname) {
                var names = {
                    contentcreator:      'Content Creator',
                    aiactivities:        'Learning Activities',
                    aiknowledgecheck:    'Knowledge Check',
                    essaymaker:          'AI Quiz',
                    practicalassessment: 'Practical Assessment',
                    assign:              'Assignment',
                    quiz:                'Quiz',
                    lesson:              'Lesson',
                    book:                'Book',
                    page:                'Resource',
                    resource:            'Resource',
                    url:                 'Resource',
                    forum:               'Forum',
                    workshop:            'Workshop',
                    glossary:            'Glossary',
                    wiki:                'Wiki',
                    h5pactivity:         'H5P',
                    scorm:               'SCORM',
                    lti:                 'External Tool'
                };
                return names[modname] || modname;
            }

            function getModuleIcon(modname) {
                var icons = {
                    contentcreator: { icon: 'CC', color: '#6366f1' },
                    aiactivities: { icon: 'LA', color: '#10b981' },
                    aiknowledgecheck: { icon: 'KC', color: '#f59e0b' },
                    essaymaker: { icon: 'AQ', color: '#ef4444' },
                    practicalassessment: { icon: 'PA', color: '#0d9488' },
                    assign: { icon: 'AS', color: '#8b5cf6' },
                    quiz: { icon: 'QZ', color: '#ec4899' },
                    lesson: { icon: 'LS', color: '#14b8a6' },
                    book: { icon: 'BK', color: '#0ea5e9' },
                    page: { icon: 'PG', color: '#64748b' },
                    resource: { icon: 'FL', color: '#78716c' },
                    url: { icon: 'URL', color: '#0284c7' },
                    forum: { icon: 'FM', color: '#d97706' },
                    workshop: { icon: 'WS', color: '#7c3aed' },
                    glossary: { icon: 'GL', color: '#059669' },
                    wiki: { icon: 'WK', color: '#4f46e5' },
                    h5pactivity: { icon: 'H5', color: '#0891b2' },
                    scorm: { icon: 'SC', color: '#b45309' },
                    lti: { icon: 'LT', color: '#6d28d9' }
                };
                return icons[modname] || { icon: modname.substring(0, 2).toUpperCase(), color: '#6b7280' };
            }

            function showScanResults(modules) {
                var container = document.getElementById('lm-scan-results');
                if (!container) return;

                if (modules.length === 0) {
                    container.innerHTML = '<div class="lm-scan-empty">No compatible activities found in this course.</div>';
                    container.style.display = 'block';
                    return;
                }

                lastScannedModules = modules;
                ensureColumnsForModules(modules);
                renderTable();
                updateStats();
                updateSaveButton();

                // v1.0.46 FIX-LM-SELECT: Add select/deselect controls so teacher can choose
                // which activities to include in AI Analyse. All selected by default.
                var html = '<div class="lm-scan-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">';
                html += '<h3 class="lm-scan-title" style="margin:0;">Course Activities Found (' + modules.length + ')</h3>';
                html += '<div style="display:flex;align-items:center;gap:8px;">';
                html += '<button type="button" id="lm-scan-select-all" class="lm-btn lm-btn-secondary" style="font-size:0.8rem;padding:4px 10px;">Select All</button>';
                html += '<button type="button" id="lm-scan-deselect-all" class="lm-btn lm-btn-secondary" style="font-size:0.8rem;padding:4px 10px;">Deselect All</button>';
                html += '</div></div>';
                html += '<div class="lm-scan-grid">';
                modules.forEach(function(mod, idx) {
                    var mi = getModuleIcon(mod.modname);
                    var def = ACTIVITY_COL_DEFS[mod.modname];

                    html += '<div class="lm-scan-card" style="position:relative;">';
                    html += '<label style="position:absolute;top:8px;right:8px;cursor:pointer;" title="Include in AI Analyse">';
                    html += '<input type="checkbox" class="lm-scan-mod-chk" data-idx="' + idx + '" checked style="width:15px;height:15px;cursor:pointer;">';
                    html += '</label>';
                    html += '<div class="lm-scan-card-icon" style="background:' + mi.color + ';">' + mi.icon + '</div>';
                    html += '<div class="lm-scan-card-body">';
                    html += '<strong>' + escapeHtml(mod.name) + '</strong>';
                    html += '<span class="lm-scan-card-type">' + escapeHtml(getModuleFriendlyName(mod.modname)) + '</span>';
                    if (def) html += '<span class="lm-scan-card-col">\u2192 ' + escapeHtml(mod.name) + ' (' + escapeHtml(getGroupLabel(def.group)) + ')</span>';
                    if (mod.sectionName) html += '<span class="lm-scan-card-count">Section: ' + escapeHtml(mod.sectionName) + '</span>';
                    if (mod.topics && mod.topics.length) html += '<span class="lm-scan-card-count">' + mod.topics.length + ' topics</span>';
                    if (mod.questions && mod.questions.length) html += '<span class="lm-scan-card-count">' + mod.questions.length + ' questions</span>';
                    if (mod.activities && mod.activities.length) html += '<span class="lm-scan-card-count">' + mod.activities.length + ' activities</span>';
                    if (mod.pages && mod.pages.length) html += '<span class="lm-scan-card-count">' + mod.pages.length + ' pages</span>';
                    if (mod.chapters && mod.chapters.length) html += '<span class="lm-scan-card-count">' + mod.chapters.length + ' chapters</span>';
                    html += '</div>';
                    html += '</div>';
                });
                html += '</div>';

                container.innerHTML = html;
                container.style.display = 'block';

                document.getElementById('lm-scan-select-all').addEventListener('click', function() {
                    container.querySelectorAll('.lm-scan-mod-chk').forEach(function(chk) { chk.checked = true; });
                });
                document.getElementById('lm-scan-deselect-all').addEventListener('click', function() {
                    container.querySelectorAll('.lm-scan-mod-chk').forEach(function(chk) { chk.checked = false; });
                });

                showToast(modules.length + ' course activities found', 'success');
            }

            function getSelectedModules(modules) {
                var container = document.getElementById('lm-scan-results');
                if (!container) return modules;
                var checkboxes = container.querySelectorAll('.lm-scan-mod-chk');
                if (!checkboxes || checkboxes.length === 0) return modules;
                var selected = [];
                checkboxes.forEach(function(chk) {
                    var idx = parseInt(chk.getAttribute('data-idx'), 10);
                    if (chk.checked && modules[idx]) selected.push(modules[idx]);
                });
                return selected.length > 0 ? selected : modules;
            }

            if (!loadSavedData()) {
                initDefaultState();
            }
            renderApp();
        }
    };
});
