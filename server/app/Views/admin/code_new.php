<?php require_once APP_ROOT . '/admin/_header.php'; ?>
<div class="admin-page-header"><div><h1 class="admin-page-title">Generate New Code</h1><div class="admin-page-subtitle">Create a new access code and link it to a TrueCerts .ttc dumps file.</div></div><a class="btn btn-outline-secondary" href="/admin/">Back</a></div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($msg || $r2_msg): ?>
  <div class="alert <?= $new_code ? 'alert-success' : 'alert-danger' ?>">
    <?php if ($msg): ?><div><?= h($msg) ?></div><?php endif; ?>
    <?php if ($r2_msg): ?><div class="small text-muted mt-1"><?= h($r2_msg) ?></div><?php endif; ?>
    <?php if ($new_code): ?>
      <hr>
      <div class="mb-2">New Code:</div>
      <div class="d-flex gap-2 align-items-center"><code class="mono fs-5" id="newcode"><?= h($new_code) ?></code><button class="btn btn-sm btn-outline-dark" type="button" onclick="copyCode()">Copy</button></div>
      <div class="text-muted small mt-2">Tip: Save this code somewhere safe.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" class="card p-4 shadow-sm" style="max-width: 980px;">
  <?= csrf_input() ?>
  <input type="hidden" name="exam_id" id="exam_id" value="">
  <input type="hidden" name="exam_name" id="exam_name" value="">
  <input type="hidden" name="quiz_exam_id" id="quiz_exam_id" value="">
  <input type="hidden" name="user_id" id="user_id" value="<?= h((string)($_POST['user_id'] ?? '')) ?>">

  <div class="row g-3">
    <input type="hidden" name="resource_type" id="resource_type" value="enbl">

    <div class="col-md-6">
      <label class="form-label">Code Type</label>
      <div class="form-control bg-light">TrueCerts .ttc Dumps File</div>
      <div class="text-muted small">This access code unlocks a dumps file only.</div>
    </div>

    <div class="col-md-6 position-relative">
      <label class="form-label">Select Dumps File *</label>
      <input class="form-control" id="exam_picker" placeholder="Type dumps code or name to search..." autocomplete="off">
      <div class="text-muted small" id="exam_picker_hint">Start typing 2+ letters to search dumps files with linked R2 file.</div>
      <div id="exam_picker_results" class="list-group position-absolute start-0 end-0 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 280px; overflow:auto;"></div>
      <div class="small mt-2 d-none" id="exam_picker_selected"></div>
    </div>

    <div class="col-12 position-relative">
      <label class="form-label">Select User</label>
      <input class="form-control" id="user_picker" placeholder="Search by username, name, or email..." autocomplete="off">
      <div class="text-muted small">Search existing users to auto-fill name, email, phone, and address.</div>
      <div id="user_picker_results" class="list-group position-absolute start-0 end-0 mt-1 shadow-sm d-none" style="z-index: 1049; max-height: 280px; overflow:auto;"></div>
      <div class="small mt-2 <?= !empty($_POST['user_id']) ? '' : 'd-none' ?>" id="user_picker_selected"></div>
    </div>

    <div class="col-md-6">
      <label class="form-label">User Name *</label>
      <input class="form-control user-detail" name="user_name" id="user_name" value="<?= h((string)($_POST['user_name'] ?? '')) ?>" placeholder="Customer name" required>
    </div>

    <div class="col-md-6">
      <label class="form-label">User Email (optional)</label>
      <input class="form-control user-detail" name="user_email" id="user_email" type="email" value="<?= h((string)($_POST['user_email'] ?? '')) ?>" placeholder="customer@email.com">
    </div>

    <div class="col-md-6">
      <label class="form-label">User Phone (optional)</label>
      <input class="form-control user-detail" name="user_phone" id="user_phone" value="<?= h((string)($_POST['user_phone'] ?? '')) ?>" placeholder="+91...">
    </div>

    <div class="col-md-6">
      <label class="form-label">User Address (optional)</label>
      <input class="form-control user-detail" name="user_address" id="user_address" value="<?= h((string)($_POST['user_address'] ?? '')) ?>" placeholder="City, State">
    </div>
        <div class="form-text mt-2">A mode works only when it is enabled on both the Exam page and this Access Code.</div>
      </div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Max Users</label>
      <input class="form-control" type="number" name="max_users" value="<?= h((string)($_POST['max_users'] ?? '1')) ?>" min="1">
      <div class="text-muted small">How many unique users/devices can activate this code.</div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Max Uses</label>
      <input class="form-control" type="number" name="max_uses" value="<?= h((string)($_POST['max_uses'] ?? '0')) ?>" min="0">
      <div class="text-muted small">How many total successful uses (0 = unlimited).</div>
      <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= isset($_POST['is_active']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Expiry (datetime-local)</label>
      <input class="form-control" type="datetime-local" name="expires_local" value="<?= h((string)($_POST['expires_local'] ?? '')) ?>">
      <div class="text-muted small">If set, this will be used.</div>
    </div>

    <div class="col-md-6">
      <label class="form-label">OR Expiry in Days</label>
      <input class="form-control" type="number" name="days" value="<?= h((string)($_POST['days'] ?? '0')) ?>" min="0">
    </div>

    <div class="col-12">
      <label class="form-label">Note</label>
      <input class="form-control" name="note" value="<?= h((string)($_POST['note'] ?? '')) ?>" placeholder="Order ID / WhatsApp / etc">
    </div>
  </div>

  <div class="mt-3"><button class="btn btn-primary" type="submit">Generate</button></div>
</form>

<script>
function copyCode(){ const el=document.getElementById('newcode'); if(!el) return; navigator.clipboard.writeText(el.textContent || ''); }
(function(){
  const rt=document.getElementById('resource_type'), picker=document.getElementById('exam_picker'), results=document.getElementById('exam_picker_results'), selected=document.getElementById('exam_picker_selected'), hint=document.getElementById('exam_picker_hint'), examId=document.getElementById('exam_id'), examName=document.getElementById('exam_name'), quizExamId=document.getElementById('quiz_exam_id');
  const userPicker=document.getElementById('user_picker'), userResults=document.getElementById('user_picker_results'), userSelected=document.getElementById('user_picker_selected'), userId=document.getElementById('user_id');
  const userName=document.getElementById('user_name'), userEmail=document.getElementById('user_email'), userPhone=document.getElementById('user_phone'), userAddress=document.getElementById('user_address');
  const modeWrap=document.querySelector('.quiz-mode-access-wrap');
  let timer=null, userTimer=null, lastItems=[], lastUsers=[];
  function esc(s){ return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
  function currentMode(){ return 'enbl'; }
  function syncQuizModeAccessVisibility(){ if(!modeWrap) return; modeWrap.classList.toggle('d-none', currentMode()!=='quiz'); }
  function endpointForMode(){ return 'ajax_exam_search_enbl.php'; }
  function clearSelection(resetText){ examId.value=''; examName.value=''; quizExamId.value=''; selected.classList.add('d-none'); selected.innerHTML=''; if(resetText && picker) picker.value=''; }
  function setHint(){ hint.textContent='Start typing 2+ letters to search dumps files with linked R2 file.'; picker.placeholder='Type dumps code or name to search...'; } else { hint.textContent='Start typing 2+ letters to search dumps files with linked R2 file.'; picker.placeholder='Type dumps code or name to search...'; } }
  function hideResults(){ results.classList.add('d-none'); results.innerHTML=''; lastItems=[]; }
  function renderResults(items){ lastItems=items||[]; if(!lastItems.length){ results.innerHTML='<div class="list-group-item text-muted small">No results found</div>'; results.classList.remove('d-none'); return; } let html=''; for(let i=0;i<lastItems.length;i++){ const it=lastItems[i]; const title=`${it.exam_id} — ${it.exam_name}${parseInt(it.has_r2||0,10)===1 ? '' : ' (No R2 file linked)'}`; const disabled=currentMode()==='enbl' && parseInt(it.has_r2||0,10)!==1; html += `<button type="button" class="list-group-item list-group-item-action${disabled?' disabled text-muted':''}" data-index="${i}">${esc(title)}</button>`; } results.innerHTML=html; results.classList.remove('d-none'); }
  async function searchExam(q){ const qq=(q||'').trim(); clearTimeout(timer); if(qq.length<2){ hideResults(); return; } results.innerHTML='<div class="list-group-item text-muted small">Searching...</div>'; results.classList.remove('d-none'); try{ const u=new URL(endpointForMode(), window.location.href); u.searchParams.set('q', qq); const res=await fetch(u.toString(), {credentials:'same-origin'}); const data=await res.json(); renderResults(data&&data.ok&&Array.isArray(data.items)?data.items:[]); }catch(e){ results.innerHTML='<div class="list-group-item text-danger small">Search failed</div>'; results.classList.remove('d-none'); } }
  function selectItem(it){ if(!it) return; if(currentMode()==='enbl' && parseInt(it.has_r2||0,10)!==1) return; examId.value=it.exam_id||''; examName.value=it.exam_name||''; quizExamId.value=''; picker.value=`${it.exam_id} — ${it.exam_name}`; selected.innerHTML=`<span class="badge text-bg-success">Selected</span> <span class="ms-1">${esc(it.exam_id)} — ${esc(it.exam_name)}</span>`; selected.classList.remove('d-none'); hideResults(); }
  function setUserReadOnly(v){ [userName,userEmail,userPhone,userAddress].forEach(el => { if(el) el.readOnly=!!v; }); }
  function clearUserSelection(resetText){ userId.value=''; userSelected.innerHTML=''; userSelected.classList.add('d-none'); setUserReadOnly(false); if(resetText && userPicker) userPicker.value=''; }
  function hideUserResults(){ userResults.classList.add('d-none'); userResults.innerHTML=''; lastUsers=[]; }
  function renderUserResults(items){ lastUsers=items||[]; if(!lastUsers.length){ userResults.innerHTML='<div class="list-group-item text-muted small">No users found</div>'; userResults.classList.remove('d-none'); return; } let html=''; for(let i=0;i<lastUsers.length;i++){ const it=lastUsers[i]; const title=(it.full_name||it.username||'') + (it.username && it.full_name && it.username!==it.full_name ? ` (${it.username})` : ''); const sub=[it.email||'', it.role||''].filter(Boolean).join(' • '); html += `<button type="button" class="list-group-item list-group-item-action" data-user-index="${i}"><div class="fw-semibold">${esc(title)}</div><div class="small text-muted">${esc(sub)}</div></button>`; } userResults.innerHTML=html; userResults.classList.remove('d-none'); }
  async function searchUser(q){ const qq=(q||'').trim(); clearTimeout(userTimer); if(qq.length<2){ hideUserResults(); return; } userResults.innerHTML='<div class="list-group-item text-muted small">Searching users...</div>'; userResults.classList.remove('d-none'); try { const u=new URL('/admin/ajax_user_search.php', window.location.origin); u.searchParams.set('q', qq); const res=await fetch(u.toString(), {credentials:'same-origin'}); const data=await res.json(); renderUserResults(data&&data.ok&&Array.isArray(data.items)?data.items:[]); } catch(e) { userResults.innerHTML='<div class="list-group-item text-danger small">User search failed</div>'; userResults.classList.remove('d-none'); } }
  function selectUser(it){ if(!it) return; userId.value=it.id||''; userName.value=it.full_name || it.username || ''; userEmail.value=it.email || ''; userPhone.value=it.phone || ''; userAddress.value=it.address || ''; userPicker.value=(it.full_name || it.username || '') + (it.username && it.full_name && it.username!==it.full_name ? ` (${it.username})` : ''); userSelected.innerHTML=`<span class="badge text-bg-success">Linked User</span> <span class="ms-1">${esc(it.full_name || it.username || '')}</span>${it.email ? `<span class="ms-2 text-muted">${esc(it.email)}</span>` : ''}`; userSelected.classList.remove('d-none'); setUserReadOnly(true); hideUserResults(); }
  picker?.addEventListener('input', function(){ clearSelection(false); timer=setTimeout(() => searchExam(picker.value), 250); });
  picker?.addEventListener('focus', function(){ if(picker.value.trim().length>=2) timer=setTimeout(() => searchExam(picker.value), 100); });
  results?.addEventListener('click', function(e){ const btn=e.target.closest('[data-index]'); if(!btn || btn.classList.contains('disabled')) return; selectItem(lastItems[parseInt(btn.getAttribute('data-index')||'-1',10)]||null); });
  document.addEventListener('click', function(e){ if(!results.contains(e.target) && e.target!==picker) hideResults(); if(!userResults.contains(e.target) && e.target!==userPicker) hideUserResults(); });
  rt?.addEventListener('change', function(){ clearSelection(true); setHint(); syncQuizModeAccessVisibility(); });
  userPicker?.addEventListener('input', function(){ clearUserSelection(false); userTimer=setTimeout(() => searchUser(userPicker.value), 250); });
  userPicker?.addEventListener('focus', function(){ if(userPicker.value.trim().length>=2) userTimer=setTimeout(() => searchUser(userPicker.value), 100); });
  userResults?.addEventListener('click', function(e){ const btn=e.target.closest('[data-user-index]'); if(!btn) return; selectUser(lastUsers[parseInt(btn.getAttribute('data-user-index')||'-1',10)]||null); });
  setHint();
  syncQuizModeAccessVisibility();
})();
</script>
<?php require_once APP_ROOT . '/admin/_footer.php'; ?>
