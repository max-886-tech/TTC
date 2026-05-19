window.TTCDragImageBuilder = (function(){
  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
  function num(v, d=0){ v = parseFloat(v); return Number.isFinite(v) ? v : d; }
  function clamp(v,min,max){ return Math.max(min, Math.min(max, v)); }

  function create(container, options){
    const state = {
      imageUrl: options.imageUrl || '',
      imageW: num(options.imageW, 0),
      imageH: num(options.imageH, 0),
      visualModeEl: options.visualModeEl,
      imageUrlEl: options.imageUrlEl,
      hiddenPiecesEl: options.hiddenPiecesEl,
      hiddenTargetsEl: options.hiddenTargetsEl,
      hiddenImageWEl: options.hiddenImageWEl,
      hiddenImageHEl: options.hiddenImageHEl,
      slotCountEl: options.slotCountEl,
      stageWrapEl: options.stageWrapEl,
      pieces: Array.isArray(options.pieces) ? options.pieces : [],
      targets: Array.isArray(options.targets) ? options.targets : [],
      imageLoaded: false
    };
    function normalizeRect(r, fallbackLabel){
      return {
        x: clamp(num(r.x, 10), 0, 10000),
        y: clamp(num(r.y, 10), 0, 10000),
        w: Math.max(20, num(r.w, 120)),
        h: Math.max(20, num(r.h, 40)),
        label: String(r.label || fallbackLabel || '').trim(),
        target_slot: parseInt(r.target_slot || 0, 10) || 0
      };
    }
    state.pieces = state.pieces.map((r,i)=>normalizeRect(r, 'Piece ' + (i+1)));
    state.targets = state.targets.map((r,i)=>normalizeRect(r, 'Target ' + (i+1)));
    const root = document.createElement('div');
    root.className = 'border rounded-4 p-3 bg-light';
    root.innerHTML = `
      <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <button type="button" class="btn btn-sm btn-outline-primary" data-act="add-piece"><i class="bi bi-crop me-1"></i>Draw Draggable Crop</button>
        <button type="button" class="btn btn-sm btn-outline-success" data-act="add-target"><i class="bi bi-bounding-box me-1"></i>Draw Target Area</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-act="fit">Refresh Overlay</button>
        <span class="small text-muted" data-draw-hint>Click a draw button, then drag on the image for an exact rectangle. Existing rectangles can still be moved and resized.</span>
      </div>
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="position-relative border rounded-4 bg-white overflow-auto user-select-none" style="min-height:280px" data-stage>
            <img alt="" class="w-100 d-block" style="max-width:100%; height:auto;" />
            <div class="position-absolute top-0 start-0 w-100 h-100" data-layer></div>
            <div class="position-absolute border border-2 d-none" data-draw-box style="pointer-events:none;background:rgba(13,110,253,.08);"></div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="border rounded-4 p-3 bg-white">
            <div class="fw-semibold mb-2">Target Mapping → Piece</div>
            <div class="small text-muted mb-2">Choose which piece belongs in each target. Unused pieces become distractors automatically.</div>
            <div data-mapping-list class="d-flex flex-column gap-2"></div>
          </div>
        </div>
      </div>`;
    state.stageWrapEl.innerHTML = '';
    state.stageWrapEl.appendChild(root);
    const img = root.querySelector('img');
    const layer = root.querySelector('[data-layer]');
    const mappingList = root.querySelector('[data-mapping-list]');
    const stage = root.querySelector('[data-stage]');
    const drawBox = root.querySelector('[data-draw-box]');
    const drawHint = root.querySelector('[data-draw-hint]');
    let drawMode = null;


    function setDrawMode(next){
      drawMode = next;
      if(drawHint){
        drawHint.textContent = next ? `Drawing ${next === 'piece' ? 'draggable crop' : 'target area'}: drag on the image to create an exact rectangle.` : 'Click a draw button, then drag on the image for an exact rectangle. Existing rectangles can still be moved and resized.';
      }
      if(drawBox) drawBox.classList.add('d-none');
      if(stage) stage.style.cursor = next ? 'crosshair' : '';
    }
    function stagePoint(ev){
      const p = point(ev);
      const r = img.getBoundingClientRect();
      return { x: p.x - r.left, y: p.y - r.top, rect: r };
    }
    function displayRectToImageRect(box){
      const sc = imageScale();
      return {
        x: clamp(box.x / (sc.sx || 1), 0, Math.max(0, state.imageW - 1)),
        y: clamp(box.y / (sc.sy || 1), 0, Math.max(0, state.imageH - 1)),
        w: Math.max(20, box.w / (sc.sx || 1)),
        h: Math.max(20, box.h / (sc.sy || 1))
      };
    }
    function syncHidden(){
      if(state.hiddenPiecesEl) state.hiddenPiecesEl.value = JSON.stringify(state.pieces);
      if(state.hiddenTargetsEl) state.hiddenTargetsEl.value = JSON.stringify(state.targets);
      if(state.hiddenImageWEl) state.hiddenImageWEl.value = state.imageW ? String(Math.round(state.imageW)) : '';
      if(state.hiddenImageHEl) state.hiddenImageHEl.value = state.imageH ? String(Math.round(state.imageH)) : '';
      if(state.slotCountEl) state.slotCountEl.value = String(Math.max(1, state.targets.length || 1));
    }
    function renderMapping(){
      mappingList.innerHTML = '';
      const slotCount = state.targets.length;

      for(let slot=1; slot<=slotCount; slot++) {
        const assignedIndex = state.pieces.findIndex(p => (parseInt(p.target_slot || 0, 10) || 0) === slot);
        const row = document.createElement('div');
        row.className = 'border rounded-3 p-2';
        let opts = '<option value="">Select piece</option>';
        state.pieces.forEach((p, idx) => {
          const selected = idx === assignedIndex ? 'selected' : '';
          opts += `<option value="${idx}" ${selected}>${esc(p.label || ('Piece ' + (idx + 1)))}</option>`;
        });
        row.innerHTML = `<div class="small fw-semibold mb-1">Target ${slot}</div><select class="form-select form-select-sm">${opts}</select>`;
        row.querySelector('select').addEventListener('change', e => {
          const nextIdx = e.target.value === '' ? -1 : parseInt(e.target.value, 10);
          state.pieces.forEach((p) => {
            if((parseInt(p.target_slot || 0, 10) || 0) === slot) p.target_slot = 0;
          });
          if(Number.isInteger(nextIdx) && nextIdx >= 0 && state.pieces[nextIdx]) {
            state.pieces[nextIdx].target_slot = slot;
          }
          renderMapping();
          syncHidden();
        });
        mappingList.appendChild(row);
      }

      const distractors = state.pieces
        .map((p, idx) => ({ label: p.label || ('Piece ' + (idx + 1)), target_slot: parseInt(p.target_slot || 0, 10) || 0 }))
        .filter(p => p.target_slot <= 0)
        .map(p => esc(p.label));

      const info = document.createElement('div');
      info.className = 'border rounded-3 p-2 bg-light';
      info.innerHTML = `<div class="small fw-semibold mb-1">Distractor Pieces</div><div class="small text-muted">${distractors.length ? distractors.join(', ') : 'None'}</div>`;
      mappingList.appendChild(info);
    }
    function ensureImage(){
      const url = String(state.imageUrlEl ? state.imageUrlEl.value : state.imageUrl || '').trim();
      state.imageUrl = url;
      if(url){
        img.src = url;
        img.style.display = '';
      } else {
        img.removeAttribute('src');
        img.style.display = 'none';
      }
    }
    function imageScale(){
      if(!state.imageLoaded || !state.imageW || !state.imageH) return {sx:1, sy:1};
      const rect = img.getBoundingClientRect();
      return { sx: rect.width / state.imageW, sy: rect.height / state.imageH };
    }
    function toStyle(r){
      const sc = imageScale();
      return `left:${r.x*sc.sx}px;top:${r.y*sc.sy}px;width:${r.w*sc.sx}px;height:${r.h*sc.sy}px;`;
    }
    function renderRects(){
      layer.innerHTML = '';
      const items = [];
      state.targets.forEach((r, idx) => items.push({kind:'target', idx, rect:r}));
      state.pieces.forEach((r, idx) => items.push({kind:'piece', idx, rect:r}));
      items.forEach(item => {
        const el = document.createElement('div');
        el.className = 'position-absolute border';
        el.style.cssText = toStyle(item.rect) + (item.kind === 'piece' ? 'background:rgba(13,110,253,.12);border:2px solid #0d6efd;' : 'background:rgba(25,135,84,.12);border:2px dashed #198754;');
        el.innerHTML = `<div class="position-absolute top-0 start-0 translate-middle-y ms-2 badge ${item.kind==='piece'?'text-bg-primary':'text-bg-success'}">${esc(item.rect.label || (item.kind==='piece'?'Piece ':'Target ') + (item.idx+1))}</div>
          <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 p-0" style="width:20px;height:20px;line-height:18px;" data-remove>&times;</button>
          <div class="position-absolute end-0 bottom-0 bg-dark" data-resize style="width:12px;height:12px;cursor:nwse-resize"></div>`;
        layer.appendChild(el);
        bindRectInteractions(el, item.kind, item.idx);
      });
      renderMapping();
      syncHidden();
    }
    function point(ev){ const t = ev.touches && ev.touches[0] ? ev.touches[0] : ev; return {x:t.clientX, y:t.clientY}; }
    function bindRectInteractions(el, kind, idx){
      const rect = kind === 'piece' ? state.pieces[idx] : state.targets[idx];
      el.querySelector('[data-remove]').addEventListener('click', () => { if(kind === 'piece') state.pieces.splice(idx,1); else state.targets.splice(idx,1); renumber(); });
      const resize = el.querySelector('[data-resize]');
      let mode = null, startX=0, startY=0, startRect=null;
      function onMove(ev){
        if(!mode) return;
        if(ev.cancelable) ev.preventDefault();
        const p = point(ev), sc = imageScale();
        const dx = (p.x - startX) / (sc.sx || 1), dy = (p.y - startY) / (sc.sy || 1);
        if(mode === 'move'){
          rect.x = clamp(startRect.x + dx, 0, Math.max(0, state.imageW - rect.w));
          rect.y = clamp(startRect.y + dy, 0, Math.max(0, state.imageH - rect.h));
        } else {
          rect.w = clamp(startRect.w + dx, 20, Math.max(20, state.imageW - rect.x));
          rect.h = clamp(startRect.h + dy, 20, Math.max(20, state.imageH - rect.y));
        }
        renderRects();
      }
      function onUp(){
        mode = null;
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', onUp);
      }
      function start(ev, nextMode){
        if(ev.cancelable) ev.preventDefault();
        ev.stopPropagation();
        const p = point(ev);
        startX = p.x; startY = p.y; startRect = {x:rect.x, y:rect.y, w:rect.w, h:rect.h}; mode = nextMode;
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        document.addEventListener('touchmove', onMove, {passive:false});
        document.addEventListener('touchend', onUp);
      }
      resize.addEventListener('mousedown', ev => start(ev, 'resize'));
      resize.addEventListener('touchstart', ev => start(ev, 'resize'), {passive:false});
      el.addEventListener('mousedown', ev => { if(ev.target.closest('[data-remove]') || ev.target.closest('[data-resize]')) return; start(ev,'move'); });
      el.addEventListener('touchstart', ev => { if(ev.target.closest('[data-remove]') || ev.target.closest('[data-resize]')) return; start(ev,'move'); }, {passive:false});
    }
    function renumber(){
      state.pieces.forEach((p,i)=>{ p.label = 'Piece ' + (i+1); if((p.target_slot||0) > state.targets.length) p.target_slot = 0; });
      state.targets.forEach((t,i)=>{ t.label = 'Target ' + (i+1); });
      renderRects();
    }
    function addPiece(){ beginDraw('piece'); }
    function addTarget(){ beginDraw('target'); }

    function beginDraw(kind){
      if(!state.imageLoaded || !state.imageW || !state.imageH){ alert('Load the image first.'); return; }
      setDrawMode(kind);
    }
    function bindDrawInteractions(){
      let active = null;
      function finish(){
        if(!active) return;
        const box = active.box;
        const kind = active.kind;
        active = null;
        if(drawBox) drawBox.classList.add('d-none');
        if(box.w >= 12 && box.h >= 12){
          const r = displayRectToImageRect(box);
          if(kind === 'piece') state.pieces.push(normalizeRect({x:r.x,y:r.y,w:r.w,h:r.h,target_slot:0}, 'Piece ' + (state.pieces.length+1)));
          else state.targets.push(normalizeRect({x:r.x,y:r.y,w:r.w,h:r.h}, 'Target ' + (state.targets.length+1)));
          renumber();
        } else {
          renderRects();
        }
        setDrawMode(null);
      }
      function move(ev){
        if(!active) return;
        if(ev.cancelable) ev.preventDefault();
        const p = stagePoint(ev);
        const r = active.base;
        const maxW = Math.max(0, r.rect.width);
        const maxH = Math.max(0, r.rect.height);
        const x1 = clamp(Math.min(r.x, p.x), 0, maxW);
        const y1 = clamp(Math.min(r.y, p.y), 0, maxH);
        const x2 = clamp(Math.max(r.x, p.x), 0, maxW);
        const y2 = clamp(Math.max(r.y, p.y), 0, maxH);
        active.box = {x:x1, y:y1, w:Math.max(1, x2-x1), h:Math.max(1, y2-y1)};
        if(drawBox){
          drawBox.classList.remove('d-none');
          drawBox.style.left = `${x1}px`;
          drawBox.style.top = `${y1}px`;
          drawBox.style.width = `${Math.max(1, x2-x1)}px`;
          drawBox.style.height = `${Math.max(1, y2-y1)}px`;
          drawBox.style.borderColor = active.kind === 'piece' ? '#0d6efd' : '#198754';
          drawBox.style.background = active.kind === 'piece' ? 'rgba(13,110,253,.08)' : 'rgba(25,135,84,.08)';
        }
      }
      function up(){
        document.removeEventListener('mousemove', move);
        document.removeEventListener('mouseup', up);
        document.removeEventListener('touchmove', move);
        document.removeEventListener('touchend', up);
        finish();
      }
      function down(ev){
        if(!drawMode) return;
        if(ev.target.closest('[data-layer]') !== layer && ev.target !== img && ev.target !== stage) return;
        if(ev.cancelable) ev.preventDefault();
        ev.stopPropagation();
        const p = stagePoint(ev);
        active = { kind: drawMode, base: p, box: {x:p.x, y:p.y, w:1, h:1} };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
        document.addEventListener('touchmove', move, {passive:false});
        document.addEventListener('touchend', up);
      }
      stage.addEventListener('mousedown', down);
      stage.addEventListener('touchstart', down, {passive:false});
    }
    img.addEventListener('load', () => { state.imageLoaded = true; state.imageW = img.naturalWidth || state.imageW || 0; state.imageH = img.naturalHeight || state.imageH || 0; syncHidden(); renderRects(); });
    window.addEventListener('resize', () => renderRects());
    bindDrawInteractions();
    root.querySelector('[data-act="add-piece"]').addEventListener('click', addPiece);
    root.querySelector('[data-act="add-target"]').addEventListener('click', addTarget);
    root.querySelector('[data-act="fit"]').addEventListener('click', renderRects);
    if(state.imageUrlEl){ state.imageUrlEl.addEventListener('input', ensureImage); state.imageUrlEl.addEventListener('change', ensureImage); }
    ensureImage();
    syncHidden();
    renderRects();
    return { getState(){ syncHidden(); return state; }, refresh(){ ensureImage(); renderRects(); } };
  }

  return { create };
})();
