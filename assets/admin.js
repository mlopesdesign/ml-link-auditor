jQuery(function($){
  // Navegação por abas (padrão MLopes Design)
  $('.mlla-tab-button').on('click', function(){
    const target = $(this).data('tab-target');
    if(!target) return;
    $('.mlla-tab-button').removeClass('is-active');
    $('.mlla-tab-panel').removeClass('is-active');
    $(this).addClass('is-active');
    $('#' + target).addClass('is-active');
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', String(target).replace('mlla-tab-', ''));
      window.history.replaceState({}, document.title, url.toString());
    } catch(e){}
  });

  const state = {
    currentXHR: null,
    activeRunId: '',
    restoreDraftsCount: 0,
    scanBusy: false
  };

  function request(action, data){
    return $.post(MLLA.ajaxUrl, $.extend({ action: action, nonce: MLLA.nonce }, data || {}));
  }

  function escapeHtml(str){
    return $('<div>').text(str == null ? '' : String(str)).html();
  }

  function ensureToastContainer(){
    if(!$('#mlla-toast-stack').length){
      $('body').append('<div id="mlla-toast-stack" class="mlla-toast-stack"></div>');
    }
    return $('#mlla-toast-stack');
  }

  function closeToast($toast){
    if(!$toast || !$toast.length) return;
    const timer = $toast.data('timer');
    if(timer){ clearTimeout(timer); }
    $toast.removeClass('is-visible');
    setTimeout(function(){ $toast.remove(); }, 220);
  }

  function toast(message, type, timeout){
    const cls = type ? 'mlla-toast-' + type : 'mlla-toast-success';
    const $area = ensureToastContainer();
    const $toast = $('<div class="mlla-toast '+cls+'" />').text(message || '');
    $area.append($toast);
    window.setTimeout(function(){ $toast.addClass('is-visible'); }, 10);
    const timer = window.setTimeout(function(){ closeToast($toast); }, timeout || 5000);
    $toast.data('timer', timer);
  }

  function abortCurrentXHR(){
    if(state.currentXHR && state.currentXHR.readyState !== 4){
      try{ state.currentXHR.abort(); }catch(e){}
    }
    state.currentXHR = null;
  }

  function setActiveRun(job){
    state.activeRunId = (job && job.run_id) ? String(job.run_id) : '';
  }

  function updateSummary(summary){
    const $box = $('#mlla-summary-cards');
    if(!$box.length || !summary) return;
    let html = '';
    Object.keys(summary).forEach(function(label){
      html += '<div class="mlla-summary-box"><span>'+ escapeHtml(label) +'</span><strong>'+ escapeHtml(summary[label]) +'</strong></div>';
    });
    $box.html(html);
  }

  function updateRestoreDraftsButton(count){
    if(count === undefined || count === null){ count = state.restoreDraftsCount || 0; }
    state.restoreDraftsCount = parseInt(count, 10) || 0;
    const label = (MLLA.strings.restoreDrafts || 'Restaurar rascunhos') + ' (' + state.restoreDraftsCount + ')';
    $('#mlla-restore-drafts-batch').text(label).prop('disabled', state.restoreDraftsCount < 1 || state.scanBusy);
  }

  function updatePrimaryButtons(job){
    const labels = {
      start: MLLA.strings.startScan || 'Iniciar varredura',
      pause: MLLA.strings.pause || 'Pausar',
      resume: MLLA.strings.resume || 'Retomar',
      stop: MLLA.strings.stop || 'Parar',
      reset: MLLA.strings.reset || 'Resetar estado',
      refresh: MLLA.strings.refresh || 'Atualizar listas'
    };
    const status = (job && job.status) ? job.status : ($('.mlla-job-status').attr('data-status') || 'idle');
    const processed = parseInt(job && job.processed_posts ? job.processed_posts : $('#mlla-processed-posts').text() || 0, 10) || 0;
    const total = parseInt(job && job.total_posts ? job.total_posts : $('#mlla-total-posts').text() || 0, 10) || 0;

    $('#mlla-start-scan').text(labels.start).prop('disabled', state.scanBusy || ['running','starting'].includes(status));
    $('#mlla-pause-scan').text(total > 0 ? labels.pause + ' (' + processed + '/' + total + ')' : labels.pause).prop('disabled', state.scanBusy || status !== 'running');
    $('#mlla-resume-scan').text(labels.resume).prop('disabled', state.scanBusy || status !== 'paused');
    $('#mlla-stop-scan').text(total > 0 ? labels.stop + ' (' + processed + '/' + total + ')' : labels.stop).prop('disabled', state.scanBusy || !['running','paused','starting'].includes(status));
    $('#mlla-reset-scan').text(labels.reset).prop('disabled', state.scanBusy);
    $('#mlla-refresh-tables').text(labels.refresh).prop('disabled', state.scanBusy);
    updateRestoreDraftsButton();
  }

  function renderJob(job){
    if(!job) return;
    $('.mlla-job-status').attr('data-status', job.status || 'idle');
    $('#mlla-status-label').text(job.status_label || job.status || 'idle');
    $('#mlla-current-item').text(job.current_item || '');
    $('#mlla-last-message').text(job.last_message || '');
    $('#mlla-processed-posts').text(job.processed_posts || 0);
    $('#mlla-total-posts').text(job.total_posts || 0);
    $('#mlla-issues-found').text(job.issues_found || 0);
    $('#mlla-broken-found').text(job.broken_found || 0);
    $('#mlla-redirect-found').text(job.redirect_found || 0);
    $('#mlla-ok-found').text(job.ok_found || 0);
    $('#mlla-progress-bar').css('width', (job.progress || 0) + '%');
    $('#mlla-recent-events').val((job.recent_events || []).join('\n'));
    setActiveRun(job);
    updatePrimaryButtons(job);
  }

  function refreshTables(payload){
    if(!payload) return;
    if(payload.results_html !== undefined){ $('#mlla-results-table').html(payload.results_html); }
    if(payload.quarantine_html !== undefined){ $('#mlla-quarantine-table').html(payload.quarantine_html); }
    if(payload.summary){ updateSummary(payload.summary); }
    if(payload.restore_drafts_count !== undefined){ updateRestoreDraftsButton(payload.restore_drafts_count); }
    if(payload.job){ renderJob(payload.job); }
  }

  function simpleAction(action, $button, busyText, successMessage){
    const original = $button.text();
    state.scanBusy = true;
    updatePrimaryButtons({});
    abortCurrentXHR();
    $button.prop('disabled', true).addClass('is-busy').text(busyText || original);
    state.currentXHR = request(action);
    return state.currentXHR.done(function(resp){
      if(resp.success){
        refreshTables(resp.data);
        if(successMessage){ toast(successMessage, 'success', 4200); }
      } else {
        toast((resp.data && resp.data.message) || (MLLA.strings.error || 'Erro na operação.'), 'error', 6000);
      }
    }).fail(function(xhr, status){
      if(status !== 'abort'){
        toast(MLLA.strings.error || 'Erro na operação.', 'error', 6000);
      }
    }).always(function(){
      state.currentXHR = null;
      state.scanBusy = false;
      $button.prop('disabled', false).removeClass('is-busy').text(original);
      updatePrimaryButtons({});
    });
  }

  function runSingleBatch(){
    if(!state.activeRunId){
      state.scanBusy = false;
      updatePrimaryButtons({});
      toast(MLLA.strings.error || 'Nenhuma execução ativa.', 'warning', 5000);
      return;
    }
    abortCurrentXHR();
    state.currentXHR = request('mlla_process_scan', { run_id: state.activeRunId });
    state.currentXHR.done(function(resp){
      if(resp.success){
        refreshTables(resp.data);
        const job = resp.data && resp.data.job ? resp.data.job : null;
        if(job && job.status === 'error'){
          toast((job.last_message || MLLA.strings.error || 'Erro na varredura.'), 'error', 6000);
        } else if(job && job.status === 'finished'){
          toast(job.last_message || 'Varredura concluída.', 'success', 4500);
        } else if(job && job.status === 'paused'){
          toast(job.last_message || 'Lote concluído.', 'success', 4500);
        }
      } else {
        toast((resp.data && resp.data.message) || (MLLA.strings.error || 'Erro na varredura.'), 'error', 6000);
      }
    }).fail(function(xhr, status){
      if(status !== 'abort'){
        toast(MLLA.strings.error || 'Erro na varredura.', 'error', 6000);
      }
    }).always(function(){
      state.currentXHR = null;
      state.scanBusy = false;
      updatePrimaryButtons({});
    });
  }

  function startOrResume(action, $button, busyText, startToast){
    if(state.scanBusy) return;
    const original = $button.text();
    abortCurrentXHR();
    state.scanBusy = true;
    updatePrimaryButtons({});
    $button.prop('disabled', true).addClass('is-busy').text(busyText || original);
    state.currentXHR = request(action);
    state.currentXHR.done(function(resp){
      if(resp.success){
        refreshTables(resp.data);
        if(startToast){ toast(startToast, 'success', 3200); }
        runSingleBatch();
      } else {
        toast((resp.data && resp.data.message) || (MLLA.strings.error || 'Erro na varredura.'), 'error', 6000);
        state.scanBusy = false;
        updatePrimaryButtons({});
      }
    }).fail(function(xhr, status){
      if(status !== 'abort'){
        toast(MLLA.strings.error || 'Erro na varredura.', 'error', 6000);
      }
      state.scanBusy = false;
      updatePrimaryButtons({});
    }).always(function(){
      if(state.currentXHR && state.currentXHR.readyState === 4){ state.currentXHR = null; }
      $button.prop('disabled', false).removeClass('is-busy').text(original);
    });
  }

  $('#mlla-start-scan').on('click', function(){
    startOrResume('mlla_start_scan', $(this), MLLA.strings.startBusy || 'Iniciando...', MLLA.strings.starting || 'Iniciando varredura...');
  });
  $('#mlla-resume-scan').on('click', function(){
    startOrResume('mlla_resume_scan', $(this), MLLA.strings.resumeBusy || 'Retomando...', MLLA.strings.running || 'Processando lote...');
  });
  $('#mlla-pause-scan').on('click', function(){ simpleAction('mlla_pause_scan', $(this), MLLA.strings.pauseBusy || 'Pausando...', MLLA.strings.paused || 'Varredura pausada.'); });
  $('#mlla-stop-scan').on('click', function(){ simpleAction('mlla_stop_scan', $(this), MLLA.strings.stopBusy || 'Parando...', MLLA.strings.partialUpdated || 'Resultados parciais atualizados.'); });
  $('#mlla-reset-scan').on('click', function(){ simpleAction('mlla_reset_scan', $(this), MLLA.strings.resetting || 'Resetando...', MLLA.strings.resetDone || 'Estado resetado do zero.'); });
  $('#mlla-refresh-tables').on('click', function(){ simpleAction('mlla_refresh_tables', $(this), MLLA.strings.refreshing || 'Atualizando...', ''); });
  $('#mlla-restore-drafts-batch').on('click', function(){
    if(!window.confirm(MLLA.strings.confirmRestoreDrafts || 'Restaurar até 100 rascunhos de posts?')) return;
    simpleAction('mlla_restore_drafts_batch', $(this), MLLA.strings.restoreDraftsBusy || 'Restaurando...', MLLA.strings.restoreDraftsDone || 'Rascunhos restaurados em massa.');
  });

  $(document).on('click', '.mlla-apply-suggestion', function(){
    const $btn = $(this);
    simpleAction('mlla_apply_suggestion', $btn, MLLA.strings.busy || 'Processando...', MLLA.strings.saved || 'Operação concluída.');
  });

  $(document).on('click', '.mlla-manual-replace', function(){
    const $btn = $(this);
    const url = window.prompt(MLLA.strings.manualUrlRequired || 'Informe a nova URL.');
    if(!url) return;
    const original = $btn.text();
    state.scanBusy = true; updatePrimaryButtons({}); abortCurrentXHR();
    $btn.prop('disabled', true).addClass('is-busy').text(MLLA.strings.busy || 'Processando...');
    state.currentXHR = request('mlla_replace_link', { result_id: $btn.data('result-id'), new_url: url });
    state.currentXHR.done(function(resp){ if(resp.success){ refreshTables(resp.data); toast(MLLA.strings.saved || 'Operação concluída.', 'success', 4200);} else { toast((resp.data && resp.data.message) || (MLLA.strings.error || 'Erro na operação.'), 'error', 6000);} }).fail(function(xhr,status){ if(status!=='abort'){ toast(MLLA.strings.error || 'Erro na operação.', 'error', 6000);} }).always(function(){ state.currentXHR=null; state.scanBusy=false; $btn.prop('disabled', false).removeClass('is-busy').text(original); updatePrimaryButtons({}); });
  });

  function bindRowAction(selector, action, confirmMsg){
    $(document).on('click', selector, function(){
      const $btn = $(this);
      if(confirmMsg && !window.confirm(confirmMsg)) return;
      const payload = {};
      if(action === 'mlla_remove_link'){ payload.result_id = $btn.data('result-id'); }
      if(action === 'mlla_move_post_to_draft'){ payload.post_id = $btn.data('post-id'); }
      if(action === 'mlla_restore_quarantine' || action === 'mlla_delete_quarantine'){ payload.quarantine_id = $btn.data('quarantine-id'); }
      const original = $btn.text();
      state.scanBusy = true; updatePrimaryButtons({}); abortCurrentXHR();
      $btn.prop('disabled', true).addClass('is-busy').text(MLLA.strings.busy || 'Processando...');
      state.currentXHR = request(action, payload);
      state.currentXHR.done(function(resp){ if(resp.success){ refreshTables(resp.data); toast(MLLA.strings.saved || 'Operação concluída.', 'success', 4200);} else { toast((resp.data && resp.data.message) || (MLLA.strings.error || 'Erro na operação.'), 'error', 6000);} }).fail(function(xhr,status){ if(status!=='abort'){ toast(MLLA.strings.error || 'Erro na operação.', 'error', 6000);} }).always(function(){ state.currentXHR=null; state.scanBusy=false; $btn.prop('disabled', false).removeClass('is-busy').text(original); updatePrimaryButtons({}); });
    });
  }

  bindRowAction('.mlla-remove-link', 'mlla_remove_link', MLLA.strings.confirmRemove);
  bindRowAction('.mlla-draft-post', 'mlla_move_post_to_draft', MLLA.strings.confirmDraft);
  bindRowAction('.mlla-restore-quarantine', 'mlla_restore_quarantine', MLLA.strings.confirmRestore);
  bindRowAction('.mlla-delete-quarantine', 'mlla_delete_quarantine', MLLA.strings.confirmDeleteQuarantine);

  const savedFlag = $('#mlla-settings-saved-flag').data('saved');
  if(String(savedFlag) === '1'){
    toast(MLLA.strings.settingsSaved || MLLA.strings.saved || 'Configurações salvas.', 'success', 4500);
    if(window.history && window.history.replaceState){
      const url = new URL(window.location.href);
      url.searchParams.delete('saved');
      window.history.replaceState({}, document.title, url.toString());
    }
  }

  request('mlla_get_status').done(function(resp){
    if(resp.success && resp.data && resp.data.job){ renderJob(resp.data.job); }
    request('mlla_refresh_tables').done(function(resp2){
      if(resp2.success){ refreshTables(resp2.data); }
      state.scanBusy = false;
      updatePrimaryButtons({});
    });
  });
});
