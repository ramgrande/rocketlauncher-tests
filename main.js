/*  Facebook Rocket‑Launcher – front‑end logic
    ===============================================================
    © 2025 – MIT‑licensed sample code.  No warranty; use at your own
    discretion and be sure to review OAuth / Graph API limits, etc.
*/

window.addEventListener('DOMContentLoaded', function () {

  /*━━━━━━━━━━━━━━━━━━ 1. Tiny helpers ━━━━━━━━━━━━━━━━━━*/
  const $ = sel => document.querySelector(sel);

  // Show / hide secret inputs (Google key & FB token)
  $('#toggleToken')    ?.addEventListener('click', () => toggleSecret('#accessToken',  '#eyeIcon'));
  $('#toggleGoogleKey')?.addEventListener('click', () => toggleSecret('#googleApiKey', '#googleEyeIcon'));

  function toggleSecret(inputSel, iconSel) {
    const inp = $(inputSel);
    const ico = $(iconSel);
    if (!inp || !ico) return;
    if (inp.type === 'password') { inp.type = 'text';  ico.textContent = '🙈'; }
    else                         { inp.type = 'password'; ico.textContent = '👁️'; }
  }

  /** Read a `fetch` Response safely as JSON or throw the raw text. */
  async function safeJson(resp) {
    const raw = await resp.text();
    try { return JSON.parse(raw); }
    catch { throw new Error(raw || resp.statusText); }
  }

  /*━━━━━━━━━━━━━━━━━━ 2. Upload‑log helpers ━━━━━━━━━━━━*/
  const rowMap   = Object.create(null);   // filename → <tr>
  let doneCount  = 0;

  function makeRow(filename, status = 'Queued') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${filename}</td>
      <td class="vidId"></td>
      <td class="statCell">
        <div class="bar"><div class="fill" style="width:0"></div></div>
        <span class="txt">${status}</span>
      </td>`;
    document.querySelector('.log-table tbody')?.appendChild(tr);
    rowMap[filename] = tr;
  }

  function updateBar(tr, pct, txt) {
    if (!tr) return;
    tr.querySelector('.fill').style.width = pct + '%';
    tr.querySelector('.txt').textContent  = txt;
  }

  /*━━━━━━━━━━━━━━━━━━ 3. Campaign template data ━━━━━━━*/
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

  const stripExt = name => name.replace(/\.[^.]+$/, '');

  /* Prefill textboxes so the user sees something */
  $('#bodyField').value         = placeholderRow[idxBody]  ?? '';
  $('#titleField').value        = placeholderRow[idxTitle] ?? '';
  $('#linkField').value         = placeholderRow[idxLink]  ?? '';
  $('#campaignNameField').value = placeholderRow[idxCampaignName] ?? '';

  /*━━━━━━━━━━━━━━━━━━ 4. Campaign structure picker ━━━━*/
  function renderStructurePicker(videoCount = 10) {
    const el = $('#structurePicker');
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
    numInput.disabled = true;
    document.querySelectorAll('input[name="structure"]').forEach(r =>
      r.addEventListener('change', () => { numInput.disabled = r.value !== 'custom'; })
    );
    numInput.addEventListener('input', () => {
      const v = Math.max(1, Math.min(videoCount, parseInt(numInput.value || '1', 10)));
      numInput.value = v;
    });
  }
  renderStructurePicker();

  /*━━━━━━━━━━━━━━━━━━ 5. XLSX preview pane ━━━━━━━━━━━*/
  let rows        = [ placeholderRow.slice() ];
  let globalData  = [];

  function populatePreview() {
    const cont = $('#previewContainer');
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

  /*━━━━━━━━━━━━━━━━━━ 6. “Load All Uploaded” button ━━*/
  $('#loadBtn').addEventListener('click', async () => {
    try {
      const r = await fetch('latest_fb_ids.json?ts=' + Date.now());
      if (!r.ok) throw new Error('latest_fb_ids.json not found');
      const data = await r.json();
      if (!Array.isArray(data) || data.length === 0) throw new Error('JSON must be a non‑empty array');

      globalData = data;
      rows = [];
      const campNameInput = $('#campaignNameField').value.trim();

      data.forEach(({ filename, video_id }) => {
        const r  = placeholderRow.slice();
        r[idxVideoID]      = video_id;
        r[idxVideoFile]    = filename;
        r[idxCampaignName] = campNameInput || r[idxCampaignName];
        r[idxAdName]       = stripExt(filename);
        rows.push(r);
      });

      renderStructurePicker(data.length);
      populatePreview();
      alert(`Loaded ${rows.length} video${rows.length > 1 ? 's' : ''}.`);
    } catch (err) { alert(err.message); }
  });

  /*━━━━━━━━━━━━━━━━━━ 7. Download XLSX button ━━━━━━━━*/
  $('#downloadBtn').addEventListener('click', () => {
    if (!globalData.length) { alert('Load video IDs first.'); return; }

    const structure = document.querySelector('input[name="structure"]:checked').value;
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
      const n = parseInt($('#adsetNumInput').value, 10) || 1;
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

  /*━━━━━━━━━━━━━━━━━━ 8. Facebook "Exists" check helper ━━━━━━━━━━━━*/
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

  /*━━━━━━━━━━━━━━━━━━ 9. Upload workflow – with pre-check ━━━━━━━━━━━━*/
  $('#uploadForm').addEventListener('submit', async e => {
    e.preventDefault();

    const folderId     = $('#folderId').value.trim();
    const accessToken  = $('#accessToken').value.trim();
    const accountId    = $('#accountId').value.trim();
    const googleApiKey = $('#googleApiKey').value.trim();
    const uploadBtn    = $('#uploadBtn');
    const logDiv       = $('#uploadLogContainer');

    uploadBtn.disabled = true;
    logDiv.innerHTML   = '';

    // 1. Count files from Google Drive (get list of file names)
    let fileNames = [];
    let fileCount = 0;
    try {
      const r  = await fetch('upload.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({ folderId, accessToken, accountId, googleApiKey, count: true })
      });
      if (!r.ok) throw new Error(await r.text());
      const js = await safeJson(r);
      fileCount = +js.count || 0;
      fileNames = js.files || []; // Expect js.files to be an array of file names
      if (!fileNames.length) throw new Error("No files found in Google Drive folder.");
    } catch (err) {
      logDiv.textContent = 'Count failed: ' + err.message;
      uploadBtn.disabled = false;
      return;
    }

    // 2. Draw table skeleton
    logDiv.innerHTML = '<b>Upload Log:</b>';
    logDiv.insertAdjacentHTML('beforeend', `
      <table class="log-table">
        <thead><tr><th>Filename</th><th>Video ID</th><th>Status</th></tr></thead>
        <tbody></tbody>
      </table>`);

    // 3. Check Facebook for existing videos
    const uploadQueue = [];
    let skippedCount = 0;

    for (const fn of fileNames) {
      const skip = await facebookVideoExists(fn, accessToken, accountId);
      if (skip) {
        makeRow(fn, 'Skipped 🚫');
        skippedCount++;
      } else {
        makeRow(fn, 'Queued');
        uploadQueue.push(fn);
      }
    }

    if (skippedCount)
      logDiv.insertAdjacentHTML('beforeend', `<div style="color:#009900;">${skippedCount} video${skippedCount>1?'s':''} skipped (already uploaded to Facebook)</div>`);

    if (!uploadQueue.length) {
      logDiv.insertAdjacentHTML('beforeend', '<div style="color:#d00;">No videos left to upload.</div>');
      uploadBtn.disabled = false;
      return;
    }

    // 4. Kick off upload job for files in uploadQueue only
    let jobId;
    try {
      const r = await fetch('upload.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({
          folderId, accessToken, accountId, googleApiKey, files: uploadQueue // <--- only new files
        })
      });
      if (!r.ok) throw new Error(await r.text());
      const js = await safeJson(r);
      jobId = js.jobId;
    } catch (err) {
      logDiv.textContent = 'Could not start upload: ' + err.message;
      uploadBtn.disabled = false;
      return;
    }

    // 5. Live progress via Server-Sent Events
    let lastBeat = Date.now();
    const es = new EventSource(`progress.php?jobId=${encodeURIComponent(jobId)}`);

    es.onmessage = ev => {
      lastBeat = Date.now();
      const m = JSON.parse(ev.data);

      if (m.init) {
        m.files.forEach(fn => makeRow(fn));
        return;
      }
      if (m.phase === 'download' || m.phase === 'upload') {
        const verb = m.phase === 'download' ? 'Downloading' : 'Uploading';
        updateBar(rowMap[m.filename], m.pct, `${verb} – ${m.pct}%`);
        return;
      }
      if (m.phase === 'done') {
        const ok = m.status === 'success';
        const tr = rowMap[m.filename];
        if (tr) tr.querySelector('.vidId').textContent = m.video_id || '';
        updateBar(tr, 100, ok ? 'Uploaded ✅' : 'Failed ❌');
        doneCount++;
        if (doneCount === uploadQueue.length) uploadBtn.disabled = false;
      }
    };

    es.onerror = () => {
      es.close();
      logDiv.insertAdjacentHTML('beforeend', '<div class="error">Connection lost.</div>');
      uploadBtn.disabled = false;
    };

    // Heartbeat counter
    setInterval(() => {
      const ago = Math.round((Date.now() - lastBeat) / 1000);
      logDiv.querySelector('.heartbeat')?.remove();
      logDiv.insertAdjacentHTML('beforeend',
        `<div class="heartbeat">Last activity ${ago}s ago</div>`);
    }, 1000);

  });

});
