<?php
require_once APP_ROOT . '/bootstrap.php';

$pdo = db();
$codesRepo = new \App\Repositories\AccessCodeRepository($pdo);
$codesSvc  = new \App\Services\AccessCodeService();
require_once APP_ROOT . '/admin/_header.php';
require_permission('code.generate');
$pdo = db();
$msg = '';
$new_code = null;
$r2_msg = '';

/**
 * Build INSERT statement dynamically so optional columns (like user_name, r2_key, r2_url)
 * don't break when the database schema hasn't been updated yet.
 */
function build_access_codes_insert(PDO $pdo, array $data): array {
  $cols = [];
  $qs   = [];
  $vals = [];

  foreach ($data as $col => $val) {
    $isOptional = in_array($col, ['user_name', 'r2_key', 'r2_url', 'quiz_exam_id', 'mock_exam_id', 'resource_type', 'max_users', 'max_uses', 'uses_count'], true);
    if ($isOptional && !db_has_column($pdo, 'access_codes', $col)) {
      continue;
    }
    $cols[] = $col;
    $qs[] = '?';
    $vals[] = $val;
  }

  $sql = "INSERT INTO access_codes (" . implode(',', $cols) . ") VALUES (" . implode(',', $qs) . ")";
  return [$sql, $vals];
}

// -------------------------
// Option A: R2 file is linked at Exam level (New Exam)
// Codes will use the exam's linked file automatically.
// -------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  // -------------------------
  // 1) Read + validate inputs
  // -------------------------
  $exam_name = trim($_POST['exam_name'] ?? '');
  $exam_id   = trim($_POST['exam_id'] ?? '');   // Exam Code (mandatory)

  $user_name    = trim($_POST['user_name'] ?? '');
  $user_email   = trim($_POST['user_email'] ?? '');
  $user_phone   = trim($_POST['user_phone'] ?? '');
  $user_address = trim($_POST['user_address'] ?? '');

  $resource_type = trim($_POST['resource_type'] ?? 'enbl');
  $quiz_exam_id = trim($_POST['quiz_exam_id'] ?? '');


  // max_users: how many unique users/devices can activate this code
  $max_users = (int)($_POST['max_users'] ?? 1);
  // max_uses: how many total successful uses are allowed (0 = unlimited)
  $max_uses  = (int)($_POST['max_uses'] ?? 0);
  $days = (int)($_POST['days'] ?? 0);
  $expires_local = $_POST['expires_local'] ?? '';
  $note = trim($_POST['note'] ?? '');
  $is_active = isset($_POST['is_active']) ? 1 : 0;

  // If this is a Quiz code, pick an existing quiz exam instead of creating a new one.
  if ($resource_type === 'quiz') {
    if ($quiz_exam_id === '') {
      $msg = 'Please select a Dumps.';
    } else {
      $ex = quiz_get_exam($pdo, $quiz_exam_id);
      if (!$ex || ($ex['resource_type'] ?? '') !== 'quiz') {
        $msg = 'Selected Dumps not found.';
      } else {
        // Override inputs from the selected exam
        $exam_id = $ex['exam_id'];
        $exam_name = $ex['exam_name'];
      }
    }
  }

  if ($msg === '' && ($exam_id === '' || $user_name === '')) {
    $msg = ($resource_type==='enbl') ? 'Exam Code and User Name are required.' : 'User Name is required.';
  } else if ($msg === '') {
    if ($max_users <= 0) $max_users = 1;
    if ($max_uses < 0) $max_uses = 0;

    $code = gen_code();
    $hash = code_hash($code);

    $expires_at = parse_datetime_local_to_utc($expires_local);
    if (!$expires_at) $expires_at = parse_days_to_expires_at($days);

    // -------------------------
    // 2) Resolve R2 file from Exam (TTC only)
    // -------------------------
    $r2_key = '';
    $r2_url = null;

    if ($resource_type === 'enbl') {
      // Exam must already exist and have a linked R2 file (set in New Exam)
      $ex = quiz_get_exam($pdo, $exam_id);
      if (!$ex) {
        $msg = 'Exam not found. Please create the Exam first (Admin → New Exam).';
      } else {
        // Prefer stored exam name to avoid mismatches
        if (trim((string)($ex['exam_name'] ?? '')) !== '') {
          $exam_name = (string)$ex['exam_name'];
        }
        $r2_key = trim((string)($ex['r2_key'] ?? ''));
        $r2_url = trim((string)($ex['r2_url'] ?? ''));
        if ($r2_key === '' && $r2_url === '') {
          $msg = 'No R2 file linked to this Exam. Please select an R2 file in New Exam / Edit Exam.';
        }
      }
    }

    // Normalize
    $r2_key = ltrim((string)$r2_key, '/');
    if (($r2_url === '' || $r2_url === null) && $r2_key !== '') $r2_url = r2_public_url($r2_key);

    $r2_msg = ($resource_type==='enbl')
      ? ($r2_url ? "Exam R2 file: {$r2_url}" : "Exam R2 key: {$r2_key}")
      : "Quiz code (no file)";


    if ($msg === '') {

    // -------------------------
    // 3) Insert access code row (schema-safe)
    // -------------------------
    $data = [
      'exam_id' => $exam_id,
      'exam_name' => $exam_name,
      'code_hash' => $hash,
      'code_plain' => $code,
      'max_users' => $max_users,
      'max_uses'  => $max_uses,
      'expires_at' => $expires_at,
      'is_active' => $is_active,
      'note' => $note ?: null,
      'user_name' => $user_name ?: null,
      'user_email' => $user_email ?: null,
      'user_phone' => $user_phone ?: null,
      'user_address' => $user_address ?: null,
      'r2_key' => ($resource_type==='enbl' ? $r2_key : null),
      'r2_url' => ($resource_type==='enbl' ? $r2_url : null),
      'quiz_exam_id' => ($resource_type==='quiz' ? $quiz_exam_id : null),
      'resource_type' => $resource_type,
      'created_by' => (int)$me['id'],
      'updated_by' => (int)$me['id'],
    ];

    // If DB doesn't have these columns yet, preserve the info in note (so nothing is lost).
    if ($user_name && !db_has_column($pdo, 'access_codes', 'user_name')) {
      $data['note'] = trim((string)$data['note'] . "\nUser Name: {$user_name}") ?: null;
    }
    if ($r2_key && !db_has_column($pdo, 'access_codes', 'r2_key')) {
      $data['note'] = trim((string)$data['note'] . "\nR2 Key: {$r2_key}") ?: null;
    }
    if ($r2_url && !db_has_column($pdo, 'access_codes', 'r2_url')) {
      $data['note'] = trim((string)$data['note'] . "\nR2 URL: {$r2_url}") ?: null;
    }

    if (($resource_type==='quiz') && !db_has_column($pdo, 'access_codes', 'quiz_exam_id')) {
      $data['note'] = trim((string)$data['note'] . "\nQuiz Exam: {$exam_id}") ?: null;
    }    $newId = $codesRepo->create($data);
audit_log_event('code_create', 'access_codes', $newId, [
      'exam_id' => $exam_id,
      'exam_name' => $exam_name,
      'user_name' => $user_name,
      'r2_key' => ($resource_type==='enbl' ? $r2_key : null),
      'r2_url' => ($resource_type==='enbl' ? $r2_url : null),
      'quiz_exam_id' => ($resource_type==='quiz' ? $quiz_exam_id : null),
      'resource_type' => $resource_type,
      'max_users' => $max_users,
      'max_uses'  => $max_uses,
      'expires_at_utc' => $expires_at,
      'is_active' => $is_active,
      'note' => $note ?: null,
    ]);

    $new_code = $code;
    $msg = 'Code generated successfully.';
    }
  }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="m-0">Generate New Code</h4>
  <a class="btn btn-outline-secondary" href="/admin/">Back</a>
