/*  Facebook Rocket-Launcher – front-end logic
    © 2025 – MIT-licensed sample code. No warranty; use at your own discretion. */

window.addEventListener('DOMContentLoaded', function () {

  /*━━━━━━━━━━━━━━━━━━ 1. Tiny helpers ━━━━━━━━━━━━━━━━━━*/
  const $ = sel => document.querySelector(sel);

  function toggleSecret(inputSel, iconSel) {
    const inp = $(inputSel), ico = $(iconSel);
    if (!inp || !ico) return;
    if (inp.type === 'password') { inp.type = 'text';  ico.textContent = '🙈'; }
    else                         { inp.type = 'password'; ico.textContent = '👁️'; }
  }

  $('#toggleToken')    ?.addEventListener('click', () => toggleSecret('#accessToken',  '#eyeIcon'));
  $('#toggleGoogleKey')?.addEventListener('click', () => toggleSecret('#googleApiKey','#googleEyeIcon'));

  async function safeJson(resp) {
    const raw = await resp.text();
    try { return JSON.parse(raw); }
    catch { throw new Error(raw || resp.statusText); }
  }

  /*━━━━━━━━━━━━━━━━━━ 2. Upload-log helpers ━━━━━━━━━━━━*/
  const rowMap  = Object.create(null);
  let doneCount = 0, fileCount = 0;

  function makeRow(filename, status = 'Queued') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${filename}</td>
      <td class="vidId"></td>
      <td class="statCell">
        <div class="bar"><div class="fill" style="width:0"></div></div>
        <span class="txt">${status}</span>
      </td>`;
    document.querySelector('.log-table tbody').appendChild(tr);
    rowMap[filename] = tr;
  }

  function updateBar(tr, pct, txt) {
    if (!tr) return;
    tr.querySelector('.fill').style.width = pct + '%';
    tr.querySelector('.txt').textContent  = txt;
  }

  /*━━━━━━━━━━━━━━━━━━ 3. Campaign template data ━━━━━━━━━━━━━━━━━━*/
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
    null,
    "Campaign #1","PAUSED","Outcome Leads","AUCTION",
    "06/17/2025 1:09:54 pm","Yes","NONE","No","[]","NONE",null,
    "ACTIVE",0,"Adset #1","06/17/2025 1:09:54 pm",15,
    "UNDEFINED",0,"No","No","[]",null,null,"LEAD",
    "https://putyourlinkhere.com","US","home, recent",18,40,
    "FACEBOOK_RELAXED, AN_RELAXED","OFFSITE_CONVERSIONS",
    '[{"event_type":"CLICK_THROUGH","window_days":7},' +
      '{"event_type":"VIEW_THROUGH","window_days":1},' +
      '{"event_type":"ENGAGED_VIDEO_VIEW","window_days":1}]',
    "IMPRESSIONS","Highest volume or value",null,"ACTIVE",null,null,
    "Ad Name #1","Headline","Ad Copy","Yes",null,null,
    "untitled","Video Page Post Ad","", "",null,"LEARN_MORE","[]",
    "No",null,"No","USER_ENROLLED_NON_DCO","TEXT_LIQUIDITY"
  ];

  const idxCampaignName = headers.indexOf("Campaign Name");
  const idxAdSetName    = headers.indexOf("Ad Set Name");
  const idxAdName       = headers.indexOf("Ad Name");
  const idxVideoID      = headers.indexOf("Video ID");
  const idxVideoFile    = headers.indexOf("Video File Name");
  const idxBody         = headers.indexOf("Body");
  const idxTitle        = headers.indexOf("Title");
  const idxLink         = headers.indexOf("Link");
  const stripExt        = name => name.replace(/\.[^.]+$/, '');

  // Prefill preview fields
  $('#bodyField').value         = placeholderRow[idxBody]  || '';
  $('#titleField').value        = placeholderRow[idxTitle] || '';
  $('#linkField').value         = placeholderRow[idxLink]  || '';
  $('#campaignNameField').value = placeholderRow[idxCampaignName] || '';

  /*━━━━━━━━━━━━━━━━━━ 4. Campaign structure picker ━━━━━━━━━━━━*/
  function renderStructurePicker(count=1) {
    const el = $('#structurePicker');
    el.innerHTML = `
      <div class="structure-picker">
        <label><input type="radio" name="structure" value="one-adset" checked>
          1 Ad Set, multiple ads
        </label>
        <label><input type="radio" name="structure" value="abo-1to1">
          ABO 1:1 (1 Ad Set per Ad)
        </label>
        <label>
          <input type="radio" name="structure" value="custom">
          Custom:
          <input type="number" id="adsetNumInput" min="1" max="${count}"
                 value="2" disabled> Ad Sets
        </label>
      </div>`;
    const numIn = $('#adsetNumInput');
    document.querySelectorAll('input[name="structure"]').forEach(r => {
      r.addEventListener('change', () => numIn.disabled = r.value!=='custom');
    });
    numIn.addEventListener('input', () => {
      let v = parseInt(numIn.value||'1',10);
      v = Math.max(1, Math.min(count, v));
      numIn.value = v;
    });
  }
  renderStructurePicker();

  /*━━━━━━━━━━━━━━━━━━ 5. XLSX preview pane ━━━━━━━━━━━━*/
  let rows = [ placeholderRow.slice() ], globalData = [];
  function populatePreview() {
    const cont = $('#previewContainer');
    cont.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'form-container';
    headers.forEach((h,i) => {
      const lab = document.createElement('label'),
            inp = document.createElement('input');
      lab.textContent = h;
      inp.readOnly = true;
      inp.value = rows[0][i] || '';
      wrap.appendChild(lab);
      wrap.appendChild(inp);
    });
    cont.appendChild(wrap);
  }
  populatePreview();

  /*━━━━━━━━━━━━━━━━━━ 6. “Load All Uploaded” button ━━━━━━━━━━━━*/
  $('#loadBtn').addEventListener('click', async () => {
    try {
      const r = await fetch('latest_fb_ids.json?ts='+Date.now());
      if (!r.ok) throw new Error('Cannot load latest_fb_ids.json');
      const data = await r.json();
      if (!Array.isArray(data)||!data.length) throw new Error('Empty JSON');
      globalData = data;
      rows = data.map(({filename,video_id}) => {
        const r = placeholderRow.slice();
        r[idxVideoID]   = video_id;
        r[idxVideoFile] = filename;
        r[idxCampaignName] = $('#campaignNameField').value.trim() || r[idxCampaignName];
        r[idxAdName]    = stripExt(filename);
        return r;
      });
      renderStructurePicker(rows.length);
      populatePreview();
      alert(`Loaded ${rows.length} video${rows.length>1?'s':''}.`);
    } catch (e) {
      alert(e.message);
    }
  });

  /*━━━━━━━━━━━━━━━━━━ 7. Download XLSX button ━━━━━━━━━━━━*/
  $('#downloadBtn').addEventListener('click', () => {
    if (!globalData.length) { alert('Load video IDs first.'); return; }
    const structure = document.querySelector('input[name="structure"]:checked').value;
    let finalRows = [];
    if (structure==='one-adset') {
      finalRows = rows;
    } else if (structure==='abo-1to1') {
      finalRows = rows.map((r,i) => {
        const cp = r.slice();
        cp[idxAdSetName] = `Adset #${i+1}`;
        return cp;
      });
    } else {
      const n = parseInt($('#adsetNumInput').value,10) || 1;
      finalRows = rows.map((r,i) => {
        const cp = r.slice();
        cp[idxAdSetName] = `Adset #${(i % n)+1}`;
        return cp;
      });
    }
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet([headers, ...finalRows]);
    XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
    XLSX.writeFile(wb, 'facebook_campaign.xlsx');
  });

  /*━━━━━━━━━━━━━━━━━━ 8. Upload workflow ━━━━━━━━━━━━*/
  $('#uploadForm').addEventListener('submit', async e => {
    e.preventDefault();

    const folderId     = $('#folderId').value.trim();
    const googleApiKey = $('#googleApiKey').value.trim();
    const accessToken  = $('#accessToken').value.trim();
    const accountId    = $('#accountId').value.trim();
    const uploadBtn    = $('#uploadBtn');
    const logDiv       = $('#uploadLogContainer');

    uploadBtn.disabled = true;
    logDiv.innerHTML   = '';

    // 8-A Count
    try {
      const res = await fetch('upload.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ folderId, googleApiKey, accessToken, accountId, count:true })
      });
      if (!res.ok) throw new Error(await res.text());
      fileCount = +(await safeJson(res)).count;
    } catch (err) {
      logDiv.textContent = 'Count failed: ' + err.message;
      uploadBtn.disabled = false;
      return;
    }

    // 8-B Draw table
    logDiv.innerHTML = '<b>Upload Log:</b>';
    logDiv.insertAdjacentHTML('beforeend', `
      <table class="log-table">
        <thead><tr><th>Filename</th><th>Video ID</th><th>Status</th></tr></thead>
        <tbody></tbody>
      </table>`);

    // 8-C Start job
    let jobId;
    try {
      const res = await fetch('upload.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ folderId, googleApiKey, accessToken, accountId })
      });
      if (!res.ok) throw new Error(await res.text());
      jobId = (await safeJson(res)).jobId;
    } catch (err) {
      logDiv.textContent = 'Could not start upload: ' + err.message;
      uploadBtn.disabled = false;
      return;
    }

    // 8-D Polling for progress push test
  
    let lastSize = 0;
    const poller = setInterval(pollProgress, 1000);

    async function pollProgress() {
      try {
        const res  = await fetch(`progress.php?jobId=${encodeURIComponent(jobId)}`);
        if (!res.ok) throw new Error('Progress fetch failed');
        const text = await res.text();
        const lines = text.trim().split('\n').filter(l => l.trim().startsWith('{'));

        for (let i = lastSize; i < lines.length; i++) {
          handleProgressMessage(JSON.parse(lines[i]));
        }
        lastSize = lines.length;

      } catch (err) {
        console.error('Poll error:', err);
        clearInterval(poller);
        logDiv.insertAdjacentHTML('beforeend','<div class="error">Polling stopped.</div>');
        uploadBtn.disabled = false;
      }
    }

    function handleProgressMessage(m) {
      if (m.phase === 'skip') {
        const tr = rowMap[m.filename];
        if (tr) updateBar(tr, 100, 'Skipped ⚠️');
        return;
      }
      if (m.phase === 'download' || m.phase === 'upload') {
        const verb = m.phase==='download' ? 'Downloading' : 'Uploading';
        updateBar(rowMap[m.filename], m.pct, `${verb} – ${m.pct}%`);
        return;
      }
      if (m.phase === 'done') {
        const ok = m.status==='success';
        const tr = rowMap[m.filename];
        if (tr) tr.querySelector('.vidId').textContent = m.video_id || '';
        updateBar(tr, 100, ok ? 'Uploaded ✅' : 'Failed ❌');
        doneCount++;
        if (doneCount === fileCount) {
          clearInterval(poller);
          uploadBtn.disabled = false;
        }
      }
    }

  });

});
