/*  Facebook Rocket‑Launcher – front‑end logic
    ===============================================================
    v1.2  (2025‑06‑29)
      • duplicate‑video handling
      • EventSource auto‑reconnect + heartbeat
      • same XLSX generator as v1.0
      • prettier secret‑eye toggle
      • no external deps beyond XLSX.js
*/

window.addEventListener('DOMContentLoaded', () => {

  /*━━━━━━━━━━━━━━━━━━ 0. Tiny helpers ━━━━━━━━━━━━━━━━━━*/
  const $    = s => document.querySelector(s);
  const $$   = s => [...document.querySelectorAll(s)];
  const stripExt = n => n.replace(/\.[^.]+$/, '');

  /*━━━━━━━━ 1. Toggle visibility for secret inputs ━━━━━*/
  if ($('#toggleToken')) {
    $('#toggleToken').addEventListener('click', () => toggleSecret('#accessToken',  '#eyeIcon'));
  }
  if ($('#toggleGoogleKey')) {
    $('#toggleGoogleKey').addEventListener('click', () => toggleSecret('#googleApiKey', '#googleEyeIcon'));
  }
  function toggleSecret(inputSel, iconSel) {
    const inp = $(inputSel), ico = $(iconSel);
    if (!inp || !ico) return;
    if (inp.type === 'password') { inp.type = 'text';  ico.textContent = '🙈'; }
    else                         { inp.type = 'password'; ico.textContent = '👁️'; }
  }

  /*━━━━━━━━━━━━━━━━ 2. Safe JSON fetch ━━━━━━━━━━━━━━━━*/
  async function safeJson(resp){
    const t = await resp.text();
    try { return JSON.parse(t); } catch { throw new Error(t || resp.statusText); }
  }

  /*━━━━━━━━━━━━━━━━ 3. Upload‑log helpers ━━━━━━━━━━━━━*/
  const rowMap  = Object.create(null);
  let remaining = 0;

  function makeRow(fn, status='Queued'){
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${fn}</td>
      <td class="vidId"></td>
      <td class="statCell">
        <div class="bar"><div class="fill" style="width:0"></div></div>
        <span class="txt">${status}</span>
      </td>`;
    const logTable = $('.log-table tbody');
    if (logTable) logTable.appendChild(tr);
    rowMap[fn] = tr;
  }
  const updateBar = (tr, pct, txt) => {
    if (!tr) return;
    const fill = tr.querySelector('.fill');
    const txtSpan = tr.querySelector('.txt');
    if (fill) fill.style.width = pct + '%';
    if (txtSpan) txtSpan.textContent = txt;
  };

  /*━━━━━━━━━━━━━━━━━━ 4. Campaign template data ━━━━━━━*/
  const headers = [
    "Campaign ID","Campaign Name","Campaign Status","Campaign Objective","Buying Type",
    "Campaign Start Time","New Objective","Buy With Prime Type","Is Budget Scheduling Enabled For Campaign",
    "Campaign High Demand Periods","Buy With Integration Partner","Ad Set ID","Ad Set Run Status",
    "Ad Set Lifetime Impressions","Ad Set Name","Ad Set Time Start","Ad Set Daily Budget",
    "Destination Type","Ad Set Lifetime Budget","Use Accelerated Delivery",
    "Is Budget Scheduling Enabled For Ad Set","Ad Set High Demand Periods","Link Object ID",
    "Optimized Conversion Tracking Pixels","Optimized Event","Link","Countries","Location Types",
    "Age Min","Age Max","Brand Safety Inventory Filtering Levels","Optimization Goal",
    "Attribution Spec","Billing Event","Ad Set Bid Strategy","Ad ID","Ad Status","Preview Link",
    "Instagram Preview Link","Ad Name","Title","Body","Optimize text per person",
    "Conversion Tracking Pixels","Image Hash","Image File Name","Creative Type","Video ID",
    "Video File Name","Instagram Account ID","Call to Action","Additional Custom Tracking Specs",
    "Video Retargeting","Permalink","Use Page as Actor","Degrees of Freedom Type","Text Transformations"
  ];

  const placeholderRow = [
    null,"Campaign #1","PAUSED","Outcome Leads","AUCTION","06/17/2025 1:09:54 pm","Yes","NONE",
    "No","[]","NONE",null,"ACTIVE",0,"Adset #1",
    "06/17/2025 1:09:54 pm",15,"UNDEFINED",0,"No","No","[]",null,null,"LEAD",
    "https://putyourlinkhere.com",
    "US","home, recent",18,40,
    "FACEBOOK_RELAXED, AN_RELAXED","OFFSITE_CONVERSIONS",
    '[{"event_type":"CLICK_THROUGH","window_days":7},{"event_type":"VIEW_THROUGH","window_days":1},{"event_type":"ENGAGED_VIDEO_VIEW","window_days":1}]',
    "IMPRESSIONS","Highest volume or value",null,"ACTIVE",null,null,
    "Ad Name #1","Headline","Ad Copy","Yes",
    null,null,"untitled","Video Page Post Ad","", "",null,"LEARN_MORE","[]","No",null,"No","USER_ENROLLED_NON_DCO","TEXT_LIQUIDITY"
  ];

  /* Column indices we’ll re‑use a lot */
  const idxCampaignName = headers.indexOf("Campaign Name");
  const idxAdSetName    = headers.indexOf("Ad Set Name");
  const idxAdName       = headers.indexOf("Ad Name");
  const idxVideoID      = headers.indexOf("Video ID");
  const idxVideoFile    = headers.indexOf("Video File Name");
  const idxBody         = headers.indexOf("Body");
  const idxTitle        = headers.indexOf("Title");
  const idxLink         = headers.indexOf("Link");

  /* Prefill textboxes so the user sees something */
  if ($('#bodyField'))         $('#bodyField').value         = placeholderRow[idxBody]  ?? '';
  if ($('#titleField'))        $('#titleField').value        = placeholderRow[idxTitle] ?? '';
  if ($('#linkField'))         $('#linkField').value         = placeholderRow[idxLink]  ?? '';
  if ($('#campaignNameField')) $('#campaignNameField').value = placeholderRow[idxCampaignName] ?? '';

  /*━━━━━━━━━━━━━━━━━━ 5. Campaign structure picker ━━━━*/
  function renderStructurePicker(videoCount = 10) {
    const el = $('#structurePicker');
    if (!el) return;
    el.innerHTML = `
      <div class="structure-picker">
        <div class="structure-title">Campaign Structure</div>
        <div class="structure-choice">
          <input type="radio" name="structure" id="oneAdset" value="one-adset" checked>
          <label for="oneAdset">1 Ad Set, multiple ads (all videos under <b>Adset #1</b>)</label>
        </div>
        <div class="structure-choice">
          <input type="radio" name="structure" id="abo1to1" value="abo-1to1">
          <label for="abo1to1">1 Ad Set per 1 Ad (ABO 1:1)</label>
        </div>
        <div class="structure-choice">
          <input type="radio" name="structure" id="customABO" value="custom">
          <label for="customABO">Custom:
            <input type="number" id="adsetNumInput"
                   class="adset-num-input" min="1" max="${videoCount}" value="2">
            ad sets (max ${videoCount})
          </label>
        </div>
      </div>`;
    const numInput = $('#adsetNumInput');
    if (numInput) numInput.disabled = true;
    $$('#structurePicker input[name="structure"]').forEach(r =>
      r.addEventListener('change', () => { if (numInput) numInput.disabled = r.value !== 'custom'; })
    );
    if (numInput) numInput.addEventListener('input', () => {
      const v = Math.max(1, Math.min(videoCount, parseInt(numInput.value || '1', 10)));
      numInput.value = v;
    });
  }
  renderStructurePicker();

  /*━━━━━━━━━━━━━━━━━━ 6. XLSX preview pane ━━━━━━━━━━━*/
  let rows        = [ placeholderRow.slice() ];
  let globalData  = [];

  function populatePreview() {
    const cont = $('#previewContainer');
    if (!cont) return;
    cont.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'form-container';
    headers.forEach((h, i) => {
      const lab = document.createElement('label'); lab.textContent = h;
      const inp = document.createElement('input'); inp.readOnly = true;
      inp.value = rows[0][i] ?? '';
      wrap.appendChild(lab);  wrap.appendChild(inp);
    });
    cont.appendChild(wrap);
  }
  populatePreview();

  /*━━━━━━━━━━━━━━━━━━ 7. “Load All Uploaded” button ━━*/
  const loadBtn = $('#loadBtn');
  if (loadBtn) {
    loadBtn.addEventListener('click', async () => {
      try {
        const r = await fetch('latest_fb_ids.json?ts=' + Date.now());
        if (!r.ok) throw new Error('latest_fb_ids.json not found');
        const data = await r.json();
        if (!Array.isArray(data) || data.length === 0) throw new Error('JSON must be a non‑empty array');

        globalData = data;
        rows = [];
        const campNameInput = $('#campaignNameField')?.value.trim() || '';

        data.forEach(({ filename, video_id }) => {
          const row  = placeholderRow.slice();
          row[idxVideoID]      = video_id;
          row[idxVideoFile]    = filename;
          row[idxCampaignName] = campNameInput || row[idxCampaignName];
          row[idxAdName]       = stripExt(filename);
          rows.push(row);
        });

        renderStructurePicker(data.length);
        populatePreview();
        alert(`Loaded ${rows.length} video${rows.length > 1 ? 's' : ''}.`);
      } catch (err) { alert(err.message); }
    });
  }

  /*━━━━━━━━━━━━━━━━━━ 8. Download XLSX button ━━━━━━━━*/
  const downloadBtn = $('#downloadBtn');
  if (downloadBtn) {
    downloadBtn.addEventListener('click', () => {
      if (!globalData.length) { alert('Load video IDs first.'); return; }

      const structureRadio = document.querySelector('input[name="structure"]:checked');
      if (!structureRadio) { alert('Select a campaign structure.'); return; }
      const structure = structureRadio.value;
      let finalRows   = [];

      if (structure === 'one-adset') {
        finalRows = rows;
      } else if (structure === 'abo-1to1') {
        finalRows = rows.map((r, i) => {
          const cp = r.slice();
          cp[idxAdSetName] = `Adset #${i + 1}`;
          return cp;
        });
      } else {                       // custom n ad sets
        const n = parseInt($('#adsetNumInput')?.value, 10) || 1;
        finalRows = rows.map((r, i) => {
          const cp = r.slice();
          cp[idxAdSetName] = `Adset #${(i % n) + 1}`;
          return cp;
        });
      }

      /* Build workbook & download */
      const wb = XLSX.utils.book_new();
      const ws = XLSX.utils.aoa_to_sheet([headers, ...finalRows]);
      XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
      XLSX.writeFile(wb, 'facebook_campaign.xlsx');
    });
  }

  /*━━━━━━━━━━━━━━━━━━ 9. Facebook "Exists" check helper ━━━━━━━━━━━━*/
  async function facebookVideoExists(filename, accessToken, accountId) {
    // Fetch existing videos and compare titles
    try {
      const stripped = filename.replace(/\.[^.]+$/, '');
      let url = `https://graph.facebook.com/v20.0/act_${accountId}/advideos?fields=title&limit=100&access_token=${encodeURIComponent(accessToken)}`;
      let found = false;
      let nextPage = url;

      // Loop through all pages (for accounts with many videos)
      while (nextPage && !found) {
        const resp = await fetch(nextPage);
        const data = await resp.json();
        if (data.data && Array.isArray(data.data)) {
          if (data.data.some(vid => vid.title === stripped)) {
            found = true;
            break;
          }
        }
        nextPage = data.paging && data.paging.next ? data.paging.next : null;
      }
      return found;
    } catch (err) {
      // Log error in console, treat as not found (upload just in case)
      console.error('FB exists check error for', filename, err);
      return false;
    }
  }

  /*━━━━━━━━━━━━━━━━━━ 10. Main upload workflow ━━━━━━━━━━━*/
  const uploadForm = $('#uploadForm');
  if (uploadForm) {
    uploadForm.addEventListener('submit', async e => {
      e.preventDefault();

      const folderId     = $('#folderId'    )?.value.trim() || '';
      const googleApiKey = $('#googleApiKey')?.value.trim() || '';
      const accessToken  = $('#accessToken' )?.value.trim() || '';
      const accountId    = $('#accountId'   )?.value.trim() || '';
      const uploadBtn    = $('#uploadBtn');
      const logDiv       = $('#uploadLogContainer');

      if (uploadBtn) uploadBtn.disabled = true;
      if (logDiv) logDiv.innerHTML = '';

      /* 10‑A ── ask the server how many files it will touch */
      let fileCount = 0;
      try{
        const r = await fetch('upload.php',{
          method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({folderId,googleApiKey,accessToken,accountId,count:true})
        });
        if (!r.ok) throw new Error(await r.text());
        fileCount = (+await r.text())|0;
      }catch(err){
        if (logDiv) logDiv.textContent = 'Count failed: '+err.message;
        if (uploadBtn) uploadBtn.disabled = false;
        return;
      }

      /* 10‑B ── draw empty log table */
      if (logDiv) logDiv.innerHTML = '<b>Upload Log:</b><table class="log-table"><thead><tr><th>Filename</th><th>Video ID</th><th>Status</th></tr></thead><tbody></tbody></table>';

      /* 10‑C ── start the job */
      let jobId='';
      try{
        const r = await fetch('upload.php',{
          method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({folderId,googleApiKey,accessToken,accountId})
        });
        if(!r.ok) throw new Error(await r.text());
        jobId = (await safeJson(r)).jobId;
      }catch(err){
        if (logDiv) logDiv.textContent = 'Could not start upload: '+err.message;
        if (uploadBtn) uploadBtn.disabled = false;
        return;
      }

      /* 10‑D ── live updates via SSE (with auto‑reconnect) */
      let es, lastBeat=Date.now(), closed=false;
      const connect = () => {
        es = new EventSource(`progress.php?jobId=${encodeURIComponent(jobId)}`);
        es.onmessage = ev => {
          lastBeat = Date.now();
          const m = JSON.parse(ev.data);

          if (m.init){
            m.files.forEach(fn=>makeRow(fn));
            remaining = m.files.length;
            return;
          }
          const tr = rowMap[m.filename];
          if (m.phase==='download' || m.phase==='upload'){
            const verb = m.phase==='download' ? 'Downloading' : 'Uploading';
            updateBar(tr, m.pct, `${verb} – ${m.pct}%`);
            return;
          }
          if (m.phase==='done'){
            if (m.status==='duplicate'){
              updateBar(tr,100,'Skipped ♻️');
              if (tr) tr.querySelector('.vidId').textContent = m.video_id;
            }else if (m.status==='success'){
              updateBar(tr,100,'Uploaded ✅');
              if (tr) tr.querySelector('.vidId').textContent = m.video_id;
            }else{
              updateBar(tr,100,'Failed ❌');
            }
            if(--remaining===0){ if (uploadBtn) uploadBtn.disabled=false; es.close(); closed=true; }
          }
        };
        es.onerror = () => { es.close(); if(!closed) setTimeout(connect,1600); };
      };
      connect();

      /* 10‑E ── heartbeat overlay so users know it’s alive */
      setInterval(()=>{
        const ago = Math.round((Date.now()-lastBeat)/1000);
        if (logDiv) {
          logDiv.querySelector('.heartbeat')?.remove();
          if(!closed) logDiv.insertAdjacentHTML('beforeend',`<div class="heartbeat">Last activity ${ago}s ago</div>`);
        }
      },1000);
    });
  }

});