</div>

<?php if ($msg || $r2_msg): ?>
  <div class="alert <?= $new_code ? 'alert-success' : 'alert-danger' ?>">
    <?php if ($msg): ?>
      <div><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($r2_msg): ?>
      <div class="small text-muted mt-1"><?= h($r2_msg) ?></div>
    <?php endif; ?>
    <?php if ($new_code): ?>
      <hr>
      <div class="mb-2">New Code:</div>
      <div class="d-flex gap-2 align-items-center">
        <code class="mono fs-5" id="newcode"><?= h($new_code) ?></code>
        <button class="btn btn-sm btn-outline-dark" type="button" onclick="copyCode()">Copy</button>
      </div>
      <div class="text-muted small mt-2">Tip: Save this code somewhere safe.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" class="card p-3 shadow-sm" style="max-width: 920px;">
  <?= csrf_input() ?>

  <div class="row g-3">

    <!-- TTC (Reader) exam picker (prevents typos) -->
    <div class="col-12 reader-only">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Search Exam (Code / Name) *</label>
          <input class="form-control" id="ttc_exam_search" placeholder="Type ENCOR, AZ-700, CCNA..." autocomplete="off">
          <div class="text-muted small">Start typing (2+ letters) to search existing Exams.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Select Exam *</label>
          <select class="form-select" id="ttc_exam_select">
            <option value="">-- Select exam --</option>
          </select>
          <div class="text-muted small" id="ttc_exam_hint">Select an exam that already has an R2 file linked.</div>
        </div>

        <!-- real POST fields -->
        <input type="hidden" name="exam_id" id="exam_id" value="">
        <input type="hidden" name="exam_name" id="exam_name" value="">
      </div>
    </div>

    <div class="col-12 quiz-only d-none">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Search Dumps (ID / Name) *</label>
          <input class="form-control" id="exam_search" placeholder="Type ENCOR, AZ-700, CCNA..." autocomplete="off">
          <div class="text-muted small">Start typing (2+ letters) to search existing Dumps.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Select Dumps *</label>
          <select class="form-select" id="exam_select">
            <option value="">-- Select exam --</option>
          </select>
          <input type="hidden" name="quiz_exam_id" id="quiz_exam_id" value="">
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Code Type *</label>
      <select class="form-select" name="resource_type" id="resource_type">
        <option value="enbl" selected>TrueCerts Dumps File</option>
        <option value="quiz">Dumps (Quiz)</option>
      </select>
      <div class="text-muted small">Choose what this access code unlocks.</div>
    </div>

    <div class="col-md-4">
      <label class="form-label">User Name *</label>
      <input class="form-control" name="user_name" placeholder="Customer name" required>
    </div>

    <div class="col-md-4">
      <label class="form-label">User Email (optional)</label>
      <input class="form-control" name="user_email" type="email" placeholder="customer@email.com">
    </div>

    <div class="col-md-4">
      <label class="form-label">User Phone (optional)</label>
      <input class="form-control" name="user_phone" placeholder="+91...">
    </div>

    <div class="col-md-4">
      <label class="form-label">User Address (optional)</label>
      <input class="form-control" name="user_address" placeholder="City, State">
    </div>
    <div class="col-md-3">
      <label class="form-label">Max Users</label>
      <input class="form-control" type="number" name="max_users" value="1" min="1">
      <div class="text-muted small">How many unique users/devices can activate this code.</div>
    </div>

    <div class="col-md-3">
      <label class="form-label">Max Uses</label>
      <input class="form-control" type="number" name="max_uses" value="0" min="0">
      <div class="text-muted small">How many total successful uses (0 = unlimited).</div>
    </div>

    <div class="col-md-3 d-flex align-items-end">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="is_active" checked>
        <label class="form-check-label">Active</label>
      </div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Expiry (datetime-local)</label>
      <input class="form-control" type="datetime-local" name="expires_local">
      <div class="text-muted small">If set, this will be used.</div>
    </div>

    <div class="col-md-6">
      <label class="form-label">OR Expiry in Days</label>
      <input class="form-control" type="number" name="days" value="0" min="0">
    </div>

    <div class="col-12">
      <label class="form-label">Note (optional)</label>
      <input class="form-control" name="note" placeholder="Order ID / WhatsApp / etc">
    </div>

  </div>

  <div class="mt-3">
    <button class="btn btn-primary" type="submit">Generate</button>
  </div>
