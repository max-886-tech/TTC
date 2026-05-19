<?php
/** @var string $csrfToken */
?>
<div class="admin-page-header">
  <div>
    <h1 class="admin-page-title">Media Library</h1>
    <div class="admin-page-subtitle">WordPress-style uploads manager with image edit, crop, alt text, captions, and clean selection flow.</div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-outline-dark" id="refreshMediaBtn"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
    <label class="btn btn-dark mb-0" for="mediaFile"><i class="bi bi-upload me-1"></i> Add Media</label>
  </div>
</div>

<style>
  .media-shell { display:grid; grid-template-columns:minmax(0, 1fr) 360px; gap:1rem; }
  .media-toolbar { position:sticky; top:.75rem; z-index:5; }
  .media-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(168px,1fr)); gap:1rem; }
  .media-card { border:1px solid rgba(15,23,42,.08); border-radius:18px; background:#fff; overflow:hidden; cursor:pointer; transition:transform .14s ease, box-shadow .14s ease, border-color .14s ease; box-shadow:0 6px 18px rgba(2,6,23,.05); }
  .media-card:hover { transform:translateY(-2px); box-shadow:0 14px 30px rgba(2,6,23,.08); }
  .media-card.active { border-color:#2271b1; box-shadow:0 0 0 3px rgba(34,113,177,.14), 0 16px 34px rgba(2,6,23,.10); }
  .media-thumb { aspect-ratio:1/1; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; }
  .media-thumb img { width:100%; height:100%; object-fit:cover; }
  .media-meta { padding:.85rem; }
  .media-side { position:sticky; top:1.1rem; }
  .media-preview { border:1px solid rgba(15,23,42,.08); border-radius:18px; overflow:hidden; background:#f8fafc; }
  .media-preview img { width:100%; display:block; max-height:260px; object-fit:contain; background:#f8fafc; }
  .media-empty { min-height:520px; display:flex; align-items:center; justify-content:center; text-align:center; color:#6b7280; }
  .media-path { font-size:.79rem; word-break:break-all; }
  .crop-stage { position:relative; display:inline-block; max-width:100%; user-select:none; }
  .crop-stage img { max-width:100%; height:auto; display:block; }
  .crop-box { position:absolute; border:2px solid #2271b1; background:rgba(34,113,177,.16); display:none; box-shadow:0 0 0 9999px rgba(15,23,42,.26); }
  .media-dropzone { border:2px dashed rgba(34,113,177,.25); border-radius:18px; background:linear-gradient(180deg, rgba(34,113,177,.04), rgba(255,255,255,.6)); }
  @media (max-width: 1199px){ .media-shell{ grid-template-columns:1fr; } .media-side{ position:static; } }
</style>

<div class="media-shell">
  <div>
    <div class="admin-panel mb-3 media-toolbar">
      <div class="admin-panel-body">
        <div class="row g-3 align-items-end">
          <div class="col-lg-4">
            <label class="form-label">Search media</label>
            <input type="text" class="form-control" id="mediaSearch" placeholder="Search file name, title, alt, caption or folder">
          </div>
          <div class="col-md-3 col-lg-2">
            <label class="form-label">Folder</label>
            <select class="form-select" id="mediaYm"><option value="">All folders</option></select>
          </div>
          <div class="col-md-3 col-lg-2">
            <label class="form-label">Sort</label>
            <select class="form-select" id="mediaSort">
              <option value="newest">Newest first</option>
              <option value="oldest">Oldest first</option>
              <option value="name_asc">Name A-Z</option>
              <option value="name_desc">Name Z-A</option>
            </select>
          </div>
          <div class="col-md-3 col-lg-2">
            <label class="form-label">View</label>
            <select class="form-select" id="mediaDensity">
              <option value="compact">Compact grid</option>
              <option value="comfortable">Comfortable grid</option>
            </select>
          </div>
          <div class="col-lg-2">
            <div class="small text-muted" id="mediaStats">Loading media...</div>
          </div>
        </div>
        <input type="file" id="mediaFile" class="d-none" accept="image/*" multiple>
        <div id="uploadMsg" class="mt-3"></div>
      </div>
    </div>

    <div class="admin-panel media-dropzone mb-3" id="dropzone">
      <div class="admin-panel-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <div class="fw-semibold">Drag and drop images here</div>
          <div class="small text-muted">Uploads go to <span class="mono">/uploads/YYYY/MM/</span> like WordPress-style monthly folders.</div>
        </div>
        <label class="btn btn-outline-dark mb-0" for="mediaFile"><i class="bi bi-images me-1"></i> Choose Files</label>
      </div>
    </div>

    <div id="mediaGridWrap" class="admin-panel">
      <div class="admin-panel-body">
        <div class="media-grid" id="mediaGrid"></div>
      </div>
    </div>
  </div>

  <div class="media-side">
    <div class="admin-panel" id="mediaSidebar">
      <div class="admin-panel-body p-0" id="mediaSidebarBody">
        <div class="media-empty p-4">
          <div>
            <div class="display-6 mb-2"><i class="bi bi-image"></i></div>
            <div class="fw-semibold">Select an image</div>
            <div class="small mt-1">You can rename the file, edit title, alt text, caption, description, copy URL, and crop the image.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="cropModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content rounded-4 border-0 shadow-lg">
      <div class="modal-header">
        <h5 class="modal-title">Crop image</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-light border rounded-4 small mb-3">Drag on the image to select the crop area, then save crop.</div>
        <div class="text-center">
          <div class="crop-stage" id="cropStage">
            <img src="" id="cropImage" alt="Crop preview">
            <div class="crop-box" id="cropBox"></div>
          </div>
        </div>
        <div class="row g-3 mt-2">
          <div class="col-sm-3"><label class="form-label">X</label><input type="number" class="form-control" id="cropX" min="0"></div>
          <div class="col-sm-3"><label class="form-label">Y</label><input type="number" class="form-control" id="cropY" min="0"></div>
          <div class="col-sm-3"><label class="form-label">Width</label><input type="number" class="form-control" id="cropW" min="1"></div>
          <div class="col-sm-3"><label class="form-label">Height</label><input type="number" class="form-control" id="cropH" min="1"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-dark" id="saveCropBtn"><i class="bi bi-crop me-1"></i> Save Crop</button>
      </div>
    </div>
  </div>
</div>

<script>
const csrfToken = <?= json_encode($csrfToken) ?>;
const mediaGrid = document.getElementById('mediaGrid');
const mediaGridWrap = document.getElementById('mediaGridWrap');
const mediaSearch = document.getElementById('mediaSearch');
const mediaStats = document.getElementById('mediaStats');
const mediaYm = document.getElementById('mediaYm');
const mediaSort = document.getElementById('mediaSort');
const mediaDensity = document.getElementById('mediaDensity');
const uploadMsg = document.getElementById('uploadMsg');
const mediaFile = document.getElementById('mediaFile');
const mediaSidebarBody = document.getElementById('mediaSidebarBody');
const dropzone = document.getElementById('dropzone');
const refreshMediaBtn = document.getElementById('refreshMediaBtn');
const cropModalEl = document.getElementById('cropModal');
const cropStage = document.getElementById('cropStage');
const cropImage = document.getElementById('cropImage');
const cropBox = document.getElementById('cropBox');
const cropX = document.getElementById('cropX');
const cropY = document.getElementById('cropY');
const cropW = document.getElementById('cropW');
const cropH = document.getElementById('cropH');
const saveCropBtn = document.getElementById('saveCropBtn');

let itemsCache = [];
let currentItem = null;
let dragStart = null;
let cropState = null;
let cropModal = null;

function getCropModal(){
  if (!cropModalEl) return null;
  if (!window.bootstrap || !window.bootstrap.Modal) return null;
  if (!cropModal) cropModal = new bootstrap.Modal(cropModalEl);
  return cropModal;
}

function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
function fmtBytes(v){ v = Number(v || 0); if(v < 1024) return v + ' B'; if(v < 1024*1024) return (v/1024).toFixed(1) + ' KB'; return (v/1024/1024).toFixed(2) + ' MB'; }
function fmtDate(ts){ return ts ? new Date(Number(ts) * 1000).toLocaleString() : ''; }
function nl2br(s){ return esc(s).replace(/\n/g, '<br>'); }

function mediaUrl(itemOrPath, version=''){
  let p = '';

  if (itemOrPath && typeof itemOrPath === 'object') {
    p = String(itemOrPath.url || itemOrPath.path || '').trim();
  } else {
    p = String(itemOrPath || '').trim();
  }

  p = p.replace(/\\/g, '/');

  if (p && !/^https?:\/\//i.test(p) && !p.startsWith('/')) {
    p = '/' + p;
  }

  p = p.split('/').map((seg, i) => {
    if (i === 0 || /^https?:$/i.test(seg)) return seg;
    return encodeURIComponent(seg);
  }).join('/');

  return version ? `${p}?v=${encodeURIComponent(version)}` : p;
}

function setGridDensity(){
  mediaGrid.style.gridTemplateColumns = mediaDensity.value === 'comfortable'
    ? 'repeat(auto-fill, minmax(210px,1fr))'
    : 'repeat(auto-fill, minmax(168px,1fr))';
}

function fillFolders(folders){
  const current = mediaYm.value;
  mediaYm.innerHTML = '<option value="">All folders</option>' + (folders || []).map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('');
  if ([...mediaYm.options].some(opt => opt.value === current)) mediaYm.value = current;
}

function renderSidebar(item){
  if(!item){
    mediaSidebarBody.innerHTML = `<div class="media-empty p-4"><div><div class="display-6 mb-2"><i class="bi bi-image"></i></div><div class="fw-semibold">Select an image</div><div class="small mt-1">You can rename the file, edit title, alt text, caption, description, copy URL, and crop the image.</div></div></div>`;
    return;
  }

  currentItem = item;
  const previewUrl = mediaUrl(item, item.mtime || Date.now());
  const copyUrl = mediaUrl(item);

  mediaSidebarBody.innerHTML = `
    <div class="p-3 border-bottom">
      <div class="media-preview mb-3">
        <img
          src="${previewUrl}"
          alt="${esc(item.alt || item.title || item.name)}"
          onerror="this.outerHTML='<div class=&quot;p-4 text-center text-muted small&quot;>Preview failed to load</div>'"
        >
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-sm btn-outline-dark" id="copyMediaUrlBtn"><i class="bi bi-link-45deg me-1"></i> Copy URL</button>
        <button class="btn btn-sm btn-outline-dark" id="openCropBtn"><i class="bi bi-crop me-1"></i> Crop</button>
        <button class="btn btn-sm btn-outline-danger" id="deleteMediaBtn"><i class="bi bi-trash me-1"></i> Delete</button>
      </div>
    </div>
    <div class="p-3">
      <div class="small text-muted mb-2">Attachment details</div>
      <div class="small"><strong>File:</strong> ${esc(item.name)}</div>
      <div class="small"><strong>Dimensions:</strong> ${item.width || '-'} × ${item.height || '-'}</div>
      <div class="small"><strong>Size:</strong> ${fmtBytes(item.size)}</div>
      <div class="small"><strong>Folder:</strong> ${esc(item.ym || '-')}</div>
      <div class="small"><strong>Uploaded:</strong> ${fmtDate(item.mtime)}</div>
      <div class="media-path text-muted mt-2">${esc(copyUrl)}</div>
      <hr>
      <form id="mediaEditForm" class="vstack gap-3">
        <input type="hidden" name="path" value="${esc(item.path)}">
        <div>
          <label class="form-label">File name</label>
          <div class="input-group">
            <input type="text" class="form-control" name="basename" value="${esc(item.basename || item.name.replace(/\.[^.]+$/, ''))}">
            <span class="input-group-text">.${esc((item.name.split('.').pop() || '').toLowerCase())}</span>
          </div>
        </div>
        <div>
          <label class="form-label">Title</label>
          <input type="text" class="form-control" name="title" value="${esc(item.title || '')}">
        </div>
        <div>
          <label class="form-label">Alt text</label>
          <input type="text" class="form-control" name="alt" value="${esc(item.alt || '')}" placeholder="Describe the image for accessibility">
        </div>
        <div>
          <label class="form-label">Caption</label>
          <textarea class="form-control" name="caption" rows="2">${esc(item.caption || '')}</textarea>
        </div>
        <div>
          <label class="form-label">Description</label>
          <textarea class="form-control" name="description" rows="3">${esc(item.description || '')}</textarea>
        </div>
        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-dark"><i class="bi bi-check2-circle me-1"></i> Save Details</button>
        </div>
        <div id="mediaFormMsg"></div>
      </form>
    </div>`;

  document.getElementById('copyMediaUrlBtn').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(copyUrl || '');
    } catch (err) {
      alert('Could not copy URL.');
    }
  });

  document.getElementById('openCropBtn').addEventListener('click', openCropModal);
  document.getElementById('deleteMediaBtn').addEventListener('click', () => deleteMedia(item.path));
  document.getElementById('mediaEditForm').addEventListener('submit', saveMediaDetails);
}

function renderGrid(items){
  itemsCache = items || [];
  mediaStats.textContent = `${itemsCache.length} file(s) shown`;

  if(!itemsCache.length){
    mediaGrid.innerHTML = '<div class="alert alert-light border rounded-4 mb-0">No uploads found.</div>';
    renderSidebar(null);
    return;
  }

  mediaGrid.innerHTML = itemsCache.map(item => `
    <div class="media-card ${currentItem && currentItem.path === item.path ? 'active' : ''}" data-path="${esc(item.path)}">
      <div class="media-thumb">
        <img
          src="${mediaUrl(item, item.mtime || '')}"
          alt="${esc(item.alt || item.title || item.name)}"
          onerror="this.closest('.media-thumb').innerHTML='<div class=&quot;text-muted small p-3 text-center&quot;>Image not found</div>'"
        >
      </div>
      <div class="media-meta">
        <div class="fw-semibold small text-break">${esc(item.title || item.name)}</div>
        <div class="text-muted small text-break">${esc(item.name)}</div>
        <div class="text-muted" style="font-size:.78rem">${esc(item.ym || '')} • ${fmtBytes(item.size)}</div>
      </div>
    </div>`).join('');

  [...mediaGrid.querySelectorAll('.media-card')].forEach(card => {
    card.addEventListener('click', () => {
      const path = card.dataset.path || '';
      const item = itemsCache.find(x => x.path === path);
      [...mediaGrid.querySelectorAll('.media-card')].forEach(el => el.classList.remove('active'));
      card.classList.add('active');
      renderSidebar(item || null);
    });
  });

  if(currentItem){
    const next = itemsCache.find(x => x.path === currentItem.path);
    renderSidebar(next || itemsCache[0]);
    const activeCard = mediaGrid.querySelector(`.media-card[data-path="${CSS.escape((next || itemsCache[0]).path)}"]`);
    if(activeCard) activeCard.classList.add('active');
  }
}

async function loadMedia(preferredPath = null){
  mediaGrid.innerHTML = '<div class="alert alert-light border rounded-4 mb-0">Loading media...</div>';

  try {
    const params = new URLSearchParams();
    if (mediaSearch.value) params.set('q', mediaSearch.value);
    if (mediaYm.value) params.set('ym', mediaYm.value);
    if (mediaSort.value) params.set('sort', mediaSort.value);

    const res = await fetch('/admin/media_list.php?' + params.toString(), {credentials:'same-origin'});
    const data = await res.json();

    if(!data.ok){
      mediaGrid.innerHTML = '<div class="alert alert-danger rounded-4 mb-0">Failed to load media.</div>';
      return;
    }

    fillFolders(data.folders || []);
    renderGrid(data.items || []);

    const toSelect = preferredPath || (currentItem && currentItem.path);
    if(toSelect){
      const found = (data.items || []).find(x => x.path === toSelect);
      if(found){
        renderSidebar(found);
        const card = mediaGrid.querySelector(`.media-card[data-path="${CSS.escape(found.path)}"]`);
        if(card){
          [...mediaGrid.querySelectorAll('.media-card')].forEach(el => el.classList.remove('active'));
          card.classList.add('active');
        }
      }
    }
  } catch (err) {
    mediaGrid.innerHTML = '<div class="alert alert-danger rounded-4 mb-0">Failed to load media.</div>';
  }
}

async function uploadFiles(files){
  const list = [...(files || [])].filter(Boolean);
  if(!list.length) return;

  uploadMsg.innerHTML = `<div class="alert alert-light border rounded-4 mb-0">Uploading ${list.length} file(s)...</div>`;
  let lastPath = null;

  for (const file of list) {
    const fd = new FormData();
    fd.append('file', file);

    try {
      const res = await fetch('/admin/media_upload.php', {method:'POST', body:fd, credentials:'same-origin'});
      const data = await res.json();

      if(!data.location){
        uploadMsg.innerHTML = `<div class="alert alert-danger rounded-4 mb-0">${esc(data.error || 'Upload failed')}</div>`;
        mediaFile.value = '';
        return;
      }

      lastPath = data.path || data.relative || lastPath;
    } catch (err) {
      uploadMsg.innerHTML = `<div class="alert alert-danger rounded-4 mb-0">Upload failed</div>`;
      mediaFile.value = '';
      return;
    }
  }

  uploadMsg.innerHTML = '<div class="alert alert-success rounded-4 mb-0">Upload complete.</div>';
  mediaFile.value = '';
  await loadMedia(lastPath || (currentItem && currentItem.path) || null);
}

async function saveMediaDetails(e){
  e.preventDefault();
  const form = e.currentTarget;
  const msg = document.getElementById('mediaFormMsg');
  const fd = new FormData(form);
  fd.append('_csrf', csrfToken);

  msg.innerHTML = '<div class="alert alert-light border rounded-4 mb-0">Saving...</div>';

  try {
    const res = await fetch('/admin/media_update.php', {method:'POST', body:fd, credentials:'same-origin'});
    const data = await res.json();

    if(!data.ok){
      msg.innerHTML = `<div class="alert alert-danger rounded-4 mb-0">${esc(data.error || 'Save failed')}</div>`;
      return;
    }

    currentItem = data.item;
    msg.innerHTML = '<div class="alert alert-success rounded-4 mb-0">Details saved.</div>';
    await loadMedia(currentItem.path);
  } catch (err) {
    msg.innerHTML = '<div class="alert alert-danger rounded-4 mb-0">Save failed</div>';
  }
}

async function deleteMedia(path){
  if(!path) return;
  if(!confirm('Delete this media file?')) return;

  const fd = new FormData();
  fd.append('_csrf', csrfToken);
  fd.append('path', path);

  try {
    const res = await fetch('/admin/media_delete.php', {method:'POST', body:fd, credentials:'same-origin'});
    const data = await res.json();
    if(!data.ok){ alert(data.error || 'Delete failed'); return; }
    currentItem = null;
    await loadMedia();
  } catch (err) {
    alert('Delete failed');
  }
}

function openCropModal(){
  if(!currentItem) return;

  cropImage.src = mediaUrl(currentItem, currentItem.mtime || Date.now());
  cropBox.style.display = 'none';
  cropState = null;
  cropX.value = 0;
  cropY.value = 0;
  cropW.value = currentItem.width || 0;
  cropH.value = currentItem.height || 0;

  const modal = getCropModal();
  if (!modal) {
    alert('Bootstrap JS not ready.');
    return;
  }
  modal.show();
}

function pointerPos(ev){
  const rect = cropImage.getBoundingClientRect();
  const clientX = ev.clientX ?? (ev.touches && ev.touches[0] ? ev.touches[0].clientX : 0);
  const clientY = ev.clientY ?? (ev.touches && ev.touches[0] ? ev.touches[0].clientY : 0);
  return { x: Math.max(0, Math.min(rect.width, clientX - rect.left)), y: Math.max(0, Math.min(rect.height, clientY - rect.top)), rect };
}

function applyCropBox(x1, y1, x2, y2){
  const left = Math.min(x1, x2), top = Math.min(y1, y2);
  const width = Math.abs(x2 - x1), height = Math.abs(y2 - y1);

  cropBox.style.left = left + 'px';
  cropBox.style.top = top + 'px';
  cropBox.style.width = width + 'px';
  cropBox.style.height = height + 'px';
  cropBox.style.display = width > 2 && height > 2 ? 'block' : 'none';

  const rect = cropImage.getBoundingClientRect();
  const scaleX = rect.width > 0 ? (currentItem?.width || 1) / rect.width : 1;
  const scaleY = rect.height > 0 ? (currentItem?.height || 1) / rect.height : 1;

  cropX.value = Math.round(left * scaleX);
  cropY.value = Math.round(top * scaleY);
  cropW.value = Math.max(1, Math.round(width * scaleX));
  cropH.value = Math.max(1, Math.round(height * scaleY));
}

cropImage.addEventListener('pointerdown', (ev) => {
  if(!currentItem) return;
  dragStart = pointerPos(ev);
  cropImage.setPointerCapture(ev.pointerId);
  applyCropBox(dragStart.x, dragStart.y, dragStart.x, dragStart.y);
});

cropImage.addEventListener('pointermove', (ev) => {
  if(!dragStart) return;
  const pos = pointerPos(ev);
  applyCropBox(dragStart.x, dragStart.y, pos.x, pos.y);
});

function endCrop(ev){
  if(!dragStart) return;
  dragStart = null;
  try { cropImage.releasePointerCapture(ev.pointerId); } catch(err){}
}

cropImage.addEventListener('pointerup', endCrop);
cropImage.addEventListener('pointercancel', endCrop);

saveCropBtn.addEventListener('click', async () => {
  if(!currentItem) return;

  const fd = new FormData();
  fd.append('_csrf', csrfToken);
  fd.append('path', currentItem.path);
  fd.append('x', cropX.value || '0');
  fd.append('y', cropY.value || '0');
  fd.append('w', cropW.value || '0');
  fd.append('h', cropH.value || '0');

  saveCropBtn.disabled = true;
  const oldHtml = saveCropBtn.innerHTML;
  saveCropBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving';

  try {
    const res = await fetch('/admin/media_crop.php', {method:'POST', body:fd, credentials:'same-origin'});
    const data = await res.json();

    saveCropBtn.disabled = false;
    saveCropBtn.innerHTML = oldHtml;

    if(!data.ok){ alert(data.error || 'Crop failed'); return; }

    currentItem = data.item;
    const modal = getCropModal();
    if (modal) modal.hide();
    await loadMedia(currentItem.path);
  } catch (err) {
    saveCropBtn.disabled = false;
    saveCropBtn.innerHTML = oldHtml;
    alert('Crop failed');
  }
});

mediaFile.addEventListener('change', () => uploadFiles(mediaFile.files));
refreshMediaBtn.addEventListener('click', () => loadMedia(currentItem && currentItem.path));
mediaDensity.addEventListener('change', setGridDensity);

let searchTimer = null;
mediaSearch.addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadMedia, 250);
});
mediaYm.addEventListener('change', loadMedia);
mediaSort.addEventListener('change', loadMedia);

['dragenter','dragover'].forEach(name => dropzone.addEventListener(name, e => {
  e.preventDefault();
  dropzone.classList.add('border-dark');
}));
['dragleave','drop'].forEach(name => dropzone.addEventListener(name, e => {
  e.preventDefault();
  dropzone.classList.remove('border-dark');
}));
dropzone.addEventListener('drop', e => uploadFiles(e.dataTransfer?.files || []));

setGridDensity();
loadMedia();
</script>
<?php require_once __DIR__ . '/_footer.php'; ?>