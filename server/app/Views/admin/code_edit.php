<?php require_once APP_ROOT . '/admin/_header.php'; ?>
<?php
  $selectedResourceType = (string)($row['resource_type'] ?? 'enbl');
  $selectedExamId = trim((string)($row['exam_id'] ?? ''));
  $selectedExamName = trim((string)($row['exam_name'] ?? ''));
  $selectedQuizExamId = trim((string)($row['quiz_exam_id'] ?? ''));
  if ($selectedResourceType === 'quiz' && $selectedQuizExamId === '' && $selectedExamId !== '') {
    $selectedQuizExamId = $selectedExamId;
  }
?>
<div class="admin-page-header"><div><h1 class="admin-page-title">Edit Code #<?= (int)$row['id'] ?></h1><div class="admin-page-subtitle">Update exam mapping, expiry, limits and customer details.</div></div><a class="btn btn-outline-secondary" href="/admin/">Back</a></div>

<?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($msg): ?><div class="alert alert-info"><?= h($msg) ?></div><?php endif; ?>

<div class="card p-4 shadow-sm" style="max-width: 980px;">
  <div class="mb-2">Code:</div>
  <div class="mono fs-5"><?= !empty($row['code_plain']) ? h($row['code_plain']) : '<span class="text-muted">[hidden]</span>' ?></div>
  <hr>

  <form method="post">
    <?= csrf_input() ?>
    <input type="hidden" name="exam_id" id="exam_id" value="<?= h($selectedExamId) ?>">
    <input type="hidden" name="exam_name" id="exam_name" value="<?= h($selectedExamName) ?>">
    <input type="hidden" name="quiz_exam_id" id="quiz_exam_id" value="<?= h($selectedQuizExamId) ?>">
    <input type="hidden" name="user_id" id="user_id" value="<?= (int)($row['user_id'] ?? 0) ?>">

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Code Type *</label>
        <?php $rt = $selectedResourceType; ?>
        <select class="form-select" name="resource_type" id="resource_type">
          <option value="enbl" <?= ($rt === 'enbl' ? 'selected' : '') ?>>TrueCerts Dumps File</option>
          <option value="quiz" <?= ($rt === 'quiz' ? 'selected' : '') ?>>Dumps (Quiz)</option>
        </select>
      </div>

      <div class="col-md-6 position-relative">
        <label class="form-label">Select Dumps File *</label>
        <input class="form-control" id="exam_picker" placeholder="Type dumps code or name to search..." autocomplete="off" value="<?= h($selectedExamId !== '' ? ($selectedExamId . ' — ' . $selectedExamName) : '') ?>">
        <div class="text-muted small" id="exam_picker_hint">Start typing 2+ letters to search existing exams.</div>
        <div id="exam_picker_results" class="list-group position-absolute start-0 end-0 mt-1 shadow-sm d-none" style="z-index: 1050; max-height: 280px; overflow:auto;"></div>
        <div class="small mt-2" id="exam_picker_selected"><?php if ($selectedExamId !== ''): ?><span class="badge text-bg-success">Selected</span><span class="ms-1"><?= h($selectedExamId . ' — ' . $selectedExamName) ?></span><?php endif; ?></div>
      </div>

      <div class="col-12 position-relative">
        <label class="form-label">Select User</label>
        <?php $userDisplay = trim((string)($row['user_name'] ?? '')) !== '' ? (string)$row['user_name'] : ''; ?>
        <input class="form-control" id="user_picker" placeholder="Search by username, name, or email..." autocomplete="off" value="<?= h($userDisplay) ?>">
        <div class="text-muted small">Search existing users to auto-fill name, email, phone, and address.</div>
        <div id="user_picker_results" class="list-group position-absolute start-0 end-0 mt-1 shadow-sm d-none" style="z-index: 1049; max-height: 280px; overflow:auto;"></div>
        <div class="small mt-2 <?= !empty($row['user_id']) ? '' : 'd-none' ?>" id="user_picker_selected"><?php if (!empty($row['user_id'])): ?><span class="badge text-bg-success">Linked User</span><span class="ms-1"><?= h((string)($row['user_name'] ?? '')) ?></span><?php endif; ?></div>
      </div>

      <div class="col-md-6"><label class="form-label">User Name</label><input class="form-control user-detail" name="user_name" id="user_name" value="<?= h($row['user_name'] ?? '') ?>" placeholder="Customer name"></div>
      <div class="col-md-6"><label class="form-label">User Email</label><input class="form-control user-detail" name="user_email" id="user_email" type="email" value="<?= h($row['user_email'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">User Phone</label><input class="form-control user-detail" name="user_phone" id="user_phone" value="<?= h($row['user_phone'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">User Address</label><input class="form-control user-detail" name="user_address" id="user_address" value="<?= h($row['user_address'] ?? '') ?>"></div>
          <div class="form-text mt-2">A mode works only when it is enabled on both the Exam page and this Access Code.</div>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Max Users</label>
        <input class="form-control" type="number" name="max_users" value="<?= (int)($row['max_users'] ?? 1) ?>" min="1">
        <div class="text-muted small">Unique users/devices allowed.</div>
        <div class="text-muted small">Used: <?= (int)($row['used_count'] ?? 0) ?> / <?= (int)($row['max_users'] ?? 1) ?></div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Max Uses</label>
        <input class="form-control" type="number" name="max_uses" value="<?= (int)($row['max_uses'] ?? 0) ?>" min="0">
        <div class="text-muted small">Total successful uses (0 = unlimited).</div>
        <div class="text-muted small">Used: <?= (int)($row['uses_count'] ?? 0) ?> / <?= ((int)($row['max_uses'] ?? 0)===0 ? '∞' : (int)$row['max_uses']) ?></div>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= ((int)$row['is_active']===1)?'checked':'' ?>><label class="form-check-label" for="is_active">Active</label></div>
      </div>

      <div class="col-md-6"><label class="form-label">Expiry (datetime-local)</label><input class="form-control" type="datetime-local" name="expires_local" value="<?= h($expires_local_value) ?>"></div>
      <div class="col-12"><label class="form-label">Note</label><input class="form-control" name="note" value="<?= h($row['note'] ?? '') ?>"></div>
    </div>

    <div class="mt-3"><button class="btn btn-primary" type="submit">Save</button> <a class="btn btn-outline-warning" href="/admin/code_reset.php?id=<?= (int)$row['id'] ?>">Reset Device</a> <a class="btn btn-outline-danger" href="/admin/code_delete.php?id=<?= (int)$row['id'] ?>">Delete</a></div>
  </form>
