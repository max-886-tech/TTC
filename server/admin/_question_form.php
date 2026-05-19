<?php
$initialIsImageSliceMode = ($qt === 'drag_drop');
?>
<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h3 class="mb-1"><?= h($pageTitle) ?></h3>
    <div class="text-muted"><span class="mono"><?= h($exam_id) ?></span> • <?= h($exam['exam_name']) ?></div>
  </div>
  <a class="btn btn-outline-dark" href="/admin/questions.php?exam_id=<?= urlencode($exam_id) ?>"><i class="bi bi-arrow-left me-1"></i> Back</a>
</div>

<?php if ($msg): ?>
  <div class="alert alert-danger rounded-4"><?= h($msg) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-4">
  <div class="card-body p-4">
    <form method="post" id="qForm">
      <?= csrf_field() ?>

      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select class="form-select" name="question_type" id="qType">
            <option value="single" <?= ($qt==='single'?'selected':'') ?>>Single (MCQ)</option>
            <option value="multiple" <?= ($qt==='multiple'?'selected':'') ?>>Multiple</option>
            <option value="yes_no" <?= ($qt==='yes_no'?'selected':'') ?>>Yes / No Radio Buttons</option>
            <option value="dropdown_matrix" <?= ($qt==='dropdown_matrix'?'selected':'') ?>>Drop-down Matrix</option>
            <option value="drag_drop" <?= ($qt==='drag_drop'?'selected':'') ?>>Drag &amp; Drop</option>
          </select>
        </div>
        <input type="hidden" name="drag_drop_mode" id="dragMode" value="categorize">
        <input type="hidden" name="drag_slot_count" id="dragSlotCount" value="<?= h($dragSlotCountValue) ?>">
        <div class="col-md-2">
          <label class="form-label">Points</label>
          <input class="form-control" type="number" name="points" value="<?= h($pointsValue) ?>" min="1">
        </div>
        <div class="col-md-2">
          <label class="form-label">Sort Order</label>
          <input class="form-control" type="number" name="sort_order" value="<?= h($sortOrderValue) ?>" min="1">
          <div class="form-text"><?= $sortHelpHtml ?></div>
          <?php if (!empty($showUseNextButton)): ?>
            <div class="mt-2"><button type="button" class="btn btn-outline-secondary btn-sm" id="btnUseNextSO">Use next</button></div>
          <?php endif; ?>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" id="act" <?= $isActiveChecked ? 'checked' : '' ?>>
            <label class="form-check-label" for="act">Active</label>
          </div>
        </div>
      </div>

      <div class="row g-3 mt-1" id="dragImageWrap">
        <input type="hidden" name="drag_visual_mode" id="dragVisualMode" value="<?= h($dragVisualModeValue) ?>">
        <div class="col-md-7" data-dd-mode="categorize_image" id="dragImageUrlWrap">
          <div class="d-flex justify-content-between align-items-center gap-2">
            <label class="form-label mb-0">Drag &amp; Drop Image URL</label>
            <a class="btn btn-sm btn-outline-secondary" href="/admin/uploads.php" target="_blank"><i class="bi bi-images me-1"></i>Media Library</a>
          </div>
          <input class="form-control" type="text" name="drag_image_url" id="dragImageUrl" value="<?= h($dragImageUrlValue) ?>" placeholder="/uploads/2026/03/example.png">
          <div class="form-text">Paste uploaded image URL, then add draggable crop areas and target areas.</div>
        </div>
        <input type="hidden" name="drag_image_pieces_json" id="dragImagePiecesJson" value="<?= h($dragImagePiecesJsonValue) ?>">
        <input type="hidden" name="drag_image_targets_json" id="dragImageTargetsJson" value="<?= h($dragImageTargetsJsonValue) ?>">
        <input type="hidden" name="drag_image_width" id="dragImageWidth" value="<?= h($dragImageWidthValue) ?>">
        <input type="hidden" name="drag_image_height" id="dragImageHeight" value="<?= h($dragImageHeightValue) ?>">
        <div class="col-12" data-dd-mode="categorize_image" id="dragImageBuilderWrap">
          <div id="dragImageBuilderStage"></div>
        </div>
      </div>

      <hr class="my-4">

      <div class="mb-3 mt-4">
        <div class="d-flex justify-content-between align-items-center gap-2">
          <label class="form-label mb-0">Case Study (optional)</label>
          <a class="btn btn-sm btn-outline-secondary" href="/admin/uploads.php" target="_blank"><i class="bi bi-images me-1"></i> Media Library</a>
        </div>
        <textarea class="form-control js-rich-editor" name="case_study_text" id="case_study_text" rows="6" placeholder="Shown above the question for all question types."><?php echo str_replace('</textarea', '</text' . 'area', (string)$caseStudyTextValue); ?></textarea>
      </div>

      <div class="mb-3 mt-4">
        <div class="d-flex justify-content-between align-items-center gap-2">
          <label class="form-label mb-0">Question</label>
          <a class="btn btn-sm btn-outline-secondary" href="/admin/uploads.php" target="_blank"><i class="bi bi-images me-1"></i> Media Library</a>
        </div>
        <textarea class="form-control js-rich-editor" name="question_text" id="question_text" rows="6" required><?php echo str_replace('</textarea', '</text' . 'area', (string)$questionTextValue); ?></textarea>
      </div>

      <div class="d-flex justify-content-between align-items-center <?= $initialIsImageSliceMode ? 'd-none' : '' ?>" id="choiceHeadWrap">
        <div>
          <h5 class="mb-0" id="choiceSectionTitle">Choices</h5>
          <div class="text-muted small" id="choiceSectionHelp">For Drag &amp; Drop, image pieces are configured from the image builder below.</div>
        </div>
        <button type="button" class="btn btn-outline-dark btn-sm" id="btnAdd"><i class="bi bi-plus-lg me-1"></i> Add Choice</button>
      </div>

      <div class="table-responsive mt-3 <?= $initialIsImageSliceMode ? 'd-none' : '' ?>" id="choiceTableWrap">
        <table class="table align-middle">
          <thead class="table-light">
            <tr>
              <th style="width: 140px;" id="thCorrect">Correct</th>
              <th id="thChoiceText">Choice Text</th>
              <th style="width: 90px;" id="thYesNoYes" class="d-none text-center">Yes</th>
              <th style="width: 90px;" id="thYesNoNo" class="d-none text-center">No</th>
              <th style="width: 260px;" id="thMatrixOptions" class="d-none">Dropdown Options</th>
              <th style="width: 220px;" id="thMatrixCorrect" class="d-none">Correct Answer</th>
              <th style="width: 120px;" class="dd-col" data-dd-mode="categorize">Target Slot</th>
              <th style="width: 180px;" class="dd-col" data-dd-mode="blanks">Blank Key</th>
              <th style="width: 100px;" class="dd-col" data-dd-mode="drag">Distractor</th>
              <th style="width: 70px;"></th>
            </tr>
          </thead>
          <tbody id="choiceBody"></tbody>
        </table>
      </div>

      <div class="mb-3 mt-4">
        <label class="form-label">Explanation (optional)</label>
        <textarea class="form-control js-rich-editor" name="explanation_text" id="explanation_text" rows="6" placeholder="Shown to the user when they click 'Show explanation' in the dumps."><?php echo str_replace('</textarea', '</text' . 'area', (string)$explanationTextValue); ?></textarea>
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-dark"><i class="bi bi-check-lg me-1"></i> <?= h($submitLabel) ?></button>
        <a class="btn btn-outline-dark" href="/admin/questions.php?exam_id=<?= urlencode($exam_id) ?>">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js" referrerpolicy="origin"></script>