</form>


<script>
(function(){
  const rt = document.getElementById('resource_type');
  const enblOnly = Array.from(document.querySelectorAll('.ttc-only'));
  const quizOnly = Array.from(document.querySelectorAll('.quiz-only'));
  const readerOnly = Array.from(document.querySelectorAll('.reader-only'));

  const examName = document.getElementById('exam_name');
  const examId = document.getElementById('exam_id');

  // TTC exam picker
  const enblSearch = document.getElementById('ttc_exam_search');
  const enblSelect = document.getElementById('ttc_exam_select');
  const enblHint = document.getElementById('ttc_exam_hint');

  const examSearch = document.getElementById('exam_search');
  const examSelect = document.getElementById('exam_select');
  const quizExamId = document.getElementById('quiz_exam_id');

  function setMode(){
    const v = (rt && rt.value) ? rt.value : 'enbl';
    const isQuiz = (v === 'quiz');

    // Show/Hide sections
    enblOnly.forEach(el => el.classList.toggle('d-none', isQuiz));
    quizOnly.forEach(el => el.classList.toggle('d-none', !isQuiz));
    readerOnly.forEach(el => el.classList.toggle('d-none', isQuiz));

    // Enable/Disable required inputs so browser validation behaves
    // (Hidden exam_id is what gets posted; we enforce selection in JS too.)
    if (examName) { examName.required = false; if (isQuiz) examName.value=''; }
    if (examId)   { examId.required   = !isQuiz; if (isQuiz) examId.value=''; }

    if (isQuiz) {
      // reset quiz picker values when switching into quiz
      if (quizExamId) quizExamId.value = '';
      if (examSelect) examSelect.innerHTML = '<option value="">-- Select exam --</option>';
      if (examSearch) examSearch.focus();
    } else {
      // reset quiz fields when switching back to TTC
      if (quizExamId) quizExamId.value = '';
    }
  }

  if (rt) rt.addEventListener('change', setMode);
  setMode();

  // ---- Small helpers ----
  function esc(s){
    return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;");
  }

  // ---- Quiz exam search (AJAX) ----
  if (!examSearch || !examSelect || !quizExamId) {
    // continue; TTC picker may still exist
  }

  let t = null;
  function debounce(fn, ms){
    return function(...args){
      clearTimeout(t);
      t = setTimeout(()=>fn.apply(this,args), ms);
    }
  }

  async function fetchExams(q){
    const qq = (q || '').trim();
    if (qq.length < 2){
      examSelect.innerHTML = '<option value="">-- Type 2+ letters --</option>';
      return;
    }
    examSelect.innerHTML = '<option value="">Searching...</option>';
    try{
      // Use URL() to stay correct even if this page has query strings / rewrites.
      const u = new URL('ajax_exam_search.php', window.location.href);
      u.searchParams.set('q', qq);
      const res = await fetch(u.toString(), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data || !data.ok){
        examSelect.innerHTML = '<option value="">No results</option>';
        return;
      }
      if (!data.items || !data.items.length){
        examSelect.innerHTML = '<option value="">No results</option>';
        return;
      }
      let html = '<option value="">-- Select exam --</option>';
      for (const it of data.items){
        const label = `${it.exam_id} — ${it.exam_name} (${it.questions} Q)`;
        html += `<option value="${esc(it.exam_id)}" data-name="${esc(it.exam_name)}">${esc(label)}</option>`;
      }
      examSelect.innerHTML = html;
    }catch(e){
      examSelect.innerHTML = '<option value="">Error</option>';
    }
  }

  if (examSearch && examSelect && quizExamId) {
    examSearch.addEventListener('input', debounce(()=>fetchExams(examSearch.value), 250));
    examSelect.addEventListener('change', ()=>{
      const val = examSelect.value || '';
      const opt = examSelect.options[examSelect.selectedIndex];
      const nm = opt ? (opt.getAttribute('data-name') || '') : '';
      quizExamId.value = val;
      if (examId) examId.value = val;
      if (examName) examName.value = nm;
    });
  }

  // ---- TTC exam search (AJAX) ----
  if (!enblSearch || !enblSelect || !examId || !examName) return;

  async function fetchEnbl(q){
    const qq = (q || '').trim();
    if (qq.length < 2){
      enblSelect.innerHTML = '<option value="">-- Type 2+ letters --</option>';
      if (enblHint) enblHint.textContent = 'Select an exam that already has an R2 file linked.';
      return;
    }
    enblSelect.innerHTML = '<option value="">Searching...</option>';
    if (enblHint) enblHint.textContent = '';
    try{
      const u = new URL('ajax_exam_search_enbl.php', window.location.href);
      u.searchParams.set('q', qq);
      const res = await fetch(u.toString(), { credentials: 'same-origin' });
      const data = await res.json();
      if (!data || !data.ok || !data.items){
        enblSelect.innerHTML = '<option value="">No results</option>';
        return;
      }
      if (!data.items.length){
        enblSelect.innerHTML = '<option value="">No results</option>';
        return;
      }
      let html = '<option value="">-- Select exam --</option>';
      for (const it of data.items){
        const has = it.has_r2 ? '✅' : '⚠️';
        const label = `${has} ${it.exam_id} — ${it.exam_name}`;
        html += `<option value="${esc(it.exam_id)}" data-name="${esc(it.exam_name)}" data-hasr2="${it.has_r2 ? '1':'0'}">${esc(label)}</option>`;
      }
      enblSelect.innerHTML = html;
    }catch(e){
      enblSelect.innerHTML = '<option value="">Error</option>';
    }
  }

  enblSearch.addEventListener('input', debounce(()=>fetchEnbl(enblSearch.value), 250));
  enblSelect.addEventListener('change', ()=>{
    const opt = enblSelect.options[enblSelect.selectedIndex];
    const id = enblSelect.value || '';
    const nm = opt ? (opt.getAttribute('data-name') || '') : '';
    const hasr2 = opt ? (opt.getAttribute('data-hasr2') === '1') : false;
    examId.value = id;
    examName.value = nm;
    if (enblHint){
      enblHint.textContent = id ? (hasr2 ? 'R2 file linked ✅' : 'No R2 file linked ⚠️ (Edit Exam to attach)') : 'Select an exam that already has an R2 file linked.';
    }
  });

  // auto-focus TTC search when page loads in TTC mode
  if (rt && rt.value === 'enbl') {
    setTimeout(()=>{ try{ enblSearch.focus(); }catch(e){} }, 50);
  }
})();
</script>

<script>
function copyCode() {
  const t = document.getElementById('newcode').innerText;
  navigator.clipboard.writeText(t);
}
</script>

<?php require_once APP_ROOT . '/admin/_footer.php'; ?>