</div>

<script>
(function(){
  const rt=document.getElementById('resource_type'), picker=document.getElementById('exam_picker'), results=document.getElementById('exam_picker_results'), selected=document.getElementById('exam_picker_selected'), hint=document.getElementById('exam_picker_hint'), examId=document.getElementById('exam_id'), examName=document.getElementById('exam_name'), quizExamId=document.getElementById('quiz_exam_id');
  const userPicker=document.getElementById('user_picker'), userResults=document.getElementById('user_picker_results'), userSelected=document.getElementById('user_picker_selected'), userId=document.getElementById('user_id');
  const userName=document.getElementById('user_name'), userEmail=document.getElementById('user_email'), userPhone=document.getElementById('user_phone'), userAddress=document.getElementById('user_address');
  const modeWrap=document.querySelector('.quiz-mode-access-wrap');
  let timer=null, userTimer=null, lastItems=[], lastUsers=[];
  function esc(s){ return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
  function currentMode(){ return 'enbl'; }
  function syncQuizModeAccessVisibility(){ if(!modeWrap) return; modeWrap.classList.toggle('d-none', currentMode()!=='quiz'); }
  function endpointForMode(){ return '/admin/ajax_exam_search_enbl.php'; }
  function syncInitialSelection(){
    if(currentMode()==='quiz' && !quizExamId.value && examId.value){
      quizExamId.value = examId.value;
    }
    if(examId.value && examName.value && !selected.innerHTML.trim()){
      selected.innerHTML=`<span class="badge text-bg-success">Selected</span> <span class="ms-1">${esc(examId.value)} — ${esc(examName.value)}</span>`;
    }
  }
  function clearSelection(resetText){ examId.value=''; examName.value=''; quizExamId.value=''; selected.innerHTML=''; if(resetText && picker) picker.value=''; }
  function setHint(){ hint.textContent='Start typing 2+ letters to search dumps files with linked R2 file.'; picker.placeholder='Type dumps code or name to search...'; } else { hint.textContent='Start typing 2+ letters to search dumps files with linked R2 file.'; picker.placeholder='Type dumps code or name to search...'; } }
  function hideResults(){ results.classList.add('d-none'); results.innerHTML=''; lastItems=[]; }
  function renderResults(items){ lastItems=items||[]; if(!lastItems.length){ results.innerHTML='<div class="list-group-item text-muted small">No results found</div>'; results.classList.remove('d-none'); return; } let html=''; for(let i=0;i<lastItems.length;i++){ const it=lastItems[i]; const title=`${it.exam_id} — ${it.exam_name}${parseInt(it.has_r2||0,10)===1 ? '' : ' (No R2 file linked)'}`; const disabled=currentMode()==='enbl' && parseInt(it.has_r2||0,10)!==1; html += `<button type="button" class="list-group-item list-group-item-action${disabled?' disabled text-muted':''}" data-index="${i}">${esc(title)}</button>`; } results.innerHTML=html; results.classList.remove('d-none'); }
  async function searchExam(q){ const qq=(q||'').trim(); clearTimeout(timer); if(qq.length<2){ hideResults(); return; } results.innerHTML='<div class="list-group-item text-muted small">Searching...</div>'; results.classList.remove('d-none'); try{ const u=new URL(endpointForMode(), window.location.origin); u.searchParams.set('q', qq); const res=await fetch(u.toString(), {credentials:'same-origin'}); const data=await res.json(); renderResults(data && data.ok && Array.isArray(data.items) ? data.items : []); }catch(e){ results.innerHTML='<div class="list-group-item text-danger small">Search failed</div>'; results.classList.remove('d-none'); } }
  function selectItem(it){ if(!it) return; if(currentMode()==='enbl' && parseInt(it.has_r2||0,10)!==1) return; examId.value=it.exam_id||''; examName.value=it.exam_name||''; quizExamId.value=''; picker.value=`${it.exam_id} — ${it.exam_name}`; selected.innerHTML=`<span class="badge text-bg-success">Selected</span> <span class="ms-1">${esc(it.exam_id)} — ${esc(it.exam_name)}</span>`; hideResults(); }
  function setUserReadOnly(v){ [userName,userEmail,userPhone,userAddress].forEach(el => { if(el) el.readOnly=!!v; }); }
  function clearUserSelection(resetText){ userId.value=''; userSelected.innerHTML=''; userSelected.classList.add('d-none'); setUserReadOnly(false); if(resetText && userPicker) userPicker.value=''; }
  function hideUserResults(){ userResults.classList.add('d-none'); userResults.innerHTML=''; lastUsers=[]; }
  function renderUserResults(items){ lastUsers=items||[]; if(!lastUsers.length){ userResults.innerHTML='<div class="list-group-item text-muted small">No users found</div>'; userResults.classList.remove('d-none'); return; } let html=''; for(let i=0;i<lastUsers.length;i++){ const it=lastUsers[i]; const title=(it.full_name||it.username||'') + (it.username && it.full_name && it.username!==it.full_name ? ` (${it.username})` : ''); const sub=[it.email||'', it.role||''].filter(Boolean).join(' • '); html += `<button type="button" class="list-group-item list-group-item-action" data-user-index="${i}"><div class="fw-semibold">${esc(title)}</div><div class="small text-muted">${esc(sub)}</div></button>`; } userResults.innerHTML=html; userResults.classList.remove('d-none'); }
  async function searchUser(q){ const qq=(q||'').trim(); clearTimeout(userTimer); if(qq.length<2){ hideUserResults(); return; } userResults.innerHTML='<div class="list-group-item text-muted small">Searching users...</div>'; userResults.classList.remove('d-none'); try{ const u=new URL('/admin/ajax_user_search.php', window.location.origin); u.searchParams.set('q', qq); const res=await fetch(u.toString(), {credentials:'same-origin'}); const data=await res.json(); renderUserResults(data&&data.ok&&Array.isArray(data.items)?data.items:[]);}catch(e){ userResults.innerHTML='<div class="list-group-item text-danger small">User search failed</div>'; userResults.classList.remove('d-none'); } }
  function selectUser(it){ if(!it) return; userId.value=it.id||''; userName.value=it.full_name || it.username || ''; userEmail.value=it.email || ''; userPhone.value=it.phone || ''; userAddress.value=it.address || ''; userPicker.value=(it.full_name || it.username || '') + (it.username && it.full_name && it.username!==it.full_name ? ` (${it.username})` : ''); userSelected.innerHTML=`<span class="badge text-bg-success">Linked User</span> <span class="ms-1">${esc(it.full_name || it.username || '')}</span>${it.email ? `<span class="ms-2 text-muted">${esc(it.email)}</span>` : ''}`; userSelected.classList.remove('d-none'); setUserReadOnly(true); hideUserResults(); }
  picker?.addEventListener('input', function(){ clearSelection(false); timer=setTimeout(() => searchExam(picker.value), 250); });
  picker?.addEventListener('focus', function(){ if(picker.value.trim().length>=2) timer=setTimeout(() => searchExam(picker.value), 100); });
  results?.addEventListener('click', function(e){ const btn=e.target.closest('[data-index]'); if(!btn || btn.classList.contains('disabled')) return; selectItem(lastItems[parseInt(btn.getAttribute('data-index')||'-1',10)]||null); });
  rt?.addEventListener('change', function(){ clearSelection(true); setHint(); syncQuizModeAccessVisibility(); });
  userPicker?.addEventListener('input', function(){ clearUserSelection(false); userTimer=setTimeout(() => searchUser(userPicker.value), 250); });
  userPicker?.addEventListener('focus', function(){ if(userPicker.value.trim().length>=2) userTimer=setTimeout(() => searchUser(userPicker.value), 100); });
  userResults?.addEventListener('click', function(e){ const btn=e.target.closest('[data-user-index]'); if(!btn) return; selectUser(lastUsers[parseInt(btn.getAttribute('data-user-index')||'-1',10)]||null); });
  document.addEventListener('click', function(e){ if(!results.contains(e.target) && e.target!==picker) hideResults(); if(!userResults.contains(e.target) && e.target!==userPicker) hideUserResults(); });
  if (parseInt(userId.value || '0', 10) > 0) setUserReadOnly(true); else setUserReadOnly(false);
  setHint();
  syncQuizModeAccessVisibility();
  syncInitialSelection();
})();
</script>
<?php require_once APP_ROOT . '/admin/_footer.php'; ?>