<script src="/admin/tinymce_media.js"></script>
<script src="/admin/dragdrop_image_builder.js?v=<?= @filemtime(__DIR__ . '/dragdrop_image_builder.js') ?: time() ?>"></script>
<script>
const body = document.getElementById('choiceBody');
const qType = document.getElementById('qType');
const dragMode = document.getElementById('dragMode');
const dragSlotCount = document.getElementById('dragSlotCount');
const dragVisualMode = document.getElementById('dragVisualMode');
const dragImageUrl = document.getElementById('dragImageUrl');
const dragImageBuilderStage = document.getElementById('dragImageBuilderStage');
const dragImagePiecesJson = document.getElementById('dragImagePiecesJson');
const dragImageTargetsJson = document.getElementById('dragImageTargetsJson');
const dragImageWidth = document.getElementById('dragImageWidth');
const dragImageHeight = document.getElementById('dragImageHeight');
const choiceHeadWrap = document.getElementById('choiceHeadWrap');
const choiceTableWrap = document.getElementById('choiceTableWrap');
const choiceSectionTitle = document.getElementById('choiceSectionTitle');
const choiceSectionHelp = document.getElementById('choiceSectionHelp');
let dragImageBuilder = null;

function initEditorFor(el, height = 220){
  if (!el || !el.id || !window.TTCTinyMedia || !window.tinymce) return;
  if (tinymce.get(el.id)) return;
  tinymce.init(TTCTinyMedia.commonConfig('#' + el.id, { height }));
}
function initAllEditors(){
  initEditorFor(document.getElementById('case_study_text'), 240);
  initEditorFor(document.getElementById('question_text'), 260);
  initEditorFor(document.getElementById('explanation_text'), 220);
  document.querySelectorAll('.js-choice-editor').forEach(el => initEditorFor(el, 180));
}
function currentVisualMode(){
  if (qType.value === 'drag_drop') return 'image_slices';
  return '';
}
function isImageSliceMode(){ return qType.value === 'drag_drop'; }
function parseJsonSafe(text, fallback){ try { const v = JSON.parse(text || ''); return Array.isArray(v) ? v : fallback; } catch(e){ return fallback; } }
function ensureImageBuilder(){
  if (!dragImageBuilderStage || !window.TTCDragImageBuilder) return;
  if (!isImageSliceMode()) { dragImageBuilderStage.innerHTML = ''; return; }
  dragImageBuilder = window.TTCDragImageBuilder.create(dragImageBuilderStage, {
    visualModeEl: dragVisualMode,
    imageUrlEl: dragImageUrl,
    hiddenPiecesEl: dragImagePiecesJson,
    hiddenTargetsEl: dragImageTargetsJson,
    hiddenImageWEl: dragImageWidth,
    hiddenImageHEl: dragImageHeight,
    slotCountEl: dragSlotCount,
    stageWrapEl: dragImageBuilderStage,
    imageUrl: dragImageUrl ? dragImageUrl.value : '',
    imageW: parseInt(dragImageWidth ? dragImageWidth.value : '0', 10) || 0,
    imageH: parseInt(dragImageHeight ? dragImageHeight.value : '0', 10) || 0,
    pieces: parseJsonSafe(dragImagePiecesJson ? dragImagePiecesJson.value : '[]', []),
    targets: parseJsonSafe(dragImageTargetsJson ? dragImageTargetsJson.value : '[]', []),
  });
}
function slotOptionsHtml(selected){
  const max = Math.max(1, parseInt(dragSlotCount.value || '1', 10) || 1);
  let out = '<option value="0">--</option>';
  for(let i=1;i<=max;i++) out += `<option value="${i}" ${String(selected)===String(i)?'selected':''}>Slot ${i}</option>`;
  return out;
}
function buildMatrixCorrectOptions(optionsText, selected){
  const lines = String(optionsText || '').split(/\r\n|\r|\n/).map(v => v.trim()).filter(Boolean);
  let out = '<option value="0">-- Select correct answer --</option>';
  lines.forEach((line, idx) => {
    const n = idx + 1;
    out += `<option value="${n}" ${String(selected)===String(n)?'selected':''}>${line.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</option>`;
  });
  return out;
}
function refreshMatrixRow(tr){
  if(!tr) return;
  const ta = tr.querySelector('.matrix-options-text');
  const sel = tr.querySelector('.matrix-correct-select');
  if(!ta || !sel) return;
  const current = sel.dataset.selected || sel.value || '0';
  sel.innerHTML = buildMatrixCorrectOptions(ta.value || '', current);
  if (!sel.querySelector(`option[value="${current}"]`)) sel.value = '0'; else sel.value = current;
  sel.dataset.selected = sel.value || '0';
}
function bindSingleChoiceBehaviour(){
  const boxes = [...document.querySelectorAll('.correct-box')];
  boxes.forEach(box => {
    box.onchange = null;
    box.disabled = false;
    if (qType.value === 'yes_no') {
      box.type = 'checkbox';
      box.name = 'choice_correct[]';
    } else {
      box.type = qType.value === 'single' ? 'radio' : 'checkbox';
      box.name = 'choice_correct[]';
    }
    if (qType.value === 'single') {
      box.onchange = () => { if (box.checked) boxes.forEach(x => { if (x !== box) x.checked = false; }); };
    } else if (qType.value === 'drag_drop') {
      box.checked = false;
      box.disabled = true;
    }
  });
}
function updateYesNoTableLabels(){
  const thCorrect = document.getElementById('thCorrect');
  const thChoiceText = document.getElementById('thChoiceText');
  const thYes = document.getElementById('thYesNoYes');
  const thNo = document.getElementById('thYesNoNo');
  const thMatrixOptions = document.getElementById('thMatrixOptions');
  const thMatrixCorrect = document.getElementById('thMatrixCorrect');
  const isYesNo = qType.value === 'yes_no';
  const isMatrix = qType.value === 'dropdown_matrix';
  if (choiceSectionTitle) choiceSectionTitle.textContent = isYesNo || isMatrix ? 'Statements' : 'Choices';
  if (choiceSectionHelp) {
    if (isYesNo) choiceSectionHelp.textContent = 'Set the correct answer for each statement using the Yes / No columns.';
    else if (isMatrix) choiceSectionHelp.textContent = 'Add one statement per row, enter dropdown options, and choose the correct dropdown answer.';
    else choiceSectionHelp.textContent = 'For Drag & Drop, image pieces are configured from the image builder below.';
  }
  if (btnAdd) {
    if (isYesNo || isMatrix) btnAdd.innerHTML = '<i class="bi bi-plus-lg me-1"></i> Add Statement';
    else btnAdd.innerHTML = '<i class="bi bi-plus-lg me-1"></i> Add Choice';
  }
  if (thCorrect) thCorrect.classList.toggle('d-none', isYesNo || isMatrix);
  if (thChoiceText) thChoiceText.textContent = isYesNo || isMatrix ? 'Statements' : 'Choice Text';
  if (thYes) thYes.classList.toggle('d-none', !isYesNo);
  if (thNo) thNo.classList.toggle('d-none', !isYesNo);
  if (thMatrixOptions) thMatrixOptions.classList.toggle('d-none', !isMatrix);
  if (thMatrixCorrect) thMatrixCorrect.classList.toggle('d-none', !isMatrix);
  document.querySelectorAll('.yesno-col').forEach(el => el.classList.toggle('d-none', !isYesNo));
  document.querySelectorAll('.correct-col').forEach(el => el.classList.toggle('d-none', isYesNo || isMatrix));
  document.querySelectorAll('.matrix-col').forEach(el => el.classList.toggle('d-none', !isMatrix));
  document.querySelectorAll('tr').forEach(refreshMatrixRow);
}
function syncYesNoLabel(input){
  const tr = input.closest('tr');
  if (!tr) return;
  const hidden = tr.querySelector('.correct-box');
  const yes = tr.querySelector('.yesno-yes');
  if (hidden && yes) hidden.checked = !!yes.checked;
}
function refreshDragColumns(){
  const isDrag = qType.value === 'drag_drop';
  const mode = dragMode.value;
  if (dragVisualMode) dragVisualMode.value = currentVisualMode();
  const dragModeWrap = document.getElementById('dragModeWrap');
  if (dragModeWrap) dragModeWrap.classList.toggle('d-none', !isDrag);
  const showImage = isDrag;
  document.querySelectorAll('#dragImageWrap [data-dd-mode]').forEach(el => {
    const wanted = el.getAttribute('data-dd-mode');
    el.classList.toggle('d-none', !(isDrag && wanted === 'categorize_image'));
  });
  if (document.getElementById('dragImageBuilderWrap')) document.getElementById('dragImageBuilderWrap').classList.toggle('d-none', !showImage);
  if (choiceHeadWrap) choiceHeadWrap.classList.toggle('d-none', isImageSliceMode());
  if (choiceTableWrap) choiceTableWrap.classList.toggle('d-none', isImageSliceMode());
  document.querySelectorAll('.dd-col').forEach(el => {
    el.classList.toggle('d-none', !isDrag);
  });
  body.querySelectorAll('.drag-target-index').forEach(sel => sel.innerHTML = slotOptionsHtml(sel.dataset.selected || sel.value || '0'));
  ensureImageBuilder();
  bindSingleChoiceBehaviour();
  updateYesNoTableLabels();
}
function reindex(){
  [...body.querySelectorAll('tr')].forEach((tr, i) => {
    const cb = tr.querySelector('input[name="choice_correct[]"]');
    const dis = tr.querySelector('input[name="choice_distractor[]"]');
    if (cb) cb.value = String(i);
    if (dis) dis.value = String(i);
    const ta = tr.querySelector('textarea[name="choice_text[]"]');
    if (ta) ta.id = 'choice_text_' + i;
    const yes = tr.querySelector('.yesno-yes');
    const no = tr.querySelector('.yesno-no');
    const matrixOptions = tr.querySelector('.matrix-options-text');
    const matrixCorrect = tr.querySelector('.matrix-correct-select');
    if (yes) yes.name = `yes_no_answer[${i}]`;
    if (no) no.name = `yes_no_answer[${i}]`;
    if (matrixOptions) matrixOptions.name = `matrix_options[${i}]`;
    if (matrixCorrect) matrixCorrect.name = `matrix_correct[${i}]`;
    if (matrixCorrect) matrixCorrect.dataset.selected = matrixCorrect.value || matrixCorrect.dataset.selected || '0';
  });
  bindSingleChoiceBehaviour();
}
function wireDel(btn){
  btn.addEventListener('click', () => {
    const tr = btn.closest('tr');
    const ta = tr.querySelector('textarea[name="choice_text[]"]');
    if (ta && ta.id && tinymce.get(ta.id)) tinymce.get(ta.id).remove();
    tr.remove();
    reindex();
    refreshDragColumns();
  });
}
function addRow(data = {}) {
  const idx = body.children.length;
  const tr = document.createElement('tr');
  const safeText = data.text || '';
  const yesChecked = !!data.correct;
  const matrixOptionsText = data.matrix_options || '';
  const matrixCorrectValue = data.matrix_correct || 0;
  tr.innerHTML = `
    <td class="text-center correct-col">
      <div class="d-inline-flex align-items-center gap-2">
        <input class="form-check-input correct-box" type="checkbox" name="choice_correct[]" value="${idx}" ${yesChecked ? 'checked' : ''}>
        <span class="small text-muted correct-label">Correct</span>
      </div>
    </td>
    <td><textarea class="form-control js-choice-editor" name="choice_text[]" id="choice_text_${idx}" rows="4">${safeText}</textarea><input type="hidden" name="choice_id[]" value="${data.id || 0}"></td>
    <td class="text-center yesno-col d-none">
      <input class="form-check-input yesno-yes" type="radio" name="yes_no_answer[${idx}]" value="yes" ${yesChecked ? 'checked' : ''}>
    </td>
    <td class="text-center yesno-col d-none">
      <input class="form-check-input yesno-no" type="radio" name="yes_no_answer[${idx}]" value="no" ${!yesChecked ? 'checked' : ''}>
    </td>
    <td class="matrix-col d-none">
      <textarea class="form-control matrix-options-text" name="matrix_options[${idx}]" rows="4" placeholder="One option per line">${matrixOptionsText}</textarea>
      <div class="form-text">Enter one dropdown option per line.</div>
    </td>
    <td class="matrix-col d-none">
      <select class="form-select matrix-correct-select" name="matrix_correct[${idx}]" data-selected="${matrixCorrectValue}">${buildMatrixCorrectOptions(matrixOptionsText, matrixCorrectValue)}</select>
    </td>
    <td class="dd-col" data-dd-mode="categorize"><select class="form-select drag-target-index" name="drag_target_index[]" data-selected="${data.target || 0}">${slotOptionsHtml(data.target || 0)}</select></td>
    <td class="dd-col" data-dd-mode="blanks"><input class="form-control" type="text" name="drag_blank_key[]" value="${data.blank_key || ''}" placeholder="blank_1"></td>
    <td class="dd-col" data-dd-mode="drag"><input class="form-check-input" type="checkbox" name="choice_distractor[]" value="${idx}" ${data.is_distractor ? 'checked' : ''}></td>
    <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm btnDel"><i class="bi bi-x-lg"></i></button></td>`;
  body.appendChild(tr);
  wireDel(tr.querySelector('.btnDel'));
  const cb = tr.querySelector('.correct-box');
  if (cb) cb.addEventListener('change', () => {
    if (qType.value === 'single' && cb.checked) { [...document.querySelectorAll('.correct-box')].forEach(x => { if (x !== cb) x.checked = false; }); }
    const yes = tr.querySelector('.yesno-yes');
    const no = tr.querySelector('.yesno-no');
    if (yes && no) { yes.checked = !!cb.checked; no.checked = !cb.checked; }
  });
  tr.querySelectorAll('.yesno-yes, .yesno-no').forEach(r => r.addEventListener('change', () => syncYesNoLabel(r)));
  const matrixOptionsArea = tr.querySelector('.matrix-options-text');
  const matrixCorrectSelect = tr.querySelector('.matrix-correct-select');
  if (matrixOptionsArea) matrixOptionsArea.addEventListener('input', () => refreshMatrixRow(tr));
  if (matrixCorrectSelect) matrixCorrectSelect.addEventListener('change', () => { matrixCorrectSelect.dataset.selected = matrixCorrectSelect.value || '0'; });
  initEditorFor(tr.querySelector('.js-choice-editor'), 180);
  reindex();
  refreshDragColumns();
}
const btnAdd = document.getElementById('btnAdd');
if (btnAdd) btnAdd.addEventListener('click', () => addRow({}));
const btnUseNextSO = document.getElementById('btnUseNextSO');
if (btnUseNextSO) btnUseNextSO.addEventListener('click', () => {
  const inp = document.querySelector('input[name="sort_order"]');
  if (inp) { inp.value = "<?= (int)$useNextValue ?>"; inp.focus(); }
});
qType.addEventListener('change', refreshDragColumns);
dragMode.addEventListener('change', refreshDragColumns);
if (dragVisualMode) dragVisualMode.addEventListener('change', refreshDragColumns);
if (dragSlotCount) dragSlotCount.addEventListener('input', refreshDragColumns);
document.getElementById('qForm').addEventListener('submit', () => { if (dragImageBuilder && isImageSliceMode()) dragImageBuilder.getState(); tinymce.triggerSave(); });
initAllEditors();
const initialRows = <?= json_encode($initialRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
initialRows.forEach(row => addRow(row));
refreshDragColumns();
</script>
<?php require_once __DIR__ . '/_footer.php'; ?>
