window.TTCTinyMedia = (function(){
  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
  async function fetchMedia(q=''){
    const res = await fetch('/admin/media_list.php?q=' + encodeURIComponent(q), {credentials:'same-origin'});
    return await res.json();
  }
  function openPicker(editor, callback){
    editor.windowManager.open({
      title: 'Media Library',
      size: 'large',
      body: {
        type: 'panel',
        items: [
          {type:'input', name:'q', label:'Search'},
          {type:'htmlpanel', name:'grid', html:'<div id="enbl-media-grid" style="max-height:430px;overflow:auto;padding:8px 0">Loading...</div>'}
        ]
      },
      buttons: [{type:'cancel', text:'Close'}],
      onChange(api, detail){
        if(detail.name === 'q'){
          renderGrid(api, editor, callback, api.getData().q || '');
        }
      },
      onSubmit(api){ api.close(); }
    });

    async function renderGrid(api, editor, callback, q){
      const panel = document.getElementById('enbl-media-grid');
      if(!panel) return;
      panel.innerHTML = 'Loading...';
      const data = await fetchMedia(q);
      if(!data.ok){ panel.innerHTML = '<div class="alert alert-danger">Failed to load media.</div>'; return; }
      const items = data.items || [];
      if(!items.length){ panel.innerHTML = '<div class="text-muted">No uploads found.</div>'; return; }
      panel.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px">' + items.map(item => `\
        <button type="button" data-path="${item.path}" style="border:1px solid #dee2e6;border-radius:16px;padding:8px;background:#fff;text-align:left;cursor:pointer">\
          <div style="aspect-ratio:4/3;background:#f8f9fa;border-radius:12px;overflow:hidden;margin-bottom:8px"><img src="${item.path}" alt="${esc(item.name)}" style="width:100%;height:100%;object-fit:cover"></div>\
          <div style="font-size:12px;font-weight:600;word-break:break-word">${esc(item.name)}</div>\
          <div style="font-size:11px;color:#6c757d">${esc(item.ym || '')}</div>\
        </button>`).join('') + '</div>';
      panel.querySelectorAll('[data-path]').forEach(btn => btn.addEventListener('click', () => {
        callback(btn.dataset.path, {alt: ''});
        editor.windowManager.close();
      }));
    }

    setTimeout(() => renderGrid(null, editor, callback, ''), 0);
  }

  function commonConfig(selector, extra){
    return Object.assign({
      selector,
      height: 260,
      menubar: false,
      branding: false,
      promotion: false,
      plugins: 'lists link image code table autoresize',
      toolbar: 'undo redo | styles | bold italic underline | bullist numlist | blockquote | link image table | alignleft aligncenter alignright | removeformat code',
      content_style: 'body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.6}',
      browser_spellcheck: true,
      contextmenu: false,
      statusbar: true,
      automatic_uploads: true,
      images_upload_url: '/admin/media_upload.php',
      images_reuse_filename: false,
      file_picker_types: 'image',
      relative_urls: false,
      remove_script_host: false,
      convert_urls: false,
      file_picker_callback: function(callback, value, meta){ openPicker(this, callback); },
      setup: function(editor){
        editor.on('change keyup undo redo', function(){ editor.save(); });
      }
    }, extra || {});
  }

  function init(selector, extra){
    if(!(window.tinymce && document.querySelector(selector))) return;
    tinymce.init(commonConfig(selector, extra));
  }

  return { init, openPicker, commonConfig };
})();
